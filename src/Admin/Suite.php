<?php
/**
 * Shared helpers for the PublishRank Suite screens (2.23): assets, tabs, KPI tiles, switches,
 * pagination and flash messages. All output is escaped here.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class Suite {
	/** @var bool */
	private static $enqueued = false;

	/** Enqueue the suite CSS/JS and the REST configuration. */
	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/pr-suite.css';
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/pr-suite.js';
		wp_enqueue_style( 'publishrank-fa-core', Branding::asset_url( 'vendor/fontawesome-free-7.3.1/css/fontawesome.css' ), array(), '7.3.1' );
		wp_enqueue_style( 'publishrank-fa-solid', Branding::asset_url( 'vendor/fontawesome-free-7.3.1/css/solid.css' ), array( 'publishrank-fa-core' ), '7.3.1' );
		wp_enqueue_style( 'publishrank-suite', Branding::asset_url( 'css/pr-suite.css' ), array(), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		wp_enqueue_script( 'publishrank-suite', Branding::asset_url( 'js/pr-suite.js' ), array(), Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
		wp_localize_script(
			'publishrank-suite',
			'PRXConfig',
			array(
				'root'  => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'close'         => __( 'Close', 'publishrank' ),
					'cancel'        => __( 'Cancel', 'publishrank' ),
					'continue'      => __( 'Continue', 'publishrank' ),
					'confirm'       => __( 'Are you sure?', 'publishrank' ),
					/* translators: %s: word to type */
					'typeToConfirm' => __( 'Type %s to confirm', 'publishrank' ),
					'copied'        => __( 'Copied to clipboard', 'publishrank' ),
					'noData'        => __( 'No data yet', 'publishrank' ),
					'undo'          => __( 'Undo', 'publishrank' ),
					'saved'         => __( 'Saved', 'publishrank' ),
					'error'         => __( 'Something went wrong.', 'publishrank' ),
				),
			)
		);
	}

	/** Enqueue on a given admin page slug. */
	public static function enqueue_for( $slug ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $page, (array) $slug, true ) ) {
			self::enqueue();
		}
	}

	public static function current_page() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** Admin URL for a PublishRank page. */
	public static function url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/** Flash and redirect back. */
	public static function done( $page, $type, $message, array $args = array(), $hash = '' ) {
		AdminAssets::flash( $type, $message );
		wp_safe_redirect( self::url( $page, $args ) . ( $hash ? '#' . rawurlencode( $hash ) : '' ) );
		exit;
	}

	/** Verify capability and nonce for an admin-post handler. */
	public static function guard( $action, $key ) {
		if ( ! \PublishRankSEO\Core\Capabilities::can( $key ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/** Open a suite screen. */
	public static function open( $title, $subtitle = '' ) {
		echo '<div class="wrap pr21 prx">';
		AdminAssets::header( $title, $subtitle );
		AdminAssets::render_flash();
	}

	public static function close() {
		echo '</div>';
	}

	/**
	 * Print a tab list. Panels must use id="panel-{key}".
	 *
	 * @param array<string,string> $tabs key => label (may contain a count after a pipe: "Label|3").
	 */
	public static function tabs( array $tabs, $current = '' ) {
		echo '<div class="prx-tabs" role="tablist" data-prx-tabs="hash">';
		$first = true;
		foreach ( $tabs as $key => $label ) {
			$count = '';
			if ( false !== strpos( (string) $label, '|' ) ) {
				list( $label, $count ) = explode( '|', (string) $label, 2 );
			}
			$selected = $current ? $current === $key : $first;
			echo '<button type="button" class="prx-tab" role="tab" id="tab-' . esc_attr( $key ) . '" aria-controls="panel-' . esc_attr( $key ) . '" aria-selected="' . ( $selected ? 'true' : 'false' ) . '">' . esc_html( $label ) . ( '' !== $count ? ' <span class="prx-count">' . esc_html( $count ) . '</span>' : '' ) . '</button>';
			$first = false;
		}
		echo '</div>';
	}

	public static function panel_open( $key, $hidden = true ) {
		echo '<div class="prx-panel prx-stack" role="tabpanel" id="panel-' . esc_attr( $key ) . '" aria-labelledby="tab-' . esc_attr( $key ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';
	}

	public static function panel_close() {
		echo '</div>';
	}

	public static function card_open( $title, $icon = '', $sub = '', $actions = '' ) {
		echo '<section class="prx-card"><div class="prx-card__head"><div><h2 class="prx-card__title">' . ( $icon ? '<span class="fa-solid ' . esc_attr( $icon ) . '" aria-hidden="true"></span>' : '' ) . esc_html( $title ) . '</h2>';
		if ( '' !== $sub ) {
			echo '<p class="prx-card__sub">' . esc_html( $sub ) . '</p>';
		}
		echo '</div>' . wp_kses_post( $actions ) . '</div>';
	}

	public static function card_close() {
		echo '</section>';
	}

	/** KPI tile. */
	public static function kpi( $label, $value, $meta = '', $accent = false ) {
		echo '<div class="prx-kpi' . ( $accent ? ' prx-kpi--accent' : '' ) . '"><span class="prx-kpi__label">' . esc_html( $label ) . '</span><span class="prx-kpi__value">' . esc_html( (string) $value ) . '</span>';
		if ( '' !== (string) $meta ) {
			echo '<span class="prx-kpi__meta">' . esc_html( (string) $meta ) . '</span>';
		}
		echo '</div>';
	}

	/** Status badge. */
	public static function badge( $text, $tone = '' ) {
		return '<span class="prx-badge' . ( $tone ? ' prx-badge--' . esc_attr( $tone ) : '' ) . '">' . esc_html( (string) $text ) . '</span>';
	}

	/** Toggle switch row. */
	public static function switch_field( $name, $checked, $label, $help = '', $value = '1' ) {
		$id = 'prx-' . substr( md5( $name . $value ), 0, 10 );
		echo '<label class="prx-switch" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( (bool) $checked, true, false ) . ' /><span class="prx-switch__ui" aria-hidden="true"></span><span class="prx-switch__text"><strong>' . esc_html( $label ) . '</strong>' . ( '' !== $help ? '<span>' . esc_html( $help ) . '</span>' : '' ) . '</span></label>';
	}

	/** Text/number/select field. */
	public static function field( $type, $name, $value, $label, $help = '', array $attrs = array(), array $options = array() ) {
		$id   = 'prx-' . substr( md5( $name ), 0, 10 );
		$attr = '';
		foreach ( $attrs as $k => $v ) {
			$attr .= ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		echo '<div class="prx-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		if ( 'select' === $type ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			foreach ( $options as $k => $v ) {
				echo '<option value="' . esc_attr( (string) $k ) . '"' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( (string) $v ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'textarea' === $type ) {
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $attr . '>' . esc_textarea( (string) $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<input class="prx-input" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . $attr . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	/** Pagination links that keep the current query arguments. */
	public static function pagination( $total, $per_page, $paged ) {
		$pages = max( 1, (int) ceil( $total / max( 1, $per_page ) ) );
		if ( $pages <= 1 ) {
			return;
		}
		$paged = max( 1, min( $pages, (int) $paged ) );
		echo '<nav class="prx-pagination" aria-label="' . esc_attr__( 'Pagination', 'publishrank' ) . '"><span>' . esc_html( sprintf( /* translators: 1: page, 2: pages */ __( 'Page %1$d of %2$d', 'publishrank' ), $paged, $pages ) ) . '</span>';
		if ( $paged > 1 ) {
			echo '<a class="prx-btn prx-btn--sm" href="' . esc_url( add_query_arg( 'paged', $paged - 1 ) ) . '">' . esc_html__( '← Previous', 'publishrank' ) . '</a>';
		}
		if ( $paged < $pages ) {
			echo '<a class="prx-btn prx-btn--sm" href="' . esc_url( add_query_arg( 'paged', $paged + 1 ) ) . '">' . esc_html__( 'Next →', 'publishrank' ) . '</a>';
		}
		echo '</nav>';
	}

	/** Start an admin-post form. */
	public static function form_open( $action, array $attrs = array() ) {
		$attr = '';
		foreach ( $attrs as $k => $v ) {
			$attr .= ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
	}

	/** Relative "time ago" for a GMT MySQL datetime or timestamp. */
	public static function ago( $when ) {
		$ts = is_numeric( $when ) ? (int) $when : strtotime( (string) $when . ( preg_match( '/[zZ]|[+-]\d\d:?\d\d$/', (string) $when ) ? '' : ' UTC' ) );
		if ( ! $ts ) {
			return '—';
		}
		/* translators: %s: human time difference */
		return sprintf( __( '%s ago', 'publishrank' ), human_time_diff( $ts, time() ) );
	}

	/** Stream a CSV download and exit. Values are neutralised against spreadsheet formula injection. */
	public static function csv( $filename, array $header, $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, $header, ',', '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), (array) $row ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	public static function csv_cell( $value ) {
		$value = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
