<?php
/**
 * Brand-coloured charts for PublishRank screens.
 *
 * Small charts (donut, bars, gauge, sparkline) are drawn here as accessible SVG so they work without
 * JavaScript. Interactive charts (multi-series line/area with Days/Weeks/Months, the world dot map)
 * are containers filled by assets/js/pr-charts.js. Colours come from the PublishRank palette
 * (plum, evergreen, brass, slate, coral) defined in assets/css/pr-analytics.css.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class Charts {
	const TONES = array( 'plum', 'teal', 'brass', 'slate', 'coral', 'sage', 'ink', 'rose' );

	/** Hex values (mirrors the CSS variables) for SVG drawn on the server. */
	const HEX = array(
		'plum'  => '#9B1245',
		'teal'  => '#3D7264',
		'brass' => '#B08A45',
		'slate' => '#4B5666',
		'coral' => '#C4574F',
		'sage'  => '#7FA393',
		'ink'   => '#252833',
		'rose'  => '#D88AA6',
		'track' => '#EDE7DE',
	);

	public static function enqueue( $map = false ) {
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/pr-analytics.css';
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/pr-charts.js';
		wp_enqueue_style( 'publishrank-analytics', Branding::asset_url( 'css/pr-analytics.css' ), array(), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		$deps = array();
		if ( $map ) {
			wp_enqueue_script( 'publishrank-worldmap', Branding::asset_url( 'js/pr-worldmap.js' ), array(), Branding::version(), true );
			$deps[] = 'publishrank-worldmap';
		}
		wp_enqueue_script( 'publishrank-charts', Branding::asset_url( 'js/pr-charts.js' ), $deps, Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
		wp_localize_script(
			'publishrank-charts',
			'PRCharts',
			array(
				'root'   => esc_url_raw( rest_url( 'publishrank/v1/' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'locale' => str_replace( '_', '-', get_user_locale() ),
				'i18n'   => array(
					'days'   => __( 'Days', 'publishrank' ),
					'weeks'  => __( 'Weeks', 'publishrank' ),
					'months' => __( 'Months', 'publishrank' ),
					'noData' => __( 'No data for this period yet', 'publishrank' ),
					'weekOf' => __( 'Week of', 'publishrank' ),
				),
			)
		);
	}

	private static function hex( $tone ) {
		return isset( self::HEX[ $tone ] ) ? self::HEX[ $tone ] : self::HEX['plum'];
	}

	/**
	 * Donut chart with legend.
	 *
	 * @param array<int,array{label:string,value:int|float,color?:string}> $segments
	 */
	public static function donut( array $segments, $center = '', $center_label = '' ) {
		$total = 0;
		foreach ( $segments as $s ) {
			$total += max( 0, (float) $s['value'] );
		}
		$r    = 54;
		$circ = 2 * M_PI * $r;
		$off  = 0;
		$svg  = '<svg class="prc-donut__svg" viewBox="0 0 140 140" role="img" aria-label="' . esc_attr( $center_label ) . '"><circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="' . esc_attr( self::HEX['track'] ) . '" stroke-width="20"/>';
		$leg  = '<ul class="prc-legend">';
		$i    = 0;
		foreach ( $segments as $s ) {
			$tone = isset( $s['color'] ) ? $s['color'] : self::TONES[ $i % count( self::TONES ) ];
			$v    = max( 0, (float) $s['value'] );
			$pct  = $total > 0 ? $v / $total : 0;
			if ( $pct > 0 ) {
				$len  = $pct * $circ;
				$svg .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="' . esc_attr( self::hex( $tone ) ) . '" stroke-width="20" stroke-dasharray="' . esc_attr( round( max( 0, $len - 1.5 ), 2 ) . ' ' . round( $circ, 2 ) ) . '" stroke-dashoffset="' . esc_attr( (string) round( -$off, 2 ) ) . '" transform="rotate(-90 70 70)"><title>' . esc_html( $s['label'] . ': ' . number_format_i18n( $v ) ) . '</title></circle>';
				$off += $len;
			}
			$leg .= '<li><span class="prc-dot prc-dot--' . esc_attr( $tone ) . '"></span><span class="prc-legend__label">' . esc_html( $s['label'] ) . '</span><strong>' . esc_html( $total > 0 ? number_format_i18n( $pct * 100, 1 ) . '%' : '—' ) . '</strong></li>';
			++$i;
		}
		$svg .= '<text x="70" y="68" text-anchor="middle" class="prc-donut__value">' . esc_html( '' !== (string) $center ? (string) $center : number_format_i18n( $total ) ) . '</text><text x="70" y="86" text-anchor="middle" class="prc-donut__label">' . esc_html( $center_label ) . '</text></svg>';
		$leg .= '</ul>';
		return '<div class="prc-donut">' . $svg . $leg . '</div>';
	}

	/**
	 * Horizontal bars.
	 *
	 * @param array<int,array{label:string,value:int|float,url?:string,meta?:string,icon?:string,tone?:string}> $rows
	 */
	public static function bars( array $rows, $max = 0, $suffix = '', $percent = false ) {
		if ( ! $rows ) {
			return '<p class="prc-empty">' . esc_html__( 'No data for this period yet', 'publishrank' ) . '</p>';
		}
		$max   = $max > 0 ? $max : max( 1, max( array_map( static function ( $r ) { return (float) $r['value']; }, $rows ) ) );
		$total = array_sum( array_map( static function ( $r ) { return (float) $r['value']; }, $rows ) );
		$out   = '<ul class="prc-bars">';
		foreach ( $rows as $i => $r ) {
			$w     = max( 1.5, min( 100, 100 * (float) $r['value'] / $max ) );
			$tone  = isset( $r['tone'] ) ? $r['tone'] : 'plum';
			$label = ( isset( $r['icon'] ) ? '<span class="prc-bars__icon" aria-hidden="true">' . esc_html( $r['icon'] ) . '</span>' : '' ) . esc_html( $r['label'] );
			$label = isset( $r['url'] ) ? '<a href="' . esc_url( $r['url'] ) . '">' . $label . '</a>' : $label;
			$val   = $percent && $total > 0 ? number_format_i18n( 100 * (float) $r['value'] / $total, 1 ) . '%' : number_format_i18n( (float) $r['value'] ) . $suffix;
			$out  .= '<li><span class="prc-bars__label">' . $label . '</span><span class="prc-bars__track"><span class="prc-bars__fill prc-bg--' . esc_attr( $tone ) . '" style="width:' . esc_attr( (string) round( $w, 1 ) ) . '%"></span></span><span class="prc-bars__value">' . esc_html( $val ) . '</span></li>';
		}
		return $out . '</ul>';
	}

	/** Semi-circle score gauge (0–100). */
	public static function gauge( $score ) {
		$score = max( 0, min( 100, (int) $score ) );
		$tone  = $score >= 80 ? 'teal' : ( $score >= 50 ? 'brass' : 'plum' );
		$len   = M_PI * 60;
		return '<div class="prc-gauge"><svg viewBox="0 0 150 86" role="img" aria-label="' . esc_attr( $score . '/100' ) . '"><path d="M15 78 A60 60 0 0 1 135 78" fill="none" stroke="' . esc_attr( self::HEX['track'] ) . '" stroke-width="14" stroke-linecap="round"/><path d="M15 78 A60 60 0 0 1 135 78" fill="none" stroke="' . esc_attr( self::hex( $tone ) ) . '" stroke-width="14" stroke-linecap="round" stroke-dasharray="' . esc_attr( round( $len * $score / 100, 2 ) . ' ' . round( $len, 2 ) ) . '"/><text x="75" y="70" text-anchor="middle" class="prc-gauge__value">' . (int) $score . '</text></svg></div>';
	}

	/** Tiny area sparkline. @param array<int,int|float> $values */
	public static function sparkline( array $values, $tone = 'plum' ) {
		$values = array_values( array_map( 'floatval', $values ) );
		$n      = count( $values );
		if ( $n < 2 ) {
			$values = array( 0, 0 );
			$n      = 2;
		}
		$max = max( 1, max( $values ) );
		$w   = 120;
		$h   = 40;
		$pts = array();
		foreach ( $values as $i => $v ) {
			$pts[] = round( $i * $w / ( $n - 1 ), 1 ) . ',' . round( $h - 3 - ( $h - 8 ) * $v / $max, 1 );
		}
		$id   = 'prcsp' . wp_rand( 1000, 999999 );
		$hex  = self::hex( $tone );
		$line = implode( ' ', $pts );
		return '<svg class="prc-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true"><defs><linearGradient id="' . esc_attr( $id ) . '" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="' . esc_attr( $hex ) . '" stop-opacity=".32"/><stop offset="1" stop-color="' . esc_attr( $hex ) . '" stop-opacity="0"/></linearGradient></defs><polygon points="0,' . $h . ' ' . esc_attr( $line ) . ' ' . $w . ',' . $h . '" fill="url(#' . esc_attr( $id ) . ')"/><polyline points="' . esc_attr( $line ) . '" fill="none" stroke="' . esc_attr( $hex ) . '" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"/></svg>';
	}

	/**
	 * Interactive line/area chart container (drawn by pr-charts.js).
	 *
	 * @param string[]                                                      $labels Y-m-d dates.
	 * @param array<int,array{name:string,tone:string,values:array<int,float>}> $series
	 */
	public static function line( array $labels, array $series, $groupable = true, $height = 300, $format = 'number' ) {
		$data = array( 'labels' => array_values( $labels ), 'series' => array_values( $series ), 'group' => (bool) $groupable, 'format' => $format );
		$out  = '<div class="prc-line" style="--prc-h:' . (int) $height . 'px" data-prc-line="' . esc_attr( (string) wp_json_encode( $data ) ) . '">';
		$out .= '<noscript>' . esc_html__( 'Charts need JavaScript.', 'publishrank' ) . '</noscript></div>';
		return $out;
	}

	/** World dot map container. @param array<string,int> $countries ISO2 => value */
	public static function world( array $countries ) {
		$names = array();
		foreach ( array_keys( $countries ) as $cc ) {
			$names[ $cc ] = \PublishRankSEO\Analytics\Geo::name( $cc );
		}
		return '<div class="prc-map" data-prc-map="' . esc_attr( (string) wp_json_encode( array( 'values' => $countries, 'names' => $names ) ) ) . '" role="img" aria-label="' . esc_attr__( 'Visitors by country', 'publishrank' ) . '"></div>';
	}

	/** Weekday × hour heatmap. @param array<int,array<int,int>> $grid */
	public static function heatmap( array $grid ) {
		$max  = 1;
		foreach ( $grid as $row ) {
			$max = max( $max, max( $row ) );
		}
		$days = array( __( 'Mon', 'publishrank' ), __( 'Tue', 'publishrank' ), __( 'Wed', 'publishrank' ), __( 'Thu', 'publishrank' ), __( 'Fri', 'publishrank' ), __( 'Sat', 'publishrank' ), __( 'Sun', 'publishrank' ) );
		$out  = '<div class="prc-heat" role="table" aria-label="' . esc_attr__( 'Page views by weekday and hour', 'publishrank' ) . '">';
		foreach ( $grid as $d => $row ) {
			$out .= '<div class="prc-heat__row" role="row"><span class="prc-heat__day" role="rowheader">' . esc_html( $days[ $d ] ) . '</span>';
			foreach ( $row as $h => $v ) {
				$a    = $v > 0 ? 0.12 + 0.88 * $v / $max : 0;
				$out .= '<span class="prc-heat__cell" role="cell" style="--a:' . esc_attr( (string) round( $a, 3 ) ) . '" title="' . esc_attr( $days[ $d ] . ' ' . sprintf( '%02d:00', $h ) . ' — ' . number_format_i18n( $v ) ) . '"></span>';
			}
			$out .= '</div>';
		}
		$out .= '<div class="prc-heat__row prc-heat__hours" aria-hidden="true"><span class="prc-heat__day"></span>';
		for ( $h = 0; $h < 24; $h++ ) {
			$out .= '<span>' . ( 0 === $h % 3 ? esc_html( (string) $h ) : '' ) . '</span>';
		}
		return $out . '</div></div>';
	}

	/** Change badge vs the previous period. */
	public static function delta( $now, $before, $invert = false ) {
		if ( $before <= 0 ) {
			return $now > 0 ? '<span class="prc-delta prc-delta--new">' . esc_html__( 'new', 'publishrank' ) . '</span>' : '<span class="prc-delta prc-delta--flat">—</span>';
		}
		$pct  = ( $now - $before ) / $before * 100;
		$good = $invert ? $pct <= 0 : $pct >= 0;
		$cls  = abs( $pct ) < 0.5 ? 'flat' : ( $good ? 'up' : 'down' );
		$icon = $pct >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
		return '<span class="prc-delta prc-delta--' . esc_attr( $cls ) . '"><i class="fa-solid ' . esc_attr( $icon ) . '" aria-hidden="true"></i>' . esc_html( ( $pct >= 0 ? '+' : '' ) . number_format_i18n( $pct, 1 ) . '%' ) . '</span>';
	}

	/** KPI card with icon, value, change badge and sparkline. */
	public static function kpi( $label, $value, $delta_html, $icon, $tone, array $spark, $hint = '', $vs = null ) {
		echo '<div class="prc-kpi"><div class="prc-kpi__top"><span class="prc-kpi__icon prc-bg--' . esc_attr( $tone ) . '"><i class="fa-solid ' . esc_attr( $icon ) . '" aria-hidden="true"></i></span><span class="prc-kpi__label">' . esc_html( $label ) . ( $hint ? ' <span class="prc-hint" title="' . esc_attr( $hint ) . '">?</span>' : '' ) . '</span></div>';
		echo '<div class="prc-kpi__value">' . esc_html( (string) $value ) . '</div>';
		echo '<div class="prc-kpi__foot">' . wp_kses_post( $delta_html ) . '<span class="prc-kpi__vs">' . esc_html( null === $vs ? __( 'vs previous period', 'publishrank' ) : $vs ) . '</span></div>';
		echo '<div class="prc-kpi__spark">' . self::sparkline( $spark, $tone ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built with escaped attributes.
	}

	public static function card_open( $title, $icon = '', $sub = '', $extra = '', $class = '' ) {
		echo '<section class="prc-card ' . esc_attr( $class ) . '"><header class="prc-card__head"><div><h2 class="prc-card__title">' . ( $icon ? '<i class="fa-solid ' . esc_attr( $icon ) . '" aria-hidden="true"></i>' : '' ) . esc_html( $title ) . '</h2>' . ( $sub ? '<p class="prc-card__sub">' . esc_html( $sub ) . '</p>' : '' ) . '</div>' . wp_kses( $extra, self::allowed_extra() ) . '</header><div class="prc-card__body">';
	}

	public static function card_close() {
		echo '</div></section>';
	}

	/** Header extras may contain links, filter inputs and small forms. */
	private static function allowed_extra() {
		$a = wp_kses_allowed_html( 'post' );
		$a['input']  = array( 'type' => true, 'class' => true, 'placeholder' => true, 'data-prc-filter' => true, 'aria-label' => true, 'name' => true, 'value' => true );
		$a['select'] = array( 'name' => true, 'class' => true, 'data-prc-range' => true, 'aria-label' => true );
		$a['option'] = array( 'value' => true, 'selected' => true );
		$a['form']   = array( 'method' => true, 'action' => true, 'class' => true );
		return $a;
	}
}
