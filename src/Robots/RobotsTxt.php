<?php
/** Virtual robots.txt additions: sitemap discovery, optional AI-crawler blocking, custom rules. */
namespace PublishRankSEO\Robots;

use PublishRankSEO\Sitemap\SitemapUrls;

defined( 'ABSPATH' ) || exit;

final class RobotsTxt {
	public function register() {
		add_filter( 'robots_txt', array( $this, 'filter' ), 20, 2 );
	}

	/** @return string[] User-agent tokens of AI training crawlers. */
	public static function ai_bots() {
		return array( 'GPTBot', 'CCBot', 'Google-Extended', 'ClaudeBot', 'anthropic-ai', 'Applebot-Extended', 'Bytespider', 'cohere-ai', 'Omgilibot' );
	}

	/** @param string $output Current robots.txt. @param mixed $public Value of blog_public. */
	public function filter( $output, $public ) {
		$output = (string) $output;
		if ( ! get_option( 'publishrank_robots_enabled', 1 ) || '0' === (string) $public ) {
			return $output;
		}
		$lines = array( '', '# PublishRank' );
		if ( get_option( 'publishrank_robots_block_ai', 0 ) ) {
			foreach ( self::ai_bots() as $bot ) {
				$lines[] = 'User-agent: ' . $bot;
				$lines[] = 'Disallow: /';
				$lines[] = '';
			}
		}
		$custom = self::clean_rules( (string) get_option( 'publishrank_robots_custom', '' ) );
		if ( '' !== $custom ) {
			$lines[] = $custom;
			$lines[] = '';
		}
		if ( get_option( 'publishrank_robots_sitemaps', 1 ) ) {
				$urls = array( SitemapUrls::index_url() );
			foreach ( $urls as $url ) {
				if ( false === stripos( $output . $custom, $url ) ) {
					$lines[] = 'Sitemap: ' . esc_url_raw( $url );
				}
			}
		}
		return rtrim( $output ) . "\n" . implode( "\n", $lines ) . "\n";
	}

	/** Default WordPress rules plus all filters, as an approximate live preview. */
	public static function preview() {
		$default = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		return (string) apply_filters( 'robots_txt', $default, get_option( 'blog_public' ) );
	}

	/**
	 * Keep only robots.txt directives we support, as printable ASCII. Pure string handling.
	 *
	 * @param string $text Raw rules.
	 * @return string
	 */
	public static function clean_rules( $text ) {
		$names = array(
			'user-agent'  => 'User-agent',
			'disallow'    => 'Disallow',
			'allow'       => 'Allow',
			'crawl-delay' => 'Crawl-delay',
			'sitemap'     => 'Sitemap',
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
			if ( 'crawl-delay' === $key && ! ctype_digit( $value ) ) {
				continue;
			}
			$out[] = $names[ $key ] . ': ' . $value;
		}
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $out ) ) );
	}
}
