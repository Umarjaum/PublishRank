<?php
/** Optional, privacy-safe technical source provenance. */
namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

final class TraceIntegrity {
	const ENABLED_OPTION = 'publishrank_source_attribution';

	public function register() {
		// Public source markers are emitted only after an administrator opts in.
		if ( self::enabled() ) {
			add_action( 'wp_head', array( $this, 'frontend_trace' ), 0 );
		}
	}

	public static function enabled() {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	/** Return a stable, public provenance fingerprint for this release. */
	public static function fingerprint() {
		return substr( hash( 'sha256', 'PublishRank|' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : 'unknown' ) . '|publishrank-seo' ), 0, 16 );
	}

	/** Emit technical source attribution on public HTML pages, regardless of the footer-credit setting. */
	/** Build the markers once so both wp_head and the theme-recovery path use identical output. */
	public static function markup() {
		if ( ! self::enabled() ) {
			return '';
		}
		$version = defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : 'unknown';
		return '<!-- PublishRank provenance: brand=PublishRank; version=' . esc_attr( $version ) . '; fingerprint=' . esc_attr( self::fingerprint() ) . " -->\n"
			. '<meta name="publishrank-brand" content="PublishRank" />' . "\n"
			. '<meta name="publishrank-version" content="' . esc_attr( $version ) . '" />' . "\n";
	}

	public function frontend_trace() {
		if ( ! self::enabled() || is_admin() || wp_doing_ajax() || is_feed() ) {
			return;
		}
		$version = defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : 'unknown';
		printf( '<!-- PublishRank provenance: brand=PublishRank; version=%s; fingerprint=%s -->' . "\n", esc_html( $version ), esc_html( self::fingerprint() ) );
		echo wp_kses(
			'<meta name="publishrank-brand" content="PublishRank" />' . "\n" . '<meta name="publishrank-version" content="' . esc_attr( $version ) . '" />' . "\n",
			array( 'meta' => array( 'name' => true, 'content' => true ) )
		);
	}
}
