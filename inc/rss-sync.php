<?php
/**
 * FreshRSS 博友动态同步
 * 从 FreshRSS API 拉取订阅文章，保存为 JSON 文件供模板读取
 */

if (!defined('ABSPATH')) exit;

/**
 * 从 FreshRSS 拉取数据并保存为 JSON
 * @param string $apiBase  FreshRSS API 地址
 * @param string $user     用户名
 * @param string $password API 密码
 * @return array 结果 ['success' => bool, 'message' => string, 'count' => int]
 */
function wbf_sync_run($apiBase, $user, $password) {
    $apiBase = rtrim(trim($apiBase), '/');
    if ($apiBase === '' || $user === '' || $password === '') {
        return array('success' => false, 'message' => 'FreshRSS API 地址、用户名或密码未配置。', 'count' => 0);
    }

    // 1. 登录获取 token
    $authToken = wbf_get_token($apiBase, $user, $password);
    if (!$authToken) {
        return array('success' => false, 'message' => 'FreshRSS 登录失败，请检查 API 地址和用户名密码。', 'count' => 0);
    }

    // 2. 获取文章
    $articles = wbf_get_articles($apiBase, $authToken);
    if ($articles === false) {
        return array('success' => false, 'message' => '获取文章列表失败。', 'count' => 0);
    }

    // 3. 获取订阅列表
    $subscriptions = wbf_get_subscriptions($apiBase, $authToken);
    if ($subscriptions === false) {
        return array('success' => false, 'message' => '获取订阅列表失败。', 'count' => 0);
    }

    // 4. 格式化
    $formatResult = wbf_format_articles($articles, $subscriptions, $apiBase);
    $formatted = $formatResult['articles'];

    // 5. 保存 JSON（包装成带元数据的对象）
    $jsonPath = wbf_json_path();
    $subsCompact = wbf_compact_subs($subscriptions);
    $output = array(
        'articles' => $formatted,
        // 这里必须是「订阅了几个站」。以前误写成 $formatResult['feed_count']
        // （本次有文章回来的 feed 数），前台博友圈就一直是「订阅 80 个」这种错数字。
        'subscription_count' => count($subsCompact),
        'last_sync_started_at' => current_time('timestamp'),
        // 订阅清单：互访对账要用它（站名 + 站址）。以前只存了个数量，列表扔掉了。
        'subscriptions' => $subsCompact,
    );
    $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return array('success' => false, 'message' => 'JSON 编码失败: ' . json_last_error_msg(), 'count' => 0);
    }

    $result = file_put_contents($jsonPath, $json);
    if ($result === false) {
        return array('success' => false, 'message' => '无法写入 JSON 文件: ' . $jsonPath, 'count' => 0);
    }

    // 更新同步时间
    update_option('riven_rss_last_sync', current_time('timestamp'));

    return array('success' => true, 'message' => '同步成功', 'count' => count($formatted));
}

/**
 * 获取 JSON 文件路径
 */
function wbf_json_path() {
    $upload_dir = wp_upload_dir();
    $dir = $upload_dir['basedir'] . '/riven-rss';
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    return $dir . '/output.json';
}

/**
 * 获取 JSON 文件 URL
 */
function wbf_json_url() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['baseurl'] . '/riven-rss/output.json';
}

/**
 * FreshRSS: 获取认证令牌
 */
function wbf_get_token($apiBase, $user, $password) {
    $loginUrl = $apiBase . '/accounts/ClientLogin';
    $postData = array(
        'Email' => $user,
        'Passwd' => $password,
        'service' => 'reader',
        'accountType' => 'HOSTED_OR_GOOGLE',
        'source' => 'riven-wp-theme'
    );

    $response = wp_remote_post($loginUrl, array(
        'timeout' => 15,
        'body' => $postData,
        'headers' => array(
            'Content-Type' => 'application/x-www-form-urlencoded',
        ),
    ));

    if (is_wp_error($response)) return false;

    $body = wp_remote_retrieve_body($response);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200) return false;
    if (strpos($body, 'Auth=') === false) return false;

    return trim(substr($body, strpos($body, 'Auth=') + 5));
}

/**
 * FreshRSS: 获取文章列表
 */
function wbf_get_articles($apiBase, $authToken) {
    $url = $apiBase . '/reader/api/0/stream/contents/reading-list?n=1000';

    $response = wp_remote_get($url, array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'GoogleLogin auth=' . $authToken,
            'Accept' => 'application/json',
        ),
    ));

    if (is_wp_error($response)) return false;

    $body = wp_remote_retrieve_body($response);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200) return false;

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['items'])) return false;

    return $data['items'];
}

/**
 * 把 FreshRSS 的订阅对象精简成对账要用的三个字段。
 *
 * FreshRSS 原始结构里还有 categories、id 等一大堆，全存进去 json 会翻好几倍，
 * 而对账只需要「站名 + 站点地址 + feed 地址」。
 *
 * @param array $subs FreshRSS 的 subscriptions 数组。
 * @return array
 */
function wbf_compact_subs($subs) {
    $out = array();
    foreach ((array) $subs as $s) {
        if (!is_array($s)) continue;
        $site = isset($s['htmlUrl']) ? trim((string) $s['htmlUrl']) : '';
        $feed = isset($s['url']) ? trim((string) $s['url']) : '';
        if ($site === '' && $feed === '') continue;
        $out[] = array(
            'title' => isset($s['title']) ? trim(wp_strip_all_tags((string) $s['title'])) : '',
            'site'  => $site,
            'feed'  => $feed,
        );
    }
    return $out;
}

/**
 * FreshRSS: 获取订阅列表
 */
function wbf_get_subscriptions($apiBase, $authToken) {
    $url = $apiBase . '/reader/api/0/subscription/list?output=json';

    $response = wp_remote_get($url, array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'GoogleLogin auth=' . $authToken,
            'Accept' => 'application/json',
        ),
    ));

    if (is_wp_error($response)) return false;

    $body = wp_remote_retrieve_body($response);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200) return false;

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['subscriptions'])) return false;

    return $data['subscriptions'];
}

/**
 * 格式化文章数据
 */
function wbf_format_articles($articles, $subscriptions, $apiBase) {
    $subMap = array();
    foreach ($subscriptions as $sub) {
        if (isset($sub['id'])) {
            $subMap[$sub['id']] = $sub;
        }
    }

    $formatted = array();
    $feedKeys = array();
    foreach ($articles as $article) {
        if (!isset($article['summary']['content'], $article['title'],
                   $article['origin']['title'], $article['alternate'][0]['href'],
                   $article['published'])) {
            continue;
        }

        $content = strip_tags(html_entity_decode($article['summary']['content'], ENT_QUOTES, 'UTF-8'));
        $descLen = mb_strlen($content, 'UTF-8');
        $shortDesc = $descLen > 99 ? mb_substr($content, 0, 99, 'UTF-8') . '...' : $content;

        $item = array(
            'site_name'   => $article['origin']['title'],
            'title'       => $article['title'],
            'link'        => $article['alternate'][0]['href'],
            'time'        => date('Y-m-d H:i', $article['published']),
            'description' => $shortDesc,
        );

        /*
         * 文章属于哪个站（归一化域名）。
         *
         * 优先用订阅对象里的 htmlUrl —— 站点的正经地址，比文章 URL 可靠：
         * 有的站文章在 blog.xxx.com 而订阅的是 xxx.com，按文章 URL 取会取到子域，
         * 跟评论表里的裸域对不上。取不到才退回文章链接。
         */
        $item['host'] = '';
        if (isset($article['origin']['streamId'])) {
            $sid = $article['origin']['streamId'];
            if (isset($subMap[$sid]['htmlUrl'])) {
                $item['host'] = wbf_norm_host($subMap[$sid]['htmlUrl']);
            }
        }
        if ('' === $item['host']) {
            $item['host'] = wbf_norm_host($item['link']);
        }

        // 图标
        if (isset($article['origin']['streamId'])) {
            $sid = $article['origin']['streamId'];
            if (isset($subMap[$sid]['iconUrl'])) {
                $iconUrl = $subMap[$sid]['iconUrl'];
                // iconUrl 是相对路径如 /f.php?h=xxx，拼上 FreshRSS 域名
                if (strpos($iconUrl, 'http') !== 0) {
                    $parsed = parse_url($apiBase);
                    if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
                        $iconUrl = $parsed['scheme'] . '://' . $parsed['host'] . $iconUrl;
                    }
                }
                $item['icon'] = $iconUrl;
            }
        }

        $formatted[] = $item;
        $feedKeys[$item['site_name']] = true;
    }

    usort($formatted, function ($a, $b) {
        return strtotime($b['time']) - strtotime($a['time']);
    });

    // 只保留近1个月，且不超过500篇
    $oneMonthAgo = strtotime('-1 month');
    $filtered = array();
    foreach ($formatted as $item) {
        if (strtotime($item['time']) >= $oneMonthAgo) {
            $filtered[] = $item;
        }
    }
    if (count($filtered) > 500) {
        $filtered = array_slice($filtered, 0, 500);
    }

    return array('articles' => $filtered, 'feed_count' => count($feedKeys));
}
