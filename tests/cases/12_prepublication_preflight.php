<?php
/** Publisher Toolkit pre-publication preflight: advisory-only, bounded, and permission-aware. */

use PublishRankSEO\Admin\PublisherToolkitPage;
use PublishRankSEO\Meta\PostMeta;

return array(
	'preflight lists draft, pending, and scheduled stories with actionable advisory checks' => function () {
		pr_as_admin();
		$draft = pr_make_post(
			array(
				'post_title'  => 'Draft story for preflight',
				'post_name'   => 'draft-story-preflight',
				'post_status' => 'draft',
			)
		);
		$pending = pr_make_post(
			array(
				'post_title'  => 'Pending story for review',
				'post_name'   => 'pending-story-preflight',
				'post_status' => 'pending',
			)
		);
		$future_date = gmdate( 'Y-m-d H:i:s', time() + 86400 );
		$scheduled   = pr_make_post(
			array(
				'post_title'    => 'Scheduled story for tomorrow',
				'post_name'     => 'scheduled-story-preflight',
				'post_status'   => 'future',
				'post_date'     => $future_date,
				'post_date_gmt' => $future_date,
			)
		);
		$published = pr_make_post(
			array(
				'post_title'  => 'Published story must not appear',
				'post_name'   => 'published-story-not-preflight',
				'post_status' => 'publish',
			)
		);
		$private = pr_make_post(
			array(
				'post_title'  => 'Private story must not appear',
				'post_name'   => 'private-story-not-preflight',
				'post_status' => 'private',
			)
		);
		pr_same( 'future', get_post_status( $scheduled ), 'fixture is scheduled' );
		$_GET = array( 'tab' => 'preflight' );
		$page = new PublisherToolkitPage();
		$page->register();
		ob_start();
		$page->page();
		$html = (string) ob_get_clean();

		pr_contains( 'Pre-publication Preflight', $html );
		pr_contains( 'Draft story for preflight', $html );
		pr_contains( 'Pending story for review', $html );
		pr_contains( 'Scheduled story for tomorrow', $html );
		pr_not_contains( 'Published story must not appear', $html );
		pr_not_contains( 'Private story must not appear', $html );
		pr_contains( 'read-only checklist offers suggestions', $html );
		pr_contains( 'Add a 70–160 character meta description.', $html, 'uses the existing local editorial audit' );
		$scheduled_label = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) get_post_time( 'U', true, $scheduled ) );
		pr_contains( $scheduled_label, $html, 'scheduled time uses the site date/time format' );
		pr_same( 'draft', get_post_status( $draft ), 'preflight does not publish or change the draft' );
		pr_same( 'pending', get_post_status( $pending ), 'preflight does not alter review status' );
		pr_same( '', get_post_meta( $draft, PostMeta::DESCRIPTION, true ), 'preflight does not save SEO metadata' );
		pr_same( 'publish', get_post_status( $published ) );
		pr_same( 'private', get_post_status( $private ) );
	},

	'preflight paginates at twenty stories per page' => function () {
		pr_as_admin();
		for ( $i = 1; $i <= 21; $i++ ) {
			wp_insert_post(
				array(
					'post_title'   => 'Preflight pagination story ' . $i,
					'post_name'    => 'preflight-pagination-' . $i,
					'post_content' => 'Draft content for the pre-publication checklist.',
					'post_status'  => 'draft',
					'post_type'    => 'post',
					'post_author'  => 1,
				)
			);
		}
		$page = new PublisherToolkitPage();
		$page->register();
		$_GET = array( 'tab' => 'preflight', 'paged' => '1' );
		ob_start();
		$page->page();
		$first_page = (string) ob_get_clean();
		pr_count( 20, 'class="pr-preflight-row"', $first_page, 'first page is bounded' );
		pr_contains( 'paged=2', $first_page, 'second page link exists' );

		$_GET = array( 'tab' => 'preflight', 'paged' => '2' );
		ob_start();
		$page->page();
		$second_page = (string) ob_get_clean();
		pr_count( 1, 'class="pr-preflight-row"', $second_page, 'remaining story appears on page two' );
	},

	'delegated publishers see only stories they can edit and no administrator settings tabs' => function () {
		pr_as_admin();
		update_option( PublisherToolkitPage::ROLES_OPTION, array( 'administrator', 'contributor' ) );
		$other_story = pr_make_post(
			array(
				'post_title'  => 'Another editor draft must stay private',
				'post_name'   => 'another-editor-draft',
				'post_status' => 'draft',
				'post_author' => 1,
			)
		);
		$editor_id = wp_insert_user(
			array(
				'user_login' => 'preflight_editor_' . wp_rand(),
				'user_pass'  => wp_generate_password(),
				'role'       => 'contributor',
			)
		);
		if ( is_wp_error( $editor_id ) ) {
			pr_fail( 'could not create contributor: ' . $editor_id->get_error_message() );
		}
		$own_story = pr_make_post(
			array(
				'post_title'  => 'Contributor own draft',
				'post_name'   => 'contributor-own-draft',
				'post_status' => 'draft',
				'post_author' => $editor_id,
			)
		);
		wp_set_current_user( $editor_id );
		$_GET = array( 'tab' => 'preflight' );
		$page = new PublisherToolkitPage();
		$page->register();
		ob_start();
		$page->page();
		$html = (string) ob_get_clean();

		pr_contains( 'Pre-publication Preflight', $html, 'delegated role can use preflight' );
		pr_contains( 'Contributor own draft', $html );
		pr_not_contains( 'Another editor draft must stay private', $html );
		pr_not_contains( 'Role Access', $html, 'role settings remain administrator-only' );
		pr_not_contains( 'Image Alt Assistant', $html, 'administrator image tools remain hidden' );
		pr_assert( current_user_can( 'edit_post', $own_story ), 'contributor can edit own story' );
		pr_assert( ! current_user_can( 'edit_post', $other_story ), 'contributor cannot edit another user story' );
		wp_set_current_user( 1 );
	},
);
