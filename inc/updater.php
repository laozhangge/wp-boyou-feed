<?php
/**
 * 自更新：只告诉 WordPress「有没有新版、包在哪」，剩下的交给核心的 Plugin_Upgrader。
 *
 * 不自研下载解压：核心是整目录替换，包里删掉的文件会真正消失，失败还有回滚。
 *
 * 更新源：https://w.593200.xyz/theme-api/wp-boyou-feed-update.json
 *
 * @package wp-boyou-feed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WBF_UPDATE_JSON' ) ) {
	define( 'WBF_UPDATE_JSON', 'https://w.593200.xyz/theme-api/wp-boyou-feed-update.json' );
}

/**
 * 拉更新信息（带缓存）。
 *
 * @param bool $force 绕过缓存。
 * @return array|null
 */
function wbf_fetch_update_info( $force = false ) {
	static $memo       = null;
	static $memo_force = false;

	if ( ! $force && is_admin() && isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$force = true;
	}
	if ( $memo_force ) {
		return $memo;
	}
	if ( ! $force && null !== $memo ) {
		return $memo;
	}

	$cached = get_transient( 'wbf_update_info' );
	if ( ! $force && is_array( $cached ) ) {
		$memo = $cached;
		return $memo;
	}

	$res = wp_remote_get(
		add_query_arg( 'v', WBF_VERSION, WBF_UPDATE_JSON ),
		array(
			'timeout'    => 4,
			'user-agent' => 'WP-Boyou-Feed/' . WBF_VERSION . '; ' . home_url( '/' ),
		)
	);

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		set_transient( 'wbf_update_info', array(), 30 * MINUTE_IN_SECONDS );
		$memo       = null;
		$memo_force = true;
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['url'] ) ) {
		set_transient( 'wbf_update_info', array(), 30 * MINUTE_IN_SECONDS );
		$memo       = null;
		$memo_force = true;
		return null;
	}

	set_transient( 'wbf_update_info', $data, 3 * HOUR_IN_SECONDS );
	$memo       = $data;
	$memo_force = true;
	return $memo;
}

// 后台「检查更新」时作废缓存。
foreach ( array( 'load-plugins.php', 'load-update-core.php' ) as $wbf_hook ) {
	add_action(
		$wbf_hook,
		function () {
			if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				delete_transient( 'wbf_update_info' );
			}
		}
	);
}

/**
 * 告诉 WordPress 有新版本。
 *
 * @param object $transient update_plugins transient。
 * @return object
 */
function wbf_check_update( $transient ) {
	if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
		return $transient;
	}

	$info = wbf_fetch_update_info();
	if ( ! $info ) {
		return $transient;
	}

	$basename = plugin_basename( WBF_FILE );
	$current  = isset( $transient->checked[ $basename ] ) ? $transient->checked[ $basename ] : WBF_VERSION;

	if ( version_compare( (string) $info['version'], (string) $current, '>' ) ) {
		$transient->response[ $basename ] = (object) array(
			'id'            => 'laozhang.org/wp-boyou-feed',
			'slug'          => 'wp-boyou-feed',
			'plugin'        => $basename,
			'new_version'   => (string) $info['version'],
			'url'           => isset( $info['homepage'] ) ? (string) $info['homepage'] : 'https://laozhang.org/',
			'package'       => (string) $info['url'],
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'tested'        => isset( $info['tested'] ) ? (string) $info['tested'] : '',
			'requires_php'  => isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '',
			'compatibility' => new stdClass(),
		);
		unset( $transient->no_update[ $basename ] );
	} else {
		unset( $transient->response[ $basename ] );
	}

	return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'wbf_check_update' );

/**
 * 「查看详情」弹窗。
 *
 * @param false|object|array $result 默认结果。
 * @param string             $action 动作。
 * @param object             $args   参数。
 * @return false|object|array
 */
function wbf_plugin_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'wp-boyou-feed' !== $args->slug ) {
		return $result;
	}

	$info = wbf_fetch_update_info();
	if ( ! $info ) {
		return $result;
	}

	$description = '<p><b>把你 RSS 订阅的博友更新聚合成一个页面</b>，并且能对照：这些博友最近一次来你博客留言是什么时候。</p>'
		. '<h4>它解决什么问题</h4>'
		. '<p>坚持互访的人常有个感觉：我天天去他那儿评论，他三年没来过我这儿。这个插件把这件事摊开——'
		. '订阅名单和评论记录一对，谁还在来往、谁已经很久没露面，一眼看得见。</p>'
		. '<h4>数据从哪来</h4>'
		. '<ul>'
		. '<li><b>订阅名单</b>：FreshRSS 的 Google Reader API（也可以用你自己填的 JSON 地址）</li>'
		. '<li><b>来访记录</b>：你自己站点的评论表，不外传</li>'
		. '</ul>'
		. '<h4>页面怎么用</h4>'
		. '<p>新建一个页面，模板选「博友动态」；或者在任意页面内容里写 <code>[wbf_boyou]</code>。'
		. '插件自己接管模板，外层仍然调用主题的页头页脚，配色导航不变。</p>';

	$faq = '<h4>页面打开很慢？</h4>'
		. '<p>插件读的是本地 JSON 文件，不走网络。第一次用要先点一次「立即同步」，'
		. '之后定时任务每小时检查、按你设的间隔拉取。</p>'
		. '<h4>换了主题会丢吗？</h4>'
		. '<p>不会。这是插件不是主题模板，换主题照常工作。</p>';

	$changelog = '';
	if ( ! empty( $info['changelog'] ) && is_array( $info['changelog'] ) ) {
		foreach ( array_slice( $info['changelog'], 0, 20 ) as $rel ) {
			if ( ! is_array( $rel ) ) {
				continue;
			}
			$ver  = isset( $rel['version'] ) ? (string) $rel['version'] : '';
			$date = isset( $rel['date'] ) ? (string) $rel['date'] : '';
			$changelog .= '<h4>v' . esc_html( $ver ) . ( '' !== $date ? '（' . esc_html( $date ) . '）' : '' ) . '</h4><ul>';
			if ( ! empty( $rel['notes'] ) && is_array( $rel['notes'] ) ) {
				foreach ( $rel['notes'] as $note ) {
					$changelog .= '<li>' . wp_kses_post( $note ) . '</li>';
				}
			}
			$changelog .= '</ul>';
		}
	}
	if ( '' === $changelog ) {
		$changelog = '<p>更新日志见插件目录下的 CHANGELOG.md。</p>';
	}

	return (object) array(
		'name'           => 'WP 博友动态',
		'slug'           => 'wp-boyou-feed',
		'version'        => (string) $info['version'],
		'author'         => '<a href="https://laozhang.org/">老张</a>',
		'author_profile' => 'https://laozhang.org/',
		'homepage'       => isset( $info['homepage'] ) ? (string) $info['homepage'] : 'https://laozhang.org/',
		'requires'       => isset( $info['requires_wp'] ) ? (string) $info['requires_wp'] : '5.5',
		'requires_php'   => isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '7.4',
		'tested'         => isset( $info['tested'] ) ? (string) $info['tested'] : '',
		'last_updated'   => isset( $info['last_updated'] ) ? (string) $info['last_updated'] : '',
		'download_link'  => (string) $info['url'],
		'sections'       => array(
			'description'  => $description,
			'faq'          => $faq,
			'changelog'    => $changelog,
		),
	);
}
add_filter( 'plugins_api', 'wbf_plugin_info', 20, 3 );

// 升级完成清缓存。
add_action(
	'upgrader_process_complete',
	function ( $upgrader, $data ) {
		if ( is_array( $data ) && 'update' === ( $data['action'] ?? '' ) && 'plugin' === ( $data['type'] ?? '' ) ) {
			delete_transient( 'wbf_update_info' );
		}
	},
	10,
	2
);
