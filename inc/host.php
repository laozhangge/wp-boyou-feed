<?php
/**
 * 域名工具：归一化、第三方平台识别。
 *
 * 两个模块都要用（同步写数据时标 host、对账与未订阅判断时比 host），
 * 所以单独放一个文件，别在 reconcile.php 里定义 —— 那个文件加载得晚，
 * rss-sync 跑同步时可能还没加载到。
 *
 * @package wp-boyou-feed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 域名归一化：小写、去协议、去 www.、去端口和末尾的点。
 *
 * 只做这些，不合并子域 —— blog.xxx.com 和 xxx.com 可能是两个完全不同的站，
 * 合并了反而会冤枉人。
 *
 * @param string $url 网址或域名。
 * @return string
 */
function wbf_norm_host( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	if ( false === strpos( $url, '//' ) ) {
		$url = 'http://' . $url;
	}

	$host = parse_url( $url, PHP_URL_HOST );
	if ( ! $host ) {
		$host = preg_replace( '#^https?://#i', '', $url );
		$host = preg_split( '#[/:?\#]#', (string) $host );
		$host = isset( $host[0] ) ? $host[0] : '';
	}

	$host = strtolower( trim( (string) $host, ". \t\n\r\0\x0B" ) );
	$host = preg_replace( '#^www\.#', '', $host );

	return $host;
}

/**
 * 这个域名是不是「根本没法订 RSS」的地方。
 *
 * 评论者填网址时有一大批填的是自己的 GitHub、微博、知乎主页 —— 这些不是博客，
 * 没有 feed 可订，标成「未订阅」只会让人白点一次。所以先排除掉。
 *
 * 注意别把 cnblogs.com、medium.com 这类真有 feed 的塞进来。
 *
 * @param string $host 归一化后的域名。
 * @return bool
 */
function wbf_is_thirdparty_host( $host ) {
	$host = (string) $host;
	if ( '' === $host ) {
		return true;
	}

	$exact = array(
		'github.com', 'gitee.com', 'gitlab.com', 'bitbucket.org',
		'weibo.com', 'weibo.cn', 't.cn',
		'zhihu.com', 'zhuanlan.zhihu.com',
		'twitter.com', 'x.com', 'facebook.com', 'fb.com',
		'instagram.com', 'linkedin.com', 't.me', 'telegram.org',
		'bilibili.com', 'douban.com', 'xiaohongshu.com',
		'youtube.com', 'youtu.be', 'tiktok.com', 'douyin.com',
		'qq.com', '163.com', '126.com', 'sina.com.cn', 'sohu.com',
		'baidu.com', 'taobao.com', 'jd.com', 'aliyun.com',
		'steamcommunity.com', 'gmail.com', 'outlook.com', 'hotmail.com',
	);

	if ( in_array( $host, $exact, true ) ) {
		return true;
	}

	return false;
}
