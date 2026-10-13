<?php
/** Optional public attribution for publishers who choose to credit PublishRank. */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class FrontendBranding {
	const ENABLED_OPTION = 'publishrank_public_attribution';
	const TEXT_OPTION    = 'publishrank_public_attribution_text';
	const URL_OPTION     = 'publishrank_public_attribution_url';

	public function register() {
		// Public hooks are not registered at all unless attribution is explicitly
		// enabled. This keeps the default front-end path free of attribution work.
		if ( self::enabled() ) {
			add_action( 'wp_footer', array( $this, 'render' ), 30 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 30 );
		}
		add_action( 'admin_footer', array( $this, 'render_admin' ), 30 );
	}

	public function enqueue_assets() {
		if ( is_admin() || wp_doing_ajax() || ! self::enabled() ) {
			return;
		}
			$path = dirname( dirname( __DIR__ ) ) . '/assets/css/branding.css';
			if ( file_exists( $path ) ) {
				wp_enqueue_style( 'publishrank-branding', plugins_url( 'assets/css/branding.css', dirname( dirname( __DIR__ ) ) . '/publishrank.php' ), array(), defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : null );
			}
	}

	public static function enabled() {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	public function render_admin() {
		if ( ! is_admin() ) { return; }
		$url = esc_url( 'https://publishrank.netlify.app/' );
		echo '<div style="text-align:center;margin:12px 0 18px;font-size:11px;opacity:.72">' . esc_html__( 'PublishRank — Enterprise SEO Infrastructure for WordPress Publishers', 'publishrank' ) . ' · <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">publishrank.netlify.app</a></div>';
	}

	public function render() {
		if ( is_admin() || wp_doing_ajax() || ! self::enabled() ) {
			return;
		}
		$text = sanitize_text_field( (string) get_option( self::TEXT_OPTION, 'Powered by PublishRank' ) );
		$url  = esc_url( (string) get_option( self::URL_OPTION, 'https://publishrank.netlify.app/' ) );
		if ( '' === $text || '' === $url ) {
			return;
		}
		$logo = esc_url( plugins_url( 'assets/images/publishrank-mark-transparent.png', dirname( dirname( __DIR__ ) ) . '/publishrank.php' ) );
		echo '<div class="publishrank-attribution">';
		echo '<a class="publishrank-attribution__link" href="' . $url . '" rel="nofollow" aria-label="' . esc_attr( $text ) . '">';
		echo '<img class="publishrank-attribution__mark" src="' . esc_url( $logo ) . '" alt="" width="20" height="20" loading="lazy" decoding="async" />';
		echo '<span>' . esc_html( $text ) . '</span>';
		echo '</a></div>';
	}
}
