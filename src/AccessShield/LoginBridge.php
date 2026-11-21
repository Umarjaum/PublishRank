<?php
/**
 * Access Shield login-address helpers used by Settings, the Setup wizard and Import / Export.
 *
 * Access Shield is PublishRank's only login protection. This class keeps the two shared option names
 * (sign-in address and blocked-request destination) in sync, and lists storage keys that PublishRank
 * releases before 2.24 wrote so upgrades and uninstall can delete them. Those keys are internal
 * identifiers only; they never appear in the interface.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

defined( 'ABSPATH' ) || exit;

final class LoginBridge {
	const PAGE            = 'publishrank-access-shield';
	const SLUG_OPTION     = 'publishrank_login_slug';
	const REDIRECT_OPTION = 'publishrank_login_redirect';

	/** Storage keys written by PublishRank releases before Access Shield (2.24). Deleted once, never read for behaviour. */
	const LEGACY_OPTIONS = array(
		'publishrank_private_login_enabled',
		'publishrank_private_login_deferred',
		'publishrank_private_login_storage',
		'publishrank_hide_login_enabled',
		'publishrank_hide_login_deferred',
		'publishrank_wp_hide_login',
		'publishrank_wp_hide_login_apache_rules_active',
	);

	/** @var self|null */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Keep the two shared option names (used by Settings and the wizard) in sync with Access Shield. */
	public static function bridge() {
		add_action( 'update_option_' . self::SLUG_OPTION, array( __CLASS__, 'from_slug_option' ), 10, 2 );
		add_action( 'add_option_' . self::SLUG_OPTION, array( __CLASS__, 'from_slug_option_add' ), 10, 2 );
		add_action( 'update_option_' . self::REDIRECT_OPTION, array( __CLASS__, 'from_redirect_option' ), 10, 2 );
		add_action( 'add_option_' . self::REDIRECT_OPTION, array( __CLASS__, 'from_redirect_option_add' ), 10, 2 );
		// The pre_option filters below make get_option() report Access Shield's value, so on a fresh site (no stored row)
		// update_option() finds nothing to update and never fires update_option_/add_option_. Sync before the write instead.
		add_filter( 'pre_update_option_' . self::SLUG_OPTION, array( __CLASS__, 'sync_slug_before_save' ), 10, 2 );
		add_filter( 'pre_update_option_' . self::REDIRECT_OPTION, array( __CLASS__, 'sync_redirect_before_save' ), 10, 2 );
		add_filter( 'pre_option_' . self::SLUG_OPTION, array( __CLASS__, 'current_login_slug' ) );
		add_filter( 'pre_option_' . self::REDIRECT_OPTION, array( __CLASS__, 'current_redirect_slug' ) );
		add_action( 'admin_init', array( __CLASS__, 'forget_legacy_options' ) );
	}

	/** Once Access Shield has imported the old values, the old module's options are deleted. */
	public static function forget_legacy_options() {
		if ( ! get_option( AccessShield::IMPORTED ) || get_option( 'publishrank_legacy_login_removed' ) ) {
			return;
		}
		foreach ( self::LEGACY_OPTIONS as $name ) {
			delete_option( $name );
		}
		delete_site_transient( 'publishrank_private_login_comment_notice' );
		update_option( 'publishrank_legacy_login_removed', gmdate( 'c' ), false );
	}

	public static function from_slug_option( $old, $new ) {
		$slug = LoginAddress::clean_slug( (string) $new );
		if ( '' !== $slug && $slug !== Settings::get( 'login_slug' ) && true === LoginAddress::validate_slug( $slug ) ) {
			$prev = Settings::all();
			Settings::update( array( 'login_slug' => $slug ) );
			if ( ! empty( $prev['login_enabled'] ) ) {
				LoginAddress::begin_change( $prev, $slug );
			}
		}
	}

	/** @param mixed $value @param mixed $old */
	public static function sync_slug_before_save( $value, $old ) {
		self::from_slug_option( $old, $value );
		return $value;
	}

	/** @param mixed $value @param mixed $old */
	public static function sync_redirect_before_save( $value, $old ) {
		self::from_redirect_option( $old, $value );
		return $value;
	}

	public static function from_slug_option_add( $name, $value ) {
		self::from_slug_option( '', $value );
	}

	public static function from_redirect_option( $old, $new ) {
		$r = self::clean_redirect_slug( (string) $new );
		Settings::update( '404' === $r || '' === $r ? array( 'blocked_behaviour' => '404', 'blocked_redirect' => '' ) : array( 'blocked_behaviour' => 'redirect', 'blocked_redirect' => $r ) );
	}

	public static function from_redirect_option_add( $name, $value ) {
		self::from_redirect_option( '', $value );
	}

	public static function enabled() {
		return LoginAddress::active();
	}

	public static function is_deferred() {
		return ! Settings::get( 'login_enabled' ) && '' !== (string) Settings::get( 'login_slug' ) && ! get_option( AccessShield::IMPORTED . '_confirmed' );
	}

	/** Setup wizard: the owner has seen the address — switch it on with the 10-minute safety window. */
	public static function confirm_login_address() {
		$prev = Settings::all();
		$slug = (string) $prev['login_slug'];
		if ( '' === $slug || true !== LoginAddress::validate_slug( $slug ) ) {
			return;
		}
		update_option( AccessShield::IMPORTED . '_confirmed', 1, false );
		if ( empty( $prev['login_enabled'] ) ) {
			Settings::update( array( 'login_enabled' => 1 ) );
			LoginAddress::begin_change( $prev, $slug );
		}
	}

	public static function current_login_slug( $blog_id = 0 ) {
		return LoginAddress::slug( is_numeric( $blog_id ) ? (int) $blog_id : 0 );
	}

	public static function current_redirect_slug() {
		return 'redirect' === Settings::get( 'blocked_behaviour' ) ? (string) Settings::get( 'blocked_redirect' ) : '404';
	}

	public static function clean_login_slug( $raw ) {
		$slug = LoginAddress::clean_slug( $raw );
		return in_array( $slug, LoginAddress::reserved(), true ) ? '' : $slug;
	}

	public static function clean_redirect_slug( $raw ) {
		$r = trim( sanitize_title( (string) $raw ) );
		return '' === $r ? '404' : $r;
	}

	public function new_login_url( $scheme = null ) {
		return '' !== LoginAddress::slug() ? LoginAddress::url( $scheme ) : wp_login_url();
	}

	/**
	 * Normalise an imported PublishRank settings file: drop option names that only older PublishRank
	 * releases wrote, so an old export can never re-create retired storage. @param array<string,mixed> $data
	 */
	public static function translate_import( array $data ) {
		foreach ( self::LEGACY_OPTIONS as $legacy ) {
			unset( $data[ $legacy ] );
		}
		return $data;
	}

	/** Option names uninstall removes. @return string[] */
	public static function uninstall_options() {
		return array_merge( array( self::SLUG_OPTION, self::REDIRECT_OPTION, 'publishrank_legacy_login_removed' ), self::LEGACY_OPTIONS );
	}
}
