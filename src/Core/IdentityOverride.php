<?php
/** Keep site identity synchronized across native WordPress and legacy PublishRank settings. */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class IdentityOverride {
	const OPTION = 'publishrank_override_wp_identity'; // Retained for import compatibility; identity is always shared.
	const MIGRATION = 'publishrank_shared_identity_version';
	private $running = false;

	public static function groups() {
		return array(
			array( 'publishrank_identity_name', 'publishrank_seo_publication_name', 'blogname', 'publishrank_home_title', 'publishrank_seo_publisher_name' ),
			array( 'publishrank_identity_description', 'publishrank_schema_org_description', 'blogdescription', 'publishrank_home_description' ),
		);
	}

	public function register() {
		add_action( 'updated_option', array( $this, 'updated' ), 5, 3 );
		add_action( 'added_option', array( $this, 'added' ), 5, 2 );
		add_action( 'deleted_option', array( $this, 'deleted' ), 5, 1 );
		add_action( 'init', array( $this, 'migrate' ), 101 );
	}

	public static function enabled() {
		return true;
	}

	public function updated( $option, $old, $value ) {
		$this->sync( $option, $value );
	}

	public function added( $option, $value ) {
		// Empty installer defaults must not erase an existing site's identity.
		if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			$this->sync( $option, $value );
		}
	}

	public function deleted( $option ) {
		foreach ( self::groups() as $group ) {
			if ( in_array( $option, $group, true ) ) {
				foreach ( $group as $peer ) {
					if ( $peer !== $option && '' !== (string) get_option( $peer, '' ) ) {
						$this->sync( $peer, get_option( $peer ) );
						return;
					}
				}
			}
		}
	}

	private function sync( $option, $value ) {
		if ( $this->running ) {
			return;
		}
		foreach ( self::groups() as $group ) {
			if ( ! in_array( $option, $group, true ) ) {
				continue;
			}
			$this->running = true;
			try {
				$value = sanitize_text_field( (string) $value );
				foreach ( $group as $peer ) {
					update_option( $peer, $value );
				}
			} finally {
				$this->running = false;
			}
			return;
		}
	}

	public function migrate() {
		if ( get_option( self::MIGRATION ) ) {
			return;
		}
		$backup = array();
		foreach ( self::groups() as $group ) {
			foreach ( $group as $option ) {
				$backup[ $option ] = get_option( $option, null );
			}
		}
		add_option( 'publishrank_shared_identity_backup', $backup, '', false );
		foreach ( self::groups() as $group ) {
			foreach ( $group as $option ) {
				$value = trim( (string) get_option( $option, '' ) );
				if ( '' !== $value ) {
					$this->sync( $option, $value );
					break;
				}
			}
		}
		update_option( self::MIGRATION, 1, false );
	}
}