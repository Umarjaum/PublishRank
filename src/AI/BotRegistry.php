<?php
/**
 * Known crawlers: AI training, AI search, AI assistants, classic search engines and SEO tools,
 * with how each operator lets publishers verify them (published IP lists or forward-confirmed
 * reverse DNS). Shared by AI Crawler Control, the AI visit log and the 404 monitor.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AI;

defined( 'ABSPATH' ) || exit;

final class BotRegistry {
	const UNVERIFIED  = 0; // Claimed the user agent but failed verification (spoofed).
	const VERIFIED    = 1;
	const PENDING     = 2; // Reverse-DNS check queued.
	const UNCHECKABLE = 3; // Operator publishes no verification method, or verification is turned off.

	/** @var array|null */
	private static $cache = null;
	/** @var string|null */
	private static $regex = null;

	/**
	 * @return array<string,array{operator:string,purpose:string,ua:string,ips:string,rdns:string[],robots_only:bool,docs:string}>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$g   = 'https://developers.google.com/static/crawling/ipranges/';
		$def = array(
			// AI training.
			'GPTBot'                => array( 'OpenAI', 'training', 'GPTBot', 'https://openai.com/gptbot.json', array(), 'https://platform.openai.com/docs/bots' ),
			'ClaudeBot'             => array( 'Anthropic', 'training', 'ClaudeBot', '', array(), 'https://support.anthropic.com/en/articles/8896518' ),
			'anthropic-ai'          => array( 'Anthropic', 'training', 'anthropic-ai', '', array(), '' ),
			'Google-Extended'       => array( 'Google', 'training', '', '', array(), 'https://developers.google.com/search/docs/crawling-indexing/google-common-crawlers#google-extended' ),
			'Applebot-Extended'     => array( 'Apple', 'training', '', '', array(), 'https://support.apple.com/en-us/119829' ),
			'CCBot'                 => array( 'Common Crawl', 'training', 'CCBot', 'https://index.commoncrawl.org/ccbot.json', array( '.crawl.commoncrawl.org' ), 'https://commoncrawl.org/ccbot' ),
			'Bytespider'            => array( 'ByteDance', 'training', 'Bytespider', '', array(), '' ),
			'meta-externalagent'    => array( 'Meta', 'training', 'meta-externalagent', '', array(), 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers' ),
			'FacebookBot'           => array( 'Meta', 'training', 'FacebookBot', '', array(), '' ),
			'cohere-ai'             => array( 'Cohere', 'training', 'cohere-ai', '', array(), '' ),
			'cohere-training-data-crawler' => array( 'Cohere', 'training', 'cohere-training-data-crawler', '', array(), '' ),
			'Amazonbot'             => array( 'Amazon', 'training', 'Amazonbot', '', array( '.crawl.amazonbot.amazon' ), 'https://developer.amazon.com/amazonbot' ),
			'Omgilibot'             => array( 'Webz.io', 'training', 'omgili', '', array(), '' ),
			'Diffbot'               => array( 'Diffbot', 'training', 'Diffbot', '', array(), '' ),
			'ImagesiftBot'          => array( 'Imagesift', 'training', 'ImagesiftBot', '', array(), '' ),
			'Timpibot'              => array( 'Timpi', 'training', 'Timpibot', '', array(), '' ),
			'AI2Bot'                => array( 'Allen Institute for AI', 'training', 'AI2Bot', '', array(), '' ),
			'PanguBot'              => array( 'Huawei', 'training', 'PanguBot', '', array(), '' ),
			// AI search.
			'OAI-SearchBot'         => array( 'OpenAI', 'search', 'OAI-SearchBot', 'https://openai.com/searchbot.json', array(), 'https://platform.openai.com/docs/bots' ),
			'Claude-SearchBot'      => array( 'Anthropic', 'search', 'Claude-SearchBot', '', array(), '' ),
			'PerplexityBot'         => array( 'Perplexity', 'search', 'PerplexityBot', 'https://www.perplexity.ai/perplexitybot.json', array(), 'https://docs.perplexity.ai/guides/bots' ),
			'DuckAssistBot'         => array( 'DuckDuckGo', 'search', 'DuckAssistBot', 'https://duckduckgo.com/duckassistbot.json', array(), 'https://duckduckgo.com/duckduckgo-help-pages/results/duckassistbot' ),
			'YouBot'                => array( 'You.com', 'search', 'YouBot', '', array(), '' ),
			// Assistants fetching on a user's request.
			'ChatGPT-User'          => array( 'OpenAI', 'assistant', 'ChatGPT-User', 'https://openai.com/chatgpt-user.json', array(), 'https://platform.openai.com/docs/bots' ),
			'Claude-User'           => array( 'Anthropic', 'assistant', 'Claude-User', '', array(), '' ),
			'Perplexity-User'       => array( 'Perplexity', 'assistant', 'Perplexity-User', 'https://www.perplexity.ai/perplexity-user.json', array(), '' ),
			'MistralAI-User'        => array( 'Mistral', 'assistant', 'MistralAI-User', 'https://mistral.ai/mistralai-user-ips.json', array(), '' ),
			'meta-externalfetcher'  => array( 'Meta', 'assistant', 'meta-externalfetcher', '', array(), '' ),
			'Google-User'           => array( 'Google', 'assistant', 'Google-(?:User|NotebookLM)', $g . 'user-triggered-fetchers-google.json', array( '.google.com', '.googleusercontent.com' ), '' ),
			// Search engines (classification and verification; not AI-controlled by default).
			'Googlebot'             => array( 'Google', 'searchengine', 'Googlebot|Google-InspectionTool|GoogleOther|Storebot-Google', $g . 'common-crawlers.json', array( '.googlebot.com', '.google.com', '.googleusercontent.com' ), '' ),
			'bingbot'               => array( 'Microsoft', 'searchengine', 'bingbot|BingPreview|msnbot', 'https://www.bing.com/toolbox/bingbot.json', array( '.search.msn.com' ), '' ),
			'Applebot'              => array( 'Apple', 'searchengine', 'Applebot', 'https://search.developer.apple.com/applebot.json', array( '.applebot.apple.com' ), '' ),
			'DuckDuckBot'           => array( 'DuckDuckGo', 'searchengine', 'DuckDuckBot', '', array(), '' ),
			'YandexBot'             => array( 'Yandex', 'searchengine', 'YandexBot|YandexImages|YandexNews', '', array( '.yandex.ru', '.yandex.net', '.yandex.com' ), '' ),
			'Baiduspider'           => array( 'Baidu', 'searchengine', 'Baiduspider', '', array( '.baidu.com', '.baidu.jp' ), '' ),
			'PetalBot'              => array( 'Huawei', 'searchengine', 'PetalBot', '', array( '.petalsearch.com' ), '' ),
			// SEO tools.
			'AhrefsBot'             => array( 'Ahrefs', 'seo', 'AhrefsBot|AhrefsSiteAudit', '', array( '.ahrefs.com', '.ahrefs.net' ), '' ),
			'SemrushBot'            => array( 'Semrush', 'seo', 'SemrushBot|SiteAuditBot', '', array( '.semrush.com' ), '' ),
			'MJ12bot'               => array( 'Majestic', 'seo', 'MJ12bot', '', array(), '' ),
			'DotBot'                => array( 'Moz', 'seo', 'DotBot|rogerbot', '', array(), '' ),
			'DataForSeoBot'         => array( 'DataForSEO', 'seo', 'DataForSeoBot', '', array(), '' ),
			'BLEXBot'               => array( 'WebMeUp', 'seo', 'BLEXBot', '', array(), '' ),
		);
		$out = array();
		foreach ( $def as $token => $d ) {
			$out[ $token ] = array(
				'operator'    => $d[0],
				'purpose'     => $d[1],
				'ua'          => $d[2],
				'ips'         => $d[3],
				'rdns'        => $d[4],
				'robots_only' => '' === $d[2],
				'docs'        => $d[5],
			);
		}
		self::$cache = (array) apply_filters( 'publishrank_ai_bot_registry', $out );
		return self::$cache;
	}

	/** @return string[] Tokens whose purpose is AI (training, search, assistant). */
	public static function ai_tokens() {
		return array_keys( array_filter( self::all(), static function ( $b ) {
			return in_array( $b['purpose'], array( 'training', 'search', 'assistant' ), true );
		} ) );
	}

	public static function get( $token ) {
		$all = self::all();
		return isset( $all[ $token ] ) ? $all[ $token ] : null;
	}

	public static function purposes() {
		return array(
			'training'     => __( 'AI training', 'publishrank' ),
			'search'       => __( 'AI search', 'publishrank' ),
			'assistant'    => __( 'AI assistant (user request)', 'publishrank' ),
			'searchengine' => __( 'Search engine', 'publishrank' ),
			'seo'          => __( 'SEO tool', 'publishrank' ),
		);
	}

	/** Identify the crawler from a user-agent string. @return string Token or ''. */
	public static function identify( $ua ) {
		$ua = (string) $ua;
		if ( '' === $ua || strlen( $ua ) > 1024 ) {
			return '';
		}
		if ( null === self::$regex ) {
			$parts = array();
			foreach ( self::all() as $token => $b ) {
				if ( '' !== $b['ua'] ) {
					// Longer, more specific tokens first (ChatGPT-User before GPT…, Claude-User before ClaudeBot).
					$parts[ $token ] = $b['ua'];
				}
			}
			uksort( $parts, static function ( $a, $b ) use ( $parts ) {
				return strlen( $parts[ $b ] ) - strlen( $parts[ $a ] );
			} );
			$named = array();
			$i     = 0;
			$map   = array();
			foreach ( $parts as $token => $re ) {
				$named[]          = '(?P<b' . $i . '>' . $re . ')';
				$map[ 'b' . $i ] = $token;
				++$i;
			}
			self::$regex = array( '~(?:' . implode( '|', $named ) . ')~i', $map );
		}
		if ( ! preg_match( self::$regex[0], $ua, $m ) ) {
			return '';
		}
		foreach ( self::$regex[1] as $group => $token ) {
			if ( isset( $m[ $group ] ) && '' !== $m[ $group ] ) {
				return $token;
			}
		}
		return '';
	}

	public static function current_ua() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 1024 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pattern-matched only.
	}

	/** Cached result for this request. */
	public static function current() {
		static $token = null;
		if ( null === $token ) {
			$token = self::identify( self::current_ua() );
		}
		return $token;
	}
}
