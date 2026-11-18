<?php
/**
 * PublishRank → 404 Monitor (v2 screen).
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Monitor\NotFoundStore as S;

defined( 'ABSPATH' ) || exit;

final class NotFoundPage {
	const SLUG     = 'publishrank-seo-404';
	const OPTION   = S::LEGACY_OPTION;
	const PER_PAGE = 50;

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		foreach ( array( 'redirect', 'dismiss', 'clear', 'toggle', 'bulk', 'settings', 'export' ) as $a ) {
			add_action( 'admin_post_publishrank_seo_404_' . $a, array( $this, 'handle_' . $a ) );
		}
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( '404 Monitor', 'publishrank' ), __( '404 Monitor', 'publishrank' ), Capabilities::cap( 'view_404' ), self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		Suite::enqueue_for( self::SLUG );
	}

	/* ------------------------------------------------------------ Handlers */

	public function handle_redirect() {
		Suite::guard( 'publishrank_seo_404_redirect', 'manage_404' );
		if ( ! Capabilities::can( 'manage_redirects' ) ) {
			wp_die( esc_html__( 'Creating redirects needs the “manage redirects” permission.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$status = isset( $_POST['status'] ) ? absint( $_POST['status'] ) : 301;
		$res    = S::redirect( $id, $target, $status );
		if ( is_wp_error( $res ) ) {
			Suite::done( self::SLUG, 'error', implode( ' ', $res->get_error_messages() ), self::keep_args() );
		}
		Suite::done( self::SLUG, 'success', __( 'Redirect created. Visitors to that address now reach the new page.', 'publishrank' ), self::keep_args() );
	}

	public function handle_dismiss() {
		Suite::guard( 'publishrank_seo_404_dismiss', 'manage_404' );
		S::set_status( array( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 ), 'ignored' );
		Suite::done( self::SLUG, 'success', __( 'Ignored. It stays hidden unless you show ignored entries.', 'publishrank' ), self::keep_args() );
	}

	public function handle_bulk() {
		Suite::guard( 'publishrank_seo_404_bulk', 'manage_404' );
		$ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
		$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		if ( ! $ids ) {
			Suite::done( self::SLUG, 'warning', __( 'Select at least one address.', 'publishrank' ), self::keep_args() );
		}
		$n = 0;
		if ( 'ignore' === $action || 'reopen' === $action ) {
			$n = S::set_status( $ids, 'ignore' === $action ? 'ignored' : 'open' );
		} elseif ( 'delete' === $action ) {
			$n = S::delete( $ids );
		} elseif ( 'redirect' === $action ) {
			if ( ! Capabilities::can( 'manage_redirects' ) ) {
				wp_die( esc_html__( 'Creating redirects needs the “manage redirects” permission.', 'publishrank' ), '', array( 'response' => 403 ) );
			}
			$target = isset( $_POST['bulk_target'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_target'] ) ) : '';
			$errors = array();
			\PublishRankSEO\Redirect\RedirectRepository::begin_batch( sprintf( 'Redirects from the 404 monitor (%d)', count( $ids ) ) );
			foreach ( $ids as $id ) {
				$row = S::get( $id );
				$to  = '' !== $target ? $target : ( $row ? ( S::suggestions( $row['path'], 1 )[0]['url'] ?? '' ) : '' );
				if ( '' === $to ) {
					$errors[] = $row ? $row['path'] : '#' . $id;
					continue;
				}
				$res = S::redirect( $id, $to, 301 );
				if ( is_wp_error( $res ) ) {
					$errors[] = ( $row ? $row['path'] : '#' . $id ) . ': ' . $res->get_error_message();
				} else {
					++$n;
				}
			}
			\PublishRankSEO\Redirect\RedirectRepository::end_batch();
			if ( $errors ) {
				/* translators: 1: created, 2: list */
				Suite::done( self::SLUG, 'warning', sprintf( __( '%1$d redirects created. Not created: %2$s', 'publishrank' ), $n, implode( ' · ', array_slice( $errors, 0, 6 ) ) ), self::keep_args() );
			}
		}
		/* translators: %d: rows */
		Suite::done( self::SLUG, 'success', sprintf( _n( '%d address updated.', '%d addresses updated.', $n, 'publishrank' ), $n ), self::keep_args() );
	}

	public function handle_clear() {
		Suite::guard( 'publishrank_seo_404_clear', 'manage_404' );
		S::clear( ! empty( $_POST['with_history'] ) );
		Suite::done( self::SLUG, 'success', __( 'The 404 log was cleared.', 'publishrank' ) );
	}

	public function handle_toggle() {
		Suite::guard( 'publishrank_seo_404_toggle', 'manage_404' );
		update_option( 'publishrank_seo_404_monitor', S::enabled() ? 0 : 1, false );
		Suite::done( self::SLUG, 'success', S::enabled() ? __( '404 monitoring is on.', 'publishrank' ) : __( '404 monitoring is off. Existing entries are kept.', 'publishrank' ) );
	}

	public function handle_settings() {
		Suite::guard( 'publishrank_seo_404_settings', 'manage_404' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		update_option( 'publishrank_seo_404_monitor', empty( $_POST['enabled'] ) ? 0 : 1, false );
		update_option( 'publishrank_404_retention_days', max( 1, min( 3650, isset( $_POST['retention'] ) ? absint( $_POST['retention'] ) : 90 ) ), false );
		update_option( 'publishrank_404_rate_ceiling', max( 10, min( 1000000, isset( $_POST['ceiling'] ) ? absint( $_POST['ceiling'] ) : 600 ) ), false );
		update_option( 'publishrank_404_sample_rate', max( 2, min( 1000, isset( $_POST['sample'] ) ? absint( $_POST['sample'] ) : 10 ) ), false );
		update_option( 'publishrank_404_noise_rules', isset( $_POST['noise'] ) ? sanitize_textarea_field( wp_unslash( $_POST['noise'] ) ) : '', false );
		// phpcs:enable
		Suite::done( self::SLUG, 'success', __( '404 settings saved.', 'publishrank' ), array(), 'settings' );
	}

	public function handle_export() {
		if ( ! Capabilities::can( 'view_404' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'publishrank_seo_404_export' );
		$rows   = S::export_rows( self::filters() );
		$header = array_shift( $rows );
		Suite::csv( 'publishrank-404-' . gmdate( 'Y-m-d' ) . '.csv', $header, $rows );
	}

	/* ------------------------------------------------------------ Render */

	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$g = wp_unslash( $_GET );
		return array(
			'search'   => isset( $g['s'] ) ? sanitize_text_field( $g['s'] ) : '',
			'class'    => isset( $g['class'] ) ? sanitize_key( $g['class'] ) : '',
			'status'   => isset( $g['state'] ) ? sanitize_key( $g['state'] ) : 'open',
			'noise'    => isset( $g['noise'] ) && 'show' === $g['noise'] ? 'show' : 'hide',
			'min_hits' => isset( $g['min'] ) ? absint( $g['min'] ) : 0,
			'from'     => isset( $g['from'] ) ? sanitize_text_field( $g['from'] ) : '',
			'to'       => isset( $g['to'] ) ? sanitize_text_field( $g['to'] ) : '',
			'orderby'  => isset( $g['orderby'] ) ? sanitize_key( $g['orderby'] ) : 'hits',
			'order'    => isset( $g['order'] ) ? sanitize_key( $g['order'] ) : 'desc',
		);
		// phpcs:enable
	}

	private static function keep_args() {
		$keep = array();
		foreach ( array( 's', 'class', 'state', 'noise', 'min', 'from', 'to', 'orderby', 'order', 'paged' ) as $k ) {
			if ( isset( $_REQUEST[ $k ] ) && '' !== $_REQUEST[ $k ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$keep[ $k ] = sanitize_text_field( wp_unslash( $_REQUEST[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		return $keep;
	}

	private static function class_badges( array $r ) {
		$out = '';
		foreach ( array( 'hits_human' => array( __( 'people', 'publishrank' ), '' ), 'hits_search' => array( __( 'search', 'publishrank' ), 'info' ), 'hits_ai' => array( __( 'AI', 'publishrank' ), 'warn' ), 'hits_scanner' => array( __( 'scanner', 'publishrank' ), 'muted' ) ) as $k => $lab ) {
			if ( $r[ $k ] > 0 ) {
				$out .= Suite::badge( $lab[0] . ' ' . number_format_i18n( $r[ $k ] ), $lab[1] ) . ' ';
			}
		}
		return $out;
	}

	public function render() {
		if ( ! Capabilities::can( 'view_404' ) ) {
			return;
		}
		$manage = Capabilities::can( 'manage_404' );
		$sum    = S::summary( 30 );
		Suite::open( __( '404 Monitor', 'publishrank' ), __( 'Find the missing addresses that readers and crawlers actually hit, see where they came from, and fix them with one click.', 'publishrank' ) );
		if ( ! S::enabled() ) {
			echo '<div class="prx-callout prx-callout--info"><span class="fa-solid fa-circle-question" aria-hidden="true"></span><div><p><strong>' . esc_html__( 'Monitoring is off.', 'publishrank' ) . '</strong> ' . esc_html__( 'Turn it on to record missing addresses. No IP addresses are stored.', 'publishrank' ) . '</p>';
			if ( $manage ) {
				Suite::form_open( 'publishrank_seo_404_toggle' );
				echo '<button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Turn on 404 monitoring', 'publishrank' ) . '</button></form>';
			}
			echo '</div></div>';
		}
		echo '<div class="prx-grid prx-grid--4">';
		Suite::kpi( __( 'Open 404 addresses', 'publishrank' ), number_format_i18n( $sum['open'] ), __( 'scanners excluded', 'publishrank' ), true );
		Suite::kpi( __( 'Hits from people (30 days)', 'publishrank' ), number_format_i18n( $sum['human'] ), sprintf( /* translators: %s: number */ __( '%s today, all visitors', 'publishrank' ), number_format_i18n( $sum['today'] ) ) );
		Suite::kpi( __( 'Crawler hits (30 days)', 'publishrank' ), number_format_i18n( $sum['search'] + $sum['ai'] ), sprintf( /* translators: 1: search, 2: AI */ __( '%1$s search · %2$s AI', 'publishrank' ), number_format_i18n( $sum['search'] ), number_format_i18n( $sum['ai'] ) ) );
		Suite::kpi( __( 'Scanner probes blocked from view', 'publishrank' ), number_format_i18n( $sum['scanner'] ), __( 'wp-config, .env, xmlrpc …', 'publishrank' ) );
		echo '</div>';
		Suite::tabs(
			array(
				'log'      => __( 'Missing addresses', 'publishrank' ),
				'trends'   => __( 'Trends & crawlers', 'publishrank' ),
				'settings' => __( 'Settings & privacy', 'publishrank' ),
			)
		);
		$this->panel_log( $manage );
		$this->panel_trends();
		$this->panel_settings( $manage );
		Suite::close();
	}

	private function panel_log( $manage ) {
		$f     = self::filters();
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res   = S::query( $f + array( 'per_page' => self::PER_PAGE, 'paged' => $paged ) );
		$can_r = Capabilities::can( 'manage_redirects' );
		Suite::panel_open( 'log', false );
		echo '<form method="get" class="prx-filters" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input class="prx-input" type="search" name="s" value="' . esc_attr( $f['search'] ) . '" placeholder="' . esc_attr__( 'Search address or referring site', 'publishrank' ) . '" aria-label="' . esc_attr__( 'Search', 'publishrank' ) . '" />';
		echo '<select name="class" aria-label="' . esc_attr__( 'Visitor type', 'publishrank' ) . '">';
		foreach ( array( '' => __( 'All visitors', 'publishrank' ), 'human' => __( 'People', 'publishrank' ), 'crawler' => __( 'Any crawler', 'publishrank' ), 'search' => __( 'Search crawlers', 'publishrank' ), 'ai' => __( 'AI crawlers', 'publishrank' ), 'scanner' => __( 'Scanners', 'publishrank' ) ) as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['class'], $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select><select name="state" aria-label="' . esc_attr__( 'State', 'publishrank' ) . '">';
		foreach ( array( 'open' => __( 'Open', 'publishrank' ), 'redirected' => __( 'Has a redirect', 'publishrank' ), 'ignored' => __( 'Ignored', 'publishrank' ), '' => __( 'Any state', 'publishrank' ) ) as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['status'], $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select><input class="prx-input prx-input--sm" type="number" min="0" name="min" value="' . esc_attr( $f['min_hits'] ? (string) $f['min_hits'] : '' ) . '" placeholder="' . esc_attr__( 'Min. hits', 'publishrank' ) . '" aria-label="' . esc_attr__( 'Minimum hits', 'publishrank' ) . '" />';
		echo '<label class="prx-check">' . esc_html__( 'Last seen from', 'publishrank' ) . ' <input type="date" name="from" value="' . esc_attr( $f['from'] ) . '" /></label><label class="prx-check">' . esc_html__( 'to', 'publishrank' ) . ' <input type="date" name="to" value="' . esc_attr( $f['to'] ) . '" /></label>';
		echo '<label class="prx-check"><input type="checkbox" name="noise" value="show"' . checked( 'show', $f['noise'], false ) . ' /> ' . esc_html__( 'Include scanner noise', 'publishrank' ) . '</label>';
		echo '<button class="prx-btn" type="submit">' . esc_html__( 'Filter', 'publishrank' ) . '</button>';
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'publishrank_seo_404_export' ), array_filter( array_map( 'strval', array( 's' => $f['search'], 'class' => $f['class'], 'state' => $f['status'], 'noise' => $f['noise'], 'min' => $f['min_hits'] ?: '', 'from' => $f['from'], 'to' => $f['to'] ) ) ) ), admin_url( 'admin-post.php' ) ), 'publishrank_seo_404_export' );
		echo '<a class="prx-btn prx-btn--ghost" href="' . esc_url( $export ) . '"><span class="fa-solid fa-download" aria-hidden="true"></span> ' . esc_html__( 'Export CSV', 'publishrank' ) . '</a></form>';

		if ( $manage ) {
			Suite::form_open( 'publishrank_seo_404_bulk', array( 'id' => 'prx-404-bulk' ) );
			foreach ( self::keep_args() as $k => $v ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
			}
			echo '<div class="prx-toolbar"><select name="bulk_action" aria-label="' . esc_attr__( 'Bulk action', 'publishrank' ) . '"><option value="">' . esc_html__( 'Bulk action…', 'publishrank' ) . '</option>' . ( $can_r ? '<option value="redirect">' . esc_html__( 'Create redirects', 'publishrank' ) . '</option>' : '' ) . '<option value="ignore">' . esc_html__( 'Ignore', 'publishrank' ) . '</option><option value="reopen">' . esc_html__( 'Reopen', 'publishrank' ) . '</option><option value="delete">' . esc_html__( 'Delete', 'publishrank' ) . '</option></select>';
			if ( $can_r ) {
				echo '<input class="prx-input" type="text" name="bulk_target" placeholder="' . esc_attr__( 'Redirect target (empty = best suggestion each)', 'publishrank' ) . '" aria-label="' . esc_attr__( 'Redirect target for selected', 'publishrank' ) . '" data-prx-show-when="bulk_action=redirect" />';
			}
			echo '<button class="prx-btn" type="submit" data-prx-confirm="' . esc_attr__( 'Apply to the selected addresses?', 'publishrank' ) . '">' . esc_html__( 'Apply', 'publishrank' ) . '</button><span class="prx-muted">' . esc_html( sprintf( /* translators: %s: number */ __( '%s matching', 'publishrank' ), number_format_i18n( $res['total'] ) ) ) . '</span></div></form>';
		}
		$sort = static function ( $key, $label ) use ( $f ) {
			$dir = ( $f['orderby'] === $key && 'desc' === $f['order'] ) ? 'asc' : 'desc';
			return '<a href="' . esc_url( add_query_arg( array( 'orderby' => $key, 'order' => $dir ) ) ) . '">' . esc_html( $label ) . ( $f['orderby'] === $key ? ' <span class="fa-solid fa-sort-' . ( 'asc' === $f['order'] ? 'up' : 'down' ) . '" aria-hidden="true"></span>' : '' ) . '</a>';
		};
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr>' . ( $manage ? '<th class="prx-col-check"><input type="checkbox" data-prx-check-all aria-label="' . esc_attr__( 'Select all on this page', 'publishrank' ) . '" /></th>' : '' ) . '<th>' . $sort( 'path', __( 'Missing address', 'publishrank' ) ) . '</th><th>' . $sort( 'hits', __( 'Hits', 'publishrank' ) ) . '</th><th>' . esc_html__( 'Who', 'publishrank' ) . '</th><th>' . esc_html__( 'Came from', 'publishrank' ) . '</th><th>' . $sort( 'last_seen', __( 'Last seen', 'publishrank' ) ) . '</th><th>' . esc_html__( 'Fix', 'publishrank' ) . '</th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="7"><div class="prx-empty"><span class="fa-solid fa-circle-check" aria-hidden="true"></span><p>' . esc_html__( 'No missing addresses match. Nice.', 'publishrank' ) . '</p></div></td></tr>';
		}
		foreach ( $res['rows'] as $r ) {
			$sug  = 'scanner' === $r['ua_class'] ? array() : S::suggestions( $r['path'], 2, 0.35 );
			$lazy = null === $sug;
			$sug  = $lazy ? array() : $sug;
			echo '<tr>' . ( $manage ? '<td class="prx-col-check"><input type="checkbox" form="prx-404-bulk" name="ids[]" value="' . (int) $r['id'] . '" aria-label="' . esc_attr( sprintf( /* translators: %s: path */ __( 'Select %s', 'publishrank' ), $r['path'] ) ) . '" /></td>' : '' );
			echo '<td><code class="prx-code">' . esc_html( $r['path'] ) . '</code> ' . ( 'redirected' === $r['status'] ? Suite::badge( __( 'redirected', 'publishrank' ), 'good' ) : ( 'ignored' === $r['status'] ? Suite::badge( __( 'ignored', 'publishrank' ), 'muted' ) : '' ) ) . '<div class="prx-muted">' . esc_html( sprintf( /* translators: %s: time */ __( 'first seen %s', 'publishrank' ), Suite::ago( $r['first_seen'] ) ) ) . '</div></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><strong>' . esc_html( number_format_i18n( $r['hits'] ) ) . '</strong></td><td>' . self::class_badges( $r ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . ( $r['referrer_host'] ? esc_html( $r['referrer_host'] ) . '<div class="prx-muted">' . esc_html( $r['top_referrer_path'] ) . '</div>' : '<span class="prx-muted">' . esc_html__( 'typed or unknown', 'publishrank' ) . '</span>' ) . '</td>';
			echo '<td>' . esc_html( Suite::ago( $r['last_seen'] ) ) . '</td><td class="prx-fix">';
			if ( $manage && $can_r && 'redirected' !== $r['status'] ) {
				Suite::form_open( 'publishrank_seo_404_redirect', array( 'class' => 'prx-fix__form' ) );
				foreach ( self::keep_args() as $k => $v ) {
					echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
				}
				$list = 'prx-sug-' . (int) $r['id'];
				echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '" /><input class="prx-input prx-input--sm" type="text" name="target" list="' . esc_attr( $list ) . '" value="' . esc_attr( $sug ? wp_make_link_relative( $sug[0]['url'] ) : '' ) . '" placeholder="/new-address/"' . ( $lazy ? ' data-prx-suggest="' . esc_url( rest_url( 'publishrank/v1/404/' . (int) $r['id'] . '/suggestions' ) ) . '"' : '' ) . ' aria-label="' . esc_attr( sprintf( /* translators: %s: path */ __( 'Redirect %s to', 'publishrank' ), $r['path'] ) ) . '" required />';
				echo '<datalist id="' . esc_attr( $list ) . '">';
				foreach ( $sug as $s ) {
					echo '<option value="' . esc_attr( wp_make_link_relative( $s['url'] ) ) . '">' . esc_html( $s['title'] . ' (' . $s['score'] . '%)' ) . '</option>';
				}
				echo '</datalist><button class="prx-btn prx-btn--sm prx-btn--primary prx-btn--icon" type="submit" title="' . esc_attr__( 'Create redirect', 'publishrank' ) . '"><span class="fa-solid fa-arrow-right" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Create redirect', 'publishrank' ) . '</span></button></form>';
				if ( $sug ) {
					echo '<div class="prx-muted">' . esc_html( sprintf( /* translators: 1: title, 2: score */ __( 'Suggested: %1$s (%2$d%% match)', 'publishrank' ), $sug[0]['title'], $sug[0]['score'] ) ) . '</div>';
				}
			}
			if ( $manage && 'open' === $r['status'] ) {
				Suite::form_open( 'publishrank_seo_404_dismiss', array( 'class' => 'prx-inline' ) );
				echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '" /><button class="prx-link-btn" type="submit">' . esc_html__( 'Ignore', 'publishrank' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $manage && $can_r ) {
			self::lazy_suggest_script();
		}
		Suite::pagination( $res['total'], self::PER_PAGE, $paged );
		Suite::panel_close();
	}

	/** Loads suggestions that did not fit the render budget, one at a time, after the page shows. */
	private static function lazy_suggest_script() {
		$nonce = wp_create_nonce( 'wp_rest' );
		echo '<script>(function(){var n=' . wp_json_encode( $nonce ) . ',q=[].slice.call(document.querySelectorAll("input[data-prx-suggest]"));function next(){var el=q.shift();if(!el)return;fetch(el.getAttribute("data-prx-suggest"),{headers:{"X-WP-Nonce":n},credentials:"same-origin"}).then(function(r){return r.ok?r.json():[]}).then(function(list){if(!Array.isArray(list)||!list.length)return;var dl=document.getElementById(el.getAttribute("list"));list.forEach(function(s){var o=document.createElement("option");var u;try{u=new URL(s.url).pathname}catch(e){u=s.url}o.value=u;o.textContent=s.title+" ("+s.score+"%)";dl&&dl.appendChild(o)});if(!el.value){var f;try{f=new URL(list[0].url).pathname}catch(e){f=list[0].url}el.value=f}var m=document.createElement("div");m.className="prx-muted";m.textContent=' . wp_json_encode( __( 'Suggested:', 'publishrank' ) ) . '+" "+list[0].title+" ("+list[0].score+"%)";el.form&&el.form.parentNode.insertBefore(m,el.form.nextSibling)}).catch(function(){}).then(next)}next()})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function panel_trends() {
		global $wpdb;
		Suite::panel_open( 'trends' );
		echo '<div class="prx-grid prx-grid--2">';
		Suite::card_open( __( '404 hits per day', 'publishrank' ), 'fa-chart-column', __( 'People (plum) and crawlers (green), last 30 days. Scanner probes are left out.', 'publishrank' ) );
		echo '<svg class="prx-chart" role="img" aria-label="' . esc_attr__( '404 hits per day', 'publishrank' ) . '" data-prx-chart="' . esc_attr( wp_json_encode( S::series( 30 ) ) ) . '"></svg>';
		$spikes = S::spikes( 30 );
		if ( $spikes ) {
			echo '<div class="prx-callout prx-callout--warn"><p><strong>' . esc_html__( 'Spikes detected', 'publishrank' ) . '</strong> ' . esc_html( implode( ', ', array_map( static function ( $s ) { return $s['day'] . ' (' . number_format_i18n( $s['hits'] ) . ')'; }, $spikes ) ) ) . ' — ' . esc_html__( 'often a broken link in a newsletter, menu or social post.', 'publishrank' ) . '</p></div>';
		}
		Suite::card_close();
		foreach ( array( 'search' => array( __( 'Top 404s from search crawlers', 'publishrank' ), 'fa-magnifying-glass', 'hits_search' ), 'ai' => array( __( 'Top 404s from AI crawlers', 'publishrank' ), 'fa-robot', 'hits_ai' ) ) as $class => $meta ) {
			Suite::card_open( $meta[0], $meta[1], __( 'Fixing these protects crawl budget and keeps your reporting citable.', 'publishrank' ) );
			$rows = S::query( array( 'class' => $class, 'orderby' => $meta[2], 'per_page' => 10, 'status' => 'open' ) )['rows'];
			if ( ! $rows ) {
				echo '<p class="prx-muted">' . esc_html__( 'None recorded.', 'publishrank' ) . '</p>';
			} else {
				echo '<ol class="prx-list">';
				foreach ( $rows as $r ) {
					echo '<li><code class="prx-code">' . esc_html( $r['path'] ) . '</code> <strong>' . esc_html( number_format_i18n( $r[ $meta[2] ] ) ) . '</strong></li>';
				}
				echo '</ol>';
			}
			Suite::card_close();
		}
		Suite::card_open( __( 'Top referring sites', 'publishrank' ), 'fa-link', __( 'Ask these sites to update their links, or redirect the address.', 'publishrank' ) );
		$t    = \PublishRankSEO\Core\Database::table( '404' );
		$refs = \PublishRankSEO\Core\Database::ready( '404' ) ? $wpdb->get_results( "SELECT referrer_host, COUNT(*) AS paths, SUM(hits) AS hits FROM {$t} WHERE referrer_host <> '' AND ua_class <> 'scanner' GROUP BY referrer_host ORDER BY hits DESC LIMIT 10", ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		echo $refs ? '<ol class="prx-list">' : '<p class="prx-muted">' . esc_html__( 'None recorded.', 'publishrank' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( (array) $refs as $r ) {
			echo '<li>' . esc_html( $r['referrer_host'] ) . ' <span class="prx-muted">' . esc_html( sprintf( /* translators: 1: paths, 2: hits */ __( '%1$s addresses · %2$s hits', 'publishrank' ), number_format_i18n( (int) $r['paths'] ), number_format_i18n( (int) $r['hits'] ) ) ) . '</span></li>';
		}
		echo $refs ? '</ol>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Suite::card_close();
		echo '</div>';
		Suite::panel_close();
	}

	private function panel_settings( $manage ) {
		Suite::panel_open( 'settings' );
		echo '<div class="prx-grid prx-grid--2">';
		Suite::card_open( __( 'Recording', 'publishrank' ), 'fa-sliders' );
		if ( $manage ) {
			Suite::form_open( 'publishrank_seo_404_settings' );
		}
		Suite::switch_field( 'enabled', S::enabled(), __( 'Record missing addresses', 'publishrank' ) );
		Suite::field( 'number', 'retention', (string) S::retention_days(), __( 'Keep entries for (days)', 'publishrank' ), __( 'A daily clean-up removes older entries in small batches.', 'publishrank' ), array( 'min' => 1, 'max' => 3650 ) );
		Suite::field( 'number', 'ceiling', (string) S::ceiling(), __( 'Record every hit up to (per minute)', 'publishrank' ), __( 'Above this, only one hit in N is written and counted N times, so totals stay accurate during floods.', 'publishrank' ), array( 'min' => 10 ) );
		Suite::field( 'number', 'sample', (string) S::sample_rate(), __( 'N (sampling rate above the limit)', 'publishrank' ), '', array( 'min' => 2, 'max' => 1000 ) );
		Suite::field( 'textarea', 'noise', (string) get_option( 'publishrank_404_noise_rules', '' ), __( 'Extra scanner-noise rules', 'publishrank' ), __( 'One per line. Use * as a wildcard (/old-cms/*.php) or a regular expression. Start a line with ! followed by a built-in rule to switch that rule off.', 'publishrank' ), array( 'rows' => 5, 'class' => 'prx-textarea prx-mono' ) );
		echo '<details class="prx-details"><summary>' . esc_html__( 'Built-in scanner rules', 'publishrank' ) . '</summary><ul class="prx-list prx-mono">';
		foreach ( S::default_noise() as $rule ) {
			echo '<li>' . esc_html( $rule ) . '</li>';
		}
		echo '</ul></details>';
		if ( $manage ) {
			echo '<div class="prx-actions-row"><button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Save settings', 'publishrank' ) . '</button></div></form>';
		}
		Suite::card_close();
		Suite::card_open( __( 'Privacy', 'publishrank' ), 'fa-user-shield' );
		echo '<ul class="prx-list"><li>' . esc_html__( 'No IP addresses are stored.', 'publishrank' ) . '</li><li>' . esc_html__( 'Browser identifiers are reduced to a visitor type (person, phone, search crawler, AI crawler, scanner).', 'publishrank' ) . '</li><li>' . esc_html__( 'Referrers keep only the site and path — query strings (which can contain search terms or tokens) are removed.', 'publishrank' ) . '</li><li>' . esc_html__( 'Suggested wording has been added to Settings → Privacy → Policy Guide.', 'publishrank' ) . '</li></ul>';
		Suite::card_close();
		if ( $manage ) {
			Suite::card_open( __( 'Clear the log', 'publishrank' ), 'fa-trash-can' );
			Suite::form_open( 'publishrank_seo_404_clear', array( 'data-prx-confirm' => __( 'Delete every 404 entry?', 'publishrank' ), 'data-prx-type' => 'DELETE', 'data-prx-danger' => '1' ) );
			Suite::switch_field( 'with_history', false, __( 'Also delete the daily history used by the chart', 'publishrank' ) );
			echo '<div class="prx-actions-row"><button class="prx-btn prx-btn--danger" type="submit">' . esc_html__( 'Clear all entries', 'publishrank' ) . '</button></div></form>';
			Suite::card_close();
		}
		echo '</div>';
		Suite::panel_close();
	}
}
