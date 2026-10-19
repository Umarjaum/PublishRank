<?php
/**
 * Virtual robots.txt: sitemap discovery, crawl-budget hygiene, per-bot AI crawler policy, content signals,
 * custom rules, staging protection, plus a standards-based parser/matcher used by the linter and the URL tester.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Robots;

use PublishRankSEO\Sitemap\SitemapUrls;

defined( 'ABSPATH' ) || exit;

final class RobotsTxt {
	const OPT_ENABLED    = 'publishrank_robots_enabled';
	const OPT_SITEMAPS   = 'publishrank_robots_sitemaps';
	const OPT_LEGACY_AI  = 'publishrank_robots_block_ai';
	const OPT_AI_POLICY  = 'publishrank_robots_ai_policy';
	const OPT_LOW_VALUE  = 'publishrank_robots_low_value';
	const OPT_TRACKING   = 'publishrank_robots_tracking';
	const OPT_ENV_BLOCK  = 'publishrank_robots_env_block';
	const OPT_SIGNALS    = 'publishrank_robots_content_signal';
	const OPT_CUSTOM     = 'publishrank_robots_custom';
	const OPT_HOUSEKEEP  = 'publishrank_robots_housekeeping';
	const OPT_AI_PATHS   = 'publishrank_robots_ai_paths';
	const MARKER         = '# --- PublishRank ---';
	/** Google reads at most 500 KiB of robots.txt. */
	const MAX_BYTES      = 512000;

	/** @var array<string,mixed>|null Option overrides used while rendering a live preview of unsaved settings. */
	private static $override = null;

	public function register() {
		add_filter( 'robots_txt', array( $this, 'filter' ), 20, 2 );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
	}

	/* ------------------------------------------------------------------ settings access */

	/** Option value, or the unsaved value while a preview is being built. */
	public static function opt( $key, $default = null ) {
		if ( is_array( self::$override ) && array_key_exists( $key, self::$override ) ) { return self::$override[ $key ]; }
		return get_option( $key, $default );
	}

	/** @return array<string,mixed> Defaults for every robots option (used by the settings screen and the preview). */
	public static function defaults() {
		return array(
			self::OPT_ENABLED   => 1,
			self::OPT_SITEMAPS  => 1,
			self::OPT_LOW_VALUE => 0,
			self::OPT_TRACKING  => 0,
			self::OPT_ENV_BLOCK => 1,
			self::OPT_HOUSEKEEP => 0,
			self::OPT_AI_PATHS  => '',
			self::OPT_AI_POLICY => array(),
			self::OPT_SIGNALS   => array( 'search' => '', 'ai_input' => '', 'ai_train' => '' ),
			self::OPT_CUSTOM    => '',
		);
	}

	/* ------------------------------------------------------------------ AI crawler catalogue */

	/**
	 * Known AI crawlers grouped by what they do, because blocking "training" and blocking "AI search" have opposite
	 * consequences for a publisher: the first protects archives, the second removes you from AI answers and citations.
	 *
	 * @return array<string,array{vendor:string,kind:string,desc:string}> Keyed by the robots.txt user-agent token.
	 */
	public static function catalog() {
		$bots = array(
			'GPTBot'              => array( 'vendor' => 'OpenAI', 'kind' => 'training', 'desc' => __( 'Collects content to train OpenAI models.', 'publishrank' ) ),
			'ClaudeBot'           => array( 'vendor' => 'Anthropic', 'kind' => 'training', 'desc' => __( 'Collects content to train Claude models.', 'publishrank' ) ),
			'anthropic-ai'        => array( 'vendor' => 'Anthropic', 'kind' => 'training', 'desc' => __( 'Older Anthropic training token, still used by some crawlers.', 'publishrank' ) ),
			'Google-Extended'     => array( 'vendor' => 'Google', 'kind' => 'training', 'desc' => __( 'Opt-out token for Gemini training and grounding. Does not affect Google Search or News ranking.', 'publishrank' ) ),
			'Applebot-Extended'   => array( 'vendor' => 'Apple', 'kind' => 'training', 'desc' => __( 'Opt-out token for Apple AI training. Does not affect Siri or Spotlight search.', 'publishrank' ) ),
			'CCBot'               => array( 'vendor' => 'Common Crawl', 'kind' => 'training', 'desc' => __( 'Builds the open Common Crawl archive many AI models are trained on.', 'publishrank' ) ),
			'Bytespider'          => array( 'vendor' => 'ByteDance', 'kind' => 'training', 'desc' => __( 'ByteDance crawler used for its AI products.', 'publishrank' ) ),
			'meta-externalagent'  => array( 'vendor' => 'Meta', 'kind' => 'training', 'desc' => __( 'Meta crawler that gathers content for AI training.', 'publishrank' ) ),
			'cohere-ai'           => array( 'vendor' => 'Cohere', 'kind' => 'training', 'desc' => __( 'Cohere crawler.', 'publishrank' ) ),
			'Omgilibot'           => array( 'vendor' => 'Webz.io', 'kind' => 'training', 'desc' => __( 'Sells crawled data sets to AI developers.', 'publishrank' ) ),
			'Diffbot'             => array( 'vendor' => 'Diffbot', 'kind' => 'training', 'desc' => __( 'Extracts content into a commercial knowledge graph.', 'publishrank' ) ),
			'ImagesiftBot'        => array( 'vendor' => 'Imagesift', 'kind' => 'training', 'desc' => __( 'Image-focused crawler for AI datasets.', 'publishrank' ) ),
			'Timpibot'            => array( 'vendor' => 'Timpi', 'kind' => 'training', 'desc' => __( 'Decentralised search index used for AI.', 'publishrank' ) ),
			'FacebookBot'         => array( 'vendor' => 'Meta', 'kind' => 'training', 'desc' => __( 'Meta crawler for speech and language models. Link previews use a different agent.', 'publishrank' ) ),
			'Amazonbot'           => array( 'vendor' => 'Amazon', 'kind' => 'training', 'desc' => __( 'Amazon crawler for Alexa and AI services.', 'publishrank' ) ),
			'OAI-SearchBot'       => array( 'vendor' => 'OpenAI', 'kind' => 'search', 'desc' => __( 'Indexes pages so they can appear in ChatGPT search results.', 'publishrank' ) ),
			'Claude-SearchBot'    => array( 'vendor' => 'Anthropic', 'kind' => 'search', 'desc' => __( 'Indexes pages for Claude search answers.', 'publishrank' ) ),
			'PerplexityBot'       => array( 'vendor' => 'Perplexity', 'kind' => 'search', 'desc' => __( 'Indexes pages for Perplexity answers and citations.', 'publishrank' ) ),
			'DuckAssistBot'       => array( 'vendor' => 'DuckDuckGo', 'kind' => 'search', 'desc' => __( 'Fetches pages for DuckDuckGo AI-assisted answers.', 'publishrank' ) ),
			'ChatGPT-User'        => array( 'vendor' => 'OpenAI', 'kind' => 'assistant', 'desc' => __( 'Opens a page when a ChatGPT user asks about it.', 'publishrank' ) ),
			'Claude-User'         => array( 'vendor' => 'Anthropic', 'kind' => 'assistant', 'desc' => __( 'Opens a page when a Claude user asks about it.', 'publishrank' ) ),
			'Perplexity-User'     => array( 'vendor' => 'Perplexity', 'kind' => 'assistant', 'desc' => __( 'Opens a page when a Perplexity user asks about it.', 'publishrank' ) ),
			'meta-externalfetcher' => array( 'vendor' => 'Meta', 'kind' => 'assistant', 'desc' => __( 'Opens a page when a Meta AI user shares or asks about a link.', 'publishrank' ) ),
			'AhrefsBot'           => array( 'vendor' => 'Ahrefs', 'kind' => 'seo', 'desc' => __( 'Commercial SEO tool. Crawls heavily and sends your site no search traffic.', 'publishrank' ) ),
			'SemrushBot'          => array( 'vendor' => 'Semrush', 'kind' => 'seo', 'desc' => __( 'Commercial SEO tool. Often one of the busiest bots on news sites.', 'publishrank' ) ),
			'MJ12bot'             => array( 'vendor' => 'Majestic', 'kind' => 'seo', 'desc' => __( 'Backlink index crawler.', 'publishrank' ) ),
			'DotBot'              => array( 'vendor' => 'Moz', 'kind' => 'seo', 'desc' => __( 'Moz link index crawler.', 'publishrank' ) ),
			'DataForSeoBot'       => array( 'vendor' => 'DataForSEO', 'kind' => 'seo', 'desc' => __( 'Data provider for SEO tools.', 'publishrank' ) ),
			'BLEXBot'             => array( 'vendor' => 'WebMeUp', 'kind' => 'seo', 'desc' => __( 'Backlink checker crawler.', 'publishrank' ) ),
			'PetalBot'            => array( 'vendor' => 'Huawei', 'kind' => 'seo', 'desc' => __( 'Petal Search crawler. Block it only if you do not target Huawei devices.', 'publishrank' ) ),
			'MistralAI-User'      => array( 'vendor' => 'Mistral', 'kind' => 'assistant', 'desc' => __( 'Opens a page when a Le Chat user asks about it.', 'publishrank' ) ),
		);
		return (array) apply_filters( 'publishrank_robots_ai_catalog', $bots );
	}

	/** @return array<string,string> Group labels keyed by catalogue kind. */
	public static function kinds() {
		return array(
			'training'  => __( 'Training crawlers', 'publishrank' ),
			'search'    => __( 'AI search crawlers', 'publishrank' ),
			'assistant' => __( 'Assistants fetching on a user\'s request', 'publishrank' ),
			'seo'       => __( 'SEO tools and scrapers (not search engines)', 'publishrank' ),
		);
	}

	/** @return string[] The nine training crawlers PublishRank has always blocked with the one-click option. */
	public static function ai_bots() {
		return array( 'GPTBot', 'CCBot', 'Google-Extended', 'ClaudeBot', 'anthropic-ai', 'Applebot-Extended', 'Bytespider', 'cohere-ai', 'Omgilibot' );
	}

	/** Bots for a quick preset: 'training', 'search', 'assistant' or 'all'. @return string[] */
	public static function preset( $name ) {
		$out = array();
		foreach ( self::catalog() as $token => $bot ) { if ( ( 'all' === $name && 'seo' !== $bot['kind'] ) || $bot['kind'] === $name ) { $out[] = $token; } }
		return $out;
	}

	/**
	 * Effective per-bot policy. Sites that used the old single "block AI training" checkbox keep their behaviour until
	 * the new table is saved, because the legacy option is translated here rather than discarded.
	 *
	 * @return array<string,string> token => 'block'|'allow'
	 */
	public static function policy() {
		$raw = self::opt( self::OPT_AI_POLICY, array() );
		$raw = is_array( $raw ) ? $raw : array();
		if ( ! $raw && self::opt( self::OPT_LEGACY_AI, 0 ) ) { foreach ( self::ai_bots() as $token ) { $raw[ $token ] = 'block'; } }
		return self::sanitize_policy( $raw );
	}

	/** @param mixed $value @return array<string,string> Only known tokens with a block/allow decision. */
	public static function sanitize_policy( $value ) {
		$out = array(); $known = self::catalog();
		foreach ( is_array( $value ) ? $value : array() as $token => $mode ) {
			if ( isset( $known[ $token ] ) && in_array( $mode, array( 'block', 'allow' ), true ) ) { $out[ $token ] = $mode; }
		}
		return $out;
	}

	/** @return string[] Paths AI crawlers must not fetch (one per line in the settings). */
	public static function ai_paths() { return self::sanitize_paths( self::opt( self::OPT_AI_PATHS, '' ) ); }

	/** @param mixed $value Textarea text or array. @return string[] Clean root-relative paths, max 50. */
	public static function sanitize_paths( $value ) {
		$out = array();
		foreach ( is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n|,/', (string) $value ) as $line ) {
			$line = (string) preg_replace( '/[^\x21-\x7E]/', '', trim( (string) $line ) );
			$line = str_replace( array( '<', '>', '#' ), '', $line );
			if ( '' === $line ) { continue; }
			if ( '/' !== $line[0] && '*' !== $line[0] ) { $line = '/' . $line; }
			$out[ $line ] = $line;
		}
		return array_slice( array_values( $out ), 0, 50 );
	}

	/** @param mixed $value @return array<string,string> */
	public static function sanitize_signals( $value ) {
		$out = array( 'search' => '', 'ai_input' => '', 'ai_train' => '' );
		foreach ( $out as $key => $unused ) {
			$v = is_array( $value ) && isset( $value[ $key ] ) ? strtolower( (string) $value[ $key ] ) : '';
			$out[ $key ] = in_array( $v, array( 'yes', 'no' ), true ) ? $v : '';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ output */

	/** @param string $output Current robots.txt. @param mixed $public Value of blog_public. */
	public function filter( $output, $public ) {
		$output = (string) $output;
		if ( ! self::opt( self::OPT_ENABLED, 1 ) || '0' === (string) $public ) {
			return $output;
		}
		// Staging, development and local copies must never be indexed, regardless of the "discourage search engines" box.
		if ( self::opt( self::OPT_ENV_BLOCK, 1 ) && function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
			return "User-agent: *\nDisallow: /\n\n" . self::MARKER . "\n# Crawling is blocked because this is a " . sanitize_key( wp_get_environment_type() ) . " environment (WP_ENVIRONMENT_TYPE).\n";
		}
		$custom = self::clean_rules( (string) self::opt( self::OPT_CUSTOM, '' ) );
		$lines  = array( '', self::MARKER );
		$wild   = array();
		$rules  = array(); // Disallow lines every crawler group repeats: a bot with its own group ignores the "*" group.
		$star   = array(); // The same rules with an explanatory comment above each section, for the "*" group.

		if ( self::opt( self::OPT_LOW_VALUE, 0 ) ) {
			$shop   = class_exists( 'WooCommerce' );
			$set = array( 'Disallow: /search/', 'Disallow: /*?s=', 'Disallow: /*&s=', 'Disallow: /*/trackback/', 'Disallow: /*?replytocom=', 'Disallow: /*&replytocom=' );
			if ( $shop ) { array_push( $set, 'Disallow: /cart/', 'Disallow: /checkout/', 'Disallow: /my-account/', 'Disallow: /*?add-to-cart=', 'Disallow: /*&add-to-cart=' ); }
			$star[] = $shop ? '# Internal search, trackbacks, comment-reply and shop account URLs add nothing to search.' : '# Internal search results, trackbacks and comment-reply URLs add nothing to search.';
			$star   = array_merge( $star, $set ); $rules = array_merge( $rules, $set );
		}
		if ( self::opt( self::OPT_HOUSEKEEP, 0 ) ) {
			$set = array( 'Disallow: /xmlrpc.php', 'Disallow: /wp-login.php', 'Disallow: /wp-register.php', 'Disallow: /*?author=', 'Disallow: /wp-json/wp/v2/users', 'Disallow: /comments/feed/', 'Disallow: /*/comments/feed/', 'Disallow: /readme.html', 'Disallow: /license.txt' );
			$star[] = '# WordPress housekeeping: login, XML-RPC, user enumeration, comment feeds and bundled text files.';
			$star   = array_merge( $star, $set ); $rules = array_merge( $rules, $set );
		}
		if ( self::opt( self::OPT_TRACKING, 0 ) ) {
			$set = array();
			foreach ( array( 'utm_', 'fbclid=', 'gclid=', 'msclkid=' ) as $param ) { $set[] = 'Disallow: /*?' . $param; $set[] = 'Disallow: /*&' . $param; }
			$star[] = '# Campaign and click-id parameters create endless duplicates of the same page.';
			$star   = array_merge( $star, $set ); $rules = array_merge( $rules, $set );
		}
		$signals = self::sanitize_signals( self::opt( self::OPT_SIGNALS, array() ) );
		$parts   = array();
		foreach ( array( 'search' => 'search', 'ai_input' => 'ai-input', 'ai_train' => 'ai-train' ) as $key => $label ) { if ( '' !== $signals[ $key ] ) { $parts[] = $label . '=' . $signals[ $key ]; } }
		if ( $parts ) {
			$wild[] = '# Content signals: your usage preferences for search, AI answers and AI training (honoured by some crawlers).';
			$wild[] = 'Content-Signal: ' . implode( ', ', $parts );
		}
		if ( $wild || $star ) { $lines[] = 'User-agent: *'; $lines = array_merge( $lines, $star, $wild, array( '' ) ); }

		$policy  = self::policy();
		$blocked = array_keys( array_filter( $policy, static function ( $m ) { return 'block' === $m; } ) );
		$allowed = array_keys( array_filter( $policy, static function ( $m ) { return 'allow' === $m; } ) );
		if ( $blocked ) {
			$lines[] = '# AI crawlers you chose to block';
			foreach ( $blocked as $bot ) { $lines[] = 'User-agent: ' . $bot; }
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}
		$paths  = self::ai_paths();
		$scoped = array();
		if ( $paths ) {
			// Paywalled or members-only areas stay off limits for every AI crawler that is not already fully blocked.
			foreach ( self::catalog() as $token => $bot ) { if ( 'seo' !== $bot['kind'] && ! in_array( $token, $blocked, true ) ) { $scoped[] = $token; } }
		}
		$welcome = array_values( array_unique( array_merge( $allowed, $scoped ) ) );
		if ( $welcome ) {
			$lines[] = $paths ? '# AI crawlers: welcome, except the protected paths below' : '# AI crawlers you chose to welcome explicitly';
			foreach ( $welcome as $bot ) { $lines[] = 'User-agent: ' . $bot; }
			array_push( $lines, 'Disallow: /wp-admin/', 'Allow: /wp-admin/admin-ajax.php' );
			$lines = array_merge( $lines, $rules );
			foreach ( $paths as $path ) { $lines[] = 'Disallow: ' . $path; }
			array_push( $lines, 'Allow: /', '' );
		}
		if ( '' !== $custom ) {
			$lines[] = '# Custom rules';
			$lines[] = $custom;
			$lines[] = '';
		}
		if ( self::opt( self::OPT_SITEMAPS, 1 ) ) {
			$added = false;
			foreach ( self::sitemap_urls() as $url ) {
				if ( false === stripos( $output . "\n" . $custom, $url ) ) { if ( ! $added ) { $lines[] = '# Sitemaps'; $added = true; } $lines[] = 'Sitemap: ' . esc_url_raw( $url ); }
			}
		}
		if ( apply_filters( 'publishrank_robots_llms_hint', true ) && get_option( 'publishrank_llms_txt_enabled', 1 ) ) {
			$lines[] = '# AI-readable site summary: ' . home_url( '/llms.txt' );
		}
		return rtrim( $output ) . "\n" . rtrim( implode( "\n", $lines ) ) . "\n";
	}

	/** @return string[] Sitemap URLs PublishRank advertises: the index and, when enabled, the News sitemap directly. */
	public static function sitemap_urls() {
		$urls = array( SitemapUrls::index_url() );
		if ( get_option( 'publishrank_seo_news_enabled', true ) ) { $urls[] = SitemapUrls::shard_url( 'news', 1 ); }
		return $urls;
	}

	/** The default WordPress rules plus every filter, exactly as a crawler would receive them. */
	public static function preview() {
		$default = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		return (string) apply_filters( 'robots_txt', $default, get_option( 'blog_public' ) );
	}

	/**
	 * Build the file from unsaved settings (live preview). Settings are applied through an in-memory override and always
	 * removed again, so nothing is stored.
	 *
	 * @param array<string,mixed> $settings Already sanitised option values keyed by option name.
	 */
	public static function preview_with( array $settings ) {
		self::$override = $settings;
		try { return self::preview(); } finally { self::$override = null; }
	}

	/**
	 * Keep only robots.txt directives we support, as printable ASCII. Pure string handling.
	 *
	 * @param string $text Raw rules.
	 * @return string
	 */
	public static function clean_rules( $text ) {
		$names = array(
			'user-agent'     => 'User-agent',
			'disallow'       => 'Disallow',
			'allow'          => 'Allow',
			'crawl-delay'    => 'Crawl-delay',
			'sitemap'        => 'Sitemap',
			'content-signal' => 'Content-Signal',
		);
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				$out[] = '';
				continue;
			}
			$line = str_replace( array( '<', '>' ), '', preg_replace( '/[^\x20-\x7E]/', '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( '#' === $line[0] ) {
				$out[] = $line;
				continue;
			}
			$pos = strpos( $line, ':' );
			if ( false === $pos ) {
				continue;
			}
			$key = strtolower( trim( substr( $line, 0, $pos ) ) );
			if ( ! isset( $names[ $key ] ) ) {
				continue;
			}
			$value = trim( substr( $line, $pos + 1 ) );
			if ( '' === $value && 'disallow' !== $key && 'allow' !== $key ) {
				continue;
			}
			if ( 'sitemap' === $key && ! preg_match( '#^https?://#i', $value ) ) {
				continue;
			}
			if ( 'crawl-delay' === $key && ! preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
				continue;
			}
			$out[] = $names[ $key ] . ': ' . $value;
		}
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $out ) ) );
	}

	/* ------------------------------------------------------------------ parser and matcher (RFC 9309) */

	/**
	 * @param string $text robots.txt contents.
	 * @return array{groups:array<int,array<string,mixed>>,sitemaps:string[],invalid:array<int,array{line:int,text:string}>}
	 */
	public static function parse( $text ) {
		$groups = array(); $sitemaps = array(); $invalid = array(); $current = null; $in_rules = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $i => $raw ) {
			$line = trim( preg_replace( '/#.*$/', '', $raw ) );
			if ( '' === $line ) { continue; }
			$pos = strpos( $line, ':' );
			if ( false === $pos ) { $invalid[] = array( 'line' => $i + 1, 'text' => trim( $raw ) ); continue; }
			$key = strtolower( trim( substr( $line, 0, $pos ) ) ); $value = trim( substr( $line, $pos + 1 ) );
			if ( 'sitemap' === $key ) { $sitemaps[] = $value; continue; }
			if ( 'user-agent' === $key ) {
				if ( null === $current || $in_rules ) { $groups[] = array( 'agents' => array(), 'rules' => array(), 'delay' => null, 'signals' => '', 'line' => $i + 1 ); $current = count( $groups ) - 1; $in_rules = false; }
				$groups[ $current ]['agents'][] = strtolower( $value );
				continue;
			}
			if ( null === $current ) { $invalid[] = array( 'line' => $i + 1, 'text' => trim( $raw ) ); continue; }
			$in_rules = true;
			if ( 'allow' === $key || 'disallow' === $key ) { $groups[ $current ]['rules'][] = array( $key, $value ); }
			elseif ( 'crawl-delay' === $key ) { $groups[ $current ]['delay'] = $value; }
			elseif ( 'content-signal' === $key ) { $groups[ $current ]['signals'] = $value; }
			else { $invalid[] = array( 'line' => $i + 1, 'text' => trim( $raw ) ); }
		}
		return array( 'groups' => $groups, 'sitemaps' => $sitemaps, 'invalid' => $invalid );
	}

	/** Google's media crawlers follow the plain Googlebot group when they have none of their own. */
	private static function fallbacks( $agent ) {
		$agent = strtolower( $agent );
		$map = array( 'googlebot-news' => array( 'googlebot-news', 'googlebot' ), 'googlebot-image' => array( 'googlebot-image', 'googlebot' ), 'googlebot-video' => array( 'googlebot-video', 'googlebot' ) );
		return isset( $map[ $agent ] ) ? $map[ $agent ] : array( $agent );
	}

	/**
	 * Is a path crawlable for an agent? Longest matching rule wins; Allow wins ties; no rule means allowed.
	 *
	 * @param array<string,mixed> $parsed Result of parse().
	 * @param string              $agent  User-agent token, e.g. Googlebot-News.
	 * @param string              $path   Path with optional query, starting with "/".
	 * @return array{allowed:bool,rule:string,group:string}
	 */
	public static function check( array $parsed, $agent, $path ) {
		$path = '/' . ltrim( (string) $path, '/' );
		// The first user-agent token (most specific first, "*" last) that has any group decides; its groups are merged.
		$candidates = self::fallbacks( $agent ); $candidates[] = '*';
		$rules = array(); $matched = '*';
		foreach ( $candidates as $candidate ) {
			$found = false;
			foreach ( $parsed['groups'] as $group ) { if ( in_array( $candidate, $group['agents'], true ) ) { $found = true; $rules = array_merge( $rules, $group['rules'] ); } }
			if ( $found ) { $matched = $candidate; break; }
		}
		$best = null; $best_len = -1; $best_allow = true;
		foreach ( $rules as $rule ) {
			list( $type, $pattern ) = $rule;
			if ( '' === $pattern ) { continue; }
			if ( ! self::matches( $pattern, $path ) ) { continue; }
			$len = strlen( rtrim( $pattern, '$' ) ); $allow = 'allow' === $type;
			if ( $len > $best_len || ( $len === $best_len && $allow && ! $best_allow ) ) { $best = $type . ': ' . $pattern; $best_len = $len; $best_allow = $allow; }
		}
		return array( 'allowed' => null === $best ? true : $best_allow, 'rule' => (string) $best, 'group' => $matched );
	}

	/** Wildcard (*) and end-anchor ($) matching from the start of the path. */
	private static function matches( $pattern, $path ) {
		$anchored = '$' === substr( $pattern, -1 );
		if ( $anchored ) { $pattern = substr( $pattern, 0, -1 ); }
		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . ( $anchored ? '$' : '' ) . '#';
		return 1 === preg_match( $regex, $path );
	}

	/* ------------------------------------------------------------------ linting and matrix */

	/**
	 * Review a robots.txt for the mistakes that cost publishers traffic.
	 *
	 * @param string $text robots.txt contents.
	 * @return array<int,array{level:string,msg:string}> level is error, warn, info or ok.
	 */
	public static function analyze( $text ) {
		$p = self::parse( $text ); $issues = array();
		$add = static function ( $level, $msg ) use ( &$issues ) { $issues[] = array( 'level' => $level, 'msg' => $msg ); };
		if ( strlen( $text ) > self::MAX_BYTES ) { $add( 'error', __( 'robots.txt is larger than 500 KiB. Google ignores everything after that limit.', 'publishrank' ) ); }
		foreach ( array_slice( $p['invalid'], 0, 5 ) as $bad ) { /* translators: 1: line number, 2: line text */ $add( 'warn', sprintf( __( 'Line %1$d is not a valid directive and will be ignored: %2$s', 'publishrank' ), $bad['line'], substr( $bad['text'], 0, 80 ) ) ); }
		$sample = array( 'Googlebot' => 'Google Search', 'Googlebot-News' => 'Google News', 'bingbot' => 'Bing' );
		foreach ( $sample as $agent => $label ) {
			$r = self::check( $p, $agent, '/' );
			if ( ! $r['allowed'] ) { /* translators: %s: crawler name */ $add( 'error', sprintf( __( 'The whole site is blocked for %s. Remove "Disallow: /" if you want it indexed.', 'publishrank' ), $label ) ); }
		}
		foreach ( array( 'Googlebot' => '/wp-content/uploads/2026/01/photo.jpg', 'Googlebot-Image' => '/wp-content/uploads/2026/01/photo.jpg' ) as $agent => $path ) {
			if ( ! self::check( $p, $agent, $path )['allowed'] && self::check( $p, $agent, '/' )['allowed'] ) { $add( 'warn', __( 'Uploaded images are blocked, so they cannot appear in Google Images, Discover or article rich results.', 'publishrank' ) ); break; }
		}
		foreach ( array( '/wp-includes/js/jquery/jquery.min.js', '/wp-content/themes/x/style.css', '/wp-content/plugins/x/assets/app.js' ) as $asset ) {
			if ( ! self::check( $p, 'Googlebot', $asset )['allowed'] && self::check( $p, 'Googlebot', '/' )['allowed'] ) { $add( 'warn', __( 'Theme, plugin or core scripts and styles are blocked. Google needs them to render your pages the way readers see them.', 'publishrank' ) ); break; }
		}
		foreach ( array_unique( array_map( 'esc_url_raw', self::sitemap_urls() ) ) as $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( $path && ! self::check( $p, 'Googlebot', $path )['allowed'] ) { /* translators: %s: sitemap path */ $add( 'error', sprintf( __( 'A rule blocks your sitemap (%s) for Googlebot.', 'publishrank' ), $path ) ); }
		}
		if ( ! $p['sitemaps'] ) { $add( 'warn', __( 'No Sitemap line is present, so crawlers have to discover your sitemap elsewhere.', 'publishrank' ) ); }
		if ( count( $p['sitemaps'] ) !== count( array_unique( array_map( 'strtolower', $p['sitemaps'] ) ) ) ) { $add( 'info', __( 'The same Sitemap line appears more than once.', 'publishrank' ) ); }
		foreach ( $p['sitemaps'] as $sm ) { if ( ! preg_match( '#^https?://#i', $sm ) ) { $add( 'warn', __( 'A Sitemap line is not an absolute URL. Crawlers require the full https:// address.', 'publishrank' ) ); break; } }
		foreach ( $p['groups'] as $g ) {
			if ( ! $g['rules'] && null !== $g['delay'] && ! in_array( '*', $g['agents'], true ) ) { /* translators: %s: crawler name */ $add( 'warn', sprintf( __( 'The group for %s only sets Crawl-delay. A crawler with its own group ignores the "*" group, so it loses your other rules.', 'publishrank' ), implode( ', ', $g['agents'] ) ) ); }
			if ( null !== $g['delay'] && ( in_array( 'googlebot', $g['agents'], true ) || in_array( '*', $g['agents'], true ) ) ) { $add( 'info', __( 'Googlebot ignores Crawl-delay. Use Search Console to slow crawling if the server struggles.', 'publishrank' ) ); }
		}
		$policy = self::policy();
		$blocked_search = array_intersect( array_keys( array_filter( $policy, static function ( $m ) { return 'block' === $m; } ) ), self::preset( 'search' ) );
		if ( $blocked_search ) { $add( 'info', __( 'You block at least one AI search crawler. Those products will not index or cite your articles, which also reduces referral traffic from them.', 'publishrank' ) ); }
		if ( get_option( 'publishrank_llms_txt_enabled', 1 ) ) {
			$blocked_all = true; foreach ( array( 'GPTBot', 'ClaudeBot', 'PerplexityBot', 'OAI-SearchBot' ) as $a ) { if ( self::check( $p, $a, '/llms.txt' )['allowed'] ) { $blocked_all = false; } }
			if ( $blocked_all ) { $add( 'info', __( 'The major AI crawlers are all blocked, so your llms.txt will not be read by them. Turn llms.txt off or allow the search crawlers.', 'publishrank' ) ); }
		}
		if ( file_exists( ABSPATH . 'robots.txt' ) ) { $add( 'error', __( 'A physical robots.txt file exists in your site root. WordPress serves that file instead of this one.', 'publishrank' ) ); }
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) { $add( 'error', __( 'WordPress is set to discourage search engines (Settings, Reading). Every crawler is asked to stay away.', 'publishrank' ) ); }
		if ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() && self::opt( self::OPT_ENV_BLOCK, 1 ) ) { $add( 'warn', __( 'This is not a production environment, so crawling is blocked. Set WP_ENVIRONMENT_TYPE to production on the live site.', 'publishrank' ) ); }
		if ( ! array_filter( $issues, static function ( $i ) { return in_array( $i['level'], array( 'error', 'warn' ), true ); } ) ) { array_unshift( $issues, array( 'level' => 'ok', 'msg' => __( 'No problems found. Search and news crawlers can reach your content.', 'publishrank' ) ) ); }
		return $issues;
	}

	/**
	 * Which crawlers can fetch which URLs under a given file: a one-glance crawlability matrix.
	 *
	 * @return array{agents:string[],rows:array<int,array{label:string,path:string,cells:array<string,bool>}>}
	 */
	public static function matrix( $text ) {
		$p = self::parse( $text );
		$agents = array( 'Googlebot', 'Googlebot-News', 'Googlebot-Image', 'bingbot', 'GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'PerplexityBot' );
		$latest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'fields' => 'ids', 'no_found_rows' => true ) );
		$latest_path = $latest ? (string) wp_parse_url( get_permalink( $latest[0] ), PHP_URL_PATH ) : '';
		$paths = array( array( __( 'Homepage', 'publishrank' ), '/' ) );
		if ( $latest_path ) { $paths[] = array( __( 'Latest article', 'publishrank' ), $latest_path ); }
		$paths[] = array( __( 'Sitemap index', 'publishrank' ), (string) wp_parse_url( SitemapUrls::index_url(), PHP_URL_PATH ) );
		if ( get_option( 'publishrank_seo_news_enabled', true ) ) { $paths[] = array( __( 'News sitemap', 'publishrank' ), (string) wp_parse_url( SitemapUrls::shard_url( 'news', 1 ), PHP_URL_PATH ) ); }
		$paths[] = array( __( 'llms.txt', 'publishrank' ), '/llms.txt' );
		$paths[] = array( __( 'Uploaded image', 'publishrank' ), '/wp-content/uploads/photo.jpg' );
		$paths[] = array( __( 'Internal search', 'publishrank' ), '/?s=news' );
		$paths[] = array( __( 'Tracking URL', 'publishrank' ), ( $latest_path ?: '/' ) . '?utm_source=x' );
		$paths[] = array( __( 'Admin area', 'publishrank' ), '/wp-admin/options.php' );
		$rows = array();
		foreach ( $paths as $row ) {
			$cells = array();
			foreach ( $agents as $agent ) { $cells[ $agent ] = self::check( $p, $agent, $row[1] )['allowed']; }
			$rows[] = array( 'label' => $row[0], 'path' => $row[1], 'cells' => $cells );
		}
		return array( 'agents' => $agents, 'rows' => $rows );
	}

	/* ------------------------------------------------------------------ live preview REST endpoint */

	public function rest() {
		register_rest_route( 'publishrank/v1', '/robots/preview', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_preview' ),
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
		) );
	}

	/** Turn raw form values into sanitised option values (identical to what saving stores). @return array<string,mixed> */
	public static function sanitize_settings( array $in ) {
		$bool = static function ( $k ) use ( $in ) { return isset( $in[ $k ] ) && rest_sanitize_boolean( $in[ $k ] ) ? 1 : 0; };
		return array(
			self::OPT_ENABLED   => $bool( self::OPT_ENABLED ),
			self::OPT_SITEMAPS  => $bool( self::OPT_SITEMAPS ),
			self::OPT_LOW_VALUE => $bool( self::OPT_LOW_VALUE ),
			self::OPT_TRACKING  => $bool( self::OPT_TRACKING ),
			self::OPT_ENV_BLOCK => $bool( self::OPT_ENV_BLOCK ),
			self::OPT_HOUSEKEEP => $bool( self::OPT_HOUSEKEEP ),
			self::OPT_AI_PATHS  => implode( "\n", self::sanitize_paths( isset( $in[ self::OPT_AI_PATHS ] ) ? $in[ self::OPT_AI_PATHS ] : '' ) ),
			self::OPT_LEGACY_AI => 0,
			self::OPT_AI_POLICY => self::sanitize_policy( isset( $in[ self::OPT_AI_POLICY ] ) ? $in[ self::OPT_AI_POLICY ] : array() ),
			self::OPT_SIGNALS   => self::sanitize_signals( isset( $in[ self::OPT_SIGNALS ] ) ? $in[ self::OPT_SIGNALS ] : array() ),
			self::OPT_CUSTOM    => self::clean_rules( isset( $in[ self::OPT_CUSTOM ] ) ? (string) $in[ self::OPT_CUSTOM ] : '' ),
		);
	}

	public function rest_preview( \WP_REST_Request $request ) {
		$text   = self::preview_with( self::sanitize_settings( (array) $request->get_params() ) );
		$marker = strpos( $text, self::MARKER );
		$ours   = false === $marker ? -1 : substr_count( substr( $text, 0, $marker ), "\n" );
		$params = (array) $request->get_params();
		$test   = null;
		if ( ! empty( $params['test_url'] ) ) {
			$raw   = trim( sanitize_text_field( (string) $params['test_url'] ) );
			$parts = wp_parse_url( preg_match( '#^https?://#i', $raw ) ? $raw : home_url( '/' . ltrim( $raw, '/' ) ) );
			$path  = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
			$agent = isset( $params['test_agent'] ) ? preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $params['test_agent'] ) : 'Googlebot';
			$home  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$test  = array_merge( self::check( self::parse( $text ), '' !== $agent ? $agent : 'Googlebot', $path ), array( 'path' => $path, 'agent' => '' !== $agent ? $agent : 'Googlebot', 'foreign' => ! empty( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( $home ) ) );
		}
		return rest_ensure_response( array(
			'test'      => $test,
			'text'      => $text,
			'ours_from' => $ours,
			'bytes'     => strlen( $text ),
			'issues'    => self::with_override( $params, static function () use ( $text ) { return self::analyze( $text ); } ),
			'matrix'    => self::matrix( $text ),
		) );
	}

	/** Run a callback with unsaved settings applied. */
	private static function with_override( array $params, callable $fn ) {
		self::$override = self::sanitize_settings( $params );
		try { return $fn(); } finally { self::$override = null; }
	}
}
