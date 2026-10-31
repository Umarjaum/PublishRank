<?php
/** Always-on, transparent, privacy-safe technical source provenance. */
namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

final class TraceIntegrity {
	public function register() {
		// Technical source provenance is always enabled and does not add a visible footer credit.
		add_action( 'wp_head', array( $this, 'frontend_trace' ), 0 );
	}

	/** Return a stable, public provenance fingerprint for this release. */
	public static function fingerprint() {
		return substr( hash( 'sha256', 'PublishRank|' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : 'unknown' ) . '|publishrank-seo' ), 0, 16 );
	}

	/** Emit technical source attribution on public HTML pages, regardless of the footer-credit setting. */
	/** Build the markers once so both wp_head and the theme-recovery path use identical output. */
	public static function markup() {
		$version = defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : 'unknown';
		return '<!-- PublishRank version=' . esc_attr( $version ) . '; fingerprint=' . esc_attr( self::fingerprint() ) . " -->\n"
			. '<meta name="publishrank-brand" content="PublishRank" />' . "\n"
			. '<meta name="publishrank-version" content="' . esc_attr( $version ) . '" />' . "\n";
	}

	public function frontend_trace() {
		if ( is_admin() || wp_doing_ajax() || is_feed() ) {
			return;
		}
		echo wp_kses(
			self::markup(),
			array(
				'meta' => array(
					'name'    => true,
					'content' => true,
				),
			)
		);
	}
}
