<?php
/**
 * Plugin Name: WP 博友动态
 * Plugin URI:  https://laozhang.org/
 * Description: 把你 RSS 订阅的博友更新聚合到一个页面，顺带对账：这些博友最近一次来你博客留言是什么时候。数据源支持 FreshRSS（Google Reader API）。
 * Version:     1.6.3
 * Author:      老张
 * Author URI:  https://laozhang.org/
 * Requires PHP: 7.4
 * Requires at least: 5.5
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-boyou-feed
 *
 * @package wp-boyou-feed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WBF_VERSION', '1.6.3' );
define( 'WBF_FILE', __FILE__ );
define( 'WBF_DIR', plugin_dir_path( __FILE__ ) );
define( 'WBF_URL', plugin_dir_url( __FILE__ ) );

/*
 * 同步引擎（FreshRSS → uploads/wp-boyou-feed/boyou.json）
 *
 * option 名一律沿用主题时代的 riven_rss_* / riven_cat_rss_* —— 这是数据契约，
 * 改了等于老站的 FreshRSS 配置、同步时间、每页条数全部失效。
 */
// 域名工具，别的模块都要用，放最前面
require_once WBF_DIR . 'inc/host.php';

require_once WBF_DIR . 'inc/rss-sync.php';

// 后台设置
require_once WBF_DIR . 'inc/settings.php';

// 互访对账
require_once WBF_DIR . 'inc/reconcile.php';

// 评论里「你还没订阅他」的提示
require_once WBF_DIR . 'inc/unsubscribed.php';

// 往 FreshRSS 加订阅 / 取消订阅
require_once WBF_DIR . 'inc/subscriptions.php';

// 自更新检查（必须无条件加载，停用状态下也要能收到升级提示）
require_once WBF_DIR . 'inc/updater.php';

/**
 * 这个页面要不要用插件的模板渲染。
 *
 * 判据有两个，任一命中即算：
 *   ① 页面选的模板是主题时代那个 page-templates/template-rss.php
 *      —— 老站迁移过来时页面一个字都不用改，模板文件从主题删掉也不影响判断；
 *   ② 页面 slug 是 boyou —— 兜底，方便重建页面的人。
 *
 * @param int $post_id 页面 ID，留空取当前请求的页面。
 * @return bool
 */
function wbf_is_boyou_page( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();
	if ( $post_id <= 0 || 'page' !== get_post_type( $post_id ) ) {
		return false;
	}

	$tpl = (string) get_post_meta( $post_id, '_wp_page_template', true );
	if ( 'page-templates/template-rss.php' === $tpl || 'template-rss.php' === basename( $tpl ) ) {
		return true;
	}

	return 'boyou' === get_post_field( 'post_name', $post_id );
}

/**
 * 接管模板。
 *
 * 为什么不等主题里的模板文件：那份文件要随主题一起维护，两边各一份必然不同步。
 * 插件自己带模板，主题里那份就能删掉了。模板内部仍然调用 get_header()/get_footer()，
 * 外壳还是主题的，配色导航页脚都不变。
 */
function wbf_template_include( $template ) {
	if ( ! is_page() || ! wbf_is_boyou_page() ) {
		return $template;
	}

	$own = WBF_DIR . 'templates/boyou.php';
	return file_exists( $own ) ? $own : $template;
}
add_filter( 'template_include', 'wbf_template_include', 99 );

/**
 * 短代码入口：页面里写 [wbf_boyou] 也能渲染，不依赖页面模板。
 *
 * @return string
 */
function wbf_boyou_shortcode() {
	ob_start();
	$own = WBF_DIR . 'templates/boyou.php';
	if ( file_exists( $own ) ) {
		include $own;
	}
	return (string) ob_get_clean();
}
add_shortcode( 'wbf_boyou', 'wbf_boyou_shortcode' );

/**
 * 样式：只在博友页面加载。
 *
 * 不依赖主题的 handle —— 插件要能脱离任何主题单独用。用优先级 20 让它在主题样式之后入队。
 */
function wbf_enqueue_assets() {
	if ( ! is_page() ) {
		return;
	}
	if ( ! wbf_is_boyou_page() && ! has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'wbf_boyou' ) ) {
		return;
	}

	$css = WBF_DIR . 'assets/css/boyou.css';
	wp_enqueue_style(
		'wbf-boyou',
		WBF_URL . 'assets/css/boyou.css',
		array(),
		file_exists( $css ) ? (string) filemtime( $css ) : WBF_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'wbf_enqueue_assets', 20 );

/**
 * 后台脚本：只在本插件的页面和评论列表加载。
 *
 * 评论列表那边要用「添加订阅」，对账页要用「删除订阅」，两处共用一份 admin.js。
 */
function wbf_admin_assets() {
	$page        = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$is_own_page = ( 0 === strpos( $page, 'wbf-' ) );
	$screen      = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$is_comments = ( $screen && 'edit-comments' === $screen->id );

	if ( ! $is_own_page && ! $is_comments ) {
		return;
	}

	$js = WBF_DIR . 'assets/js/admin.js';
	wp_enqueue_script(
		'wbf-admin',
		WBF_URL . 'assets/js/admin.js',
		array(),
		file_exists( $js ) ? (string) filemtime( $js ) : WBF_VERSION,
		true
	);
	wp_localize_script(
		'wbf-admin',
		'WBF_ADMIN',
		array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'wbf_sub' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'wbf_admin_assets' );

/**
 * ===== 同步：AJAX 手动触发 + 定时任务 =====
 *
 * cron 钩子名沿用 riven_rss_cron_hook，不改成 wbf_*：老站上已经排了任务，
 * 改名会在库里留下一个永远触发不了的孤儿排程。
 */

/**
 * 跑一次同步。
 *
 * @return array
 */
function wbf_do_sync() {
	$api  = (string) get_option( 'riven_rss_freshrss_api', '' );
	$user = (string) get_option( 'riven_rss_freshrss_user', '' );
	$pass = (string) get_option( 'riven_rss_freshrss_pass', '' );
	return wbf_sync_run( $api, $user, $pass );
}

/**
 * AJAX：后台「立即同步」按钮。
 */
function wbf_ajax_sync() {
	check_ajax_referer( 'wbf_sync', '_wpnonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '权限不足' ) );
	}

	$result = wbf_do_sync();
	if ( ! empty( $result['success'] ) ) {
		wp_send_json_success( $result );
	}
	wp_send_json_error( $result );
}
add_action( 'wp_ajax_wbf_sync', 'wbf_ajax_sync' );

/**
 * 定时同步。
 */
function wbf_cron_sync() {
	wbf_do_sync();
}
add_action( 'riven_rss_cron_hook', 'wbf_cron_sync' );

/**
 * 自定义同步间隔（小时数从设置里读）。
 *
 * @param array $schedules 已有间隔。
 * @return array
 */
function wbf_cron_schedules( $schedules ) {
	$hours = (int) get_option( 'riven_rss_sync_interval', 6 );
	if ( $hours < 1 ) {
		$hours = 6;
	}
	$schedules['riven_rss_interval'] = array(
		'interval' => $hours * HOUR_IN_SECONDS,
		'display'  => sprintf( '每 %d 小时（博友动态）', $hours ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'wbf_cron_schedules' );

/**
 * 插件激活：注册定时任务。
 */
function wbf_activate() {
	if ( ! wp_next_scheduled( 'riven_rss_cron_hook' ) ) {
		wp_schedule_event( time() + 60, 'riven_rss_interval', 'riven_rss_cron_hook' );
	}
}
register_activation_hook( __FILE__, 'wbf_activate' );

/**
 * 插件停用：清掉定时任务，别留孤儿排程。
 */
function wbf_deactivate() {
	/*
	 * 主题还是 1.3.8 及更早时，它自己也挂了 riven_rss_cron_hook（自带 rss-sync.php）。
	 * 那种情况下这个钩子是主题的，清了会让那个站彻底停止同步 —— 判断一下再决定。
	 */
	if ( ! file_exists( get_template_directory() . '/inc/rss-sync.php' ) ) {
		wp_clear_scheduled_hook( 'riven_rss_cron_hook' );
	}
}
register_deactivation_hook( __FILE__, 'wbf_deactivate' );

/**
 * 改了同步间隔就重排任务。
 *
 * @param mixed $old 旧值。
 * @param mixed $new 新值。
 */
function wbf_reschedule_on_interval_change( $old, $new ) {
	if ( $old === $new ) {
		return;
	}
	wp_clear_scheduled_hook( 'riven_rss_cron_hook' );
	wp_schedule_event( time() + 60, 'riven_rss_interval', 'riven_rss_cron_hook' );
}
add_action( 'update_option_riven_rss_sync_interval', 'wbf_reschedule_on_interval_change', 10, 2 );
