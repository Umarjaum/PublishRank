<?php
/** Module bootstrap for the content graph and internal-link intelligence. */
namespace PublishRankSEO\InternalLinks;

defined( 'ABSPATH' ) || exit;

final class InternalLinksModule {
	public function register() {
		if ( ! Store::enabled() ) {
			return;
		}
		add_action( 'save_post', array( $this, 'sync_post' ), 40, 1 );
		add_action( 'before_delete_post', array( $this, 'delete_post' ), 20, 1 );
		if ( is_admin() ) {
			( new InternalLinksPage() )->register();
		}
	}

	public function sync_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( Store::supports_post( $post ) ) {
			Scanner::scan_post( $post_id );
		}
	}

	public function delete_post( $post_id ) {
		Store::delete_post( $post_id );
	}
}
