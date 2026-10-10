<?php
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers PublishRank modules defensively so one damaged module cannot crash the whole plugin.
 */
final class ModuleLoader {
	private $failures = array();

	public function register( $class, $critical = false, $args = array() ) {
		$class = ltrim( (string) $class, '\\' );
		if ( '' === $class ) {
			return false;
		}
		try {
			if ( ! class_exists( $class ) ) {
				return $this->fail( $class, 'class_missing', $critical );
			}
			$module = new $class( ...$args );
			if ( ! is_object( $module ) || ! method_exists( $module, 'register' ) ) {
				return $this->fail( $class, 'register_method_missing', $critical );
			}
			$module->register();
			return true;
		} catch ( \Throwable $e ) {
			return $this->fail( $class, 'runtime_exception', $critical, $e->getMessage() );
		}
	}

	public function failures() {
		return $this->failures;
	}

	private function fail( $class, $reason, $critical, $message = '' ) {
		$this->failures[] = array(
			'class'    => $class,
			'reason'   => $reason,
			'critical' => (bool) $critical,
			'message'  => is_string( $message ) ? substr( $message, 0, 240 ) : '',
		);
		if ( function_exists( 'do_action' ) ) {
			do_action( 'publishrank_module_failed', $class, $reason, $critical, $message );
		}
		return false;
	}
}
