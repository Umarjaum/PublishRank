<?php
/** Admin report, controls and explicit recommendation approval workflow. */
namespace PublishRankSEO\InternalLinks;

use PublishRankSEO\Admin\AdminAssets;
use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class InternalLinksPage {
	const SLUG     = 'publishrank-seo-internal-links';
	const PER_PAGE = 50;

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_post_publishrank_internal_links_settings', array( $this, 'handle_settings' ) );
		add_action( 'admin_post_publishrank_internal_links_scan', array( $this, 'handle_scan' ) );
		add_action( 'admin_post_publishrank_internal_links_batch', array( $this, 'handle_batch' ) );
		add_action( 'admin_post_publishrank_internal_links_approve', array( $this, 'handle_approve' ) );
		add_action( 'admin_post_publishrank_internal_links_insert', array( $this, 'handle_insert' ) );
		add_action( 'admin_post_publishrank_internal_links_dismiss', array( $this, 'handle_dismiss' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Internal Links', 'publishrank' ), __( 'Internal Links', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function settings() {
		register_setting( 'publishrank_internal_links', 'publishrank_internal_link_enabled', array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => true ) );
		register_setting( 'publishrank_internal_links', 'publishrank_internal_link_min_inbound', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_threshold' ), 'default' => 1 ) );
		register_setting( 'publishrank_internal_links', 'publishrank_internal_link_max_recommendations', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_max' ), 'default' => 5 ) );
		register_setting( 'publishrank_internal_links', 'publishrank_internal_link_post_types', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_post_types' ), 'default' => '' ) );
	}

	public function sanitize_threshold( $value ) {
		return max( 0, min( 5, absint( $value ) ) );
	}

	public function sanitize_max( $value ) {
		return max( 1, min( 10, absint( $value ) ) );
	}

	public function sanitize_post_types( $value ) {
		$public = get_post_types( array( 'public' => true ), 'names' ); // Includes 'page' (public but not publicly_queryable).
		$out    = array();
		foreach ( preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) as $type ) {
			$type = sanitize_key( $type );
			if ( $type && in_array( $type, (array) $public, true ) && 'attachment' !== $type ) {
				$out[] = $type;
			}
		}
		return implode( ', ', array_values( array_unique( $out ) ) );
	}

	private function can_manage_or_edit( $source_id = 0, $target_id = 0 ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return $source_id && $target_id && current_user_can( 'edit_post', $source_id ) && current_user_can( 'edit_post', $target_id );
	}

	private function guard( $action, $source_id = 0, $target_id = 0 ) {
		if ( ! $this->can_manage_or_edit( $source_id, $target_id ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this internal link.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	public function handle_settings() {
		AdminAssets::guard( 'publishrank_internal_links_settings' );
		update_option( 'publishrank_internal_link_enabled', ! empty( $_POST['publishrank_internal_link_enabled'] ) );
		update_option( 'publishrank_internal_link_min_inbound', $this->sanitize_threshold( $_POST['publishrank_internal_link_min_inbound'] ?? 1 ) );
		update_option( 'publishrank_internal_link_max_recommendations', $this->sanitize_max( $_POST['publishrank_internal_link_max_recommendations'] ?? 5 ) );
		update_option( 'publishrank_internal_link_post_types', $this->sanitize_post_types( $_POST['publishrank_internal_link_post_types'] ?? '' ) );
		Store::invalidate_cache();
		AdminAssets::done( self::SLUG, 'success', __( 'Internal-link settings saved. Run a graph scan after changing post types.', 'publishrank' ) );
	}

	public function handle_scan() {
		AdminAssets::guard( 'publishrank_internal_links_scan' );
		Scanner::start_rebuild();
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'scan' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to rebuild the content graph.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'publishrank_internal_links_batch' );
		$result = Scanner::run_batch();
		if ( $result && ! empty( $result['done'] ) ) {
			AdminAssets::done( self::SLUG, 'success', __( 'Content graph and internal-link recommendations are up to date.', 'publishrank' ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'scan' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function recommendation_from_request() {
		$id  = absint( $_POST['recommendation_id'] ?? 0 );
		$rec = Store::recommendation( $id );
		if ( ! $rec ) {
			AdminAssets::done( self::SLUG, 'error', __( 'Recommendation not found.', 'publishrank' ) );
		}
		return $rec;
	}

	public function handle_approve() {
		$rec = $this->recommendation_from_request();
		$this->guard( 'publishrank_internal_links_approve', $rec->source_object_id, $rec->target_object_id );
		if ( ! in_array( $rec->status, array( 'pending', 'dismissed' ), true ) ) {
			AdminAssets::done( self::SLUG, 'error', __( 'This recommendation has already been handled.', 'publishrank' ) );
		}
		Store::mark_recommendation( $rec->id, 'approved' );
		AdminAssets::done( self::SLUG, 'success', __( 'Recommendation approved. No content has been changed yet.', 'publishrank' ) );
	}

	public function handle_dismiss() {
		$rec = $this->recommendation_from_request();
		$this->guard( 'publishrank_internal_links_dismiss', $rec->source_object_id, $rec->target_object_id );
		Store::mark_recommendation( $rec->id, 'dismissed' );
		AdminAssets::done( self::SLUG, 'success', __( 'Recommendation dismissed.', 'publishrank' ) );
	}

	public function handle_insert() {
		$rec = $this->recommendation_from_request();
		$this->guard( 'publishrank_internal_links_insert', $rec->source_object_id, $rec->target_object_id );
		if ( 'approved' !== $rec->status ) {
			AdminAssets::done( self::SLUG, 'error', __( 'Approve the recommendation before inserting it.', 'publishrank' ) );
		}
		$source = get_post( (int) $rec->source_object_id );
		$target = get_post( (int) $rec->target_object_id );
		if ( ! Store::supports_post( $source ) || ! Store::supports_post( $target ) ) {
			AdminAssets::done( self::SLUG, 'error', __( 'The source or target content is no longer published.', 'publishrank' ) );
		}
		if ( LinkInserter::content_links_to_post( $source->post_content, $target->ID ) ) {
			Store::mark_recommendation( $rec->id, 'inserted' );
			AdminAssets::done( self::SLUG, 'error', __( 'The source already links to this target. Duplicate links are not inserted.', 'publishrank' ) );
		}
		$content = LinkInserter::insert( $source->post_content, (string) get_permalink( $target ), $rec->anchor_text );
		if ( is_wp_error( $content ) ) {
			AdminAssets::done( self::SLUG, 'error', $content->get_error_message() );
		}
		$result = wp_update_post( wp_slash( array( 'ID' => $source->ID, 'post_content' => $content ) ), true );
		if ( is_wp_error( $result ) ) {
			AdminAssets::done( self::SLUG, 'error', $result->get_error_message() );
		}
		Scanner::scan_post( $source->ID );
		Scanner::scan_post( $target->ID );
		Store::mark_recommendation( $rec->id, 'inserted' );
		AdminAssets::done( self::SLUG, 'success', __( 'Approved internal link inserted and graph updated.', 'publishrank' ) );
	}

	public function render() {
		if ( ! $this->can_manage_or_edit() ) {
			return;
		}
		$settings = Store::settings();
		$stats    = Store::stats();
		$orphan_page = max( 1, absint( $_GET['orphan_page'] ?? 1 ) );
		$rec_page    = max( 1, absint( $_GET['rec_page'] ?? 1 ) );
		$orphans     = Store::orphans( $settings['threshold'], $orphan_page, self::PER_PAGE );
		$recs        = Store::recommendations( array( 'pending', 'approved', 'inserted' ), $rec_page, self::PER_PAGE );

		echo '<div class="wrap">';
		AdminAssets::header( __( 'Content Graph / Internal Link Intelligence', 'publishrank' ), __( 'Find orphaned content and approve relevant internal links without automatic content changes.', 'publishrank' ) );
		AdminAssets::render_flash();
		$this->render_scan_progress();

		echo '<div class="rhs-grid">';
		printf( '<div class="rhs-card"><p class="rhs-label">%s</p><div class="rhs-stat">%s</div></div>', esc_html__( 'Analyzed URLs', 'publishrank' ), esc_html( number_format_i18n( $stats['nodes'] ) ) );
		printf( '<div class="rhs-card"><p class="rhs-label">%s</p><div class="rhs-stat">%s</div></div>', esc_html__( 'Internal Links', 'publishrank' ), esc_html( number_format_i18n( $stats['edges'] ) ) );
		printf( '<div class="rhs-card"><p class="rhs-label">%s</p><div class="rhs-stat">%s</div></div>', esc_html__( 'Orphaned URLs', 'publishrank' ), esc_html( number_format_i18n( $stats['orphans'] ) ) );
		printf( '<div class="rhs-card"><p class="rhs-label">%s</p><div class="rhs-stat">%s</div></div>', esc_html__( 'Link Opportunities', 'publishrank' ), esc_html( number_format_i18n( $stats['pending'] + $stats['approved'] ) ) );
		echo '</div>';

		$this->render_settings();
		$this->render_orphans( $orphans, $settings, $stats );
		$this->render_recommendations( $recs );
		echo '</div>';
	}

	private function render_scan_progress() {
		$state = Scanner::state();
		if ( ! $state ) {
			return;
		}
		$phase = 'nodes' === $state['phase'] ? __( 'Analyzing published content', 'publishrank' ) : __( 'Generating recommendations', 'publishrank' );
		$total = max( 1, (int) $state['total'] );
		$done  = 'nodes' === $state['phase'] ? min( 100, (int) ( ( (int) $state['processed'] / $total ) * 100 ) ) : 100;
		echo '<div class="notice notice-info rhs-ilink-progress"><p><strong>' . esc_html( $phase ) . '</strong> — ' . esc_html( $done ) . '%</p></div>';
		echo '<form class="rhs-internal-links-autosubmit" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="publishrank_internal_links_batch" />';
		wp_nonce_field( 'publishrank_internal_links_batch' );
		submit_button( __( 'Continue graph scan', 'publishrank' ), 'primary', 'publishrank_continue_scan', false );
		echo '</form>';
	}

	private function render_settings() {
		echo '<div class="rhs-card rhs-ilink-settings"><h2>' . esc_html__( 'Graph settings', 'publishrank' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="publishrank_internal_links_settings" />';
		// Do not print settings_fields() here: it adds action=update, which overrides the admin-post action above and sends the form nowhere.
		wp_nonce_field( 'publishrank_internal_links_settings' );
		echo '<p><label><input type="checkbox" name="publishrank_internal_link_enabled" value="1" ' . checked( Store::enabled(), true, false ) . ' /> ' . esc_html__( 'Enable the internal-link content graph', 'publishrank' ) . '</label></p>';
		echo '<p><label><strong>' . esc_html__( 'Orphan threshold', 'publishrank' ) . '</strong><br /><input type="number" min="0" max="5" name="publishrank_internal_link_min_inbound" value="' . esc_attr( Store::settings()['threshold'] ) . '" /></label> <span class="description">' . esc_html__( 'Content at or below this inbound-link count is reported.', 'publishrank' ) . '</span></p>';
		echo '<p><label><strong>' . esc_html__( 'Recommendations per orphan', 'publishrank' ) . '</strong><br /><input type="number" min="1" max="10" name="publishrank_internal_link_max_recommendations" value="' . esc_attr( Store::settings()['max'] ) . '" /></label></p>';
		echo '<p><label><strong>' . esc_html__( 'Post types', 'publishrank' ) . '</strong><br /><input type="text" class="large-text" name="publishrank_internal_link_post_types" value="' . esc_attr( Store::settings()['types'] ) . '" /></label><br /><span class="description">' . esc_html__( 'Comma-separated. Leave empty for all public content types.', 'publishrank' ) . '</span></p>';
		submit_button( __( 'Save settings', 'publishrank' ), 'primary', 'publishrank_save_settings', false );
		echo '</form>';
		echo '<form class="rhs-internal-links-confirm" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="' . esc_attr__( 'Rebuild the content graph and regenerate pending recommendations?', 'publishrank' ) . '">';
		echo '<input type="hidden" name="action" value="publishrank_internal_links_scan" />';
		wp_nonce_field( 'publishrank_internal_links_scan' );
		submit_button( __( 'Run graph scan', 'publishrank' ), 'secondary', 'publishrank_run_scan', false );
		echo '</form></div>';
	}

	private function render_orphans( $orphans, $settings, $stats ) {
		echo '<div class="rhs-card"><h2>' . esc_html__( 'Orphan report', 'publishrank' ) . '</h2>';
		echo '<p>' . esc_html( sprintf( /* translators: 1: number of published URLs, 2: inbound-link threshold. */ __( '%1$d published URLs have %2$d or fewer meaningful inbound internal links.', 'publishrank' ), $stats['orphans'], $settings['threshold'] ) ) . '</p>';
		echo '<table class="widefat striped rhs-ilink-table"><thead><tr><th>' . esc_html__( 'Priority', 'publishrank' ) . '</th><th>' . esc_html__( 'Content', 'publishrank' ) . '</th><th>' . esc_html__( 'Affected URL', 'publishrank' ) . '</th><th>' . esc_html__( 'Inbound', 'publishrank' ) . '</th><th>' . esc_html__( 'Outbound', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $orphans as $node ) {
			$priority = $node->priority ? $node->priority : 'low';
			$label    = ucfirst( $priority );
			$edit     = get_edit_post_link( (int) $node->object_id );
			$parsed   = wp_parse_url( (string) $node->url );
			$display  = ! empty( $parsed['path'] ) ? $parsed['path'] : (string) $node->url;
			echo '<tr>';
			echo '<td><span class="rhs-pill rhs-pill--' . esc_attr( $priority ) . '">' . esc_html( $label ) . '</span></td>';
			echo '<td><strong>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $node->title ) . '</a>' : esc_html( $node->title ) ) . '</strong><br /><code>' . esc_html( $node->object_type ) . '</code></td>';
			echo '<td><a href="' . esc_url( $node->url ) . '"><code>' . esc_html( $display ) . '</code></a></td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $node->inbound_count ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $node->outbound_count ) ) . '</td>';
			echo '</tr>';
		}
		if ( empty( $orphans ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No orphaned published content was found.', 'publishrank' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->pagination( $stats['orphans'], max( 1, absint( $_GET['orphan_page'] ?? 1 ) ), 'orphan_page' );
		echo '</div>';
	}

	private function render_recommendations( $recs ) {
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s, %s)', Store::table( 'recommendations' ), 'pending', 'approved', 'inserted' ) );
		echo '<div class="rhs-card"><h2>' . esc_html__( 'Recommended internal links', 'publishrank' ) . '</h2>';
		echo '<p>' . esc_html__( 'Approve first, then insert. PublishRank never changes published content automatically.', 'publishrank' ) . '</p>';
		echo '<table class="widefat striped rhs-ilink-table"><thead><tr><th>' . esc_html__( 'Source article', 'publishrank' ) . '</th><th>' . esc_html__( 'Target article', 'publishrank' ) . '</th><th>' . esc_html__( 'Suggested anchor', 'publishrank' ) . '</th><th>' . esc_html__( 'Score', 'publishrank' ) . '</th><th>' . esc_html__( 'Signals', 'publishrank' ) . '</th><th>' . esc_html__( 'Status', 'publishrank' ) . '</th><th>' . esc_html__( 'Actions', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $recs as $rec ) {
			$signals = json_decode( (string) $rec->signals, true );
			$labels  = is_array( $signals ) ? array_keys( $signals ) : array();
			echo '<tr>';
			echo '<td><a href="' . esc_url( get_edit_post_link( (int) $rec->source_object_id ) ) . '">' . esc_html( $rec->source_title ) . '</a><br /><a href="' . esc_url( $rec->source_url ) . '"><code>' . esc_html( wp_parse_url( (string) $rec->source_url, PHP_URL_PATH ) ) . '</code></a></td>';
			echo '<td><a href="' . esc_url( get_edit_post_link( (int) $rec->target_object_id ) ) . '">' . esc_html( $rec->target_title ) . '</a><br /><a href="' . esc_url( $rec->target_url ) . '"><code>' . esc_html( wp_parse_url( (string) $rec->target_url, PHP_URL_PATH ) ) . '</code></a></td>';
			echo '<td><code>' . esc_html( $rec->anchor_text ) . '</code></td>';
			echo '<td><strong>' . esc_html( number_format_i18n( (float) $rec->score, 0 ) ) . '</strong>/100</td>';
			echo '<td>' . esc_html( implode( ', ', $labels ) ) . '</td>';
			echo '<td><span class="rhs-pill rhs-pill--' . esc_attr( $rec->status ) . '">' . esc_html( ucfirst( $rec->status ) ) . '</span></td>';
			echo '<td class="rhs-ilink-actions">' . wp_kses( $this->row_actions( $rec ), array(
				'form' => array( 'method' => true, 'action' => true, 'class' => true, 'data-confirm' => true ),
				'input' => array( 'type' => true, 'name' => true, 'value' => true, 'id' => true ),
				'div' => array( 'class' => true ), 'p' => array( 'class' => true ),
				'button' => array( 'type' => true, 'class' => true, 'name' => true, 'value' => true, 'id' => true ),
				'span' => array( 'class' => true ),
			) ) . '</td>';
			echo '</tr>';
		}
		if ( empty( $recs ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'Run a graph scan to generate recommendations.', 'publishrank' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->pagination( $total, max( 1, absint( $_GET['rec_page'] ?? 1 ) ), 'rec_page' );
		echo '</div>';
	}

	private function row_actions( $rec ) {
		if ( 'inserted' === $rec->status ) {
			return esc_html__( 'Inserted', 'publishrank' );
		}
		ob_start();
		if ( 'pending' === $rec->status ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_internal_links_approve" /><input type="hidden" name="recommendation_id" value="' . esc_attr( $rec->id ) . '" />';
			wp_nonce_field( 'publishrank_internal_links_approve' );
			submit_button( __( 'Approve', 'publishrank' ), 'small', 'submit', false );
			echo '</form>';
		}
		if ( 'approved' === $rec->status ) {
			echo '<form class="rhs-internal-links-confirm" data-confirm="' . esc_attr__( 'Insert this approved internal link into published content?', 'publishrank' ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_internal_links_insert" /><input type="hidden" name="recommendation_id" value="' . esc_attr( $rec->id ) . '" />';
			wp_nonce_field( 'publishrank_internal_links_insert' );
			submit_button( __( 'Insert approved link', 'publishrank' ), 'small', 'submit', false );
			echo '</form>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_internal_links_dismiss" /><input type="hidden" name="recommendation_id" value="' . esc_attr( $rec->id ) . '" />';
		wp_nonce_field( 'publishrank_internal_links_dismiss' );
		submit_button( __( 'Dismiss', 'publishrank' ), 'small', 'submit', false );
		echo '</form>';
		return ob_get_clean();
	}

	private function pagination( $total, $page, $param ) {
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		if ( $pages <= 1 ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( array( $param => '%#%' ), Branding::page_url( self::SLUG ) ),
				'format'    => '',
				'current'   => max( 1, absint( $page ) ),
				'total'     => $pages,
				'prev_text' => __( '&laquo; Previous', 'publishrank' ),
				'next_text' => __( 'Next &raquo;', 'publishrank' ),
			)
		);
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
	}
}
