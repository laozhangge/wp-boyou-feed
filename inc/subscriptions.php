<?php
/**
 * 订阅管理：往 FreshRSS 加订阅 / 取消订阅。
 *
 * 走 Google Reader 兼容接口：
 *   ① POST /accounts/ClientLogin   拿 Auth
 *   ② GET  /reader/api/0/token     拿 T（编辑令牌，改订阅必须带）
 *   ③ POST /reader/api/0/subscription/edit
 *        ac=subscribe / unsubscribe，s=feed/<feed 地址>，T=<token>
 *
 * 两个入口：
 *   - 「互访对账」每行后面的「删除订阅」
 *   - 评论列表「未订阅」标记后面的「添加订阅」
 *
 * 注意：本地 json 里那份 subscriptions 是同步时从 FreshRSS 抄下来的快照。
 * 这里改完 FreshRSS 之后顺手把快照里对应的那一项也增删掉，不然页面刷新还是老样子，
 * 得等下一个同步周期才对 —— 那要等几个小时，看着像没生效。
 *
 * @package wp-boyou-feed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 读 FreshRSS 配置。
 *
 * @return array array( 'api', 'user', 'pass' )
 */
function wbf_freshrss_conf() {
	return array(
		'api'  => rtrim( (string) get_option( 'riven_rss_freshrss_api', '' ), '/' ),
		'user' => (string) get_option( 'riven_rss_freshrss_user', '' ),
		'pass' => (string) get_option( 'riven_rss_freshrss_pass', '' ),
	);
}

/**
 * 拿 Auth + T 两个令牌。
 *
 * @return array|WP_Error array( 'api', 'auth', 'token' )
 */
function wbf_freshrss_tokens() {
	$conf = wbf_freshrss_conf();
	if ( '' === $conf['api'] || '' === $conf['user'] || '' === $conf['pass'] ) {
		return new WP_Error( 'wbf_no_conf', '还没填 FreshRSS 地址或账号，先去「博友动态 → 设置」填好。' );
	}

	$auth = wbf_get_token( $conf['api'], $conf['user'], $conf['pass'] );
	if ( ! $auth ) {
		return new WP_Error( 'wbf_auth', 'FreshRSS 登录失败，检查设置里的账号密码。' );
	}

	$resp = wp_remote_get(
		$conf['api'] . '/reader/api/0/token',
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'GoogleLogin auth=' . $auth,
			),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return new WP_Error( 'wbf_token', '取编辑令牌失败：' . $resp->get_error_message() );
	}

	$token = trim( (string) wp_remote_retrieve_body( $resp ) );
	if ( '' === $token || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return new WP_Error( 'wbf_token', 'FreshRSS 没给编辑令牌（HTTP ' . (int) wp_remote_retrieve_response_code( $resp ) . '）。' );
	}

	return array(
		'api'   => $conf['api'],
		'auth'  => $auth,
		'token' => $token,
	);
}

/**
 * 加订阅或取消订阅。
 *
 * $stream 是完整的 stream id，形如 feed/https://example.com/feed。
 *
 * 为什么不让调用方传裸 feed 地址、这里自己拼 feed/ 前缀：FreshRSS 会把 URL 规范化
 * （实测 /feed/ 存成 /feed），拼出来的 id 跟库里那条对不上，取消订阅直接 400。
 * 所以取消时先用 wbf_freshrss_stream_id() 从**实时订阅列表**里取准确 id。
 *
 * @param string $action subscribe 或 unsubscribe。
 * @param string $stream 完整 stream id（feed/...）。
 * @return true|WP_Error
 */
function wbf_freshrss_sub_edit( $action, $stream ) {
	$action = ( 'unsubscribe' === $action ) ? 'unsubscribe' : 'subscribe';
	$stream = trim( (string) $stream );
	if ( '' === $stream ) {
		return new WP_Error( 'wbf_feed', '没有 feed 地址。' );
	}
	if ( 0 !== strpos( $stream, 'feed/' ) ) {
		$stream = 'feed/' . $stream;
	}

	$tokens = wbf_freshrss_tokens();
	if ( is_wp_error( $tokens ) ) {
		return $tokens;
	}

	$resp = wp_remote_post(
		$tokens['api'] . '/reader/api/0/subscription/edit',
		array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'GoogleLogin auth=' . $tokens['auth'],
			),
			'body'    => array(
				'ac' => $action,
				's'  => $stream,
				'T'  => $tokens['token'],
			),
		)
	);

	if ( is_wp_error( $resp ) ) {
		return new WP_Error( 'wbf_sub', '请求 FreshRSS 失败：' . $resp->get_error_message() );
	}

	$code = (int) wp_remote_retrieve_response_code( $resp );
	$body = trim( (string) wp_remote_retrieve_body( $resp ) );

	// FreshRSS 成功时回 "OK"；有的版本回 HTTP 200 空 body，所以两个都认。
	if ( 200 !== $code ) {
		$hint = ( 400 === $code )
			? '（常见原因：feed 地址无效，或者 RSS 源本身有语法错误、FreshRSS 解析不了）'
			: '';
		return new WP_Error( 'wbf_sub', 'FreshRSS 拒绝了这次操作（HTTP ' . $code . '）' . $hint . '。' );
	}
	if ( '' !== $body && 'OK' !== $body ) {
		return new WP_Error( 'wbf_sub', 'FreshRSS 返回：' . wp_strip_all_tags( $body ) );
	}

	return true;
}

/**
 * 按域名从**实时**订阅列表里取出那一条订阅的完整信息。
 *
 * 跟 wbf_freshrss_stream_id() 的区别：那个只要 id（取消订阅用），这个要 url/title，
 * 用来判断「是不是已经订过了」—— 已经订过就别再提交，FreshRSS 对重复订阅回 400。
 *
 * @param string $host 归一化域名。
 * @return array 找不到返回空数组。
 */
function wbf_freshrss_find_sub( $host ) {
	$conf = wbf_freshrss_conf();
	if ( '' === $conf['api'] || '' === $conf['user'] || '' === $conf['pass'] ) {
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

	$host = wbf_norm_host( $host );
	if ( '' === $host ) {
		return array();
	}

	foreach ( $list as $s ) {
		if ( ! is_array( $s ) || empty( $s['id'] ) ) {
			continue;
		}
		$site_host = isset( $s['htmlUrl'] ) ? wbf_norm_host( $s['htmlUrl'] ) : '';
		$feed_host = isset( $s['url'] ) ? wbf_norm_host( $s['url'] ) : '';
		if ( $host === $site_host || $host === $feed_host ) {
			return $s;
		}
	}

	return array();
}

/**
 * 按域名从 FreshRSS 的**实时**订阅列表里找出准确的 stream id。
 *
 * 用实时列表而不是本地快照：快照里的 feed 地址是上一次同步抄下来的，
 * FreshRSS 可能已经把它规范化过（/feed/ -> /feed），拿旧地址去删会 400。
 *
 * @param string $host 归一化域名。
 * @return string 找不到返回空串。
 */
function wbf_freshrss_stream_id( $host ) {
	$conf = wbf_freshrss_conf();
	if ( '' === $conf['api'] ) {
		return '';
	}

	$auth = wbf_get_token( $conf['api'], $conf['user'], $conf['pass'] );
	if ( ! $auth ) {
		return '';
	}

	$list = wbf_get_subscriptions( $conf['api'], $auth );
	if ( ! is_array( $list ) ) {
		return '';
	}

	$host = wbf_norm_host( $host );
	foreach ( $list as $s ) {
		if ( ! is_array( $s ) || empty( $s['id'] ) ) {
			continue;
		}
		$site_host = isset( $s['htmlUrl'] ) ? wbf_norm_host( $s['htmlUrl'] ) : '';
		$feed_host = isset( $s['url'] ) ? wbf_norm_host( $s['url'] ) : '';
		if ( $host === $site_host || $host === $feed_host ) {
			return (string) $s['id'];
		}
	}

	return '';
}

/**
 * 这个地址能不能拿出去抓。
 *
 * 要抓的地址来自访客评论，等于访客能影响服务器去请求哪儿 —— 必须挡内网。
 * 只管三件事：协议、host 是不是 IP 字面量或内网、DNS 解析出来是不是内网。
 *
 * @param string $url 网址。
 * @return true|WP_Error
 */
function wbf_url_crawlable( $url ) {
	$url = trim( (string) $url );
	$p   = wp_parse_url( $url );

	if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
		return new WP_Error( 'wbf_url', '地址不完整。' );
	}
	if ( ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ) {
		return new WP_Error( 'wbf_url', '只支持 http / https。' );
	}

	$host = strtolower( $p['host'] );

	if ( 'localhost' === $host || '.local' === substr( $host, -6 ) || '.internal' === substr( $host, -9 ) ) {
		return new WP_Error( 'wbf_url', '这个地址不对外。' );
	}

	// 直接写 IP 的一律不收（正常博客不会拿 IP 当域名）。
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return new WP_Error( 'wbf_url', '不接受直接用 IP 的地址。' );
	}

	$ip = gethostbyname( $host );
	if ( $ip === $host ) {
		return new WP_Error( 'wbf_url', '域名解析不出来。' );
	}
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return new WP_Error( 'wbf_url', '这个域名指向内网地址，已拦下。' );
	}

	return true;
}

/**
 * 试一个候选地址，判断它是不是**能用的** feed。
 *
 * 光看 HTTP 200 不够 —— FreshRSS 收到订阅后会自己用 SimpleXML 解析，
 * 解析不过去直接回 400，用户看到的就是一句没头没脑的「HTTP 400」。
 * 所以这里照 FreshRSS 那条路先走一遍，把原因提前抓出来。
 *
 * @param string $url 候选地址。
 * @return array state: ok|notfeed|bad|http，reason 失败原因，body 成功时的内容。
 */
function wbf_feed_sniff( $url ) {
	$resp = wp_remote_get(
		$url,
		array(
			'timeout'     => 12,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (compatible; WP-Boyou-Feed/' . WBF_VERSION . ')',
		)
	);
	if ( is_wp_error( $resp ) ) {
		return array( 'state' => 'http', 'reason' => $resp->get_error_message() );
	}

	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( 200 !== $code ) {
		return array( 'state' => 'http', 'reason' => 'HTTP ' . $code );
	}

	$body = (string) wp_remote_retrieve_body( $resp );
	$head = ltrim( substr( $body, 0, 400 ) );
	$is_xml = ( 0 === strpos( $head, '<?xml' ) )
		|| false !== stripos( $body, '<rss' )
		|| false !== stripos( $body, '<feed' )
		|| false !== stripos( $body, '<rdf:RDF' );
	if ( ! $is_xml ) {
		return array( 'state' => 'notfeed', 'reason' => '返回的是网页不是 RSS' );
	}

	$prev = libxml_use_internal_errors( true );
	libxml_clear_errors();
	$xml  = simplexml_load_string( $body );
	$errs = libxml_get_errors();
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	if ( false === $xml ) {
		$first = '';
		foreach ( (array) $errs as $e ) {
			$msg = trim( (string) $e->message );
			if ( '' !== $msg ) {
				$first = $msg;
				break;
			}
		}
		return array( 'state' => 'bad', 'reason' => ( '' !== $first ? $first : 'XML 解析失败' ) );
	}

	return array( 'state' => 'ok', 'body' => $body );
}

/**
 * 猜一个站的 feed 地址。
 *
 * 顺序：首页声明的 <link rel="alternate"> → 几个最常见的路径。
 * 每条候选都过一遍 wbf_feed_sniff()，能解析的才算数 —— 不然后面 FreshRSS 只回 400。
 *
 * 路径表覆盖三类程序：WordPress（/feed/、/?feed=rss2）、EMLOG（/rss.php）、
 * 静态博客 Hexo/Hugo（/atom.xml、/index.xml）。EMLOG 排在第二个：
 * 它的首页模板通常不写 feed 声明，只能靠路径试出来。
 *
 * 探测要发好几次请求，所以做成「点击时才跑」，不要挂到列表页上。
 *
 * @param string $site 站点地址。
 * @return string|WP_Error 找到返回 feed 地址。
 */
function wbf_discover_feed( $site ) {
	$site = esc_url_raw( trim( (string) $site ) );
	if ( '' === $site ) {
		return new WP_Error( 'wbf_feed', '没有站点地址。' );
	}

	$safe = wbf_url_crawlable( $site );
	if ( is_wp_error( $safe ) ) {
		return $safe;
	}

	$resp = wp_remote_get(
		$site,
		array(
			'timeout'     => 18,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (compatible; WP-Boyou-Feed/' . WBF_VERSION . ')',
		)
	);
	if ( is_wp_error( $resp ) ) {
		return new WP_Error( 'wbf_feed', '打不开这个站：' . $resp->get_error_message() );
	}

	$html = (string) wp_remote_retrieve_body( $resp );

	$cands = array();

	// ① 首页里声明的 feed（最准，WordPress / Hexo / Hugo 都会写）。
	//    评论 feed 跳过：订成评论流，博友圈里显示的全是他家的评论。
	if ( preg_match_all( '#<link[^>]+>#i', $html, $m ) ) {
		foreach ( $m[0] as $tag ) {
			if ( ! preg_match( '#rel\s*=\s*["\']?alternate#i', $tag ) ) {
				continue;
			}
			if ( ! preg_match( '#type\s*=\s*["\']application/(rss|atom)\+xml#i', $tag ) ) {
				continue;
			}
			if ( ! preg_match( '#href\s*=\s*["\']([^"\']+)["\']#i', $tag, $h ) ) {
				continue;
			}
			$href = html_entity_decode( trim( $h[1] ), ENT_QUOTES, 'UTF-8' );
			if ( '' === $href || false !== stripos( $href, 'comments/feed' ) ) {
				continue;
			}
			$cands[] = wbf_url_join( $site, $href );
		}
	}

	// ② 常见路径。
	$base  = preg_replace( '#^(https?://[^/]+).*$#i', '$1', $site );
	$paths = array();
	foreach ( array( '/feed/', '/rss.php', '/?feed=rss2', '/atom.xml', '/index.xml', '/rss.xml', '/feed.php' ) as $path ) {
		$paths[] = $base . $path;
	}

	$declared = $cands; // 首页自己声明的那几条，命中坏 feed 时可以提前收工。
	$cands    = array_values( array_unique( array_merge( $cands, $paths ) ) );

	$broken = '';
	foreach ( $cands as $try ) {
		$sniff = wbf_feed_sniff( $try );
		if ( 'ok' === $sniff['state'] ) {
			return $try;
		}
		// 是 RSS 但内容坏了：记下来。这种站不是「没开 feed」，是 feed 得修。
		if ( 'bad' === $sniff['state'] && '' === $broken ) {
			$broken = $try . '||' . $sniff['reason'];
			// 首页自己声明的 feed 都解析不了，就没必要再挨个试路径了 —— 省用户十几秒等待。
			if ( in_array( $try, $declared, true ) ) {
				break;
			}
		}
	}

	if ( '' !== $broken ) {
		list( $bad_url, $bad_reason ) = explode( '||', $broken, 2 );
		return new WP_Error(
			'wbf_feed_bad',
			'找到了 RSS（' . $bad_url . '），但它的内容有 XML 语法错误，FreshRSS 解析不了，订阅会被拒。'
			. '这不是你这边的问题，得请站长修一下 feed（报错：' . $bad_reason . '）。'
		);
	}

	return new WP_Error( 'wbf_feed', '这个站找不到 RSS 地址，可能没开 feed。' );
}

/**
 * 相对地址补全成绝对地址。
 *
 * @param string $base 站点地址。
 * @param string $href 可能是相对的。
 * @return string
 */
function wbf_url_join( $base, $href ) {
	if ( preg_match( '#^https?://#i', $href ) ) {
		return $href;
	}
	$p = wp_parse_url( $base );
	if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
		return $href;
	}
	$root = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );

	if ( 0 === strpos( $href, '//' ) ) {
		return $p['scheme'] . ':' . $href;
	}
	if ( 0 === strpos( $href, '/' ) ) {
		return $root . $href;
	}
	return rtrim( $root, '/' ) . '/' . ltrim( $href, '/' );
}

/**
 * 就地改本地 json 里的 subscriptions（新增或删除一项）。
 *
 * 只动 subscriptions 和 subscription_count，articles 一个不碰 —— 文章列表要等下次同步
 * 才会自然变化，这次先把订阅状态改对，页面上立刻看得出来。
 *
 * @param string $mode add|remove|remove_host。
 * @param array  $item add 时是 array( title, site, feed )；remove 时是 array( 'feed' => ... )。
 * @return bool
 */
function wbf_json_patch_subs( $mode, $item ) {
	$path = wbf_json_path();
	if ( ! is_file( $path ) || ! is_writable( $path ) ) {
		return false;
	}

	$raw = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return false;
	}

	$subs = ( isset( $data['subscriptions'] ) && is_array( $data['subscriptions'] ) ) ? $data['subscriptions'] : array();
	$next = array();

	if ( 'add' === $mode ) {
		$exists = false;
		foreach ( $subs as $s ) {
			if ( isset( $s['feed'] ) && $s['feed'] === $item['feed'] ) {
				$exists = true;
			}
		}
		$next = $subs;
		if ( ! $exists ) {
			$next[] = array(
				'title' => isset( $item['title'] ) ? $item['title'] : '',
				'site'  => isset( $item['site'] ) ? $item['site'] : '',
				'feed'  => $item['feed'],
			);
		}
	} elseif ( 'remove_host' === $mode ) {
		// 按域名删：快照里的 feed 地址可能是规范化之前的样子，不能靠它匹配。
		$want = wbf_norm_host( isset( $item['host'] ) ? $item['host'] : '' );
		foreach ( $subs as $s ) {
			$h = '';
			if ( isset( $s['site'] ) ) {
				$h = wbf_norm_host( $s['site'] );
			}
			if ( '' === $h && isset( $s['feed'] ) ) {
				$h = wbf_norm_host( $s['feed'] );
			}
			if ( '' !== $want && $h === $want ) {
				continue;
			}
			$next[] = $s;
		}
	} else {
		foreach ( $subs as $s ) {
			if ( isset( $s['feed'] ) && $s['feed'] === $item['feed'] ) {
				continue;
			}
			$next[] = $s;
		}
	}

	$data['subscriptions']     = $next;
	$data['subscription_count'] = count( $next );

	// 先记下原属主：用 WP-CLI（root）跑过之后，rename 出来的新文件会带 root 属主，
	// 之后 www 用户就写不进去了 —— 症状是「同步出错：无法写入 JSON 文件」。
	$before = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	// 先写临时文件再 rename：同步任务可能同时也在写这个文件，直接覆盖会写出半截 json。
	$tmp = $path . '.wbf-tmp';
	$ok  = @file_put_contents( $tmp, wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $ok ) {
		return false;
	}
	$done = @rename( $tmp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	if ( $done && is_array( $before ) && function_exists( 'chown' ) ) {
		// 以 www 身份跑时 chown 会失败（没权限），无害；以 root 跑时正好把属主还回去。
		@chown( $path, $before['uid'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@chgrp( $path, $before['gid'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	return $done;
}

/**
 * AJAX：加订阅 / 取消订阅。
 */
function wbf_ajax_sub_edit() {
	check_ajax_referer( 'wbf_sub', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '权限不足。' ) );
	}

	$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$host = isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '';
	$site = isset( $_POST['site'] ) ? esc_url_raw( wp_unslash( $_POST['site'] ) ) : '';
	$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

	if ( 'unsubscribe' === $do ) {
		$host = wbf_norm_host( $host );
		if ( '' === $host ) {
			wp_send_json_error( array( 'message' => '域名不对。' ) );
		}

		$stream = wbf_freshrss_stream_id( $host );
		if ( '' === $stream ) {
			wp_send_json_error( array( 'message' => 'FreshRSS 里找不到这个站的订阅，可能已经被取消了。' ) );
		}

		$r = wbf_freshrss_sub_edit( 'unsubscribe', $stream );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}

		// 本地快照按域名删（快照里的 feed 地址可能跟 FreshRSS 规范化后的不一致）
		wbf_json_patch_subs( 'remove_host', array( 'host' => $host ) );
		wp_send_json_success(
			array(
				'message' => '已取消订阅 ' . ( '' !== $title ? $title : $host ),
				'host'    => $host,
			)
		);
	}

	if ( 'subscribe' === $do ) {
		if ( '' === $site ) {
			wp_send_json_error( array( 'message' => '没有站点地址。' ) );
		}

		$host_now = wbf_norm_host( $site );

		/*
		 * 已经订过就直接说，别再往 FreshRSS 提交 —— 实测它对重复订阅回的是 400
		 * Bad Request，用户看到「被拒绝」以为出错，其实是他早就订过了。
		 * 顺手把订阅补进本地快照：本地那份是同步时抄的，可能落后。
		 */
		$already = wbf_freshrss_find_sub( $host_now );
		if ( ! empty( $already['id'] ) ) {
			$keep = isset( $already['url'] ) ? (string) $already['url'] : '';
			if ( '' !== $keep ) {
				wbf_json_patch_subs(
					'add',
					array(
						'title' => ( '' !== $title ? $title : ( isset( $already['title'] ) ? (string) $already['title'] : $host_now ) ),
						'site'  => $site,
						'feed'  => $keep,
					)
				);
			}
			wp_send_json_success(
				array(
					'message' => '这个站已经在订阅里了，不用重复添加。',
					'feed'    => $keep,
					'host'    => $host_now,
					'already' => 1,
				)
			);
		}

		$feed = wbf_discover_feed( $site );
		if ( is_wp_error( $feed ) ) {
			wp_send_json_error( array( 'message' => $feed->get_error_message() ) );
		}

		$r = wbf_freshrss_sub_edit( 'subscribe', 'feed/' . $feed );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}

		wbf_json_patch_subs(
			'add',
			array(
				'title' => ( '' !== $title ? $title : wbf_norm_host( $site ) ),
				'site'  => $site,
				'feed'  => $feed,
			)
		);

		wp_send_json_success(
			array(
				'message' => '已订阅，feed 是 ' . $feed . '（他的文章要等下次同步才会出现在博友圈）',
				'feed'    => $feed,
				'host'    => wbf_norm_host( $site ),
			)
		);
	}

	wp_send_json_error( array( 'message' => '不认识的操作。' ) );
}
add_action( 'wp_ajax_wbf_sub_edit', 'wbf_ajax_sub_edit' );
