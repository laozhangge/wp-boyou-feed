<?php
/**
 * 后台设置页
 *
 * option 名全部沿用主题时代的 riven_rss_* / riven_cat_rss_*，一个都没改 ——
 * 改名等于老站的 FreshRSS 地址、账号、同步间隔、每页条数全部失效。
 *
 * @package wp-boyou-feed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 菜单。
 */
function wbf_register_menu() {
	add_menu_page(
		'博友动态',
		'博友动态',
		'manage_options',
		'wbf-settings',
		'wbf_settings_page',
		'dashicons-rss',
		58
	);

	// 第一个子项与父菜单同名，WP 会自动加一条，这里显式改成「设置」
	add_submenu_page( 'wbf-settings', '博友动态设置', '设置', 'manage_options', 'wbf-settings', 'wbf_settings_page' );
	add_submenu_page( 'wbf-settings', '互访对账', '互访对账', 'manage_options', 'wbf-reconcile', 'wbf_reconcile_page' );
}
add_action( 'admin_menu', 'wbf_register_menu' );

/**
 * 字段定义（一处声明，保存和渲染都用它）。
 *
 * @return array
 */
function wbf_fields() {
	return array(
		'riven_rss_freshrss_api' => array(
			'label'  => 'FreshRSS API 地址',
			'type'   => 'text',
			'desc'   => '形如 <code>https://rss.example.com/api/greader.php</code>，末尾不要带斜杠。',
			'default' => '',
		),
		'riven_rss_freshrss_user' => array(
			'label'  => 'FreshRSS 用户名',
			'type'   => 'text',
			'desc'   => 'FreshRSS 后台的登录用户名（不是邮箱）。',
			'default' => '',
		),
		'riven_rss_freshrss_pass' => array(
			'label'  => 'FreshRSS 密码',
			'type'   => 'text',
			'desc'   => '建议在 FreshRSS 里单独建一个只读账号给这个插件用。',
			'default' => '',
		),
		'riven_rss_sync_interval' => array(
			'label'  => '同步间隔（小时）',
			'type'   => 'number',
			'desc'   => '默认 6 小时。改完会自动重排定时任务。',
			'default' => '6',
		),
		'riven_cat_rss_json' => array(
			'label'  => '自定义 JSON 地址（可选）',
			'type'   => 'text',
			'desc'   => '留空就用 FreshRSS 同步生成的本地文件。填了则优先读这个地址，可以是别人家的 JSON。',
			'default' => '',
		),
		'riven_cat_rss_page' => array(
			'label'  => '每页显示数量',
			'type'   => 'number',
			'desc'   => '建议填双数（10、12、14），便于与边栏对齐高度。默认 10。',
			'default' => '10',
		),
	);
}

/**
 * 保存。
 */
function wbf_handle_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '权限不足。' );
	}
	check_admin_referer( 'wbf_save' );

	foreach ( wbf_fields() as $key => $conf ) {
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		if ( 'number' === $conf['type'] ) {
			$val = (string) max( 0, (int) $raw );
		} else {
			$val = sanitize_text_field( (string) $raw );
		}

		update_option( $key, $val );
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'wbf-settings', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_wbf_save', 'wbf_handle_save' );

/**
 * 数据文件的公网自检。
 *
 * 为什么要有：数据目录名是随机生成的（128 位），正常没人猜得到；目录里也放了
 * .htaccess 硬拦 —— 但那只在 Apache 生效，Nginx/IIS 不认。插件改不了别人的
 * Web 服务器配置，能做的只有「发现被暴露就说清楚」：真的去请求一次自己的地址，
 * 拿到 200 且内容是我们的数据 → 亮红警告，给出可复制的规则。
 *
 * 服务器访问不了自己域名（hairpin）时拿不到结论，就什么都不说，不瞎报警。
 *
 * @return array{state:string,url:string}
 */
function wbf_self_exposure_check() {
	$url = wbf_json_url();
	if ( '' === $url ) {
		return array( 'state' => 'unknown', 'url' => '' );
	}

	$cache = get_transient( 'wbf_exposure_check' );
	if ( is_array( $cache ) ) {
		return $cache;
	}

	$resp = wp_remote_get(
		$url,
		array(
			'timeout'   => 6,
			'sslverify' => false,
			'headers'   => array( 'Cache-Control' => 'no-cache' ),
		)
	);

	$out = array( 'state' => 'unknown', 'url' => $url );

	/*
	 * 先按公网地址请求。有的服务器访问不了自己的域名（hairpin NAT），
	 * 这时走回环：127.0.0.1 + Host 头，Nginx 照样按 server_name 匹配，
	 * 判定规则是否生效是准的（只是证明不了外网可达，所以 403 才敢说 closed）。
	 */
	$probes = array( $url );
	$parts  = wp_parse_url( $url );
	if ( ! empty( $parts['host'] ) && ! empty( $parts['path'] ) ) {
		$scheme   = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$probes[] = $scheme . '://127.0.0.1' . $parts['path'];
	}

	foreach ( $probes as $i => $probe ) {
		$args = array(
			'timeout'   => 6,
			'sslverify' => false,
			'headers'   => array( 'Cache-Control' => 'no-cache' ),
		);
		if ( $i > 0 ) {
			$args['headers']['Host'] = $parts['host'];
		}

		$resp = wp_remote_get( $probe, $args );
		if ( is_wp_error( $resp ) ) {
			continue;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );

		if ( 200 === $code && false !== strpos( $body, '"articles"' ) ) {
			$out['state'] = 'open';
			break;
		}
		if ( in_array( $code, array( 403, 404 ), true ) ) {
			$out['state'] = 'closed';
			break;
		}
	}

	set_transient( 'wbf_exposure_check', $out, 6 * HOUR_IN_SECONDS );

	return $out;
}

/**
 * 设置页。
 */
function wbf_settings_page() {
	$last_sync = (int) get_option( 'riven_rss_last_sync', 0 );
	$last_str  = $last_sync > 0 ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $last_sync ), 'Y-m-d H:i' ) : '从未同步';

	$json_path = wbf_json_path();
	$json_ok   = '' !== $json_path && file_exists( $json_path );
	$json_time = $json_ok ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', filemtime( $json_path ) ), 'Y-m-d H:i' ) : '-';
	$count     = 0;
	if ( $json_ok ) {
		$data  = json_decode( (string) @file_get_contents( $json_path ), true );
		$count = is_array( $data ) && isset( $data['articles'] ) ? count( (array) $data['articles'] ) : 0;
	}
	?>
	<div class="wrap">
		<h1>博友动态</h1>

		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>已保存。</p></div>
		<?php endif; ?>

		<div class="card" style="max-width:none;">
			<h2 style="margin-top:0;">同步状态</h2>
			<p>
				上次同步：<b><?php echo esc_html( $last_str ); ?></b>
				　｜　数据文件：<?php echo $json_ok ? '<b style="color:#46b450;">正常</b>（' . esc_html( $json_time ) . '，' . (int) $count . ' 篇）' : '<b style="color:#dc3232;">还没有数据</b>'; ?>
			</p>
			<p>
				<button type="button" id="wbf-sync-btn" class="button button-secondary">🔄 立即同步</button>
				<span id="wbf-sync-status" style="margin-left:10px;"></span>
			</p>
			<p class="description">
				同步会把订阅的文章拉到本地文件，前台页面读本地文件，不经过网络。
				定时任务每小时检查一次，到点才真正拉取。
			</p>
		</div>

		<?php
		$wbf_exposure = wbf_self_exposure_check();
		$wbf_is_nginx = isset( $_SERVER['SERVER_SOFTWARE'] ) && false !== stripos( (string) $_SERVER['SERVER_SOFTWARE'], 'nginx' );
		if ( 'open' === $wbf_exposure['state'] ) :
			?>
			<div class="notice notice-error">
				<p><b>你的数据文件现在能被公网直接下载。</b>里面是全部订阅的站名、站址和文章摘要。目录名是 128 位随机串，正常猜不出来，但这个地址一旦被日志、截图、备份带出去，别人就能整份拿走。</p>
				<p>目录里已经放了 <code>.htaccess</code>，<b>Apache 主机不用管</b>（已生效）。你这台是 Nginx / IIS，需要在站点配置里加一条，然后重载 Web 服务器：</p>
				<pre style="padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;overflow:auto;">location ^~ /wp-content/uploads/<?php echo esc_html( wbf_data_slug() ); ?>/ {
    deny all;
}</pre>
				<p class="description">加完刷新本页，警告会消失（自检结果缓存 6 小时）。</p>
			</div>
		<?php elseif ( 'unknown' === $wbf_exposure['state'] && $wbf_is_nginx ) : ?>
			<div class="notice notice-warning">
				<p>数据目录是随机名（<code><?php echo esc_html( wbf_data_slug() ); ?></code>），正常猜不出来；但你这台是 <b>Nginx</b>，不认目录里的 <code>.htaccess</code>。想彻底关死，在站点配置里加一条再重载即可：</p>
				<pre style="padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;overflow:auto;">location ^~ /wp-content/uploads/<?php echo esc_html( wbf_data_slug() ); ?>/ {
    deny all;
}</pre>
				<p class="description">服务器访问不了自己的域名，所以这里没法替你实测——没加也不影响功能，只是多一道保险。</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wbf_save">
			<?php wp_nonce_field( 'wbf_save' ); ?>

			<h2>FreshRSS 连接</h2>
			<table class="form-table" role="presentation">
				<?php foreach ( wbf_fields() as $key => $conf ) : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $conf['label'] ); ?></label></th>
						<td>
							<input
								name="<?php echo esc_attr( $key ); ?>"
								id="<?php echo esc_attr( $key ); ?>"
								type="<?php echo 'number' === $conf['type'] ? 'number' : 'text'; ?>"
								value="<?php echo esc_attr( get_option( $key, $conf['default'] ) ); ?>"
								class="<?php echo 'number' === $conf['type'] ? 'small-text' : 'regular-text'; ?>">
							<?php if ( ! empty( $conf['desc'] ) ) : ?>
								<p class="description"><?php echo wp_kses_post( $conf['desc'] ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2>页面</h2>
			<p class="description">
				新建一个页面，模板选「博友动态」；或者随便哪个页面，内容里写 <code>[wbf_boyou]</code>。
				插件会自动接管，不需要改主题。
			</p>

			<?php submit_button( '保存设置' ); ?>
		</form>

		<script>
		jQuery(function ($) {
			$('#wbf-sync-btn').on('click', function () {
				var btn = $(this), st = $('#wbf-sync-status');
				btn.prop('disabled', true).text('⏳ 同步中...');
				st.text('').css('color', '');
				$.post(ajaxurl, {
					action: 'wbf_sync',
					_wpnonce: '<?php echo esc_js( wp_create_nonce( 'wbf_sync' ) ); ?>'
				}, function (res) {
					btn.prop('disabled', false).text('🔄 立即同步');
					if (res.success) {
						st.text('✅ ' + res.data.message + '（' + res.data.count + ' 篇）').css('color', '#46b450');
					} else {
						st.text('❌ ' + (res.data && res.data.message ? res.data.message : '未知错误')).css('color', '#dc3232');
					}
				}).fail(function () {
					btn.prop('disabled', false).text('🔄 立即同步');
					st.text('❌ 请求失败').css('color', '#dc3232');
				});
			});
		});
		</script>
	</div>
	<?php
}
