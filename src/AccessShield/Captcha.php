<?php
/**
 * Optional human check with the site owner's own Cloudflare Turnstile, hCaptcha or Google reCAPTCHA
 * keys. The secret key is stored encrypted. Verification uses wp_safe_remote_post() with TLS
 * verification and a size limit.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

defined( 'ABSPATH' ) || exit;

final class Captcha {
	/** @return array<string,array{label:string,script:string,class:string,field:string,verify:string}> */
	public static function providers() {
		return array(
			'turnstile' => array( 'label' => 'Cloudflare Turnstile', 'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js', 'class' => 'cf-turnstile', 'field' => 'cf-turnstile-response', 'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify' ),
			'hcaptcha'  => array( 'label' => 'hCaptcha', 'script' => 'https://js.hcaptcha.com/1/api.js', 'class' => 'h-captcha', 'field' => 'h-captcha-response', 'verify' => 'https://api.hcaptcha.com/siteverify' ),
			'recaptcha' => array( 'label' => 'Google reCAPTCHA v2', 'script' => 'https://www.google.com/recaptcha/api.js', 'class' => 'g-recaptcha', 'field' => 'g-recaptcha-response', 'verify' => 'https://www.google.com/recaptcha/api/siteverify' ),
		);
	}

	public static function configured() {
		$p = (string) Settings::get( 'captcha_provider' );
		return isset( self::providers()[ $p ] ) && '' !== (string) Settings::get( 'captcha_site_key' ) && '' !== Crypto::decrypt( (string) Settings::get( 'captcha_secret' ) );
	}

	/** Whether the current client must pass a CAPTCHA now. */
	public static function required( $ip ) {
		if ( ! self::configured() || Guard::exempt( $ip ) ) {
			return false;
		}
		switch ( Settings::get( 'captcha_mode' ) ) {
			case 'always':
				return true;
			case 'after_failures':
				return Guard::ip_failures( $ip ) >= (int) Settings::get( 'captcha_after' ) || Guard::challenge_active();
			default:
				return Guard::challenge_active();
		}
	}

	/** Widget markup for a form. */
	public static function markup() {
		if ( ! self::required( Client::ip() ) ) {
			return '';
		}
		$p = self::providers()[ Settings::get( 'captcha_provider' ) ];
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the provider script must be present inside login, register and custom forms.
		return '<div class="publishrank-captcha" style="margin:0 0 16px;transform:scale(.89);transform-origin:0 0"><div class="' . esc_attr( $p['class'] ) . '" data-sitekey="' . esc_attr( (string) Settings::get( 'captcha_site_key' ) ) . '"></div></div><script src="' . esc_url( $p['script'] ) . '" async defer></script>';
	}

	/** @return true|\WP_Error */
	public static function verify() {
		$key = (string) Settings::get( 'captcha_provider' );
		if ( ! isset( self::providers()[ $key ] ) ) {
			return true;
		}
		$p     = self::providers()[ $key ];
		$token = isset( $_POST[ $p['field'] ] ) ? sanitize_text_field( wp_unslash( $_POST[ $p['field'] ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the CAPTCHA token is the proof.
		if ( '' === $token || strlen( $token ) > 4096 ) {
			return new \WP_Error( 'publishrank_captcha_missing', 'missing' );
		}
		$res = wp_safe_remote_post(
			$p['verify'],
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 65536,
				'body'                => array(
					'secret'   => Crypto::decrypt( (string) Settings::get( 'captcha_secret' ) ),
					'response' => $token,
					'remoteip' => Client::ip(),
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			SecurityLog::add( 'warning', 'warn', array( 'detail' => 'CAPTCHA provider unreachable; ' . ( Settings::get( 'captcha_fail_open' ) ? 'allowed (fail open).' : 'refused (fail closed).' ) ) );
			return Settings::get( 'captcha_fail_open' ) ? true : new \WP_Error( 'publishrank_captcha_unreachable', 'unreachable' );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $body ) && ! empty( $body['success'] ) ? true : new \WP_Error( 'publishrank_captcha_failed', 'failed' );
	}
}
