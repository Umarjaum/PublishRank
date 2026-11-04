<?php
/**
 * Publisher workflow tools: role-aware bulk SEO editing and conservative image alt assistance.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\SEO\ArticleIntelligence;
use PublishRankSEO\SEO\EditorialAudit;

defined( 'ABSPATH' ) || exit;

final class PublisherToolkitPage {
	const SLUG         = 'publishrank-publisher-toolkit';
	const ROLES_OPTION = 'publishrank_toolkit_roles';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 16 );
		add_action( 'admin_post_publishrank_bulk_seo_update', array( $this, 'bulk_update' ) );
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
		$allowed_tabs = array( 'bulk', 'preflight' );
		if ( current_user_can( 'manage_options' ) ) {
			$allowed_tabs = array_merge( $allowed_tabs, array( 'images', 'access' ) );
		}
		if ( ! in_array( $tab, $allowed_tabs, true ) ) {
			$tab = 'bulk';
		}
		echo '<div class="wrap">';
		AdminAssets::header( __( 'Publisher Toolkit', 'publishrank' ), __( 'Editorial workflows for story preflight, bulk metadata, image alt text, and role-based access.', 'publishrank' ) );
		$this->notice();
		echo '<nav class="nav-tab-wrapper">';
		$tabs = array(
			'bulk'     => __( 'Bulk SEO Editor', 'publishrank' ),
			'preflight' => __( 'Pre-publication Preflight', 'publishrank' ),
		);
		if ( current_user_can( 'manage_options' ) ) {
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
		if ( 'preflight' === $tab ) {
			$this->preflight_tab();
		} elseif ( 'images' === $tab ) {
			$this->images_tab();
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

	private function bulk_tab() {
		$page_num = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
		$query    = new \WP_Query(
			array(
				'post_type'      => $this->editable_post_types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 30,
				'paged'          => $page_num,
				's'              => $search,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			)
		);
		echo '<p>' . esc_html__( 'Select content and set only the fields you want to change. Blank fields are left unchanged unless you explicitly select “Clear this field”. Per-post edit permissions are checked before every update.', 'publishrank' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="bulk" /><label class="screen-reader-text" for="publishrank-bulk-search">' . esc_html__( 'Search content', 'publishrank' ) . '</label><input id="publishrank-bulk-search" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search posts and pages', 'publishrank' ) . '" /><button class="button">' . esc_html__( 'Search', 'publishrank' ) . '</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'publishrank_bulk_seo_update' );
		echo '<input type="hidden" name="action" value="publishrank_bulk_seo_update" />';
		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><td><input type="checkbox" id="publishrank-select-all" aria-label="' . esc_attr__( 'Select all visible content', 'publishrank' ) . '" /></td><th>' . esc_html__( 'Content', 'publishrank' ) . '</th><th>' . esc_html__( 'SEO title', 'publishrank' ) . '</th><th>' . esc_html__( 'Meta description', 'publishrank' ) . '</th><th>' . esc_html__( 'Robots', 'publishrank' ) . '</th></tr></thead><tbody>';
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					continue;
				}
				echo '<tr><td><input type="checkbox" name="post_ids[]" value="' . (int) $post->ID . '" /></td><td><strong><a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( ! empty( get_the_title( $post ) ) ? get_the_title( $post ) : __( '(no title)', 'publishrank' ) ) . '</a></strong><br><small>' . esc_html( get_post_status_object( $post->post_status )->label ?? $post->post_status ) . ' · ID ' . (int) $post->ID . '</small></td><td>' . esc_html( (string) get_post_meta( $post->ID, PostMeta::TITLE, true ) ) . '</td><td>' . esc_html( (string) get_post_meta( $post->ID, PostMeta::DESCRIPTION, true ) ) . '</td><td>' . esc_html( (string) get_post_meta( $post->ID, PostMeta::ROBOTS, true ) ) . '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="5">' . esc_html__( 'No content found.', 'publishrank' ) . '</td></tr>';
		}
		echo '</tbody></table><h2>' . esc_html__( 'Apply changes to selected content', 'publishrank' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'SEO title', 'publishrank' ) . ' <input type="text" name="seo_title" maxlength="255" class="regular-text" /></label> <label><input type="checkbox" name="clear_title" value="1" /> ' . esc_html__( 'Clear this field', 'publishrank' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Meta description', 'publishrank' ) . ' <textarea name="seo_description" rows="2" maxlength="320" class="large-text"></textarea></label> <label><input type="checkbox" name="clear_description" value="1" /> ' . esc_html__( 'Clear this field', 'publishrank' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Robots directive', 'publishrank' ) . ' <select name="robots"><option value="">' . esc_html__( 'Leave unchanged', 'publishrank' ) . '</option><option value="index,follow">index, follow</option><option value="noindex,follow">noindex, follow</option><option value="index,nofollow">index, nofollow</option><option value="noindex,nofollow">noindex, nofollow</option></select></label></p>';
		submit_button( __( 'Update selected content', 'publishrank' ) );
		echo '</form>';
		if ( $query->max_num_pages > 1 ) {
			echo '<p>';
			for ( $i = 1; $i <= (int) $query->max_num_pages; $i++ ) {
				$url = add_query_arg(
					array(
						'page'  => self::SLUG,
						'tab'   => 'bulk',
						'paged' => $i,
						's'     => $search,
					),
					admin_url( 'admin.php' )
				);
				echo ' <a class="button ' . ( $i === $page_num ? 'button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . (int) $i . '</a> ';
			}
			echo '</p>';
		}
		wp_reset_postdata();
	}

	/** Read-only checklist for unpublished stories the current publisher is allowed to edit. */
	private function preflight_tab() {
		$page_num   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination parameter.
		$post_types = array_values( array_intersect( $this->editable_post_types(), ArticleIntelligence::supported_post_types() ) );
		if ( empty( $post_types ) ) {
			echo '<p>' . esc_html__( 'No supported editorial content types are available for preflight.', 'publishrank' ) . '</p>';
			return;
		}

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => array( 'draft', 'pending', 'future' ),
			'posts_per_page' => 20,
			'paged'          => $page_num,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);
		$can_edit_others = false;
		foreach ( $post_types as $post_type ) {
			$type_object = get_post_type_object( $post_type );
			$capability  = is_object( $type_object ) && isset( $type_object->cap->edit_others_posts ) ? $type_object->cap->edit_others_posts : 'edit_others_posts';
			if ( current_user_can( $capability ) ) {
				$can_edit_others = true;
				break;
			}
		}
		if ( ! $can_edit_others ) {
			$query_args['author'] = get_current_user_id();
		}

		$query = new \WP_Query( $query_args );
		echo '<p>' . esc_html__( 'Review drafts, stories awaiting approval, and scheduled pieces before they go live. This read-only checklist offers suggestions; it never blocks publication or changes your content. Only stories you can edit are shown.', 'publishrank' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Story', 'publishrank' ) . '</th><th>' . esc_html__( 'Status / scheduled time', 'publishrank' ) . '</th><th>' . esc_html__( 'SEO checklist', 'publishrank' ) . '</th><th>' . esc_html__( 'Suggested next steps', 'publishrank' ) . '</th></tr></thead><tbody>';
		$audit = new EditorialAudit();
		$shown = 0;
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$edit_url = get_edit_post_link( $post->ID );
			if ( ! $edit_url ) {
				continue;
			}
			++$shown;
			$status_object = get_post_status_object( $post->post_status );
			$status_label  = is_object( $status_object ) && isset( $status_object->label ) ? $status_object->label : $post->post_status;
			$status_html   = esc_html( $status_label );
			if ( 'future' === $post->post_status ) {
				$timestamp = (int) get_post_time( 'U', true, $post );
				if ( $timestamp > 0 ) {
					$status_html .= '<br /><small>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ) . '</small>';
				}
			}

			$report  = $audit->analyze( $post );
			$score   = max( 0, min( 100, (int) ( $report['score'] ?? 0 ) ) );
			$passed  = max( 0, (int) ( $report['passed'] ?? 0 ) );
			$total   = max( 1, (int) ( $report['total'] ?? 8 ) );
			$issues  = isset( $report['issues'] ) && is_array( $report['issues'] ) ? array_values( array_filter( $report['issues'], 'is_string' ) ) : array();
			$title   = get_the_title( $post );
			$title   = '' !== trim( (string) $title ) ? $title : __( '(no title)', 'publishrank' );
			$type_object = get_post_type_object( $post->post_type );
			$type_label  = is_object( $type_object ) && isset( $type_object->labels->singular_name ) ? $type_object->labels->singular_name : $post->post_type;
			echo '<tr class="pr-preflight-row"><td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a></strong><br /><small>' . esc_html( $type_label ) . ' · ID ' . (int) $post->ID . '</small></td><td>' . $status_html . '</td><td><strong>' . esc_html( sprintf( __( '%1$d/100 · %2$d of %3$d checks', 'publishrank' ), $score, $passed, $total ) ) . '</strong></td><td>';
			if ( empty( $issues ) ) {
				echo esc_html__( 'No suggestions from this checklist.', 'publishrank' );
			} else {
				$visible_issues = array_slice( $issues, 0, 3 );
				echo '<details><summary>' . esc_html( sprintf( _n( '%d suggestion', '%d suggestions', count( $issues ), 'publishrank' ), count( $issues ) ) ) . '</summary><ul>';
				foreach ( $visible_issues as $issue ) {
					echo '<li>' . esc_html( $issue ) . '</li>';
				}
				if ( count( $issues ) > count( $visible_issues ) ) {
					echo '<li>' . esc_html( sprintf( __( 'And %d more; open the story for the full checklist.', 'publishrank' ), count( $issues ) - count( $visible_issues ) ) ) . '</li>';
				}
				echo '</ul></details>';
			}
			echo '</td></tr>';
		}
		if ( 0 === $shown ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No draft, pending-review, or scheduled stories you can edit were found on this page.', 'publishrank' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $query->max_num_pages > 1 ) {
			echo '<nav class="tablenav"><div class="tablenav-pages" aria-label="' . esc_attr__( 'Preflight pages', 'publishrank' ) . '">';
			for ( $i = 1; $i <= (int) $query->max_num_pages; $i++ ) {
				$url = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'preflight', 'paged' => $i ), admin_url( 'admin.php' ) );
				echo ' <a class="button ' . ( $i === $page_num ? 'button-primary' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $i === $page_num ? ' aria-current="page"' : '' ) . '>' . (int) $i . '</a> ';
			}
			echo '</div></nav>';
		}
		wp_reset_postdata();
	}

	public function bulk_update() {
		if ( ! current_user_can( 'manage_publishrank_seo' ) ) {
			wp_die( esc_html__( 'You do not have permission to bulk-edit SEO metadata.', 'publishrank' ), 403 );
		}
		check_admin_referer( 'publishrank_bulk_seo_update' );
		$ids          = isset( $_POST['post_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) ) ) ) : array();
		$ids          = array_slice( $ids, 0, 100 );
		$title        = isset( $_POST['seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_title'] ) ) : '';
		$description  = isset( $_POST['seo_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['seo_description'] ) ) : '';
		$robots       = isset( $_POST['robots'] ) ? sanitize_key( wp_unslash( $_POST['robots'] ) ) : '';
		$valid_robots = array( '', 'index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow' );
		if ( ! in_array( $robots, $valid_robots, true ) ) {
			$robots = '';
		}
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) || ! in_array( get_post_type( $id ), $this->editable_post_types(), true ) ) {
				continue;
			}
			if ( ! empty( $_POST['clear_title'] ) ) {
				delete_post_meta( $id, PostMeta::TITLE );
			} elseif ( '' !== $title ) {
				update_post_meta( $id, PostMeta::TITLE, substr( $title, 0, 255 ) );
			}
			if ( ! empty( $_POST['clear_description'] ) ) {
				delete_post_meta( $id, PostMeta::DESCRIPTION );
			} elseif ( '' !== $description ) {
				update_post_meta( $id, PostMeta::DESCRIPTION, substr( $description, 0, 320 ) );
			}
			if ( '' !== $robots ) {
				update_post_meta( $id, PostMeta::ROBOTS, explode( ',', $robots ) );
			}
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => self::SLUG,
					'tab'                => 'bulk',
					'publishrank_notice' => 'updated',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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
		if ( ! current_user_can( 'manage_options' ) ) {
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
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Only administrators can change role access settings.', 'publishrank' ) . '</p></div>';
			return;
		}
		$allowed = get_option( self::ROLES_OPTION, array( 'administrator' ) );
		if ( ! is_array( $allowed ) ) {
			$allowed = array( 'administrator' );
		}
		global $wp_roles;
		$roles = isset( $wp_roles ) ? $wp_roles->roles : array();
		echo '<p>' . esc_html__( 'Choose which WordPress roles can access PublishRank’s pre-publication preflight, bulk SEO editor, and image fallback settings. Administrator access is always retained. Grant access only to trusted editorial roles.', 'publishrank' ) . '</p>';
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
		if ( ! current_user_can( 'manage_options' ) ) {
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
