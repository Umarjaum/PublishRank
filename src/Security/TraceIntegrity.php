<?php
/** Optional, privacy-safe source attribution for administrators who explicitly enable it. */
namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

final class TraceIntegrity {
	public function register() {
		// Public-facing attribution must never be imposed on publishers by default.
		if ( ! get_option( 'publishrank_source_attribution', false ) ) {
			return;
		}
		add_action( 'wp_head', array( $this, 'frontend_trace' ), 0 );
	}

	/** Return a stable, public provenance fingerprint for this release. */
	public static function fingerprint() {
		return substr( hash( 'sha256', 'PublishRank|' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : 'unknown' ) . '|publishrank-seo' ), 0, 16 );
	}

	/** Emit source attribution only after the administrator explicitly opts in. */
	public function frontend_trace() {
		if ( is_admin() || wp_doing_ajax() || is_feed() || ! get_option( 'publishrank_source_attribution', false ) ) {
			return;
		}
		$version = defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : 'unknown';
		echo '<!-- PublishRank provenance: brand=PublishRank; version=' . esc_attr( $version ) . '; fingerprint=' . esc_attr( self::fingerprint() ) . " -->\n";
		echo '<meta name="publishrank-brand" content="PublishRank" />' . "\n";
		echo '<meta name="publishrank-version" content="' . esc_attr( $version ) . '" />' . "\n";
	}
}
