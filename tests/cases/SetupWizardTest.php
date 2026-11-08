<?php
/** Setup wizard: step track maths, optional steps and the first-run locked side menu. */

use PublishRankSEO\Admin\AdminShell;
use PublishRankSEO\Admin\OnboardingPage;

final class SetupWizardTest extends PublishRankTestCase {
	public function test_track_starts_empty_and_ends_full() {
		$this->assertSame( 0, OnboardingPage::progress_percent( 1 ) );
		$this->assertSame( 100, OnboardingPage::progress_percent( OnboardingPage::TOTAL_STEPS ) );
	}

	public function test_track_fill_stops_on_the_current_dot() {
		// Eight steps: the rail spans seven gaps between dot centres, so step 2 is one seventh.
		$this->assertSame( 14, OnboardingPage::progress_percent( 2 ) );
		$this->assertSame( 57, OnboardingPage::progress_percent( 5 ) );
	}

	public function test_track_clamps_out_of_range_steps() {
		$this->assertSame( 0, OnboardingPage::progress_percent( 0 ) );
		$this->assertSame( 100, OnboardingPage::progress_percent( 99 ) );
	}

	public function test_only_steps_without_required_fields_can_be_skipped() {
		$optional = OnboardingPage::optional_steps();
		$this->assertFalse( in_array( 1, $optional, true ), 'Welcome has its own Start button.' );
		$this->assertFalse( in_array( 2, $optional, true ), 'Publisher holds the required fields.' );
		$this->assertFalse( in_array( OnboardingPage::TOTAL_STEPS, $optional, true ), 'The review step finishes setup.' );
		foreach ( $optional as $step ) {
			foreach ( OnboardingPage::fields( $step ) as $field ) {
				$this->assertFalse( $field['required'], 'Optional step ' . $step . ' must not hold required field ' . $field['key'] );
			}
		}
	}

	public function test_step_labels_are_short_enough_for_the_track() {
		foreach ( OnboardingPage::steps() as $number => $step ) {
			$this->assertTrue( strlen( $step['label'] ) <= 12, 'Step ' . $number . ' label is too long for the step track: ' . $step['label'] );
		}
	}

	public function test_locked_menu_links_only_the_current_screen() {
		wp_set_current_user( 1 );
		ob_start();
		AdminShell::nav( 'publishrank-onboarding', true );
		$html = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $html, '<a ' ), 'Only the wizard itself stays a link while setup is locked.' );
		$this->assertTrue( false !== strpos( $html, 'aria-current="page"' ) );
		$this->assertTrue( false !== strpos( $html, 'pr-nav-locked' ) );
	}

	public function test_unlocked_menu_links_every_screen() {
		wp_set_current_user( 1 );
		ob_start();
		AdminShell::nav( 'publishrank-onboarding' );
		$html = (string) ob_get_clean();
		$this->assertSame( 0, substr_count( $html, 'pr-nav-locked' ) );
		$this->assertTrue( substr_count( $html, '<a ' ) > 1 );
	}
}
