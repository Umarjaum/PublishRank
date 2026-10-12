<?php
namespace PublishRankSEO\SEO;

defined( 'ABSPATH' ) || exit;

/** Search-engine ownership verification output. */
final class SiteConnections {
	public function register() { add_action( 'wp_head', array( $this, 'meta' ), 2 ); }
	public function meta() {
		if ( is_admin() ) { return; }
		$tags = array( 'google' => 'google-site-verification', 'bing' => 'msvalidate.01', 'yandex' => 'yandex-verification', 'baidu' => 'baidu-site-verification', 'pinterest' => 'p:domain_verify' );
		foreach ( $tags as $key => $name ) {
			$value = (string) get_option( 'publishrank_verification_' . $key, '' );
			$value = substr( preg_replace( '/[^A-Za-z0-9._\-]/', '', trim( $value ) ), 0, 256 );
			if ( '' !== $value ) { echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $value ) . '" />' . "\n"; }
		}
	}
}
