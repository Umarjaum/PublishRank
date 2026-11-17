<?php
/**
 * Keep the PublishRank logo in sync with WordPress's own Site Logo and Site Icon.
 *
 * PublishRank stores the publisher logo as an image address (publishrank_identity_logo and its legacy mirror
 * publishrank_seo_publisher_logo). WordPress stores the Site Logo as a Media Library item in the active theme's
 * custom_logo theme mod and the site_logo option (block themes), and the Site Icon in site_icon.
 *
 * - Choosing a logo in PublishRank sets the WordPress Site Logo (when the image is in the Media Library), and the
 *   Site Icon too when none exists and the image is square and at least 512 px.
 * - Choosing a logo in Appearance → Customize / Site Editor updates PublishRank.
 * - Switching theme carries the logo to the new theme.
 * - Nothing is ever downloaded from another site; external addresses are kept in PublishRank only.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class LogoSync {
	const PR_OPTIONS = array( 'publishrank_identity_logo', 'publishrank_seo_publisher_logo' );
	const MIGRATION  = 'publishrank_logo_sync_version';
	const STATUS     = 'publishrank_logo_sync_status';

	/** @var bool */
	private static $running = false;

	public function register() {
		foreach ( self::PR_OPTIONS as $o ) {
			add_action( 'update_option_' . $o, array( $this, 'from_publishrank' ), 20, 2 );
			add_action( 'add_option_' . $o, array( $this, 'added_publishrank' ), 20, 2 );
		}
		add_action( 'update_option_site_logo', array( $this, 'from_site_logo' ), 20, 2 );
		add_action( 'add_option_site_logo', array( $this, 'added_site_logo' ), 20, 2 );
		add_filter( 'pre_set_theme_mod_custom_logo', array( $this, 'from_custom_logo' ), 20, 2 );
		add_action( 'after_switch_theme', array( $this, 'carry_to_theme' ), 20 );
		add_action( 'init', array( $this, 'migrate' ), 102 );
	}

	/** Media Library ID for an image address on this site, or 0. */
	public static function attachment_for( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return 0;
		}
		$id = (int) attachment_url_to_postid( $url );
		if ( ! $id ) {
			// Sized variants ("logo-300x100.png") resolve to their original.
			$id = (int) attachment_url_to_postid( (string) preg_replace( '#-\d+x\d+(?=\.[a-z0-9]+$)#i', '', $url ) );
		}
		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/** Current WordPress Site Logo ID (theme mod first, then the block-theme option). */
	public static function wp_logo_id() {
		$id = (int) get_theme_mod( 'custom_logo', 0 );
		if ( ! $id ) {
			$id = (int) get_option( 'site_logo', 0 );
		}
		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/** Status for the settings screen. @return array{pr:string,wp_logo:int,wp_icon:int,synced:bool,note:string} */
	public static function status() {
		$pr  = (string) get_option( 'publishrank_identity_logo', '' );
		$pr  = '' !== $pr ? $pr : (string) get_option( 'publishrank_seo_publisher_logo', '' );
		$wp  = self::wp_logo_id();
		$ico = (int) get_option( 'site_icon', 0 );
		$syn = $wp && '' !== $pr && self::attachment_for( $pr ) === $wp;
		$st  = get_option( self::STATUS, array() );
		return array( 'pr' => $pr, 'wp_logo' => $wp, 'wp_icon' => $ico, 'synced' => $syn, 'note' => is_array( $st ) && isset( $st['note'] ) ? (string) $st['note'] : '' );
	}

	private static function note( $note ) {
		update_option( self::STATUS, array( 'note' => (string) $note, 'time' => time() ), false );
	}

	/** Write the logo into WordPress. */
	private static function push_to_wp( $url ) {
		$id = self::attachment_for( $url );
		if ( ! $id ) {
			if ( '' !== trim( (string) $url ) ) {
				self::note( 'external' );
			}
			return 0;
		}
		self::$running = true;
		try {
			if ( (int) get_theme_mod( 'custom_logo', 0 ) !== $id ) {
				set_theme_mod( 'custom_logo', $id );
			}
			if ( (int) get_option( 'site_logo', 0 ) !== $id ) {
				update_option( 'site_logo', $id );
			}
			if ( ! (int) get_option( 'site_icon', 0 ) ) {
				$meta = wp_get_attachment_metadata( $id );
				if ( is_array( $meta ) && ! empty( $meta['width'] ) && (int) $meta['width'] === (int) $meta['height'] && (int) $meta['width'] >= 512 ) {
					update_option( 'site_icon', $id );
				}
			}
		} finally {
			self::$running = false;
		}
		self::note( 'synced' );
		return $id;
	}

	/** Write a Media Library logo into PublishRank. */
	private static function push_to_pr( $id ) {
		$id  = (int) $id;
		$url = $id && wp_attachment_is_image( $id ) ? (string) wp_get_attachment_image_url( $id, 'full' ) : '';
		if ( '' === $url ) {
			return;
		}
		self::$running = true;
		try {
			foreach ( self::PR_OPTIONS as $o ) {
				if ( (string) get_option( $o, '' ) !== $url ) {
					update_option( $o, $url );
				}
			}
		} finally {
			self::$running = false;
		}
		self::note( 'synced' );
	}

	public function from_publishrank( $old, $value ) {
		if ( self::$running ) {
			return;
		}
		$value = (string) $value;
		// Keep the two PublishRank fields identical.
		self::$running = true;
		try {
			foreach ( self::PR_OPTIONS as $o ) {
				if ( (string) get_option( $o, '' ) !== $value ) {
					update_option( $o, $value );
				}
			}
		} finally {
			self::$running = false;
		}
		if ( '' !== trim( $value ) ) {
			self::push_to_wp( $value );
		}
	}

	public function added_publishrank( $option, $value ) {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$this->from_publishrank( '', $value );
		}
	}

	public function from_site_logo( $old, $value ) {
		if ( ! self::$running && (int) $value ) {
			self::push_to_pr( (int) $value );
		}
	}

	public function added_site_logo( $option, $value ) {
		$this->from_site_logo( 0, $value );
	}

	/** Filter (runs before the theme mod is saved): mirror to PublishRank and the block-theme option. */
	public function from_custom_logo( $value, $old = null ) {
		if ( ! self::$running && (int) $value && (int) $value !== (int) $old ) {
			self::push_to_pr( (int) $value );
			self::$running = true;
			try {
				update_option( 'site_logo', (int) $value );
			} finally {
				self::$running = false;
			}
		}
		return $value;
	}

	public function carry_to_theme() {
		$st = self::status();
		if ( ! (int) get_theme_mod( 'custom_logo', 0 ) && '' !== $st['pr'] ) {
			self::push_to_wp( $st['pr'] );
		}
	}

	/** One-time alignment on upgrade: fill whichever side is empty. */
	public function migrate() {
		if ( (int) get_option( self::MIGRATION, 0 ) >= 1 ) {
			return;
		}
		update_option( self::MIGRATION, 1, false );
		$st = self::status();
		if ( '' === $st['pr'] && $st['wp_logo'] ) {
			self::push_to_pr( $st['wp_logo'] );
		} elseif ( '' !== $st['pr'] && ! $st['wp_logo'] ) {
			self::push_to_wp( $st['pr'] );
		}
	}
}
