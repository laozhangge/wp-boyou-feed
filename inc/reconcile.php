<?php
/**
 * 互访对账：我订阅的这些人，最近一次来我这儿留言是什么时候。
 *
 * 两边数据：
 *   ① 订阅列表 —— 同步时从 FreshRSS 拉下来，存在 output.json 的 subscriptions 里
 *   ② 来访记录 —— 本站评论表里 comment_author_url 的域名 + comment_date
 *
 * 为什么用域名匹配（不用邮箱）：订阅列表只有站址，没有邮箱。所以对不上的人一律归到
 * 「单向」，不去猜。宁可少算，不要错算。
 *
 * @package wp-boyou-feed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 从 output.json 里读订阅列表。
 *
 * @return array 每项 array( 'title', 'site', 'feed' )。
 */
function wbf_subs_from_json() {
	$path = wbf_json_path();
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return array();
	}

	$data = json_decode( (string) @file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! is_array( $data ) || empty( $data['subscriptions'] ) || ! is_array( $data['subscriptions'] ) ) {
		return array();
	}

	$out = array();
	foreach ( $data['subscriptions'] as $s ) {
		if ( ! is_array( $s ) ) {
			continue;
		}
		$host = wbf_norm_host( isset( $s['site'] ) ? $s['site'] : '' );
		if ( '' === $host ) {
			$host = wbf_norm_host( isset( $s['feed'] ) ? $s['feed'] : '' );
		}
		if ( '' === $host ) {
			continue;
		}
		$out[ $host ] = array(
			'title' => isset( $s['title'] ) ? (string) $s['title'] : $host,
			'site'  => isset( $s['site'] ) ? (string) $s['site'] : '',
			// feed 地址留着，「删除订阅」要用它去调 FreshRSS。
			'feed'  => isset( $s['feed'] ) ? (string) $s['feed'] : '',
			'host'  => $host,
		);
	}

	return $out;
}

/**
 * 读订阅列表时顺手能得到的一层：每个订阅站最近一次发文章是什么时候。
 *
 * 有个绕不开的限制要先说透：boyou.json 里的 articles 只留近 1 个月（最多 500 篇），
 * 所以这份表只能回答「本月内更新过没有」。本月内有文章的站，时间是精确的；
 * 一个月没动静的站，这里查不到他到底停更了多久 —— 那一半靠 wbf_probe_last_posts()
 * 去 FreshRSS 现查，查回来的结果存 option 缓存（wbf_last_post_cache，24 小时有效）。
 *
 * 为什么不把同步窗口直接拉大到一年：171 个订阅一年能灌几万篇文章进 boychou.json，
 * 博友圈页面只用最新 500 篇，剩下的纯浪费，页面加载还慢。按需现查更省。
 *
 * @return array host => 最近一篇文章的 epoch 时间戳。
 */
function wbf_updated_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map  = array();
	$path = wbf_json_path();
	if ( is_file( $path ) && is_readable( $path ) ) {
		$data = json_decode( (string) @file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( is_array( $data ) && ! empty( $data['articles'] ) && is_array( $data['articles'] ) ) {
			foreach ( $data['articles'] as $a ) {
				if ( ! is_array( $a ) ) {
					continue;
				}
				// time 是同步时用 date() 生成的 UTC 字符串，解析时补上时区，别让 PHP 按本机时区猜。
				$ts = strtotime( ( isset( $a['time'] ) ? (string) $a['time'] : '' ) . ' GMT' );
				$h  = wbf_norm_host( isset( $a['host'] ) ? (string) $a['host'] : '' );
				if ( ! $ts || '' === $h ) {
					continue;
				}
				// 文件里的 host 可能是子域（blog.xxx.com），折叠到订阅的主域再比。
				$main = wbf_host_in_subs( $h );
				$key  = ( '' !== $main ) ? $main : $h;
				if ( ! isset( $map[ $key ] ) || $ts > $map[ $key ] ) {
					$map[ $key ] = $ts;
				}
			}
		}
	}

	return $map;
}

/**
 * 「最后更新」的持久缓存（探回来的结果放这里）。
 *
 * @return array host => array( ts => int, checked => int, miss => bool )
 */
function wbf_lastpost_cache_get() {
	$c = get_option( 'wbf_last_post_cache', array() );
	return is_array( $c ) ? $c : array();
}

/**
 * 写缓存。整体覆盖，调用方先取再改。
 *
 * @param array $cache 全量缓存。
 * @return void
 */
function wbf_lastpost_cache_set( $cache ) {
	update_option( 'wbf_last_post_cache', $cache, false );
}

/**
 * 某个订阅站当前的「最后更新」信息。
 *
 * 优先级：同步窗口内的时间（每天刷新，最准）> 24 小时内的探查缓存 > 没有数据。
 *
 * @param string $host       订阅主域名。
 * @param array  $updated_map wbf_updated_map() 的结果。
 * @return array|null array( ts, source )；source: sync=同步窗口内 / probe=现查缓存 / dead=查过但没查到。null=还查过没结果，等着被探。
 */
function wbf_lastpost_for( $host, $updated_map ) {
	if ( isset( $updated_map[ $host ] ) ) {
		return array( 'ts' => (int) $updated_map[ $host ], 'source' => 'sync' );
	}

	$cache = wbf_lastpost_cache_get();
	if ( isset( $cache[ $host ] ) ) {
		$c   = $cache[ $host ];
		$age = current_time( 'timestamp' ) - (int) $c['checked'];
		if ( $age >= 0 && $age < DAY_IN_SECONDS ) {
			return array( 'ts' => (int) $c['ts'], 'source' => ( (int) $c['ts'] > 0 ) ? 'probe' : 'dead' );
		}
	}

	return null;
}

/**
 * 去 FreshRSS 现查一批站的最新文章时间。
 *
 * 登录和订阅列表各拉一次（几十个站共用），然后每个站调一次 stream/contents?n=1
 * —— 只要最新一篇的时间，请求很轻。查不到的（订阅没了 / feed 坏了）记 miss，
 * miss 也是有效结论：连 FreshRSS 都拿不到更新，这个站多半真出事了。
 *
 * @param array $hosts 归一化域名列表。
 * @return array host => array( ts => int, miss => bool )
 */
function wbf_probe_last_posts( $hosts ) {
	$conf = wbf_freshrss_conf();
	if ( '' === $conf['api'] ) {
		return array();
	}

	$auth = wbf_get_token( $conf['api'], $conf['user'], $conf['pass'] );
	if ( ! $auth ) {
		return array();
	}

	$list = wbf_get_subscriptions( $conf['api'], $auth );
	if ( ! is_array( $list ) ) {
		return array();
	}

	// 实时订阅列表里建「域名 → stream id」的索引（站点地址和 feed 地址都认）。
	$by_key = array();
	foreach ( $list as $s ) {
		if ( ! is_array( $s ) || empty( $s['id'] ) ) {
			continue;
		}
		foreach ( array( 'htmlUrl', 'url' ) as $f ) {
			$h = isset( $s[ $f ] ) ? wbf_norm_host( (string) $s[ $f ] ) : '';
			if ( '' !== $h ) {
				$by_key[ $h ] = (string) $s['id'];
			}
		}
	}

	$cache = wbf_lastpost_cache_get();
	$now   = current_time( 'timestamp' );
	$got   = array();

	foreach ( (array) $hosts as $host ) {
		$host = wbf_norm_host( (string) $host );
		if ( '' === $host ) {
			continue;
		}

		$sid = isset( $by_key[ $host ] ) ? $by_key[ $host ] : '';
		if ( '' === $sid ) {
			$cache[ $host ] = array( 'ts' => 0, 'checked' => $now, 'miss' => true );
			$got[ $host ]   = array( 'ts' => 0, 'miss' => true );
			continue;
		}

		$ts = 0;
		$resp = wp_remote_get(
			$conf['api'] . '/reader/api/0/stream/contents/' . rawurlencode( $sid ) . '?n=1',
			array(
				'timeout' => 8,
				'headers' => array( 'Authorization' => 'GoogleLogin auth=' . $auth ),
			)
		);
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$j = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			if ( is_array( $j ) && ! empty( $j['items'][0]['published'] ) ) {
				$ts = (int) $j['items'][0]['published'];
			}
		}

		$cache[ $host ] = array( 'ts' => $ts, 'checked' => $now );
		$got[ $host ]   = array( 'ts' => $ts );
	}

	wbf_lastpost_cache_set( $cache );
	return $got;
}

/**
 * AJAX 任务的全局锁：同类任务同时只允许一个在跑。
 *
 * 教训：探查/复核一批在服务端要跑一两分钟，用户等不及会关页面重开、连点几个
 * 筛选 —— 每次打开页面前端都会自动再来一轮。旧页面的请求 PHP 不会因为浏览器
 * 走了就停，几个两分钟的长任务叠在池子里，后台就卡成一片。所以服务端自己锁：
 * 有任务在跑，后来的直接返回「忙」，前端收到就停手。
 *
 * @param string $name 锁名。
 * @param int    $ttl  锁自动过期秒数（要比一批的最长耗时略长，防止异常退出后死锁）。
 * @return bool true = 拿到锁；false = 已有任务在跑。
 */
function wbf_task_lock( $name, $ttl ) {
	if ( get_transient( 'wbf_lock_' . $name ) ) {
		return false;
	}
	set_transient( 'wbf_lock_' . $name, 1, $ttl );
	return true;
}

/**
 * AJAX：现查一批站的最后更新时间。
 *
 * 对账页打开时，同步窗口内查不到的站由前端自动把域名批量发过来。一次最多 60 个，
 * 前端自己分批。每个站一次 FreshRSS 请求，8 秒超时，最坏一批一分来钟 ——
 * 所以先 set_time_limit 放宽，别让 PHP 半路掐了。
 *
 * 抓的目标地址全部来自 FreshRSS 自己的订阅列表（管理员自己同步下来的），
 * 不是访客输入，不构成 SSRF 面；真正的访客输入探测走 wbf_url_crawlable()。
 */
function wbf_ajax_probe_stale() {
	check_ajax_referer( 'wbf_sub', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '权限不足。' ) );
	}

	if ( ! wbf_task_lock( 'probe', 120 ) ) {
		wp_send_json_success( array( 'items' => array(), 'busy' => 1, 'now' => current_time( 'timestamp' ) ) );
	}

	$hosts = isset( $_POST['hosts'] ) ? (array) wp_unslash( $_POST['hosts'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$hosts = array_filter(
		array_map(
			function ( $h ) {
				return wbf_norm_host( sanitize_text_field( (string) $h ) );
			},
			$hosts
		)
	);
	$hosts = array_values( array_unique( array_slice( $hosts, 0, 60 ) ) );

	if ( empty( $hosts ) ) {
		delete_transient( 'wbf_lock_probe' );
		wp_send_json_error( array( 'message' => '没有要查的域名。' ) );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore
	}

	$items = wbf_probe_last_posts( $hosts );
	delete_transient( 'wbf_lock_probe' );

	wp_send_json_success(
		array(
			'items' => $items,
			'now'   => current_time( 'timestamp' ),
		)
	);
}
add_action( 'wp_ajax_wbf_probe_stale', 'wbf_ajax_probe_stale' );

/*
 * ============================================================
 * 二道复核（双保险）
 *
 * 背景：FreshRSS 的 GReader API 实测不吐任何抓取错误状态
 * （subscription/list 只有 id/title/categories/url/htmlUrl/iconUrl/priority
 * 七个键，error 字段根本没有），所以「FreshRSS 说他没更新」只是线索，
 * 不是结论 —— 也可能是 FreshRSS 自己抓不动了。
 *
 * 复核的口径：自己直接去访问这个站。
 *   ① 先抓他的 feed 地址（订阅快照里记的）—— 200 且能认出 <item>/<entry>
 *      就算活着，顺手把 feed 里最新的文章时间抠出来；如果比 FreshRSS 给的
 *      还新，说明是 FreshRSS 抓取出了问题，以复核为准。
 *   ② feed 挂了再抓站点首页 —— 首页活着 = 「feed 异常但站还在」（黄）；
 *      首页也挂了 = 「站点无法访问」（红），这才是删订阅的硬依据。
 *
 * 目标地址全部来自订阅快照（管理员自己同步下来的数据），不是访客输入；
 * 即便如此还是拦一道内网地址，访问别人的站不该有例外。
 */

/**
 * 复核结果的缓存读取。三天内算有效，过期的不返回（等于没核过，前端会重核）。
 *
 * 死站短期不会复活，活站短期也不会死，三天是省请求和保新鲜之间的折衷。
 *
 * @return array host => array( state, ts, checked, note )
 */
function wbf_verify_cache_get() {
	$c    = get_option( 'wbf_verify_cache', array() );
	$out  = array();
	$now  = current_time( 'timestamp' );
	$keep = 3 * DAY_IN_SECONDS;
	if ( is_array( $c ) ) {
		foreach ( $c as $host => $v ) {
			if ( is_array( $v ) && isset( $v['checked'] ) && ( $now - (int) $v['checked'] ) < $keep ) {
				$out[ $host ] = $v;
			}
		}
	}
	return $out;
}

/**
 * 写复核缓存。调用方先取（全量，含过期的都行）再改再存。
 *
 * @param array $cache 全量缓存。
 * @return void
 */
function wbf_verify_cache_set( $cache ) {
	update_option( 'wbf_verify_cache', $cache, false );
}

/**
 * 复核目标的地址许不允许访问：只许 http/https，解析出来必须是公网 IP。
 *
 * 解析不出 DNS 的不拦 —— 放出去让 wp_remote_get 自己失败，结论一样是「无法访问」，
 * 但原因记的是真实原因，不是我们猜的。
 *
 * @param string $url 目标地址。
 * @return bool
 */
function wbf_verify_url_allowed( $url ) {
	$p = wp_parse_url( (string) $url );
	if ( ! is_array( $p ) || empty( $p['host'] ) ) {
		return false;
	}
	$scheme = isset( $p['scheme'] ) ? strtolower( (string) $p['scheme'] ) : '';
	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		return false;
	}

	/*
	 * 注意：gethostbyname 对 IP 字面量是原样返回的 —— 127.0.0.1 进去 127.0.0.1 出来，
	 * 跟「解析失败」长得一模一样。先按 IP 校验，剩下的才走域名解析。
	 * 这个坑实测踩过：不先判，127.0.0.1 会顺着「解析不出就放行」溜进去。
	 */
	$host = $p['host'];
	$ip   = $host;
	if ( ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
		$ip = @gethostbyname( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $ip || $ip === $host ) {
			return true; // 域名解析不出，放出去让 wp_remote_get 自己失败，结论一样是「无法访问」。
		}
	}

	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return false; // 内网 / 保留段，坚决不去。
	}
	return true;
}

/**
 * 把 wp_remote_get 的失败翻成人话，进 note 给管理员看。
 *
 * @param WP_Error|mixed $err 错误对象。
 * @return string
 */
function wbf_verify_err_note( $err ) {
	if ( ! is_wp_error( $err ) ) {
		return '';
	}
	$msg = $err->get_error_message();
	if ( false !== stripos( $msg, 'resolve' ) || false !== stripos( $msg, 'name' ) ) {
		return '域名解析失败';
	}
	if ( false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'timeout' ) ) {
		return '连接超时';
	}
	if ( false !== stripos( $msg, 'SSL' ) || false !== stripos( $msg, 'certificate' ) ) {
		return '证书错误';
	}
	return mb_substr( wp_strip_all_tags( $msg ), 0, 40 );
}

/**
 * 从 feed 正文里抠最新的文章时间。
 *
 * 不上 XML 解析器：这里只要个大概时间，正则抓 pubDate/updated/published，
 * 取前 30 个里最大的那个。feed 顺序不一定最新在前，取最大稳一些。
 *
 * @param string $body feed 原文。
 * @return int epoch 时间，抠不到返回 0。
 */
function wbf_verify_feed_ts( $body ) {
	if ( ! preg_match_all( '/<(pubDate|updated|published|dc:date)[^>]*>([^<]+)</i', (string) $body, $m ) ) {
		return 0;
	}
	$best = 0;
	foreach ( array_slice( $m[2], 0, 30 ) as $d ) {
		$t = strtotime( trim( (string) $d ) );
		if ( $t && $t > $best ) {
			$best = $t;
		}
	}
	return $best;
}

/**
 * 复核一个站：先 feed，后站点。
 *
 * @param string $host 订阅主域名（只用来做缓存键）。
 * @param string $feed feed 地址，可为空。
 * @param string $site 站点地址，可为空。
 * @return array array( state => ok-feed|ok-site|dead|err, ts => int, checked => int, note => string )
 */
function wbf_verify_site( $host, $feed, $site ) {
	$now = current_time( 'timestamp' );
	$res = array(
		'state'   => 'err',
		'ts'      => 0,
		'checked' => $now,
		'note'    => '',
	);

	$ua = 'Mozilla/5.0 (compatible; WP-BoyouFeed-Verify; +https://wordpress.org/plugins/wp-boyou-feed/)';
	// 302 跳转常见于换域名的博友站，跟两跳足够；跳过去哪不归我们管，反正判定只看结果。
	// 超时 6 秒：复核一批几十个站，每个都等 10 秒的话整批太久，6 秒连不上的站基本也真不行了。
	$args = array(
		'timeout'     => 6,
		'redirection' => 2,
		'user-agent'  => $ua,
	);

	$feed = trim( (string) $feed );
	$site = trim( (string) $site );

	// ① 先抓 feed。
	$feed_ok   = false;
	$feed_note = '';
	if ( $feed && wbf_verify_url_allowed( $feed ) ) {
		$resp = wp_remote_get( $feed, $args );
		if ( is_wp_error( $resp ) ) {
			$feed_note = wbf_verify_err_note( $resp );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = (string) wp_remote_retrieve_body( $resp );
			if ( $code >= 200 && $code < 300 && preg_match( '/<(item|entry)[\s>]/i', $body ) ) {
				$feed_ok    = true;
				$res['ts']  = wbf_verify_feed_ts( $body );
			} elseif ( $code ) {
				$feed_note = 'HTTP ' . $code;
			} else {
				$feed_note = '响应异常';
			}
		}
	} elseif ( $feed ) {
		$feed_note = '目标地址不可访问（内网），跳过';
	} else {
		$feed_note = '订阅快照里没有 feed 地址';
	}

	if ( $feed_ok ) {
		$res['state'] = 'ok-feed';
		return $res;
	}

	// ② feed 不行，看站点本身还活着没。
	if ( $site && wbf_verify_url_allowed( $site ) ) {
		$resp = wp_remote_get( $site, $args );
		if ( is_wp_error( $resp ) ) {
			$res['state'] = 'dead';
			$res['note']  = $feed_note ? 'feed：' . $feed_note . '；站点：' . wbf_verify_err_note( $resp ) : wbf_verify_err_note( $resp );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( $code >= 200 && $code < 300 ) {
				// 站活着，feed 挂了 —— 这种先别急着删，也许人家换了 feed 地址，去看看再说。
				$res['state'] = 'ok-site';
				$res['note']  = $feed_note;
			} else {
				$res['state'] = 'dead';
				$res['note']  = ( $feed_note ? 'feed：' . $feed_note . '；' : '' ) . '站点 HTTP ' . $code;
			}
		}
	} else {
		$res['state'] = 'dead';
		$res['note']  = $feed_note ? $feed_note . '；站点地址也没有' : '站点地址也没有';
	}

	return $res;
}

/**
 * AJAX：复核一批站。
 *
 * 前端把每站的 feed/站点地址一起带过来（来自订阅快照的行属性），服务端不猜。
 * 一批最多 6 个：每站最多两次请求、每次 6 秒超时，最坏一批一分多钟。
 * 全局锁跟探查共用一套逻辑：有任务在跑就直接说忙，别让长任务在池子里堆积。
 */
function wbf_ajax_verify_sites() {
	check_ajax_referer( 'wbf_sub', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '权限不足。' ) );
	}

	if ( ! wbf_task_lock( 'verify', 120 ) ) {
		wp_send_json_success( array( 'items' => array(), 'busy' => 1, 'now' => current_time( 'timestamp' ) ) );
	}

	$hosts = isset( $_POST['hosts'] ) ? (array) wp_unslash( $_POST['hosts'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$feeds = isset( $_POST['feeds'] ) ? (array) wp_unslash( $_POST['feeds'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$sites = isset( $_POST['sites'] ) ? (array) wp_unslash( $_POST['sites'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	$known = wbf_subs_from_json();
	if ( empty( $known ) ) {
		delete_transient( 'wbf_lock_verify' );
		wp_send_json_error( array( 'message' => '订阅快照还没同步，先去「博友动态 → 设置」点一次「立即同步」。' ) );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 120 ); // phpcs:ignore
	}

	// 快照里的 host => ( feed, site )，前端传来的地址只当参考，以快照为准 —— 防止有人借这个口子探别的站。
	$targets = array();
	foreach ( $known as $h => $sub ) {
		$targets[ $h ] = array( 'feed' => $sub['feed'], 'site' => $sub['site'] );
	}

	$cache = get_option( 'wbf_verify_cache', array() );
	if ( ! is_array( $cache ) ) {
		$cache = array();
	}

	$items = array();
	$now   = current_time( 'timestamp' );
	$n     = 0;
	foreach ( $hosts as $h ) {
		if ( $n >= 6 ) {
			break;
		}
		$h = wbf_norm_host( sanitize_text_field( (string) $h ) );
		if ( '' === $h || ! isset( $targets[ $h ] ) ) {
			continue; // 不在订阅快照里的不核。
		}

		$v = wbf_verify_site( $h, $targets[ $h ]['feed'], $targets[ $h ]['site'] );
		$v['checked'] = $now;
		$cache[ $h ]  = $v;
		$items[ $h ]  = $v;
		$n++;
	}

	wbf_verify_cache_set( $cache );
	delete_transient( 'wbf_lock_verify' );

	wp_send_json_success(
		array(
			'items' => $items,
			'now'   => $now,
		)
	);
}
add_action( 'wp_ajax_wbf_verify_sites', 'wbf_ajax_verify_sites' );

/**
 * 来访记录三件套：按域名、按邮箱、以及「哪个域名收到过哪个邮箱的留言」。
 *
 * 为什么要邮箱这一层：假设订阅了某博主的两个站（主域和 blog 子域）。
 * 他后来只在 blog 子域那个站填网址留言，
 * 于是 example.com 那一行被判成「失联 6 年多」—— 技术上说这个域名确实 6 年没出现，
 * 但对账要回答的是「这个人还来不来」，不是「这个域名还出现不出现」。
 *
 * 同一个人换了站留言，邮箱一般不变。所以按邮箱再兜一次底：
 * 某个站的评论者邮箱如果在别的域名下还有更近的记录，就按那条算。
 *
 * @param bool $force 绕过缓存。
 * @return array array( 'host' => [...], 'email' => [...], 'host_emails' => [...] )
 */
function wbf_seen_bundle( $force = false ) {
	global $wpdb;

	if ( ! $force ) {
		$cached = get_transient( 'wbf_seen_bundle_v1' );
		if ( is_array( $cached ) && isset( $cached['host'] ) ) {
			return $cached;
		}
	}

	/*
	 * 字段名是 user_id，不是 comment_user_id —— WP 的 comments 表从来就叫 user_id，
	 * 写成 comment_user_id 会直接 Unknown column，查询变成空结果，
	 * 整张对账表就全被判成「单向」。这个错踩过一次。
	 *
	 * comment_type 要限死 '' 或 'comment'：pingback / trackback 也带一个网址，
	 * 但那是「谁的页面引用了我」，不是「谁来看过我」，混进来会把老链接算成活跃来访。
	 */
	$rows = $wpdb->get_results(
		"SELECT comment_author_url AS url, comment_author AS name,
		        comment_author_email AS email, comment_date_gmt AS d
		 FROM {$wpdb->comments}
		 WHERE comment_approved = '1'
		   AND user_id = 0
		   AND comment_author_url <> ''
		   AND comment_type IN ( '', 'comment' )
		 LIMIT 50000",
		ARRAY_A
	);

	$by_host      = array();
	$by_email     = array();
	$host_emails  = array();

	foreach ( (array) $rows as $r ) {
		$host = wbf_norm_host( $r['url'] );
		if ( '' === $host ) {
			continue;
		}
		$ts = strtotime( $r['d'] . ' GMT' );
		if ( ! $ts ) {
			continue;
		}

		$item = array(
			'ts'   => $ts,
			'url'  => (string) $r['url'],
			'name' => (string) $r['name'],
			'host' => $host,
		);

		if ( ! isset( $by_host[ $host ] ) || $ts > $by_host[ $host ]['ts'] ) {
			$by_host[ $host ] = $item;
		}

		$email = strtolower( trim( (string) $r['email'] ) );
		if ( '' !== $email && false !== strpos( $email, '@' ) ) {
			if ( ! isset( $by_email[ $email ] ) || $ts > $by_email[ $email ]['ts'] ) {
				$by_email[ $email ] = $item;
			}
			$host_emails[ $host ][ $email ] = true;
		}
	}

	$bundle = array(
		'host'        => $by_host,
		'email'       => $by_email,
		'host_emails' => $host_emails,
	);

	set_transient( 'wbf_seen_bundle_v1', $bundle, 10 * MINUTE_IN_SECONDS );
	return $bundle;
}

/**
 * 只要域名那一层（保留旧接口，别处可能还在用）。
 *
 * @param bool $force 绕过缓存。
 * @return array
 */
function wbf_last_seen_map( $force = false ) {
	$b = wbf_seen_bundle( $force );
	return $b['host'];
}

/**
 * 分档。
 *
 * @param int|null $days 距今天数，null 表示从未来过。
 * @return array array( 标签, 键名 )
 */
function wbf_grade( $days ) {
	if ( null === $days ) {
		return array( '单向', 'never' );
	}
	if ( $days <= 90 ) {
		return array( '常来', 'hot' );
	}
	if ( $days <= 365 ) {
		return array( '偶尔', 'warm' );
	}
	if ( $days <= 730 ) {
		return array( '冷淡', 'cold' );
	}
	return array( '失联', 'gone' );
}

/**
 * 天数换成人话：12 天 / 5 个月 / 3 年 2 个月。
 *
 * @param int $days 天数。
 * @return string
 */
function wbf_days_text( $days ) {
	$days = (int) $days;
	if ( $days < 1 ) {
		return '今天';
	}
	if ( $days < 30 ) {
		return $days . ' 天';
	}
	if ( $days < 365 ) {
		return (string) floor( $days / 30 ) . ' 个月';
	}
	$y = floor( $days / 365 );
	$m = floor( ( $days % 365 ) / 30 );
	return $m > 0 ? $y . ' 年 ' . $m . ' 个月' : $y . ' 年';
}

/**
 * host => 对账行 的映射。博友圈每张卡片按域名取自己的档位。
 *
 * 一次请求只算一遍：对账本身要读 json + 查评论表，30 张卡片各算一次就废了。
 *
 * @return array
 */
function wbf_reconcile_tips() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map = array();
	$d   = wbf_reconcile_data();
	foreach ( $d['rows'] as $r ) {
		$map[ $r['host'] ] = $r;
	}
	return $map;
}

/**
 * 拼一句给前端 tooltip 用的话（多行，前端按换行拆）。
 *
 * @param array|null $row 对账行。
 * @return string
 */
function wbf_tip_text( $row ) {
	if ( ! is_array( $row ) || empty( $row['key'] ) ) {
		return '';
	}

	$labels = array(
		'hot'   => array( '常来', '3 个月内来过' ),
		'warm'  => array( '偶尔', '3 个月到 1 年没来' ),
		'cold'  => array( '冷淡', '1 到 2 年没来' ),
		'gone'  => array( '失联', '2 年以上没来' ),
		'never' => array( '单向', '还没来你博客留过言' ),
	);
	$k     = $row['key'];
	$lines = array();

	if ( isset( $labels[ $k ] ) ) {
		$lines[] = $labels[ $k ][0] . ' · ' . $labels[ $k ][1];
	}

	if ( null === $row['days'] ) {
		$lines[] = '你的博客里没有他的留言记录';
	} else {
		$lines[] = '上次来留言：' . get_date_from_gmt( gmdate( 'Y-m-d', $row['last_ts'] ), 'Y-m-d' )
			. '（' . wbf_days_text( $row['days'] ) . '前）';
		if ( $row['last_name'] ) {
			$lines[] = '留言署名：' . $row['last_name'];
		}
	}

	// 更新维度：只在他站上有数据时说。没数据（还没探过）就不提，免得都是「查询中」。
	if ( isset( $row['post_ts'] ) && $row['post_ts'] > 0 ) {
		$lines[] = '最近更新：' . get_date_from_gmt( gmdate( 'Y-m-d', $row['post_ts'] ), 'Y-m-d' )
			. '（' . wbf_days_text( (int) $row['post_days'] ) . '前）';
	} elseif ( isset( $row['post_dead'] ) && $row['post_dead'] ) {
		$lines[] = '最近更新：FreshRSS 里查不到他的新文章';
	}

	return implode( "\n", $lines );
}

/**
 * 订阅域名索引：所有已知域名（站点地址 / feed 地址）→ 这条订阅的主域名。
 *
 * @return array
 */
function wbf_sub_alias_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map = array();
	foreach ( wbf_subs_from_json() as $host => $sub ) {
		foreach ( wbf_sub_host_keys( $host, $sub ) as $k ) {
			if ( ! isset( $map[ $k ] ) ) {
				$map[ $k ] = $host;
			}
		}
	}
	return $map;
}

/**
 * 这个域名属于哪条订阅。不属于任何订阅返回空串。
 *
 * 先精确比，再逐级去掉最左边的标签往父域找 —— 因为评论里填的常常是子域，
 * 而订阅里是裸域。example.com 就是这么被冤枉的：订阅记的是 example.com，
 * 某博主每次留言填的是 blog.example.com，对账只按 example.com 去找，
 * 找到的最后一条停在 2020-03-09，于是判成「失联 6 年多」，
 * 实际他 2026-10-19 还来过。
 *
 * 反过来不合并的风险也在：xxx.com 和 blog.xxx.com 理论上可能是两个不同的站。
 * 但只往「已经订阅了的父域」上归，等于信了 FreshRSS 里那条订阅 —— 出错的概率
 * 远小于「把常来的人算成失联」的代价。宁可多算一次来访，不可漏算。
 *
 * @param string $host 归一化域名。
 * @return string 匹配到的订阅主域名，没有则空串。
 */
function wbf_host_in_subs( $host ) {
	$host = (string) $host;
	if ( '' === $host ) {
		return '';
	}

	$map = wbf_sub_alias_map();
	if ( isset( $map[ $host ] ) ) {
		return $map[ $host ];
	}

	$parts = explode( '.', $host );
	while ( count( $parts ) > 2 ) {
		array_shift( $parts );
		$try = implode( '.', $parts );
		if ( isset( $map[ $try ] ) ) {
			return $map[ $try ];
		}
	}

	return '';
}

/**
 * 一条订阅可能认两个域名：站点地址一个、feed 地址一个。
 *
 * 站方换域名之后，FreshRSS 里那条订阅的站点地址往往还是老的，feed 却已经被
 * 跳转/改成了新域名。评论里人家填的当然是新域名 —— 只按站点地址比，就会把
 * 「他一直在来」错判成「从未来过」。laozhang.org 实测有 18 条这样的订阅
 * （savouer.com->leitao.cn、xiangshitan.com->chenrui.com、xqrp.com->yufm.com …）。
 *
 * @param string $host 订阅的主域名（站点地址归一化后）。
 * @param array  $sub  订阅项。
 * @return array 去重后的域名列表。
 */
function wbf_sub_host_keys( $host, $sub ) {
	$keys = array( $host );
	$feed = isset( $sub['feed'] ) ? wbf_norm_host( $sub['feed'] ) : '';
	if ( '' !== $feed && $feed !== $host ) {
		$keys[] = $feed;
	}
	return $keys;
}

/**
 * 把订阅列表和来访记录对起来。
 *
 * @return array array( 'rows' => [...], 'stats' => [...], 'sync_time' => int )
 */
function wbf_reconcile_data() {
	$subs = wbf_subs_from_json();

	$bundle      = wbf_seen_bundle();
	$seen        = $bundle['host'];
	$by_email    = $bundle['email'];
	$host_emails = $bundle['host_emails'];

	$now = current_time( 'timestamp' );

	$rows  = array();
	$stats = array( 'total' => count( $subs ), 'hot' => 0, 'warm' => 0, 'cold' => 0, 'gone' => 0, 'never' => 0, 'stale' => 0, 'bad' => 0 );

	// 复核结果（三天内有效的那部分），叠在探查数据上面用。
	$vcache    = wbf_verify_cache_get();
	$probe_fix = array();

	/*
	 * 「最后更新」的数据源。窗口内有文章的直接就有；没有的看缓存，缓存也没有
	 * 就是 null，页面上先显示「查询中」，前端拿到后自动去 FreshRSS 现查。
	 */
	$updated_map = wbf_updated_map();

	/*
	 * 先把来访记录按订阅折叠：blog.example.com 这种子域归到订阅里的 example.com，
	 * 同一个站留最近的这一次。不折叠的话，子域那条记录永远对不上。
	 */
	$folded = array();
	foreach ( $seen as $h => $v ) {
		$main = wbf_host_in_subs( $h );
		$key  = ( '' !== $main ) ? $main : $h;
		if ( ! isset( $folded[ $key ] ) || $v['ts'] > $folded[ $key ]['ts'] ) {
			$folded[ $key ] = $v;
		}
	}

	foreach ( $subs as $host => $sub ) {
		// 站点域名和 feed 域名都试一遍，取最近的那次来访。
		$last = null;
		foreach ( wbf_sub_host_keys( $host, $sub ) as $k ) {
			if ( isset( $folded[ $k ] ) && ( ! $last || $folded[ $k ]['ts'] > $last['ts'] ) ) {
				$last = $folded[ $k ];
			}
		}

		/*
		 * 再拿这个站收到过的邮箱去别处找一遍。
		 *
		 * 某博主订阅了两个站（example.com 和 blog.example.com），2020 年后只在 blog 那个填网址。
		 * 光按域名算，example.com 就成了「失联 6 年多」。同一个人换站留言，邮箱一般不变，
		 * 所以只要这些邮箱在别的域名下有更近的记录，就按那条算。
		 */
		foreach ( wbf_sub_host_keys( $host, $sub ) as $k ) {
			if ( empty( $host_emails[ $k ] ) ) {
				continue;
			}
			foreach ( array_keys( $host_emails[ $k ] ) as $em ) {
				if ( isset( $by_email[ $em ] ) && ( ! $last || $by_email[ $em ]['ts'] > $last['ts'] ) ) {
					$last = $by_email[ $em ];
				}
			}
		}
		$days = null;
		if ( $last ) {
			$days = (int) floor( ( $now - $last['ts'] ) / DAY_IN_SECONDS );
			if ( $days < 0 ) {
				$days = 0;
			}
		}
		list( $label, $key ) = wbf_grade( $days );
		$stats[ $key ]++;

	/*
	 * 更新维度（跟来访档位互相独立）：
	 *   fresh  —— 180 天内发过文章，正常，不标
	 *   stale  —— 半年到一年没更新，黄标
	 *   old    —— 一年以上没更新，红标（该删的头号候选）
	 *   unknown—— 同步窗口内没有、缓存也没有，等前端现查
	 */
		$lp        = wbf_lastpost_for( $host, $updated_map );
		$post_ts   = $lp ? (int) $lp['ts'] : 0;
		$post_days = null;
		$post_key  = 'unknown';
		if ( $post_ts > 0 ) {
			$post_days = (int) floor( ( $now - $post_ts ) / DAY_IN_SECONDS );
			if ( $post_days < 0 ) {
				$post_days = 0;
			}
			if ( $post_days > 365 ) {
				$post_key = 'old';
			} elseif ( $post_days > 180 ) {
				$post_key = 'stale';
			} else {
				$post_key = 'fresh';
			}
		}

		/*
		 * 二道复核叠上来：FreshRSS 说他半年没更新，可能是 FreshRSS 自己抓不动了。
		 * 复核直接抓过 feed、发现比 FreshRSS 记的还新，就以复核为准。
		 */
		$verify       = isset( $vcache[ $host ] ) ? $vcache[ $host ] : null;
		$verify_state = $verify ? (string) $verify['state'] : '';
		if ( $verify && 'ok-feed' === $verify_state && (int) $verify['ts'] > $post_ts ) {
			$post_ts   = (int) $verify['ts'];
			$post_days = (int) floor( ( $now - $post_ts ) / DAY_IN_SECONDS );
			if ( $post_days < 0 ) {
				$post_days = 0;
			}
			if ( $post_days > 365 ) {
				$post_key = 'old';
			} elseif ( $post_days > 180 ) {
				$post_key = 'stale';
			} else {
				$post_key = 'fresh';
			}
			// 顺手把探查缓存也纠正掉，不然 24 小时内还会显示旧结论。
			if ( ! isset( $probe_fix[ $host ] ) ) {
				$probe_fix[ $host ] = $post_ts;
			}
		}
		if ( 'stale' === $post_key || 'old' === $post_key ) {
			$stats['stale']++;
		}
		if ( 'dead' === $verify_state || 'ok-site' === $verify_state ) {
			$stats['bad']++;
		}

		$rows[] = array(
			'host'      => $host,
			'title'     => $sub['title'],
			'site'      => $sub['site'],
			'feed'      => isset( $sub['feed'] ) ? $sub['feed'] : '',
			'last_name' => $last ? $last['name'] : '',
			'last_ts'   => $last ? $last['ts'] : 0,
			// 这条记录是他在哪个域名下留的。跟主域名不同，说明他是用另一个站来的。
			'via_host'  => ( $last && isset( $last['host'] ) ) ? $last['host'] : '',
			'days'      => $days,
			'grade'     => $label,
			'key'       => $key,
			'post_ts'   => $post_ts,
			'post_days' => $post_days,
			'post_key'  => $post_key,
			// dead = 探过但没查到（feed 坏了或订阅已不在），跟 unknown（还没探）区分开。
			'post_dead' => ( $lp && 'dead' === $lp['source'] ) ? 1 : 0,
			// 二道复核：state 空 = 没复核过或已过期；ok-feed = feed 正常；ok-site = feed 挂了站还在；dead = 站都打不开了。
			'verify_state'   => $verify_state,
			'verify_note'    => $verify ? (string) $verify['note'] : '',
			'verify_checked' => $verify ? (int) $verify['checked'] : 0,
		);
	}

	/*
	 * 排序按「先看哪个档，再看多久没来」：
	 * 失联 → 冷淡 → 偶尔 → 常来 → 单向。
	 *
	 * 单向（订阅了但从没留过言）放最后是有意的 —— 这类站往往最多，
	 * 排前面会把真正该看的「失联」挤到几屏以后，等于没做。
	 * 同一档里天数大的（更久没来）排前面，天数一样按站名。
	 */
	$order = array( 'gone' => 0, 'cold' => 1, 'warm' => 2, 'hot' => 3, 'never' => 4 );
	// 复核出问题的站往前排：都确认打不开了，没有理由排在常来站后面。
	$vorder = array( 'dead' => 0, 'ok-site' => 1 );
	usort(
		$rows,
		function ( $a, $b ) use ( $order, $vorder ) {
			$av = isset( $vorder[ $a['verify_state'] ] ) ? $vorder[ $a['verify_state'] ] : 9;
			$bv = isset( $vorder[ $b['verify_state'] ] ) ? $vorder[ $b['verify_state'] ] : 9;
			if ( $av !== $bv ) {
				return $av < $bv ? -1 : 1;
			}
			$ao = isset( $order[ $a['key'] ] ) ? $order[ $a['key'] ] : 9;
			$bo = isset( $order[ $b['key'] ] ) ? $order[ $b['key'] ] : 9;
			if ( $ao !== $bo ) {
				return $ao < $bo ? -1 : 1;
			}
			$ad = null === $a['days'] ? -1 : (int) $a['days'];
			$bd = null === $b['days'] ? -1 : (int) $b['days'];
			if ( $ad !== $bd ) {
				return $ad > $bd ? -1 : 1;
			}
			return strcmp( (string) $a['title'], (string) $b['title'] );
		}
	);

	// 复核纠正过的「最后更新」写回探查缓存：FreshRSS 的旧结论别再显示 24 小时。
	if ( $probe_fix ) {
		$lpc = wbf_lastpost_cache_get();
		foreach ( $probe_fix as $h => $ts ) {
			$lpc[ $h ] = array( 'ts' => $ts, 'checked' => $now );
		}
		wbf_lastpost_cache_set( $lpc );
	}

	$sync = 0;
	$path = wbf_json_path();
	if ( is_file( $path ) ) {
		$sync = (int) filemtime( $path );
	}

	return array( 'rows' => $rows, 'stats' => $stats, 'sync_time' => $sync );
}

/**
 * 后台页面。
 */
function wbf_reconcile_page() {
	$data  = wbf_reconcile_data();
	$rows  = $data['rows'];
	$stats = $data['stats'];
	$sync  = $data['sync_time'];

	$filter = isset( $_GET['grade'] ) ? sanitize_key( wp_unslash( $_GET['grade'] ) ) : '';
	$upd    = isset( $_GET['upd'] ) ? sanitize_key( wp_unslash( $_GET['upd'] ) ) : '';
	$labels = array( 'hot' => '常来', 'warm' => '偶尔', 'cold' => '冷淡', 'gone' => '失联', 'never' => '单向' );

	$shown = $filter ? array_values( array_filter( $rows, function ( $r ) use ( $filter ) { return $r['key'] === $filter; } ) ) : $rows;
	if ( 'stale' === $upd ) {
		$shown = array_values(
			array_filter(
				$shown,
				function ( $r ) {
					return 'stale' === $r['post_key'] || 'old' === $r['post_key'];
				}
			)
		);
		// 久未更新的视图里，停更最久的排最前 —— 这个视图的任务就是找该删的站。
		usort(
			$shown,
			function ( $a, $b ) {
				$ad = (int) $a['post_days'];
				$bd = (int) $b['post_days'];
				if ( $ad !== $bd ) {
					return $ad > $bd ? -1 : 1;
				}
				return strcmp( (string) $a['title'], (string) $b['title'] );
			}
		);
	}
	if ( 'bad' === $upd ) {
		$shown = array_values(
			array_filter(
				$shown,
				function ( $r ) {
					return 'dead' === $r['verify_state'] || 'ok-site' === $r['verify_state'];
				}
			)
		);
		// 复核确认打不开的排最前，feed 异常的在后 —— 前面的才是该删的。
		usort(
			$shown,
			function ( $a, $b ) {
				$aw = ( 'dead' === $a['verify_state'] ) ? 0 : 1;
				$bw = ( 'dead' === $b['verify_state'] ) ? 0 : 1;
				if ( $aw !== $bw ) {
					return $aw < $bw ? -1 : 1;
				}
				return strcmp( (string) $a['title'], (string) $b['title'] );
			}
		);
	}
	?>
	<div class="wrap">
		<h1>互访对账</h1>

		<?php if ( ! $sync ) : ?>
			<div class="notice notice-warning"><p>还没有同步数据。先到「博友动态 → 设置」点一次「立即同步」，订阅列表就有了。</p></div>
			<?php return; ?>
		<?php endif; ?>

		<?php if ( 0 === (int) $stats['total'] ) : ?>
			<div class="notice notice-warning"><p>数据文件里没有订阅列表。同步一次就会带上（旧版插件同步的 json 里没有这一段）。</p></div>
			<?php return; ?>
		<?php endif; ?>

		<div class="card" style="max-width:none;padding:16px 20px;">
			<p style="margin:0 0 12px;">
				订阅 <b><?php echo (int) $stats['total']; ?></b> 个站　｜　
				数据同步于 <?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $sync ), 'Y-m-d H:i' ) ); ?>
			</p>
			<p style="margin:0;">
				<?php
				$box = array(
					''     => array( '全部', (int) $stats['total'], '#2271b1' ),
					'hot'  => array( '常来（3 个月内）', (int) $stats['hot'], '#46b450' ),
					'warm' => array( '偶尔（3–12 个月）', (int) $stats['warm'], '#72aee6' ),
					'cold' => array( '冷淡（1–2 年）', (int) $stats['cold'], '#dba617' ),
					'gone' => array( '失联（2 年以上）', (int) $stats['gone'], '#d63638' ),
					'never' => array( '单向（从未来过）', (int) $stats['never'], '#8c8f94' ),
				);
				foreach ( $box as $key => $b ) :
					$url = $key ? add_query_arg( array( 'page' => 'wbf-reconcile', 'grade' => $key ), admin_url( 'admin.php' ) ) : add_query_arg( array( 'page' => 'wbf-reconcile' ), admin_url( 'admin.php' ) );
					$on  = ( $filter === $key ) ? 'font-weight:700;text-decoration:none;border-bottom:2px solid ' . $b[2] . ';padding-bottom:2px;' : 'text-decoration:none;border-bottom:1px solid transparent;';
					?>
					<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;margin:0 18px 8px 0;color:<?php echo esc_attr( $b[2] ); ?>;<?php echo esc_attr( $on ); ?>">
						<?php echo esc_html( $b[0] ); ?> <b><?php echo (int) $b[1]; ?></b>
					</a>
				<?php endforeach; ?>

				<?php
				// 「久未更新」是另一个维度（他站上还发不发文章），单独一条，和来访档位可以叠加筛选。
				// 高亮必须按具体值判：久未更新和站点异常共用 upd 参数，只判真假会两条同时亮。
				$upd_url  = 'stale' === $upd ? remove_query_arg( 'upd' ) : add_query_arg( array( 'upd' => 'stale' ) );
				$upd_on   = ( 'stale' === $upd ) ? 'font-weight:700;text-decoration:none;border-bottom:2px solid #996800;padding-bottom:2px;' : 'text-decoration:none;border-bottom:1px solid transparent;';
				?>
				<a href="<?php echo esc_url( $upd_url ); ?>" style="display:inline-block;margin:0 18px 8px 0;color:#996800;<?php echo esc_attr( $upd_on ); ?>" title="他自己的站半年以上没发新文章了（和上面的来访档位互相独立，可叠加筛选）">
					久未更新（半年以上） <b><?php echo (int) $stats['stale']; ?></b>
				</a>

				<?php
				// 「站点异常」：双保险复核的结果 —— FreshRSS 说他没更新还不算数，
				// 自己访问过他的 feed 和站点都打不开（红）或 feed 挂了站点还在（黄）才算。
				$bad_url = ( 'bad' === $upd ) ? remove_query_arg( 'upd' ) : add_query_arg( array( 'upd' => 'bad' ) );
				$bad_on  = ( 'bad' === $upd ) ? 'font-weight:700;text-decoration:none;border-bottom:2px solid #d63638;padding-bottom:2px;' : 'text-decoration:none;border-bottom:1px solid transparent;';
				?>
				<a href="<?php echo esc_url( $bad_url ); ?>" style="display:inline-block;margin:0 18px 8px 0;color:#d63638;<?php echo esc_attr( $bad_on ); ?>" title="打开对账页时自动复核过的站：访问他的 feed 和站点，feed 挂了但站还在算异常，两个都打不开才算死站">
					站点异常（复核） <b><?php echo (int) $stats['bad']; ?></b>
				</a>
			</p>

		</div>

		<p class="description" style="margin:14px 0 6px;">当前列出 <b><?php echo count( $shown ); ?></b> 个站<?php echo 'stale' === $upd ? '（已按「久未更新」筛选，停更最久的在前，点「全部」恢复）' : ( 'bad' === $upd ? '（已按「站点异常」筛选，复核确认打不开的在前，点「全部」恢复）' : ( $filter ? '（已按档位筛选，点上面的「全部」看完整列表）' : '（复核过有异常的站排最前，其余按「失联 → 冷淡 → 偶尔 → 常来 → 单向」排，同一档里最久没来的在前）' ) ); ?>。</p>
		<div style="max-height:70vh;overflow:auto;border:1px solid #c3c4c7;">
		<table class="widefat striped" style="margin:0;border:0;">
			<thead style="position:sticky;top:0;z-index:2;">
				<tr>
					<th style="width:19%;background:#f6f7f7;">站名</th>
					<th style="width:16%;background:#f6f7f7;">域名</th>
					<th style="width:14%;background:#f6f7f7;">最后一次来留言</th>
					<th style="width:8%;background:#f6f7f7;">距今</th>
					<th style="width:7%;background:#f6f7f7;">档位</th>
					<th style="width:22%;background:#f6f7f7;">他站上最近更新</th>
					<th style="width:14%;background:#f6f7f7;">操作</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $shown ) : ?>
				<tr><td colspan="7">这一类里没有站。</td></tr>
			<?php else : ?>
				<?php foreach ( $shown as $r ) : ?>
					<tr>
						<td>
							<?php if ( $r['site'] ) : ?>
								<a href="<?php echo esc_url( $r['site'] ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( $r['title'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $r['title'] ); ?>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $r['host'] ); ?></code></td>
						<td>
							<?php
							if ( null === $r['days'] ) {
								echo '<span style="color:#8c8f94;">从未来过</span>';
							} else {
								echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i', $r['last_ts'] ), 'Y-m-d H:i' ) );
								if ( $r['last_name'] ) {
									echo '<br><span style="color:#8c8f94;font-size:12px;">' . esc_html( $r['last_name'] ) . '</span>';
								}
								// 他不是用这个域名留的 —— 说清楚，别让人以为数据错了。
								if ( $r['via_host'] && $r['via_host'] !== $r['host'] ) {
									echo '<br><span style="color:#996800;font-size:12px;" title="同一个人的另一个站，邮箱对上了">以 ' . esc_html( $r['via_host'] ) . ' 留言</span>';
								}
							}
							?>
						</td>
						<td>
							<?php
							echo null === $r['days'] ? '—' : esc_html( wbf_days_text( $r['days'] ) );
							?>
						</td>
						<td><?php echo esc_html( $r['grade'] ); ?></td>
						<?php
						/*
						 * 复核需要用的地址挂在行属性上：前端复核时原样带回来，
						 * 服务端只认订阅快照里的地址，这里传什么只作展示。
						 */
						$need_verify = ( 'dead' === $r['verify_state'] || 'ok-site' === $r['verify_state'] )
							? false
							: ( $r['post_dead'] || 'stale' === $r['post_key'] || 'old' === $r['post_key'] );
						?>
						<td class="wbf-lp" data-host="<?php echo esc_attr( $r['host'] ); ?>"
							data-feed="<?php echo esc_attr( $r['feed'] ); ?>"
							data-site="<?php echo esc_attr( $r['site'] ); ?>"
							<?php echo $need_verify ? 'data-need-verify="1"' : ''; ?>>
							<?php
							if ( 'dead' === $r['verify_state'] ) {
								// 复核过了，feed 和站点都打不开 —— 这才是删订阅的硬依据。
								$vdate = $r['verify_checked'] ? get_date_from_gmt( gmdate( 'Y-m-d', $r['verify_checked'] ), 'Y-m-d' ) : '';
								echo '<b style="color:#d63638;">站点无法访问</b>';
								echo '<br><span style="font-size:12px;color:#8c8f94;" title="' . esc_attr( $r['verify_note'] ) . '">';
								echo $vdate ? esc_html( $vdate ) . '复核' : '已复核';
								if ( $r['verify_note'] ) {
									echo ' · ' . esc_html( mb_substr( $r['verify_note'], 0, 30 ) );
								}
								echo '</span>';
							} elseif ( 'ok-site' === $r['verify_state'] ) {
								// feed 挂了但站点还活着：先别删，可能人家换了 feed 地址。
								$vdate = $r['verify_checked'] ? get_date_from_gmt( gmdate( 'Y-m-d', $r['verify_checked'] ), 'Y-m-d' ) : '';
								echo '<b style="color:#996800;">feed 读不到 · 站点可访问</b>';
								echo '<br><span style="font-size:12px;color:#8c8f94;" title="' . esc_attr( $r['verify_note'] ) . '">';
								echo $vdate ? esc_html( $vdate ) . '复核' : '已复核';
								if ( $r['post_ts'] > 0 ) {
									echo ' · 更新停在 ' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d', $r['post_ts'] ), 'Y-m-d' ) );
								}
								echo '</span>';
							} elseif ( 'unknown' === $r['post_key'] ) {
								// 同步窗口和缓存里都没有 —— 页面打开后前端自动去 FreshRSS 现查，查一次缓存 24 小时。
								echo '<span class="wbf-lp-pending" style="color:#8c8f94;">查询中…</span>';
							} elseif ( $r['post_ts'] > 0 ) {
								echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d', $r['post_ts'] ), 'Y-m-d' ) );
								echo '<br><span style="font-size:12px;color:#8c8f94;">' . esc_html( wbf_days_text( (int) $r['post_days'] ) ) . '前';
								if ( 'old' === $r['post_key'] ) {
									echo ' · <b style="color:#d63638;">一年以上没更新</b>';
								} elseif ( 'stale' === $r['post_key'] ) {
									echo ' · <b style="color:#996800;">半年以上没更新</b>';
								}
								echo '</span>';
								// FreshRSS 说他没更新 —— 只算线索，等复核确认后再下结论。
								if ( $r['post_dead'] || 'stale' === $r['post_key'] || 'old' === $r['post_key'] ) {
									echo '<br><span class="wbf-verify-pending" style="font-size:12px;color:#8c8f94;">待复核</span>';
								}
							} else {
								echo '<span style="color:#8c8f94;" title="FreshRSS 里也拿不到他的更新，可能 feed 坏了或订阅已失效">查不到更新</span>';
								echo '<br><span class="wbf-verify-pending" style="font-size:12px;color:#8c8f94;">待复核</span>';
							}
							?>
						</td>
						<td>
							<?php if ( '' !== $r['feed'] ) : ?>
								<button type="button" class="button button-small wbf-unsub-btn"
									data-host="<?php echo esc_attr( $r['host'] ); ?>"
									data-title="<?php echo esc_attr( $r['title'] ); ?>">删除订阅</button>
							<?php else : ?>
								<span style="color:#8c8f94;font-size:12px;" title="订阅快照里没记这个站的 feed 地址，重新同步一次就有了">无 feed</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		</div>

		<p class="description" style="margin-top:14px;">
			只统计<b>已通过审核</b>的评论，站长自己回复的不算。域名按「去掉 www 的裸域」精确匹配，
			不做子域合并 —— 对不上的人归到「单向」，不猜。
			「删除订阅」是直接调 FreshRSS 的接口取消，不是只从这张表里划掉；取消后这个站的文章要等下次同步才会从博友圈消失。
			一个人有多个站时（比如某博主的主域和 blog 子域），会按留言邮箱认人 —— 哪个站最近来过就都算他来过，并在下面标出「以 xxx 留言」。
			「他站上最近更新」一半来自同步数据（只覆盖最近一个月），一半是打开这页时现查 FreshRSS 的结果（查一次管 24 小时）；
			「半年以上没更新」只是提示，半年没发文对有些博主是正常节奏，删不删你自己定。
			FreshRSS 拿不到更新（或半年没更新）的站，页面会<b>自动再复核一遍</b>：直接访问他的 feed 和站点 ——
			两个都打不开才标「站点无法访问」，feed 挂了但站还在标黄（可能人家换了 feed 地址，别急着删）；
			复核结果三天内直接复用，不重复发请求。
		</p>
	</div>
	<?php
}
