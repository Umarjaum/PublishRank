<?php
namespace PublishRankSEO\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Local tamper/integrity monitor. Detection only; it never phones home and never blocks GPL editing.
 */
final class IntegrityManager {
	const OPTION = 'publishrank_integrity_manifest';
	/** Bumped when packaged files are rebuilt without a plugin version change, so the baseline is re-created. */
	const BUILD = 'r2';
	const STATUS_OPTION = 'publishrank_integrity_status';
	const SCHEMA = 1;

	public function register() {
		add_action( 'admin_init', array( $this, 'check' ), 20 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ), 30 );
		add_action( 'publishrank_module_failed', array( $this, 'record_module_failure' ), 10, 4 );
		add_filter( 'publishrank_integrity_status', array( $this, 'status' ) );
	}

	/** Create a baseline during activation or after a versioned update. */
	public static function establish_baseline( $force = false ) {
		$existing = get_option( self::OPTION, array() );
		$version  = defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : 'unknown';
		if ( ! $force && is_array( $existing ) && isset( $existing['version'], $existing['files'] ) && $version === $existing['version'] && ( $existing['build'] ?? '' ) === self::BUILD ) {
			return;
		}
		$manifest = self::build_manifest();
		if ( ! empty( $manifest['files'] ) ) {
			update_option( self::OPTION, $manifest, false );
			update_option( self::STATUS_OPTION, array( 'state' => 'baseline', 'checked_at' => gmdate( 'c' ), 'missing' => array(), 'changed' => array(), 'unexpected' => array() ), false );
		}
	}

	public function check() {
		$manifest = get_option( self::OPTION, array() );
		$version  = defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : 'unknown';
		if ( ! is_array( $manifest ) || empty( $manifest['files'] ) ) {
			self::establish_baseline();
			return;
		}
		if ( ( isset( $manifest['version'] ) && $manifest['version'] !== $version ) || ( $manifest['build'] ?? '' ) !== self::BUILD ) {
			self::establish_baseline( true );
			return;
		}
		$result = self::compare_manifest( $manifest );
		update_option( self::STATUS_OPTION, $result, false );
	}

	public function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$status = get_option( self::STATUS_OPTION, array() );
		if ( ! is_array( $status ) || 'tampered' !== ( $status['state'] ?? '' ) ) { return; }
		$missing = isset( $status['missing'] ) ? count( (array) $status['missing'] ) : 0;
		$changed = isset( $status['changed'] ) ? count( (array) $status['changed'] ) : 0;
		$unexpected = isset( $status['unexpected'] ) ? count( (array) $status['unexpected'] ) : 0;
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'PublishRank integrity warning:', 'publishrank' ) . '</strong> ' . esc_html( sprintf( __( '%d missing, %d changed and %d unexpected PHP files were detected. PublishRank will continue with defensive module loading where possible.', 'publishrank' ), $missing, $changed, $unexpected ) ) . '</p></div>';
	}

	public function status( $status ) {
		$saved = get_option( self::STATUS_OPTION, array() );
		return is_array( $saved ) ? $saved : $status;
	}

	public function record_module_failure( $class, $reason, $critical, $message ) {
		$status = get_option( self::STATUS_OPTION, array() );
		if ( ! is_array( $status ) ) { $status = array(); }
		$status['module_failures'] = isset( $status['module_failures'] ) && is_array( $status['module_failures'] ) ? $status['module_failures'] : array();
		$status['module_failures'][] = array( 'class' => sanitize_text_field( $class ), 'reason' => sanitize_key( $reason ), 'critical' => (bool) $critical, 'message' => sanitize_text_field( $message ), 'at' => gmdate( 'c' ) );
		$status['module_failures'] = array_slice( $status['module_failures'], -20 );
		update_option( self::STATUS_OPTION, $status, false );
	}

	public static function build_manifest() {
		$root = defined( 'PUBLISHRANK_SEO_PATH' ) ? PUBLISHRANK_SEO_PATH : dirname( dirname( __DIR__ ) ) . '/';
		$files = array();
		$targets = array( $root . 'publishrank.php' );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . 'src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) { $targets[] = $file->getPathname(); }
		}
		foreach ( $targets as $path ) {
			if ( is_readable( $path ) ) {
				$relative = ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
				$files[ $relative ] = hash_file( 'sha256', $path );
			}
		}
		ksort( $files );
		return array( 'schema' => self::SCHEMA, 'version' => defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : 'unknown', 'build' => self::BUILD, 'created_at' => gmdate( 'c' ), 'files' => $files );
	}

	public static function compare_manifest( $manifest ) {
		$current = self::build_manifest();
		$expected = isset( $manifest['files'] ) && is_array( $manifest['files'] ) ? $manifest['files'] : array();
		$actual = $current['files'];
		$missing = array(); $changed = array(); $unexpected = array();
		foreach ( $expected as $file => $hash ) {
			if ( ! isset( $actual[ $file ] ) ) { $missing[] = $file; }
			elseif ( ! hash_equals( (string) $hash, (string) $actual[ $file ] ) ) { $changed[] = $file; }
		}
		foreach ( $actual as $file => $hash ) { if ( ! array_key_exists( $file, $expected ) ) { $unexpected[] = $file; } }
		return array( 'state' => ( $missing || $changed || $unexpected ) ? 'tampered' : 'healthy', 'checked_at' => gmdate( 'c' ), 'missing' => $missing, 'changed' => $changed, 'unexpected' => $unexpected );
	}
}
