<?php
/**
 * 博友圈页面的渲染辅助函数（从主题模板里抽出来的，只定义一次）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wbf_e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function wbf_text($value, $default = '') {
    $value = trim(strip_tags((string) $value));
    return $value === '' ? $default : $value;
}

function wbf_mb_len($text) {
    return mb_strlen((string) $text, 'UTF-8');
}

function wbf_mb_substr($text, $start, $length) {
    return mb_substr((string) $text, $start, $length, 'UTF-8');
}

function wbf_array_get($array, $keys, $default = '') {
    foreach ((array) $keys as $key) {
        if (isset($array[$key]) && trim((string) $array[$key]) !== '') {
            return $array[$key];
        }
    }
    return $default;
}

function wbf_is_list_array($array) {
    if (!is_array($array)) return false;
    $i = 0;
    foreach (array_keys($array) as $key) {
        if ($key !== $i) return false;
        $i++;
    }
    return true;
}

function wbf_safe_url($url, $default = '#') {
    $url = trim((string) $url);
    if ($url === '') return $default;
    if (preg_match('/^https?:\/\//i', $url)) return $url;
    return $default;
}

function wbf_origin($url) {
    $parts = parse_url((string) $url);
    if (empty($parts['scheme']) || empty($parts['host'])) return '';
    return $parts['scheme'] . '://' . $parts['host'];
}

function wbf_join_url($url, $baseUrl) {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (preg_match('/^https?:\/\//i', $url)) return $url;
    if (strpos($url, '//') === 0) return 'https:' . $url;
    $origin = wbf_origin($baseUrl);
    if ($origin === '') return '';
    return $origin . '/' . ltrim($url, '/');
}

function wbf_domain($url) {
    $host = parse_url((string) $url, PHP_URL_HOST);
    $host = $host ? preg_replace('/^www\./i', '', $host) : '';
    return $host === '' ? '未知站点' : $host;
}

function wbf_clean_summary($value, $length = 68) {
    $text = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    if ($text === '') return '这篇文章暂时没有摘要，点进去看看作者最近写了什么。';
    if (wbf_mb_len($text) > $length) return wbf_mb_substr($text, 0, $length) . '...';
    return $text;
}

function wbf_time_value($value) {
    if (is_numeric($value)) return intval($value);
    $value = trim((string) $value);
    if ($value === '') return 0;
    $time = strtotime($value);
    return $time ? $time : 0;
}

function wbf_date_label($timestamp) {
    $timestamp = intval($timestamp);
    if ($timestamp <= 0) return '';
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $day = date('Y-m-d', $timestamp);
    if ($day === $today) return '今天 ' . date('H:i', $timestamp);
    if ($day === $yesterday) return '昨天 ' . date('H:i', $timestamp);
    return date('m-d H:i', $timestamp);
}

function wbf_day_badge($timestamp) {
    $timestamp = intval($timestamp);
    if ($timestamp <= 0) return array('', '');
    $day = date('Y-m-d', $timestamp);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $beforeYesterday = date('Y-m-d', strtotime('-2 days'));
    if ($day === $today) return array('今天', 'today');
    if ($day === $yesterday) return array('昨天', 'yesterday');
    if ($day === $beforeYesterday) return array('前天', 'before-yesterday');
    return array('', '');
}

function wbf_page_url($baseUrl, $page) {
    $page = max(1, intval($page));
    if ($page <= 1) return $baseUrl;
    return $baseUrl . (strpos($baseUrl, '?') === false ? '?' : '&') . 'rss_page=' . $page;
}

function wbf_pick_list($data) {
    if (!is_array($data)) return array();
    if (wbf_is_list_array($data)) return $data;
    foreach (array('articles', 'items', 'data', 'list') as $key) {
        if (isset($data[$key]) && is_array($data[$key])) return $data[$key];
    }
    return array();
}

/**
 * 把「指向本站 uploads 的 URL」映射成本地文件路径。
 *
 * 为什么要这个（1.3.8，2026-10-23）：
 *   博友圈的数据本来就在本地磁盘上 —— rss-sync.php 从 FreshRSS 拉完，
 *   直接写进 uploads/riven-rss/output.json。但本模板原来是拿这个文件的
 *   http 地址去 wp_remote_get，等于让服务器**通过公网请求自己**。
 *   只要这台机器访问不了自己的域名（hairpin NAT、防火墙、WAF、换 IP、上 CDN
 *   都会），页面就报 cURL error 28，一条数据都读不出来。
 *   文件就在手边，没必要绕这一圈网络。
 *
 *   注意：只认「本站 uploads 目录下」的地址。站长在设置里填了别人家的 JSON
 *   地址时，这里返回空串，照旧走 HTTP。
 *
 * @param string $url 可能是本站的 URL。
 * @return string 本地绝对路径；不是本站 uploads 下的文件则返回空串。
 */
function wbf_local_path_for_url($url) {
    $url = trim((string) $url);
    if ($url === '') return '';

    $uploads = wp_upload_dir();
    if (empty($uploads['baseurl']) || empty($uploads['basedir'])) return '';

    // 归一化：去协议、去 www、去 query，只比路径
    $norm = function ($u) {
        $u = (string) strtok((string) $u, '?');
        $u = preg_replace('#^https?://#i', '', $u);
        $u = preg_replace('#^www\.#i', '', $u);
        return rtrim($u, '/');
    };

    $url_path  = $norm($url);
    $base_path = $norm($uploads['baseurl']);
    if ($base_path === '' || strpos($url_path, $base_path . '/') !== 0) {
        return '';
    }

    $rel  = substr($url_path, strlen($base_path));
    $file = rtrim($uploads['basedir'], '/') . $rel;

    // 防目录穿越：真实路径必须仍然落在 uploads 里
    $real = realpath($file);
    $root = realpath($uploads['basedir']);
    if ($real === false || $root === false) return '';
    if (strpos($real, rtrim($root, '/') . '/') !== 0) return '';

    return $real;
}

function wbf_fetch_from_json($jsonUrl, $limit = 200) {
    $jsonUrl = trim((string) $jsonUrl);
    $limit = max(1, min(500, intval($limit)));

    if ($jsonUrl === '') {
        return array('articles' => array(), 'subscription_count' => 0, 'sync_time' => 0, 'error' => '请在主题设置中配置 RSS JSON 地址（rss_json_url）。');
    }

    $body = '';

    /*
     * ① 本站 uploads 下的文件 → 直接读磁盘，不经过网络。
     *
     * 这条分支是 1.3.8 加的。原来只有 ②，于是「服务器访问不了自己域名」的机器
     * 上一片空白（laozhang.org 2026-10-23 就是这样：数据 218KB 好端端躺在磁盘上，
     * 页面却报 cURL error 28）。
     */
    $local_path = wbf_local_path_for_url($jsonUrl);
    if ($local_path !== '' && is_file($local_path) && is_readable($local_path)) {
        $body = (string) @file_get_contents($local_path); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    // ② 本地没有（或配置的是外部地址）→ 照旧走 HTTP
    if ($body === '') {
        $response = wp_remote_get($jsonUrl, array('timeout' => 15));
        if (is_wp_error($response)) {
            return array('articles' => array(), 'subscription_count' => 0, 'sync_time' => 0, 'error' => 'JSON 地址读取失败：' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
    }

    if (empty($body)) {
        return array('articles' => array(), 'subscription_count' => 0, 'sync_time' => 0, 'error' => 'JSON 内容为空。');
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return array('articles' => array(), 'subscription_count' => 0, 'sync_time' => 0, 'error' => 'JSON 解析失败，请确认返回内容是合法 JSON。');
    }

    $list = wbf_pick_list($data);
    $formatted = array();
    $feedKeys = array();

    foreach ($list as $item) {
        if (!is_array($item)) continue;

        $origin = isset($item['origin']) && is_array($item['origin']) ? $item['origin'] : array();
        $summary = '';

        if (isset($item['description'])) $summary = $item['description'];
        elseif (isset($item['summary']) && is_string($item['summary'])) $summary = $item['summary'];
        elseif (isset($item['summary']['content'])) $summary = $item['summary']['content'];
        elseif (isset($item['content']['content'])) $summary = $item['content']['content'];
        elseif (isset($item['content'])) $summary = $item['content'];

        $link = wbf_array_get($item, array('link', 'url', 'href', 'permalink'), '#');
        if ($link === '#' && isset($item['alternate'][0]['href'])) $link = $item['alternate'][0]['href'];
        $link = wbf_safe_url($link);

        $siteName = wbf_text(wbf_array_get($item, array('site_name', 'siteName', 'feed_title', 'feedTitle', 'source', 'blog_name', 'blogName')));
        if ($siteName === '' && isset($origin['title'])) $siteName = wbf_text($origin['title']);
        if ($siteName === '') $siteName = wbf_domain($link);

        $domain = wbf_text(wbf_array_get($item, array('site_domain', 'domain', 'host')));
        if ($domain === '') $domain = wbf_domain($link);

        $title = wbf_text(wbf_array_get($item, array('title', 'name'), '未命名文章'), '未命名文章');

        $timeRaw = wbf_array_get($item, array('published', 'time', 'date', 'created', 'created_at', 'published_at', 'updated', 'updated_at'), 0);
        $timestamp = wbf_time_value($timeRaw);

        $icon = wbf_array_get($item, array('icon', 'iconUrl', 'icon_url', 'favicon', 'avatar', 'image', 'logo'), '');
        $icon = wbf_join_url($icon, $jsonUrl);

        $feedKey = $siteName !== '' ? $siteName : $domain;
        if ($feedKey !== '') $feedKeys[$feedKey] = true;

        $formatted[] = array(
            'site_name'   => $siteName,
            'site_domain' => $domain,
            'title'       => $title,
            'link'        => $link,
            'time'        => $timestamp,
            'time_label'  => wbf_date_label($timestamp),
            'day_badge'   => wbf_day_badge($timestamp),
            'description' => wbf_clean_summary($summary),
            'icon'        => $icon,
        );
    }

    usort($formatted, function ($a, $b) {
        return intval($b['time']) - intval($a['time']);
    });

    if (count($formatted) > $limit) {
        $formatted = array_slice($formatted, 0, $limit);
    }

    /*
     * 订阅清单是事实，subscription_count 只是同步那一刻写下的快照。
     * 老数据里这个字段存的还是「本次有文章的站数」（80），拿它当准数会一直显示错，
     * 所以有数组就按数组长度算，字段只在没有数组时兜底。
     */
    $subscriptionCount = 0;
    if (isset($data['subscriptions']) && is_array($data['subscriptions']) && $data['subscriptions']) {
        $subscriptionCount = count($data['subscriptions']);
    } elseif (isset($data['subscription_count'])) {
        $subscriptionCount = intval($data['subscription_count']);
    } elseif (isset($data['subscriptionCount'])) {
        $subscriptionCount = intval($data['subscriptionCount']);
    }
    if ($subscriptionCount <= 0) $subscriptionCount = count($feedKeys);

    return array(
        'articles' => $formatted,
        'subscription_count' => $subscriptionCount,
        'sync_time' => wbf_time_value(wbf_array_get($data, array('last_sync_started_at'), 0)),
        'error' => '',
    );
}
