<?php
/**
 * Authenticated encryption for secrets stored in the database (CAPTCHA secrets, TOTP seeds,
 * AI provider keys). Uses libsodium (bundled with PHP 7.2+ and polyfilled by WordPress). The key is
 * derived from the site's secret salts, so a database dump alone does not reveal the secrets.
 *
 * Define PUBLISHRANK_ENCRYPTION_KEY in wp-config.php (any long random string) to use a dedicated key.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

defined( 'ABSPATH' ) || exit;

final class Crypto {
	const PREFIX = 'prenc1:';

	private static function key() {
		$material = defined( 'PUBLISHRANK_ENCRYPTION_KEY' ) && PUBLISHRANK_ENCRYPTION_KEY ? (string) PUBLISHRANK_ENCRYPTION_KEY : wp_salt( 'secure_auth' ) . wp_salt( 'logged_in' );
		return hash_hmac( 'sha256', 'publishrank-encryption-v1', $material, true );
	}

	/** Encrypt a string. Empty input stays empty. */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return $plain; // Extremely old hosts only; callers still work.
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** Decrypt; returns '' when the value cannot be authenticated. Plain legacy values pass through. */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key() );
		return false === $plain ? '' : $plain;
	}

	/** Display a secret as "••••abcd". */
	public static function mask( $stored ) {
		$plain = self::decrypt( $stored );
		if ( '' === $plain ) {
			return '';
		}
		return str_repeat( '•', 8 ) . substr( $plain, -4 );
	}
}
