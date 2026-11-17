<?php
/**
 * Turns a referrer, campaign tags and a user agent into report dimensions.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Analytics;

defined( 'ABSPATH' ) || exit;

final class Classifier {
	/** host fragment => engine label. */
	const ENGINES = array(
		'google.' => 'Google', 'bing.com' => 'Bing', 'yahoo.' => 'Yahoo', 'duckduckgo.com' => 'DuckDuckGo', 'yandex.' => 'Yandex',
		'baidu.com' => 'Baidu', 'ecosia.org' => 'Ecosia', 'search.brave.com' => 'Brave', 'naver.com' => 'Naver', 'seznam.cz' => 'Seznam',
		'qwant.com' => 'Qwant', 'startpage.com' => 'Startpage', 'sogou.com' => 'Sogou', 'yep.com' => 'Yep',
	);
	const SOCIAL = array(
		'facebook.' => 'Facebook', 'fb.me' => 'Facebook', 'l.facebook.com' => 'Facebook', 'instagram.com' => 'Instagram', 't.co' => 'X (Twitter)',
		'twitter.com' => 'X (Twitter)', 'x.com' => 'X (Twitter)', 'linkedin.com' => 'LinkedIn', 'lnkd.in' => 'LinkedIn', 'pinterest.' => 'Pinterest',
		'reddit.com' => 'Reddit', 'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube', 'tiktok.com' => 'TikTok', 'whatsapp.com' => 'WhatsApp',
		'wa.me' => 'WhatsApp', 'telegram.' => 'Telegram', 't.me' => 'Telegram', 'threads.net' => 'Threads', 'mastodon.' => 'Mastodon',
		'bsky.app' => 'Bluesky', 'quora.com' => 'Quora', 'news.ycombinator.com' => 'Hacker News', 'vk.com' => 'VK',
	);
	const AI = array(
		'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'perplexity.ai' => 'Perplexity', 'gemini.google.com' => 'Gemini',
		'copilot.microsoft.com' => 'Copilot', 'claude.ai' => 'Claude', 'you.com' => 'You.com', 'phind.com' => 'Phind', 'meta.ai' => 'Meta AI',
		'chat.deepseek.com' => 'DeepSeek', 'grok.com' => 'Grok', 'poe.com' => 'Poe', 'mistral.ai' => 'Mistral',
	);
	const MAIL = array( 'mail.google.com', 'outlook.', 'mail.yahoo.', 'webmail', 'mail.', 'proton.me' );

	/**
	 * @return array{source:string,ref:string,engine:string}
	 */
	public static function source( $referrer, $utm_source = '', $utm_medium = '' ) {
		$medium = strtolower( (string) $utm_medium );
		$usrc   = strtolower( (string) $utm_source );
		$host   = strtolower( (string) wp_parse_url( (string) $referrer, PHP_URL_HOST ) );
		$host   = preg_replace( '/^www\./', '', $host );
		$own    = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		if ( in_array( $medium, array( 'email', 'e-mail', 'newsletter' ), true ) ) {
			return array( 'source' => 'email', 'ref' => $usrc ? $usrc : $host, 'engine' => '' );
		}
		if ( in_array( $medium, array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_social', 'display' ), true ) ) {
			return array( 'source' => 'paid', 'ref' => $usrc ? $usrc : $host, 'engine' => '' );
		}
		if ( in_array( $medium, array( 'social', 'social-network', 'sm' ), true ) ) {
			return array( 'source' => 'social', 'ref' => $usrc ? $usrc : $host, 'engine' => '' );
		}
		if ( '' === $host || $host === $own ) {
			if ( '' !== $usrc ) {
				foreach ( self::AI as $frag => $label ) {
					if ( false !== strpos( $usrc, strtok( $frag, '.' ) ) ) {
						return array( 'source' => 'ai', 'ref' => $label, 'engine' => '' );
					}
				}
				return array( 'source' => 'referral', 'ref' => $usrc, 'engine' => '' );
			}
			return array( 'source' => 'direct', 'ref' => '', 'engine' => '' );
		}
		foreach ( self::AI as $frag => $label ) {
			if ( self::match( $host, $frag ) ) {
				return array( 'source' => 'ai', 'ref' => $label, 'engine' => '' );
			}
		}
		foreach ( self::ENGINES as $frag => $label ) {
			if ( self::match( $host, $frag ) && false === strpos( $host, 'mail.' ) ) {
				return array( 'source' => 'organic', 'ref' => $host, 'engine' => $label );
			}
		}
		foreach ( self::SOCIAL as $frag => $label ) {
			if ( self::match( $host, $frag ) ) {
				return array( 'source' => 'social', 'ref' => $label, 'engine' => '' );
			}
		}
		foreach ( self::MAIL as $frag ) {
			if ( false !== strpos( $host, $frag ) ) {
				return array( 'source' => 'email', 'ref' => $host, 'engine' => '' );
			}
		}
		return array( 'source' => 'referral', 'ref' => $host, 'engine' => '' );
	}

	private static function match( $host, $frag ) {
		if ( '.' === substr( $frag, -1 ) ) {
			return 0 === strpos( $host, $frag ) || false !== strpos( $host, '.' . $frag );
		}
		return $host === $frag || ( strlen( $host ) > strlen( $frag ) && '.' . $frag === substr( $host, -strlen( $frag ) - 1 ) );
	}

	/** Labels for traffic sources, in display order. @return array<string,string> */
	public static function source_labels() {
		return array(
			'organic'  => __( 'Organic search', 'publishrank' ),
			'direct'   => __( 'Direct', 'publishrank' ),
			'referral' => __( 'Referrals', 'publishrank' ),
			'social'   => __( 'Social media', 'publishrank' ),
			'ai'       => __( 'AI assistants', 'publishrank' ),
			'email'    => __( 'Email', 'publishrank' ),
			'paid'     => __( 'Paid ads', 'publishrank' ),
		);
	}

	/** Known crawlers, previews and tools that should never count as visitors. */
	public static function is_bot( $ua ) {
		$ua = strtolower( (string) $ua );
		if ( '' === $ua || strlen( $ua ) < 20 ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|mediapartners|preview|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|python|curl|wget|httpclient|okhttp|java\/|go-http|axios|node-fetch|scrapy|phantom|facebookexternalhit|embedly|quora link|whatsapp|discord|telegrambot|semrush|ahrefs|mj12|dotbot|petal|bytespider|gptbot|ccbot|claude|perplexity|amazonbot|applebot/i', $ua );
	}

	/** @return array{device:string,browser:string,os:string} */
	public static function agent( $ua, $width = 0 ) {
		$u      = (string) $ua;
		$width  = (int) $width;
		$tablet = (bool) preg_match( '/iPad|Tablet|Nexus (7|9|10)|SM-T|Kindle|Silk|PlayBook|(Android(?!.*Mobile))/i', $u );
		$mobile = ! $tablet && (bool) preg_match( '/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini/i', $u );
		if ( ! $tablet && ! $mobile && $width > 0 && $width < 768 ) {
			$mobile = true;
		}
		$device = $tablet ? 'tablet' : ( $mobile ? 'mobile' : 'desktop' );
		$browser = 'Other';
		foreach ( array( 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'FxiOS' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari' ) as $needle => $label ) {
			if ( false !== strpos( $u, $needle ) ) {
				$browser = $label;
				break;
			}
		}
		$os = 'Other';
		foreach ( array( 'Windows' => 'Windows', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Android' => 'Android', 'Mac OS X' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux' ) as $needle => $label ) {
			if ( false !== strpos( $u, $needle ) ) {
				$os = $label;
				break;
			}
		}
		return array( 'device' => $device, 'browser' => $browser, 'os' => $os );
	}
}
