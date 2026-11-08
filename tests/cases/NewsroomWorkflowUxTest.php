<?php
/** Regression coverage for Newsroom filters, notifications and role-appropriate stages. */

final class NewsroomWorkflowUxTest extends PublishRankTestCase {
	private $user_ids = array();

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		parent::tearDown();
	}
	private function newsroom() {
		$this->set_option(
			'uj_newsroom_workflow',
			array(
				'pitch'     => 'Pitch',
				'assigned'  => 'Assigned',
				'reporting' => 'Reporting',
				'editing'   => 'Editing',
				'approved'  => 'Approved',
				'published' => 'Published',
			)
		);
		return PublishRank_Newsroom::instance();
	}

	private function invoke_private( $object, $method, array $arguments = array() ) {
		$reflection = new ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $object, $arguments );
	}

	public function test_dashboard_and_csv_share_the_same_filter_predicate() {
		$newsroom = $this->newsroom();
		$matching = $this->make_post( array( 'post_title' => 'Climate budget review', 'post_content' => 'Reporting brief' ) );
		$other    = $this->make_post( array( 'post_title' => 'Culture diary', 'post_content' => 'Different assignment' ) );

		update_post_meta( $matching, '_uj_stage', 'editing' );
		update_post_meta( $matching, '_uj_priority', 'urgent' );
		update_post_meta( $matching, '_uj_desk', 'Politics' );
		update_post_meta( $other, '_uj_stage', 'reporting' );
		update_post_meta( $other, '_uj_priority', 'normal' );
		update_post_meta( $other, '_uj_desk', 'Culture' );

		$filters = $this->invoke_private(
			$newsroom,
			'story_filters',
			array( array( 'q' => 'climate', 'stage' => 'editing', 'priority' => 'urgent', 'desk' => 'Politics' ) )
		);
		$stories = array( get_post( $matching ), get_post( $other ) );
		$filtered = $this->invoke_private( $newsroom, 'filter_stories', array( $stories, $filters ) );

		$this->assertSame( array( $matching ), array_map( 'intval', array_column( $filtered, 'ID' ) ) );
	}

	public function test_in_app_notifications_respect_opt_out_and_current_story_access() {
		$newsroom = $this->newsroom();
		$user_id  = wp_insert_user(
			array(
				'user_login' => 'prnews' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		$this->assertFalse( is_wp_error( $user_id ) );
		$this->user_ids[] = (int) $user_id;
		$user = get_user_by( 'id', $user_id );
		$user->add_cap( PublishRank_Newsroom::CAP_ACCESS );
		$story_id = $this->make_post( array( 'post_type' => PublishRank_Newsroom::POST_TYPE, 'post_author' => 1, 'post_title' => 'Private desk story' ) );
		update_post_meta( $story_id, '_uj_reporter', $user_id );

		$this->invoke_private( $newsroom, 'create_in_app_notification', array( $user_id, $story_id, '<b>Assignment</b>', 'Story assigned' ) );
		wp_set_current_user( $user_id );
		$records = $this->invoke_private( $newsroom, 'notification_records' );
		$this->assertCount( 1, $records );
		$this->assertSame( 'Assignment', $records[0]['title'] );

		update_user_meta( $user_id, 'uj_newsroom_inapp_enabled', '0' );
		$this->invoke_private( $newsroom, 'create_in_app_notification', array( $user_id, $story_id, 'Muted alert', 'Should not be stored' ) );
		$this->assertCount( 1, get_user_meta( $user_id, 'uj_newsroom_notifications', true ) );

		update_post_meta( $story_id, '_uj_reporter', 0 );
		$this->assertSame( array(), $this->invoke_private( $newsroom, 'notification_records' ), 'Alerts for stories the user can no longer access must not be exposed.' );
	}

	public function test_non_manager_stage_choices_are_limited_to_adjacent_transitions() {
		$newsroom = $this->newsroom();
		$choices  = $this->invoke_private( $newsroom, 'allowed_stages', array( 'editing', false ) );
		$new_story_choices = $this->invoke_private( $newsroom, 'allowed_stages', array( 'pitch', true ) );

		$this->assertSame( array( 'reporting', 'editing', 'approved' ), array_keys( $choices ) );
		$this->assertSame( array( 'pitch' ), array_keys( $new_story_choices ) );
	}
}
