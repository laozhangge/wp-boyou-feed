<?php
/**
 * 博友动态页面模板（由插件通过 template_include 接管）
 *
 * 外层仍走主题的 get_header()/get_footer()，配色、导航、页脚都是主题的，
 * 只有中间这块内容归插件。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WBF_DIR . 'inc/template-helpers.php';

get_header();

$rss_json_url = get_option('riven_cat_rss_json', '');
if ($rss_json_url === '') {
    // 空则走本地数据文件（1.6.4 起在 uploads/rss-<随机>/boyou.json），直接给路径，不绕 URL
    $rss_json_url = wbf_json_path();
}

// Helper functions

$rssResult = wbf_fetch_from_json($rss_json_url, 9999);
$allArticles = $rssResult['articles'];
$rssError = $rssResult['error'];
$subscriptionCount = intval($rssResult['subscription_count']);
$latestTime = intval($rssResult['sync_time']);

$pageSize = max(2, intval(get_option('riven_cat_rss_page', 10)));
if ($pageSize % 2 !== 0) $pageSize++; // 确保双数
$currentPage = max(1, intval(isset($_GET['rss_page']) ? $_GET['rss_page'] : 1));
$totalArticles = count($allArticles);
$totalPage = max(1, (int) ceil($totalArticles / $pageSize));

if ($currentPage > $totalPage) $currentPage = $totalPage;

$articles = array_slice($allArticles, ($currentPage - 1) * $pageSize, $pageSize);
$baseUrl = get_permalink();
$pageTitle = '博友动态';

// 统计：本月、本周、今日
$monthStart = strtotime(date('Y-m-01 00:00:00'));
$weekStart  = strtotime('monday this week 00:00:00');
$dayStart   = strtotime(date('Y-m-d 00:00:00'));
$monthCount = 0;
$weekCount  = 0;
$dayCount   = 0;
foreach ($allArticles as $article) {
    $time = intval($article['time']);
    if ($time <= 0) continue;
    if ($time >= $monthStart) $monthCount++;
    if ($time >= $weekStart)  $weekCount++;
    if ($time >= $dayStart)   $dayCount++;
}

$avatarLazy = function_exists('get_Lazyload') ? get_Lazyload() : 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';

/*
 * 每张卡片的标题上挂一句「互访对账」的结论：这个站的博友多久没来留言了。
 *
 * 只给能管评论的人看：对访客来说「这站两年没来留言」既没意义，还等于把站长的
 * 人际往来摊在明面上。访客连数据都不算（省掉一次对账查询）。
 */
$wbfShowTips = is_user_logged_in() && current_user_can( 'moderate_comments' );
$wbfTips     = ( $wbfShowTips && function_exists( 'wbf_reconcile_tips' ) ) ? wbf_reconcile_tips() : array();
?>

<main id="main-content" class="rss-page">
        <section class="ztj-rss-wrap">
            <header class="ztj-rss-hero">
                <div class="ztj-rss-hero-main">
                    <svg class="ztj-rss-hero-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M5 5.6c7.4 0 13.4 6 13.4 13.4" stroke="currentColor" stroke-width="2.25" stroke-linecap="round"/>
                        <path d="M5 11.1c4.4 0 7.9 3.5 7.9 7.9" stroke="currentColor" stroke-width="2.25" stroke-linecap="round"/>
                        <circle cx="6.4" cy="17.6" r="1.9" fill="currentColor"/>
                    </svg>
                    <div class="ztj-rss-hero-copy">
                        <h1><?php echo wbf_e($pageTitle); ?></h1>
                        <p>友链最新动态，发现更多有温度的文字与思考</p>
                    </div>
                </div>
            </header>

            <div class="ztj-rss-intro">
                欢迎浏览友链站点的最新更新，发现更多有温度的文字与思考，拓展视野，收获灵感。
            </div>

            <section class="ztj-rss-stats" aria-label="RSS 数据统计">
                <div class="ztj-rss-stat">
                    <svg class="ztj-rss-stat-icon" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                        <circle cx="22" cy="42" r="5" fill="currentColor"/>
                        <path d="M18 28c9.9 0 18 8.1 18 18M18 15c17.1 0 31 13.9 31 31" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                    <div class="ztj-rss-stat-copy">
                        <span class="ztj-rss-stat-label">已订阅站点</span>
                        <span class="ztj-rss-stat-value"><em><?php echo intval($subscriptionCount); ?></em>个</span>
                    </div>
                </div>

                <div class="ztj-rss-stat">
                    <svg class="ztj-rss-stat-icon" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                        <rect x="16" y="12" width="32" height="40" rx="4" stroke="currentColor" stroke-width="4"/>
                        <path d="M24 24h16M24 34h16M24 44h10" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                    <div class="ztj-rss-stat-copy">
                        <span class="ztj-rss-stat-label">本月文章</span>
                        <span class="ztj-rss-stat-value"><em><?php echo intval($monthCount); ?></em>篇</span>
                    </div>
                </div>

                <div class="ztj-rss-stat">
                    <svg class="ztj-rss-stat-icon" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                        <rect x="12" y="16" width="40" height="32" rx="4" stroke="currentColor" stroke-width="4"/>
                        <path d="M20 28h24M20 38h16" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
                        <path d="M24 12v8M40 12v8" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                    <div class="ztj-rss-stat-copy">
                        <span class="ztj-rss-stat-label">本周文章</span>
                        <span class="ztj-rss-stat-value"><em><?php echo intval($weekCount); ?></em>篇</span>
                    </div>
                </div>

                <div class="ztj-rss-stat">
                    <svg class="ztj-rss-stat-icon" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                        <circle cx="32" cy="32" r="20" stroke="currentColor" stroke-width="4"/>
                        <path d="M32 20v13l9 6" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <div class="ztj-rss-stat-copy">
                        <span class="ztj-rss-stat-label">今日文章</span>
                        <span class="ztj-rss-stat-value"><em><?php echo intval($dayCount); ?></em>篇</span>
                    </div>
                </div>
            </section>

            <?php if ($rssError !== '' && empty($allArticles)): ?>
                <div class="ztj-rss-empty">
                    <strong>暂时没有读取到 RSS 内容。</strong><br>
                    <?php echo wbf_e($rssError); ?>
                </div>
            <?php elseif (empty($allArticles)): ?>
                <div class="ztj-rss-empty">
                    <strong>暂时还没有可展示的文章。</strong><br>
                    请稍后再来看看，或者确认 JSON 中已经存在文章内容。
                </div>
            <?php else: ?>
                <div class="ztj-rss-grid">
                    <?php foreach ($articles as $article): ?>
                        <?php $articleTime = intval($article['time']); ?>
                        <?php
                        /*
                         * 站域名在三个地方，按可靠性排：
                         *   site_domain —— 中间层已经把 json 里的 host 取出来了（同步时按订阅地址算的，最准）
                         *   host        —— 直接读原始 json 时才有
                         *   文章链接    —— 什么都没有的时候兜底，但换个域名的站会取到新域名，对不上
                         */
                        $cardHost = '';
                        if (!empty($article['site_domain'])) {
                            $cardHost = $article['site_domain'];
                        } elseif (!empty($article['host'])) {
                            $cardHost = $article['host'];
                        } elseif (function_exists('wbf_norm_host')) {
                            $cardHost = wbf_norm_host($article['link']);
                        }
                        if (function_exists('wbf_norm_host')) {
                            $cardHost = wbf_norm_host($cardHost);
                        }
                        $cardRow  = isset($wbfTips[$cardHost]) ? $wbfTips[$cardHost] : null;
                        $cardTip  = $cardRow ? wbf_tip_text($cardRow) : '';
                        ?>
                        <article class="ztj-rss-card">
                            <h2 class="ztj-rss-title"<?php
                                if ('' !== $cardTip) {
                                    echo ' data-wbf-tip="' . str_replace("\n", '&#10;', esc_attr($cardTip)) . '"';
                                    echo ' data-wbf-grade="' . esc_attr($cardRow['key']) . '"';
                                }
                            ?>>
                                <a href="<?php echo wbf_e($article['link']); ?>" target="_blank" rel="noopener noreferrer"><?php echo wbf_e($article['title']); ?></a>
                                <?php if (!empty($article['day_badge'][0])): ?>
                                    <span class="ztj-rss-day-badge <?php echo wbf_e($article['day_badge'][1]); ?>"><?php echo wbf_e($article['day_badge'][0]); ?></span>
                                <?php endif; ?>
                            </h2>
                            <p class="ztj-rss-desc"><?php echo wbf_e($article['description']); ?></p>
                            <div class="ztj-rss-footer">
                                <span class="ztj-rss-avatar">
                                    <?php if ($article['icon'] !== ''): ?>
                                        <img class="lazyload" src="<?php echo wbf_e($avatarLazy); ?>" data-src="<?php echo wbf_e($article['icon']); ?>" alt="<?php echo wbf_e($article['site_name']); ?>" loading="lazy" referrerpolicy="no-referrer">
                                    <?php else: ?>
                                        <span class="ztj-rss-fallback"><?php echo wbf_e(wbf_mb_substr($article['site_name'], 0, 1)); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="ztj-rss-site"><?php echo wbf_e($article['site_name']); ?></span>
                                <time class="ztj-rss-date" datetime="<?php echo $articleTime > 0 ? wbf_e(date('c', $articleTime)) : ''; ?>"><?php echo $articleTime > 0 ? wbf_e(date('m-d', $articleTime)) : wbf_e($article['time_label']); ?></time>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPage > 1): ?>
                    <ul class="home-pagination" aria-label="RSS 分页导航">
                        <?php if ($currentPage > 1): ?>
                            <li class="prev">
                                <a href="<?php echo wbf_e(wbf_page_url($baseUrl, $currentPage - 1)); ?>">
                                    <svg class="home-page-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 18l-6-6 6-6"/></svg><span class="home-page-text">上一页</span>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $currentPage - 2);
                        $end = min($totalPage, $currentPage + 2);
                        if ($start > 1) {
                            echo '<li><a href="' . wbf_e(wbf_page_url($baseUrl, 1)) . '">1</a></li>';
                            if ($start > 2) echo '<li><a>&hellip;</a></li>';
                        }
                        for ($i = $start; $i <= $end; $i++) {
                            if ($i === $currentPage) {
                                echo '<li class="active"><a>' . $i . '</a></li>';
                            } else {
                                echo '<li><a href="' . wbf_e(wbf_page_url($baseUrl, $i)) . '">' . $i . '</a></li>';
                            }
                        }
                        if ($end < $totalPage) {
                            if ($end < $totalPage - 1) echo '<li><a>&hellip;</a></li>';
                            echo '<li><a href="' . wbf_e(wbf_page_url($baseUrl, $totalPage)) . '">' . $totalPage . '</a></li>';
                        }
                        ?>

                        <?php if ($currentPage < $totalPage): ?>
                            <li class="next">
                                <a href="<?php echo wbf_e(wbf_page_url($baseUrl, $currentPage + 1)); ?>">
                                    <span class="home-page-text">下一页</span><svg class="home-page-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 18l6-6-6-6"/></svg>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </section>
</main>

<?php get_footer(); ?>
