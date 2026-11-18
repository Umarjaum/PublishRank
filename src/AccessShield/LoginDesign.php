<?php
/**
 * Access Shield sign-in page designer: logo, colours, background, layout, corner radius, heading,
 * welcome message and footer links for the WordPress sign-in, registration and password screens.
 *
 * Styling is applied with one small inline stylesheet on the login screen only, built from validated
 * values (colours, numbers, media URLs from this site). The form fields, labels, error messages and
 * security features (CAPTCHA, two-factor, lockouts) are untouched, so accessibility and protection
 * stay intact. Nothing is loaded on any other page.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

defined( 'ABSPATH' ) || exit;

final class LoginDesign {
	const OPTION = 'publishrank_login_design';

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			'enabled'      => 0,
			'layout'       => 'center', // center | left | right.
			'logo_id'      => 0,
			'logo_width'   => 84,
			'logo_link'    => 'home', // home | wordpress.
			'bg_color'     => '#f4f1ec',
			'bg_image_id'  => 0,
			'bg_overlay'   => 35,
			'card_color'   => '#ffffff',
			'text_color'   => '#1d2327',
			'accent_color' => '#9b1245',
			'button_text'  => '#ffffff',
			'radius'       => 12,
			'shadow'       => 1,
			'font'         => 'system', // system | serif | rounded.
			'heading'      => '',
			'message'      => '',
			'hide_back'    => 0,
			'hide_lang'    => 0,
			'footer_note'  => '',
		);
	}

	/** @return array<string,mixed> */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/** Strict colour check: #rgb / #rrggbb only. */
	public static function color( $v, $fallback ) {
		$v = strtolower( trim( (string) $v ) );
		return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $v ) ? $v : $fallback;
	}

	/** @param array<string,mixed> $in */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();
		$out['enabled']      = empty( $in['enabled'] ) ? 0 : 1;
		$out['layout']       = isset( $in['layout'] ) && in_array( $in['layout'], array( 'center', 'left', 'right' ), true ) ? $in['layout'] : 'center';
		$out['logo_id']      = isset( $in['logo_id'] ) && wp_attachment_is_image( absint( $in['logo_id'] ) ) ? absint( $in['logo_id'] ) : 0;
		$out['logo_width']   = isset( $in['logo_width'] ) ? max( 40, min( 320, absint( $in['logo_width'] ) ) ) : $d['logo_width'];
		$out['logo_link']    = isset( $in['logo_link'] ) && 'wordpress' === $in['logo_link'] ? 'wordpress' : 'home';
		$out['bg_image_id']  = isset( $in['bg_image_id'] ) && wp_attachment_is_image( absint( $in['bg_image_id'] ) ) ? absint( $in['bg_image_id'] ) : 0;
		$out['bg_overlay']   = isset( $in['bg_overlay'] ) ? max( 0, min( 90, absint( $in['bg_overlay'] ) ) ) : $d['bg_overlay'];
		foreach ( array( 'bg_color', 'card_color', 'text_color', 'accent_color', 'button_text' ) as $k ) {
			$out[ $k ] = self::color( $in[ $k ] ?? '', $d[ $k ] );
		}
		$out['radius']      = isset( $in['radius'] ) ? max( 0, min( 32, absint( $in['radius'] ) ) ) : $d['radius'];
		$out['shadow']      = empty( $in['shadow'] ) ? 0 : 1;
		$out['font']        = isset( $in['font'] ) && in_array( $in['font'], array( 'system', 'serif', 'rounded' ), true ) ? $in['font'] : 'system';
		$out['heading']     = substr( sanitize_text_field( (string) ( $in['heading'] ?? '' ) ), 0, 120 );
		$out['message']     = substr( sanitize_textarea_field( (string) ( $in['message'] ?? '' ) ), 0, 500 );
		$out['hide_back']   = empty( $in['hide_back'] ) ? 0 : 1;
		$out['hide_lang']   = empty( $in['hide_lang'] ) ? 0 : 1;
		$out['footer_note'] = substr( sanitize_text_field( (string) ( $in['footer_note'] ?? '' ) ), 0, 200 );
		return $out;
	}

	public static function save( array $in ) {
		update_option( self::OPTION, self::sanitize( $in ), false );
	}

	public static function register() {
		$d = self::get();
		if ( empty( $d['enabled'] ) ) {
			return;
		}
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'styles' ), 20 );
		add_filter( 'login_headerurl', array( __CLASS__, 'logo_url' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'logo_text' ) );
		add_filter( 'login_message', array( __CLASS__, 'message' ) );
		add_filter( 'login_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'login_footer', array( __CLASS__, 'footer' ) );
		if ( ! empty( $d['hide_lang'] ) ) {
			add_filter( 'login_display_language_dropdown', '__return_false' );
		}
	}

	public static function logo_url() {
		return 'wordpress' === self::get()['logo_link'] ? __( 'https://wordpress.org/', 'publishrank' ) : home_url( '/' );
	}

	public static function logo_text() {
		return get_bloginfo( 'name', 'display' );
	}

	public static function body_class( $classes ) {
		$d         = self::get();
		$classes[] = 'pr-login';
		$classes[] = 'pr-login--' . $d['layout'];
		return $classes;
	}

	public static function message( $message ) {
		$d    = self::get();
		$html = '';
		if ( '' !== $d['heading'] ) {
			$html .= '<h1 class="pr-login__heading">' . esc_html( $d['heading'] ) . '</h1>';
		}
		if ( '' !== $d['message'] ) {
			$html .= '<p class="message pr-login__message">' . nl2br( esc_html( $d['message'] ) ) . '</p>';
		}
		return $html . $message;
	}

	public static function footer() {
		$d = self::get();
		if ( '' !== $d['footer_note'] ) {
			echo '<p class="pr-login__note">' . esc_html( $d['footer_note'] ) . '</p>';
		}
	}

	/** Stylesheet text for a design (also used by the admin preview). */
	public static function css( array $d ) {
		$d      = array_merge( self::defaults(), $d );
		$logo   = $d['logo_id'] ? (string) wp_get_attachment_image_url( (int) $d['logo_id'], 'medium' ) : '';
		$bg     = $d['bg_image_id'] ? (string) wp_get_attachment_image_url( (int) $d['bg_image_id'], 'full' ) : '';
		$fonts  = array(
			'system'  => '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif',
			'serif'   => 'Georgia,"Times New Roman",serif',
			'rounded' => 'ui-rounded,"SF Pro Rounded","Nunito","Segoe UI",sans-serif',
		);
		$overlay = round( (int) $d['bg_overlay'] / 100, 2 );
		$css     = 'body.login.pr-login{--prl-bg:' . $d['bg_color'] . ';--prl-card:' . $d['card_color'] . ';--prl-text:' . $d['text_color'] . ';--prl-accent:' . $d['accent_color'] . ';--prl-btn-text:' . $d['button_text'] . ';--prl-radius:' . (int) $d['radius'] . 'px;'
			. 'background:var(--prl-bg)' . ( '' !== $bg ? ' linear-gradient(rgba(0,0,0,' . $overlay . '),rgba(0,0,0,' . $overlay . ')),url("' . esc_url_raw( $bg ) . '") center/cover no-repeat fixed' : '' ) . ';color:var(--prl-text);font-family:' . $fonts[ $d['font'] ] . ';}'
			. 'body.login.pr-login #login{width:min(380px,92vw);padding:6vh 0 24px}'
			. 'body.login.pr-login form{background:var(--prl-card);border:0;border-radius:var(--prl-radius);' . ( $d['shadow'] ? 'box-shadow:0 10px 40px rgba(0,0,0,.12);' : 'box-shadow:none;border:1px solid rgba(0,0,0,.12);' ) . 'padding:26px 24px}'
			. 'body.login.pr-login label,body.login.pr-login .pr-login__heading{color:var(--prl-text)}'
			. 'body.login.pr-login .pr-login__heading{font-size:22px;text-align:center;margin:0 0 14px;font-weight:600;' . ( '' !== $bg ? 'color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.4);' : '' ) . '}'
			. 'body.login.pr-login input[type=text]:focus,body.login.pr-login input[type=password]:focus,body.login.pr-login input[type=email]:focus{border-color:var(--prl-accent);box-shadow:0 0 0 1px var(--prl-accent)}'
			. 'body.login.pr-login .button-primary{background:var(--prl-accent);border-color:var(--prl-accent);color:var(--prl-btn-text);border-radius:calc(var(--prl-radius)*.6);text-shadow:none;box-shadow:none}'
			. 'body.login.pr-login .button-primary:hover,body.login.pr-login .button-primary:focus{filter:brightness(1.08);background:var(--prl-accent);border-color:var(--prl-accent);color:var(--prl-btn-text)}'
			. 'body.login.pr-login .button-primary:focus{box-shadow:0 0 0 2px var(--prl-card),0 0 0 4px var(--prl-accent)}'
			. 'body.login.pr-login .message,body.login.pr-login .notice,body.login.pr-login #login_error{border-radius:calc(var(--prl-radius)*.6);border-left-color:var(--prl-accent)}'
			. 'body.login.pr-login #nav a,body.login.pr-login #backtoblog a,body.login.pr-login .privacy-policy-link{color:' . ( '' !== $bg ? '#fff' : 'var(--prl-text)' ) . '}'
			. 'body.login.pr-login #nav a:hover,body.login.pr-login #backtoblog a:hover{color:var(--prl-accent)}'
			. 'body.login.pr-login .pr-login__note{text-align:center;font-size:12px;opacity:.8;margin:16px auto;max-width:380px;' . ( '' !== $bg ? 'color:#fff;' : '' ) . '}';
		if ( '' !== $logo ) {
			$css .= 'body.login.pr-login h1 a{background-image:url("' . esc_url_raw( $logo ) . '");background-size:contain;background-position:center;width:' . (int) $d['logo_width'] . 'px;height:' . (int) $d['logo_width'] . 'px;max-width:100%}';
		}
		if ( ! empty( $d['hide_back'] ) ) {
			$css .= 'body.login.pr-login #backtoblog{display:none}';
		}
		if ( 'center' !== $d['layout'] ) {
			$side = 'left' === $d['layout'] ? 'margin-left:8vw;margin-right:auto' : 'margin-right:8vw;margin-left:auto';
			$css .= '@media (min-width:900px){body.login.pr-login #login{' . $side . '}body.login.pr-login .pr-login__note{' . $side . '}}';
		}
		$css .= '@media (prefers-reduced-motion:reduce){body.login.pr-login *{transition:none!important}}';
		return $css;
	}

	public static function styles() {
		// Values are validated in sanitize(); the URLs are attachment URLs from this site.
		wp_register_style( 'publishrank-login-design', false, array( 'login' ), '1' );
		wp_enqueue_style( 'publishrank-login-design' );
		wp_add_inline_style( 'publishrank-login-design', self::css( self::get() ) );
	}
}
