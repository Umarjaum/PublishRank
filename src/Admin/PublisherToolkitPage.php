<?php
/**
 * Publisher workflow tools: role-aware bulk SEO editing and conservative image alt assistance.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class PublisherToolkitPage {
	const SLUG         = 'publishrank-publisher-toolkit';
	const ROLES_OPTION = 'publishrank_toolkit_roles';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 16 );
		add_action( 'admin_post_publishrank_bulk_seo_update', array( $this, 'bulk_update' ) );
		add_action( 'admin_post_publishrank_bulk_seo_preview', array( $this, 'bulk_preview' ) );
		add_action( 'admin_post_publishrank_bulk_seo_import', array( $this, 'bulk_import' ) );
		add_action( 'admin_post_publishrank_bulk_seo_rollback', array( $this, 'bulk_rollback' ) );
		add_action( 'admin_post_publishrank_bulk_seo_export', array( $this, 'bulk_export' ) );
		add_action( 'admin_post_publishrank_toolkit_save', array( $this, 'save_settings' ) );
		add_action( 'admin_post_publishrank_fill_missing_alt', array( $this, 'fill_missing_alt' ) );
		add_filter( 'user_has_cap', array( $this, 'grant_toolkit_capability' ), 10, 4 );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'fallback_alt_attribute' ), 20, 3 );
	}

	public function menu() {
		add_submenu_page(
			Branding::MENU_SLUG,
			__( 'Publisher Toolkit', 'publishrank' ),
			__( 'Publisher Toolkit', 'publishrank' ),
			'manage_publishrank_seo',
			self::SLUG,
			array( $this, 'page' )
		);
	}

	/** Grant a dedicated capability only to roles explicitly allowed by an administrator. */
	public function grant_toolkit_capability( $allcaps, $caps, $args, $user ) {
		if ( empty( $user->roles ) || ! is_array( $user->roles ) ) {
			return $allcaps;
		}
		$allowed = get_option( self::ROLES_OPTION, array( 'administrator' ) );
		if ( ! is_array( $allowed ) ) {
			$allowed = array( 'administrator' );
		}
		if ( array_intersect( $user->roles, array_map( 'sanitize_key', $allowed ) ) ) {
			$allcaps['manage_publishrank_seo'] = true;
		}
		return $allcaps;
	}

	public function page() {
		if ( ! current_user_can( 'manage_publishrank_seo' ) ) {
			wp_die( esc_html__( 'You do not have permission to access Publisher Toolkit.', 'publishrank' ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'bulk'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
		if ( ! in_array( $tab, array( 'bulk', 'history', 'images', 'access' ), true ) || ( ! in_array( $tab, array( 'bulk', 'history' ), true ) && ! \PublishRankSEO\Core\Capabilities::can( 'bulk_edit' ) ) ) {
			$tab = 'bulk';
		}
		echo '<div class="wrap">';
		AdminAssets::header( __( 'Publisher Toolkit', 'publishrank' ), __( 'Editorial-scale SEO workflows inspired by publisher needs: bulk metadata, missing image alt text, and role-based access.', 'publishrank' ) );
		$this->notice();
		AdminAssets::render_flash();
		echo '<nav class="nav-tab-wrapper">';
		$tabs = array( 'bulk' => __( 'Bulk SEO Editor', 'publishrank' ), 'history' => __( 'Change history', 'publishrank' ) );
		if ( \PublishRankSEO\Core\Capabilities::can( 'bulk_edit' ) ) {
			$tabs['images'] = __( 'Image Alt Assistant', 'publishrank' );
			$tabs['access'] = __( 'Role Access', 'publishrank' );
		}
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a class="nav-tab %1$s" href="%2$s">%3$s</a>',
				$tab === $key ? 'nav-tab-active' : '',
				esc_url(
					add_query_arg(
						array(
							'page' => self::SLUG,
							'tab'  => $key,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html( $label )
			);
		}
		echo '</nav>';
		if ( 'images' === $tab ) {
			$this->images_tab();
		} elseif ( 'history' === $tab ) {
			$this->history_tab();
		} elseif ( 'access' === $tab ) {
			$this->access_tab();
		} else {
			$this->bulk_tab();
		}
		echo '</div>';
	}

	private function notice() {
		if ( empty( $_GET['publishrank_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
			return;
		}
		$notice   = sanitize_key( wp_unslash( $_GET['publishrank_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
		$messages = array(
			'updated'        => __( 'SEO metadata updated for the selected content.', 'publishrank' ),
			'alt_filled'     => __( 'Missing image alt text was filled where a useful attachment title was available. Existing alt text was preserved.', 'publishrank' ),
			'settings_saved' => __( 'Publisher Toolkit settings saved.', 'publishrank' ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------ Bulk SEO Editor (2.26.1) */

	private static function bulk_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => 'bulk' ), $args ), admin_url( 'admin.php' ) );
	}

	private function bulk_tab() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters and review token.
		if ( ! empty( $_GET['review'] ) ) {
			$this->bulk_review( sanitize_key( wp_unslash( $_GET['review'] ) ) );
			return;
		}
		$f = BulkSeo::filters( wp_unslash( $_GET ) );
		// phpcs:enable
		$q      = BulkSeo::query( $f, 30 );
		$counts = BulkSeo::issue_counts();
		$defs   = BulkSeo::fields();
		echo '<div class="pr-bulk" data-pr-no-help>';
		echo '<p class="pr-bulk__intro">' . esc_html__( 'Edit SEO fields for many items at once. Nothing is saved until you review the changes. Every applied batch can be undone from Change history.', 'publishrank' ) . '</p>';
		// Filters.
		echo '<form method="get" class="pr-bulk__filters" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="bulk" />';
		echo '<label><span>' . esc_html__( 'Search', 'publishrank' ) . '</span><input type="search" name="s" value="' . esc_attr( $f['s'] ) . '" /></label>';
		echo '<label><span>' . esc_html__( 'Content type', 'publishrank' ) . '</span><select name="ptype"><option value="">' . esc_html__( 'All types', 'publishrank' ) . '</option>';
		foreach ( BulkSeo::post_types() as $t ) {
			$o = get_post_type_object( $t );
			echo '<option value="' . esc_attr( $t ) . '"' . selected( $f['type'], $t, false ) . '>' . esc_html( $o ? $o->labels->name : $t ) . '</option>';
		}
		echo '</select></label><label><span>' . esc_html__( 'Status', 'publishrank' ) . '</span><select name="pstatus"><option value="">' . esc_html__( 'Any status', 'publishrank' ) . '</option>';
		foreach ( array( 'publish', 'draft', 'pending', 'private', 'future' ) as $s ) {
			$o = get_post_status_object( $s );
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $f['status'], $s, false ) . '>' . esc_html( $o ? $o->label : $s ) . '</option>';
		}
		echo '</select></label><label><span>' . esc_html__( 'Category', 'publishrank' ) . '</span>';
		wp_dropdown_categories( array( 'name' => 'cat', 'selected' => $f['cat'], 'show_option_all' => __( 'All categories', 'publishrank' ), 'hide_empty' => false, 'hierarchical' => true ) );
		echo '</label><label><span>' . esc_html__( 'Show', 'publishrank' ) . '</span><select name="issue">';
		$issues = array(
			''                => __( 'Everything', 'publishrank' ),
			'no_title'        => __( 'No SEO title (uses template)', 'publishrank' ),
			'no_desc'         => __( 'No meta description', 'publishrank' ),
			/* translators: %d: count */
			'dup_title'       => sprintf( __( 'Duplicate SEO titles (%d)', 'publishrank' ), $counts['dup_title'] ),
			/* translators: %d: count */
			'dup_desc'        => sprintf( __( 'Duplicate descriptions (%d)', 'publishrank' ), $counts['dup_desc'] ),
			'long_title'      => __( 'SEO title over 60 characters', 'publishrank' ),
			'long_desc'       => __( 'Description over 160 characters', 'publishrank' ),
			'noindex'         => __( 'Set to noindex', 'publishrank' ),
			'canonical_other' => __( 'Custom canonical URL', 'publishrank' ),
		);
		foreach ( $issues as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['issue'], $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></label><button class="button">' . esc_html__( 'Filter', 'publishrank' ) . '</button>';
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'publishrank_bulk_seo_export' ), array_filter( array( 's' => $f['s'], 'ptype' => $f['type'], 'pstatus' => $f['status'], 'cat' => $f['cat'] ?: '', 'issue' => $f['issue'] ) ) ), admin_url( 'admin-post.php' ) ), 'publishrank_bulk_seo_export' );
		echo ' <a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export CSV', 'publishrank' ) . '</a></form>';

		// Editable grid.
		echo '<form method="post" id="pr-bulk-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'publishrank_bulk_seo_preview' );
		echo '<input type="hidden" name="action" value="publishrank_bulk_seo_preview" /><input type="hidden" name="back" value="' . esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) . '" />'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		echo '<div class="pr-ds-table-wrap pr-bulk__wrap"><table class="widefat striped pr-bulk__table"><thead><tr><td class="check-column"><input type="checkbox" id="publishrank-select-all" aria-label="' . esc_attr__( 'Select all visible content', 'publishrank' ) . '" /></td><th scope="col">' . esc_html__( 'Content', 'publishrank' ) . '</th><th scope="col">' . esc_html( $defs['title']['label'] ) . '</th><th scope="col">' . esc_html( $defs['description']['label'] ) . '</th><th scope="col">' . esc_html( $defs['robots']['label'] ) . '</th><th scope="col">' . esc_html( $defs['canonical']['label'] ) . '</th><th scope="col">' . esc_html( $defs['focus']['label'] ) . '</th></tr></thead><tbody>';
		$shown = 0;
		foreach ( $q->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			++$shown;
			$v     = BulkSeo::values( $post->ID );
			$id    = (int) $post->ID;
			$title = get_the_title( $post );
			$st    = get_post_status_object( $post->post_status );
			echo '<tr data-pr-bulk-row><th scope="row" class="check-column"><input type="checkbox" name="post_ids[]" value="' . $id . '" aria-label="' . esc_attr( sprintf( /* translators: %s: post title */ __( 'Select %s', 'publishrank' ), $title ) ) . '" /></th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="pr-bulk__content"><strong><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( '' !== $title ? $title : __( '(no title)', 'publishrank' ) ) . '</a></strong><div class="pr-bulk__meta">' . esc_html( ( $st ? $st->label : $post->post_status ) . ' · ' . $post->post_type . ' · ID ' . $id ) . '</div></td>';
			echo '<td><input type="text" class="pr-bulk__in" data-pr-count="60" name="rows[' . $id . '][title]" value="' . esc_attr( $v['title'] ) . '" data-orig="' . esc_attr( $v['title'] ) . '" placeholder="' . esc_attr__( 'Template', 'publishrank' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: post */ __( 'SEO title for %s', 'publishrank' ), $title ) ) . '" /><span class="pr-bulk__count" aria-hidden="true"></span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><textarea class="pr-bulk__in" rows="2" data-pr-count="160" name="rows[' . $id . '][description]" data-orig="' . esc_attr( $v['description'] ) . '" placeholder="' . esc_attr__( 'Automatic', 'publishrank' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: post */ __( 'Meta description for %s', 'publishrank' ), $title ) ) . '">' . esc_textarea( $v['description'] ) . '</textarea><span class="pr-bulk__count" aria-hidden="true"></span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><select class="pr-bulk__in" name="rows[' . $id . '][robots]" data-orig="' . esc_attr( $v['robots'] ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: post */ __( 'Robots for %s', 'publishrank' ), $title ) ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$opts = array( '' => __( 'Site default', 'publishrank' ), 'index,follow' => 'index, follow', 'noindex,follow' => 'noindex, follow', 'index,nofollow' => 'index, nofollow', 'noindex,nofollow' => 'noindex, nofollow' );
			if ( '' !== $v['robots'] && ! isset( $opts[ $v['robots'] ] ) ) {
				$opts[ $v['robots'] ] = $v['robots'];
			}
			foreach ( $opts as $k => $l ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $v['robots'], $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select></td><td><input type="text" inputmode="url" class="pr-bulk__in" name="rows[' . $id . '][canonical]" value="' . esc_attr( $v['canonical'] ) . '" data-orig="' . esc_attr( $v['canonical'] ) . '" placeholder="' . esc_attr__( 'This page', 'publishrank' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: post */ __( 'Canonical URL for %s', 'publishrank' ), $title ) ) . '" /></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><input type="text" class="pr-bulk__in" name="rows[' . $id . '][focus]" value="' . esc_attr( $v['focus'] ) . '" data-orig="' . esc_attr( $v['focus'] ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: post */ __( 'Focus keyword for %s', 'publishrank' ), $title ) ) . '" /></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( ! $shown ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No content matches these filters.', 'publishrank' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		// Same value for all selected rows.
		echo '<fieldset class="pr-bulk__set"><legend>' . esc_html__( 'Set the same value on selected rows', 'publishrank' ) . '</legend>';
		echo '<label><span>' . esc_html__( 'SEO title', 'publishrank' ) . '</span><input type="text" name="set[title]" maxlength="255" /></label>';
		echo '<label><span>' . esc_html__( 'Meta description', 'publishrank' ) . '</span><textarea name="set[description]" rows="2" maxlength="320"></textarea></label>';
		echo '<label><span>' . esc_html__( 'Robots', 'publishrank' ) . '</span><select name="set[robots]"><option value="__keep">' . esc_html__( 'Leave unchanged', 'publishrank' ) . '</option><option value="">' . esc_html__( 'Site default', 'publishrank' ) . '</option><option value="index,follow">index, follow</option><option value="noindex,follow">noindex, follow</option><option value="index,nofollow">index, nofollow</option><option value="noindex,nofollow">noindex, nofollow</option></select></label>';
		echo '<label class="pr-bulk__check"><input type="checkbox" name="clear[title]" value="1" /> ' . esc_html__( 'Clear SEO title (use template)', 'publishrank' ) . '</label><label class="pr-bulk__check"><input type="checkbox" name="clear[description]" value="1" /> ' . esc_html__( 'Clear meta description', 'publishrank' ) . '</label><label class="pr-bulk__check"><input type="checkbox" name="clear[canonical]" value="1" /> ' . esc_html__( 'Clear canonical URL', 'publishrank' ) . '</label>';
		echo '<p class="pr-bulk__hint">' . esc_html__( 'Template variables such as %%title%% and %%sep%% work in titles and descriptions.', 'publishrank' ) . '</p></fieldset>';
		echo '<p class="pr-bulk__actions"><button type="submit" class="button button-primary">' . esc_html__( 'Review changes', 'publishrank' ) . '</button> <span class="pr-bulk__dirty" aria-live="polite"></span></p></form>';
		if ( $q->max_num_pages > 1 ) {
			echo '<nav class="pr-bulk__pages" aria-label="' . esc_attr__( 'Pages', 'publishrank' ) . '">';
			for ( $i = 1; $i <= min( 200, (int) $q->max_num_pages ); $i++ ) {
				echo '<a class="button' . ( $i === $f['paged'] ? ' button-primary' : '' ) . '"' . ( $i === $f['paged'] ? ' aria-current="page"' : '' ) . ' href="' . esc_url( self::bulk_url( array_filter( array( 's' => $f['s'], 'ptype' => $f['type'], 'pstatus' => $f['status'], 'cat' => $f['cat'] ?: '', 'issue' => $f['issue'], 'paged' => $i ) ) ) ) . '">' . (int) $i . '</a> ';
			}
			echo '</nav>';
		}
		// CSV import.
		echo '<form method="post" enctype="multipart/form-data" class="pr-bulk__import" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'publishrank_bulk_seo_import' );
		echo '<input type="hidden" name="action" value="publishrank_bulk_seo_import" /><h3>' . esc_html__( 'Import from CSV', 'publishrank' ) . '</h3><p>' . esc_html__( 'Export a CSV, edit it in a spreadsheet, then import it. Only the columns in your file are changed, and you review every change before it is saved.', 'publishrank' ) . '</p>';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'CSV file', 'publishrank' ) . '</span><input type="file" name="csv" accept=".csv,text/csv" required /></label> <button class="button">' . esc_html__( 'Upload and review', 'publishrank' ) . '</button></form>';
		echo '</div>';
		self::bulk_script();
		wp_reset_postdata();
	}

	/** Small progressive enhancement: counters, changed-row highlight, select-all. The form works without it. */
	private static function bulk_script() {
		$i18n = array( 'changed' => __( '%d fields changed', 'publishrank' ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
		echo '<script>(function(){var f=document.getElementById("pr-bulk-form");if(!f)return;var t=' . wp_json_encode( $i18n ) . ';function cnt(el){var s=el.parentNode.querySelector(".pr-bulk__count");if(!s)return;var n=el.value.length,m=+el.getAttribute("data-pr-count");s.textContent=n?n+"/"+m:"";s.className="pr-bulk__count"+(n>m?" is-over":"")}function dirty(){var n=0;f.querySelectorAll("[data-orig]").forEach(function(el){var c=el.value!==el.getAttribute("data-orig");el.classList.toggle("is-changed",c);if(c)n++});var d=f.querySelector(".pr-bulk__dirty");if(d)d.textContent=n?t.changed.replace("%d",n):""}f.addEventListener("input",function(e){if(e.target.hasAttribute("data-pr-count"))cnt(e.target);dirty()});f.addEventListener("change",dirty);f.querySelectorAll("[data-pr-count]").forEach(cnt);var all=document.getElementById("publishrank-select-all");if(all)all.addEventListener("change",function(){f.querySelectorAll("input[name=\'post_ids[]\']").forEach(function(c){c.checked=all.checked})});f.addEventListener("submit",function(){f.querySelectorAll("[data-orig]").forEach(function(el){if(el.value===el.getAttribute("data-orig"))el.disabled=true})})})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function bulk_guard( $nonce ) {
		if ( ! current_user_can( 'manage_publishrank_seo' ) ) {
			wp_die( esc_html__( 'You do not have permission to bulk-edit SEO metadata.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	/** Step 1: build a plan from the grid and the "same value" fields, then show it for review. */
	public function bulk_preview() {
		$this->bulk_guard( 'publishrank_bulk_seo_preview' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is validated by BulkSeo::validate().
		$req  = array();
		$rows = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array();
		foreach ( $rows as $id => $fields ) {
			foreach ( (array) $fields as $k => $v ) {
				$req[ absint( $id ) ][ sanitize_key( $k ) ] = is_scalar( $v ) ? (string) $v : '';
			}
		}
		$ids   = isset( $_POST['post_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) ) : array();
		$set   = isset( $_POST['set'] ) && is_array( $_POST['set'] ) ? wp_unslash( $_POST['set'] ) : array();
		$clear = isset( $_POST['clear'] ) && is_array( $_POST['clear'] ) ? wp_unslash( $_POST['clear'] ) : array();
		// Legacy (2.26.0) form fields.
		foreach ( array( 'seo_title' => 'title', 'seo_description' => 'description' ) as $legacy => $k ) {
			if ( isset( $_POST[ $legacy ] ) && '' !== $_POST[ $legacy ] ) {
				$set[ $k ] = (string) wp_unslash( $_POST[ $legacy ] );
			}
		}
		if ( isset( $_POST['robots'] ) && '' !== $_POST['robots'] ) {
			$set['robots'] = (string) wp_unslash( $_POST['robots'] );
		}
		// phpcs:enable
		foreach ( $ids as $id ) {
			foreach ( array( 'title', 'description', 'canonical' ) as $k ) {
				if ( ! empty( $clear[ $k ] ) ) {
					$req[ $id ][ $k ] = '';
				} elseif ( isset( $set[ $k ] ) && '' !== trim( (string) $set[ $k ] ) ) {
					$req[ $id ][ $k ] = (string) $set[ $k ];
				}
			}
			if ( isset( $set['robots'] ) && '__keep' !== $set['robots'] ) {
				$req[ $id ]['robots'] = (string) $set['robots'];
			}
		}
		$plan = BulkSeo::plan( $req, 'editor' );
		$this->to_review( $plan );
	}

	public function bulk_import() {
		$this->bulk_guard( 'publishrank_bulk_seo_import' );
		$file = isset( $_FILES['csv'] ) && is_array( $_FILES['csv'] ) ? $_FILES['csv'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > 5 * MB_IN_BYTES ) {
			$this->bulk_done( 'error', __( 'Choose a CSV file under 5 MB.', 'publishrank' ) );
		}
		$res = BulkSeo::parse_csv( $file['tmp_name'] );
		if ( '' !== $res['error'] ) {
			$this->bulk_done( 'error', $res['error'] );
		}
		$this->to_review( BulkSeo::plan( $res['requested'], 'csv' ) );
	}

	private function to_review( array $plan ) {
		if ( ! $plan['rows'] ) {
			$this->bulk_done( 'info', __( 'Nothing to change: every value already matches.', 'publishrank' ) );
		}
		wp_safe_redirect( self::bulk_url( array( 'review' => BulkSeo::stash( $plan ) ) ) );
		exit;
	}

	private function bulk_done( $type, $message, $tab = 'bulk' ) {
		AdminAssets::flash( $type, $message );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Step 2: the review screen. */
	private function bulk_review( $token ) {
		$plan = BulkSeo::unstash( $token );
		if ( ! $plan ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This review has expired. Make the changes again.', 'publishrank' ) . '</p></div><p><a class="button" href="' . esc_url( self::bulk_url() ) . '">' . esc_html__( 'Back to the editor', 'publishrank' ) . '</a></p>';
			return;
		}
		$defs = BulkSeo::fields();
		echo '<div class="pr-bulk pr-bulk--review" data-pr-no-help><h2>' . esc_html__( 'Review changes', 'publishrank' ) . '</h2>';
		/* translators: 1: changes, 2: errors, 3: unchanged */
		echo '<p>' . esc_html( sprintf( __( '%1$d changes ready · %2$d cannot be applied · %3$d already had that value.', 'publishrank' ), $plan['changes'], $plan['errors'], $plan['skipped'] ) ) . '</p>';
		echo '<div class="pr-ds-table-wrap"><table class="widefat striped pr-bulk__diff"><thead><tr><th scope="col">' . esc_html__( 'Content', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Field', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Now', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'After', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $plan['rows'] as $r ) {
			$t = $r['post_id'] ? get_the_title( $r['post_id'] ) : '';
			echo '<tr class="' . ( $r['error'] ? 'is-error' : '' ) . '"><td>' . esc_html( '' !== $t ? $t : '#' . $r['post_id'] ) . ' <span class="pr-bulk__meta">ID ' . (int) $r['post_id'] . '</span></td><td>' . esc_html( $defs[ $r['field'] ]['label'] ) . '</td><td><del>' . ( '' === $r['old'] ? '<em>' . esc_html__( 'default', 'publishrank' ) . '</em>' : esc_html( $r['old'] ) ) . '</del></td><td>' . ( $r['error'] ? '<strong class="pr-bulk__err">' . esc_html( $r['error'] ) . '</strong> <code>' . esc_html( $r['new'] ) . '</code>' : '<ins>' . ( '' === $r['new'] ? '<em>' . esc_html__( 'default', 'publishrank' ) . '</em>' : esc_html( $r['new'] ) ) . '</ins>' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>';
		echo '<form method="post" class="pr-bulk__actions" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'publishrank_bulk_seo_update' );
		echo '<input type="hidden" name="action" value="publishrank_bulk_seo_update" /><input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
		if ( $plan['changes'] ) {
			/* translators: %d: number of changes */
			echo '<button class="button button-primary" type="submit">' . esc_html( sprintf( _n( 'Apply %d change', 'Apply %d changes', $plan['changes'], 'publishrank' ), $plan['changes'] ) ) . '</button> ';
		}
		echo '<a class="button" href="' . esc_url( self::bulk_url() ) . '">' . esc_html__( 'Cancel', 'publishrank' ) . '</a></form></div>';
	}

	/** Step 3: apply a reviewed plan. Requests without a reviewed plan are turned into a review, never applied blindly. */
	public function bulk_update() {
		if ( empty( $_POST['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below / by bulk_preview().
			$this->bulk_guard( 'publishrank_bulk_seo_update' );
			$_POST['_wpnonce'] = wp_create_nonce( 'publishrank_bulk_seo_preview' );
			$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
			$this->bulk_preview();
			return;
		}
		$this->bulk_guard( 'publishrank_bulk_seo_update' );
		$plan = BulkSeo::unstash( sanitize_key( wp_unslash( $_POST['token'] ) ), true );
		if ( ! $plan ) {
			$this->bulk_done( 'error', __( 'This review has expired. Nothing was changed.', 'publishrank' ) );
		}
		$res = BulkSeo::apply( $plan );
		/* translators: 1: applied, 2: conflicts, 3: errors */
		$msg = sprintf( __( '%1$d changes saved. %2$d skipped because the field was edited after your review. %3$d could not be saved.', 'publishrank' ), $res['applied'], $res['conflicts'], $res['errors'] );
		$this->bulk_done( $res['applied'] ? 'success' : 'warning', $msg, $res['applied'] ? 'history' : 'bulk' );
	}

	public function bulk_rollback() {
		$this->bulk_guard( 'publishrank_bulk_seo_rollback' );
		$res = BulkSeo::rollback( isset( $_POST['batch'] ) ? sanitize_key( wp_unslash( $_POST['batch'] ) ) : '' );
		/* translators: 1: restored, 2: conflicts */
		$this->bulk_done( 'success', sprintf( __( '%1$d values restored. %2$d kept because they were edited again later.', 'publishrank' ), $res['restored'], $res['conflicts'] ), 'history' );
	}

	public function bulk_export() {
		if ( ! current_user_can( 'manage_publishrank_seo' ) ) {
			wp_die( esc_html__( 'You do not have permission to export SEO metadata.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'publishrank_bulk_seo_export' );
		$rows = BulkSeo::export_rows( BulkSeo::filters( wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by filters().
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="publishrank-seo-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		foreach ( $rows as $i => $r ) {
			fputcsv( $out, $i ? array_map( array( BulkSeo::class, 'csv_cell' ), $r ) : $r, ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private function history_tab() {
		$rows = BulkSeo::batches( 50 );
		echo '<div class="pr-bulk" data-pr-no-help><h2>' . esc_html__( 'Change history', 'publishrank' ) . '</h2><p>' . esc_html__( 'Each saved batch keeps the previous values. Undo restores them, except fields that were edited again since.', 'publishrank' ) . '</p>';
		echo '<div class="pr-ds-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'When', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'By', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Source', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Changes', 'publishrank' ) . '</th><th scope="col">' . esc_html__( 'Undo', 'publishrank' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No bulk changes yet.', 'publishrank' ) . '</td></tr>';
		}
		foreach ( $rows as $b ) {
			$u = get_userdata( (int) $b['user_id'] );
			echo '<tr><td>' . esc_html( get_date_from_gmt( $b['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td><td>' . esc_html( $u ? $u->display_name : '—' ) . '</td><td>' . esc_html( 'csv' === $b['source'] ? __( 'CSV import', 'publishrank' ) : __( 'Bulk editor', 'publishrank' ) ) . '</td>';
			/* translators: 1: total, 2: undone, 3: conflicts */
			echo '<td>' . esc_html( sprintf( __( '%1$d fields · %2$d undone · %3$d kept (edited later)', 'publishrank' ), (int) $b['total'], (int) $b['undone'], (int) $b['conflicts'] ) ) . '</td><td>';
			if ( (int) $b['active'] > 0 ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'publishrank_bulk_seo_rollback' );
				echo '<input type="hidden" name="action" value="publishrank_bulk_seo_rollback" /><input type="hidden" name="batch" value="' . esc_attr( $b['batch_id'] ) . '" /><button class="button" type="submit">' . esc_html__( 'Undo batch', 'publishrank' ) . '</button></form>';
			} else {
				echo '<span aria-hidden="true">—</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	private function images_tab() {
		$enabled = (bool) get_option( 'publishrank_image_alt_fallback_enabled', false );
		echo '<p>' . esc_html__( 'Improve accessibility and image context without overwriting editors’ existing alt text. The fallback uses the attachment title only when an image has no saved alt text.', 'publishrank' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'publishrank_toolkit_save' );
		echo '<input type="hidden" name="action" value="publishrank_toolkit_save" /><input type="hidden" name="return_tab" value="images" />';
		echo '<label><input type="checkbox" name="alt_fallback_enabled" value="1" ' . checked( $enabled, true, false ) . ' /> ' . esc_html__( 'Use attachment-title fallback for missing frontend image alt attributes', 'publishrank' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'This does not overwrite stored alt text. Editors should still write descriptive, context-appropriate alt text, and use empty alt text for decorative images.', 'publishrank' ) . '</p>';
		submit_button( __( 'Save image settings', 'publishrank' ) );
		echo '</form><hr /><h2>' . esc_html__( 'Fill missing alt text in the media library', 'publishrank' ) . '</h2>';
		echo '<p>' . esc_html__( 'This one-time action fills blank alt fields from useful attachment titles only. Existing alt text is never overwritten. It processes a maximum of 100 images per run.', 'publishrank' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'publishrank_fill_missing_alt' );
		echo '<input type="hidden" name="action" value="publishrank_fill_missing_alt" />';
		submit_button( __( 'Fill missing alt text (up to 100 images)', 'publishrank' ), 'secondary' );
		echo '</form>';
	}

	public function fallback_alt_attribute( $attr, $attachment, $size ) {
		unset( $size );
		if ( empty( get_option( 'publishrank_image_alt_fallback_enabled', false ) ) || ! is_object( $attachment ) || empty( $attachment->ID ) ) {
			return $attr;
		}
		if ( ( ! isset( $attr['alt'] ) || '' === trim( (string) $attr['alt'] ) ) && ! metadata_exists( 'post', (int) $attachment->ID, '_wp_attachment_image_alt' ) ) {
			$title = get_the_title( (int) $attachment->ID );
			if ( '' !== trim( (string) $title ) ) {
				$attr['alt'] = wp_strip_all_tags( $title );
			}
		}
		return $attr;
	}

	public function fill_missing_alt() {
		if ( ! \PublishRankSEO\Core\Capabilities::can( 'bulk_edit' ) ) {
			wp_die( esc_html__( 'Only administrators can run the media-library batch operation.', 'publishrank' ), 403 );
		}
		check_admin_referer( 'publishrank_fill_missing_alt' );
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_wp_attachment_image_alt',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$title = trim( wp_strip_all_tags( get_the_title( $id ) ) );
			if ( '' !== $title ) {
				update_post_meta( $id, '_wp_attachment_image_alt', substr( $title, 0, 500 ) );
			}
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => self::SLUG,
					'tab'                => 'images',
					'publishrank_notice' => 'alt_filled',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function access_tab() {
		if ( ! \PublishRankSEO\Core\Capabilities::can( 'bulk_edit' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Only administrators can change role access settings.', 'publishrank' ) . '</p></div>';
			return;
		}
		$allowed = get_option( self::ROLES_OPTION, array( 'administrator' ) );
		if ( ! is_array( $allowed ) ) {
			$allowed = array( 'administrator' );
		}
		global $wp_roles;
		$roles = isset( $wp_roles ) ? $wp_roles->roles : array();
		echo '<p>' . esc_html__( 'Choose which WordPress roles can access PublishRank’s bulk SEO editor and image fallback settings. Administrator access is always retained. Grant access only to trusted editorial roles.', 'publishrank' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'publishrank_toolkit_save' );
		echo '<input type="hidden" name="action" value="publishrank_toolkit_save" /><input type="hidden" name="return_tab" value="access" />';
		foreach ( $roles as $slug => $role ) {
			if ( 'administrator' === $slug ) {
				echo '<p><label><input type="checkbox" checked disabled /> ' . esc_html( translate_user_role( $role['name'] ) ) . ' — ' . esc_html__( 'always enabled', 'publishrank' ) . '</label></p>';
				continue;
			}
			echo '<p><label><input type="checkbox" name="allowed_roles[]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, $allowed, true ), true, false ) . ' /> ' . esc_html( translate_user_role( $role['name'] ) ) . '</label></p>';
		}
		submit_button( __( 'Save role access', 'publishrank' ) );
		echo '</form>';
	}

	public function save_settings() {
		if ( ! \PublishRankSEO\Core\Capabilities::can( 'bulk_edit' ) ) {
			wp_die( esc_html__( 'Only administrators can change Publisher Toolkit settings.', 'publishrank' ), 403 );
		}
		check_admin_referer( 'publishrank_toolkit_save' );
		$tab = isset( $_POST['return_tab'] ) ? sanitize_key( wp_unslash( $_POST['return_tab'] ) ) : 'access';
		if ( 'images' === $tab ) {
			update_option( 'publishrank_image_alt_fallback_enabled', ! empty( $_POST['alt_fallback_enabled'] ) ? 1 : 0, false );
		} else {
			$roles   = isset( $_POST['allowed_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['allowed_roles'] ) ) : array();
			$roles[] = 'administrator';
			global $wp_roles;
			$valid = isset( $wp_roles ) ? array_keys( $wp_roles->roles ) : array( 'administrator' );
			$roles = array_values( array_unique( array_intersect( $valid, $roles ) ) );
			update_option( self::ROLES_OPTION, $roles, false );
		}
		if ( ! in_array( $tab, array( 'access', 'images' ), true ) ) {
			$tab = 'access';
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => self::SLUG,
					'tab'                => $tab,
					'publishrank_notice' => 'settings_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function editable_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( array_map( 'sanitize_key', $types ) );
	}
}
