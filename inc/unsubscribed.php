<?php
/**
 * 评论里「你还没订阅他」的提示。
 *
 * 要解决的事：有新博友来留言，但我不确定自己订没订他的 RSS，得挨个去 FreshRSS 里搜。
 *
 * 做法：评论列表里，最近一段时间来留言、而且域名不在订阅列表里的人，
 * 作者名后面挂一个「未订阅」小标；页面顶部再汇总一条，告诉他最近有几个人还没订。
 *
 * 口径说明（别指望它 100% 准，原因写在下面）：
 *   - 只能拿评论里填的网址去比订阅列表，而有人填的是 GitHub / 微博这种主页，没 feed 可订
 *     → 这类直接被排除，不标
 *   - 有人换过域名，评论里还是老域名 → 会误标成「未订阅」，只能靠人看一眼
 *   - 只标最近的评论（默认 90 天），十几年前的老评论不标 —— 那些早处理过了，
 *     全标出来只会屏幕上下一片红，等于没提示
 *
 * @package wp-boyou-feed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 订阅站收到过的所有留言邮箱 → 对应的订阅主域。
 *
 * 用来避免一种「假阳性」：一个人有两个站，你订了他其中一个，
 * 他用另一个站来留言 —— 按域名看是「没订阅过」，按人看其实早订了。
 * 实测老张站上有 2 个（ww.sangushui.com / jefftay.com），
 * 点「添加订阅」会给他重复订上同一个人的第二个站。
 *
 * 对账那边已经按邮箱认人了，这里必须用同一套标准，否则同一个概念两个结论。
 *
 * @return array email => 订阅主域
 */
function wbf_sub_email_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map    = array();
	$bundle = wbf_seen_bundle();
	$alias  = wbf_sub_alias_map();

	foreach ( array_keys( $alias ) as $h ) {
		if ( empty( $bundle['host_emails'][ $h ] ) ) {
			continue;
		}
		foreach ( array_keys( $bundle['host_emails'][ $h ] ) as $em ) {
			if ( ! isset( $map[ $em ] ) ) {
				$map[ $em ] = $alias[ $h ];
			}
		}
	}

	return $map;
}

/**
 * 往前看多少天。默认 90 天，站长可以用过滤器改。
 *
 * @return int
 */
function wbf_unsub_window_days() {
	$days = (int) apply_filters( 'wbf_unsub_window_days', 90 );
	return $days > 0 ? $days : 90;
}

/**
 * 这条评论的作者，是不是「来了但我没订阅」。
 *
 * @param int $comment_id 评论 ID。
 * @return bool
 */
function wbf_comment_is_unsub( $comment_id ) {
	static $cache = array();
	$comment_id  = (int) $comment_id;

	if ( isset( $cache[ $comment_id ] ) ) {
		return $cache[ $comment_id ];
	}

	$cache[ $comment_id ] = false;
	$comment              = get_comment( $comment_id );
	if ( ! $comment ) {
		return false;
	}

	// 只算通过审核的普通评论；pingback / 点赞不算「人来过」。
	if ( '1' !== (string) $comment->comment_approved ) {
		return false;
	}
	if ( ! in_array( (string) $comment->comment_type, array( '', 'comment' ), true ) ) {
		return false;
	}
	// 登录用户（含站长自己）不算访客。
	if ( 0 !== (int) $comment->user_id ) {
		return false;
	}

	$url = trim( (string) $comment->comment_author_url );
	if ( '' === $url ) {
		return false;
	}

	// 只看最近一段时间的。
	$ts = strtotime( $comment->comment_date_gmt . ' GMT' );
	if ( ! $ts || $ts < ( time() - wbf_unsub_window_days() * DAY_IN_SECONDS ) ) {
		return false;
	}

	$host = wbf_norm_host( $url );
	if ( '' === $host || wbf_is_thirdparty_host( $host ) ) {
		return false;
	}

	// 这个域名属于某条订阅（含子域归并、换域名的情况）就不算「未订阅」。
	if ( '' !== wbf_host_in_subs( $host ) ) {
		return false;
	}

	// 邮箱这层也看一眼：他可能是用另一个站来的，那个站已经订了。
	$email = strtolower( trim( (string) $comment->comment_author_email ) );
	if ( '' !== $email && false !== strpos( $email, '@' ) ) {
		$emails = wbf_sub_email_map();
		if ( isset( $emails[ $email ] ) ) {
			return false;
		}
	}

	$cache[ $comment_id ] = $host;
	return $host;
}

/**
 * 在后台评论列表的作者名后面挂一个「未订阅」小标。
 *
 * 用 comment_author 这个过滤器，不用 get_comment_author：
 * 评论列表的 column_author() 走的是 comment_author()，而 comment_author() 是
 * `echo apply_filters('comment_author', ...)` —— 返回值直接输出，不转义。
 * 挂在 get_comment_author 上会被后面某处 escape 掉，页面上直接露出一串 <span ...> 文本
 * （第一版就是这么错了一次，截图里能看到字面 HTML）。
 *
 * @param string $author     作者名。
 * @param int    $comment_id 评论 ID。
 * @return string
 */
function wbf_mark_unsub_author( $author, $comment_id = 0 ) {
	if ( ! is_admin() ) {
		return $author;
	}
	global $pagenow;
	if ( 'edit-comments.php' !== $pagenow ) {
		return $author;
	}
	if ( ! current_user_can( 'moderate_comments' ) ) {
		return $author;
	}

	$host = wbf_comment_is_unsub( $comment_id );
	if ( ! $host ) {
		return $author;
	}

	$tip = sprintf( '你还没订阅他：%s（点右边的「添加订阅」就能订上）', $host );

	$out = $author . ' <span class="wbf-unsub" title="' . esc_attr( $tip ) . '" '
		. 'style="display:inline-block;margin-left:4px;padding:0 5px;border-radius:3px;'
		. 'background:#fcf0f1;color:#d63638;border:1px solid #f0c9cb;font-size:11px;'
		. 'font-weight:400;line-height:17px;vertical-align:1px;white-space:nowrap;">未订阅</span>';

	/*
	 * 「添加订阅」只给能改站点设置的人 —— 加订阅是往 FreshRSS 里写东西，
	 * 比"能审评论"高一档。编辑看到的是红标，但不会看到一个点下去就报权限不足的按钮。
	 */
	if ( current_user_can( 'manage_options' ) ) {
		$c    = get_comment( $comment_id );
		$site = $c ? (string) $c->comment_author_url : '';
		if ( '' !== $site ) {
			$out .= ' <button type="button" class="button-link wbf-sub-add"'
				. ' data-site="' . esc_url( $site ) . '"'
				. ' data-title="' . esc_attr( $author ) . '"'
				. ' style="margin-left:5px;font-size:11px;color:#2271b1;text-decoration:none;'
				. 'white-space:nowrap;background:none;border:0;padding:0;cursor:pointer;">添加订阅</button>';
		}
	}

	return $out;
}
add_filter( 'comment_author', 'wbf_mark_unsub_author', 10, 2 );

/**
 * 最近一段时间来留言、但没被订阅的站。
 *
 * @return array 每项 array( 'host', 'name', 'url', 'last' )
 */
function wbf_recent_unsub_sites() {
	global $wpdb;

	$since = gmdate( 'Y-m-d H:i:s', time() - wbf_unsub_window_days() * DAY_IN_SECONDS );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT comment_author_url AS url, comment_author AS name,
			        comment_author_email AS email, comment_date_gmt AS d
			 FROM {$wpdb->comments}
			 WHERE comment_approved = '1'
			   AND user_id = 0
			   AND comment_author_url <> ''
			   AND comment_type IN ( '', 'comment' )
			   AND comment_date_gmt >= %s
			 ORDER BY comment_date_gmt DESC
			 LIMIT 500",
			$since
		),
		ARRAY_A
	);

	$out    = array();
	$emails = wbf_sub_email_map();

	foreach ( (array) $rows as $r ) {
		$host = wbf_norm_host( $r['url'] );
		if ( '' === $host || wbf_is_thirdparty_host( $host ) || '' !== wbf_host_in_subs( $host ) ) {
			continue;
		}

		// 他另一个站已经订了，就别再劝他订一遍。
		$em = strtolower( trim( (string) $r['email'] ) );
		if ( '' !== $em && isset( $emails[ $em ] ) ) {
			continue;
		}
		if ( isset( $out[ $host ] ) ) {
			continue; // 同一个人留了好几条，只算一个站。
		}
		$out[ $host ] = array(
			'host' => $host,
			'name' => (string) $r['name'],
			'url'  => (string) $r['url'],
			'last' => (string) $r['d'],
		);
	}

	return $out;
}

/**
 * 评论列表顶部的汇总提示。
 */
function wbf_unsub_notice() {
	if ( ! current_user_can( 'moderate_comments' ) ) {
		return;
	}
	global $pagenow;
	if ( 'edit-comments.php' !== $pagenow ) {
		return;
	}

	$sites = wbf_recent_unsub_sites();
	if ( ! $sites ) {
		return;
	}

	$days  = wbf_unsub_window_days();
	$total = count( $sites );
	$show  = array_slice( $sites, 0, 8, true );

	echo '<div class="notice notice-info" style="padding:10px 14px;">';
	echo '<p style="margin:0 0 6px;"><strong>最近 ' . (int) $days . ' 天有 '
		. (int) $total . ' 位来访者你还没订阅</strong>'
		. '：这些站来过你的博客留言，但不在你的 FreshRSS 订阅列表里。'
		. '他们的评论作者名后面会带一个红色的「未订阅」小标。</p>';
	echo '<p style="margin:0;line-height:2;">';
	foreach ( $show as $s ) {
		printf(
			'<a href="%s" target="_blank" rel="noopener noreferrer" style="margin-right:14px;">%s</a>',
			esc_url( $s['url'] ),
			esc_html( $s['name'] !== '' ? $s['name'] : $s['host'] )
		);
	}
	if ( $total > count( $show ) ) {
		echo '<span style="color:#646970;">…另有 ' . (int) ( $total - count( $show ) ) . ' 个</span>';
	}
	echo '</p>';
	echo '<p style="margin:6px 0 0;color:#646970;font-size:12px;">'
		. '判定只看评论里填的网址，跟订阅列表按域名比对。填的是 GitHub / 微博这类没 feed 的主页会被跳过；'
		. '换过域名的人可能误标，看到熟的忽略就行。</p>';
	echo '</div>';
}
add_action( 'admin_notices', 'wbf_unsub_notice' );
