<?php
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class ImageOptimizerPage {
	const SLUG = 'publishrank-image-optimizer';
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 14 );
		add_action( 'admin_post_publishrank_optimize_image', array( $this, 'optimize' ) ); }
	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Image Optimizer', 'publishrank' ), __( 'Image Optimizer', 'publishrank' ), 'upload_files', self::SLUG, array( $this, 'page' ) ); }
	public function page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return; }
		$items = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		echo '<div class="wrap">';
		AdminAssets::header( __( 'Image Optimizer', 'publishrank' ), __( 'Prepare image assets for Google Discover, Google News, Core Web Vitals and social previews.', 'publishrank' ) );
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Thanks for choosing PublishRank.', 'publishrank' ) . '</strong> ' . esc_html__( 'Use this tool to review oversized images before they reach your newsroom templates. Originals are retained when an optimized derivative is created.', 'publishrank' ) . '</p></div>';
		/* translators: %1$d: number of px; %2$d: numeric count or measurement described by the message; %3$s: preferred image format. */
		echo '<p>' . esc_html( sprintf( __( 'Current policy: maximum width %1$d px, quality %2$d, preferred format %3$s.', 'publishrank' ), (int) get_option( 'publishrank_image_max_width', 2000 ), (int) get_option( 'publishrank_image_quality', 82 ), strtoupper( (string) get_option( 'publishrank_image_format', 'webp' ) ) ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Image', 'publishrank' ) . '</th><th>' . esc_html__( 'File', 'publishrank' ) . '</th><th>' . esc_html__( 'Dimensions', 'publishrank' ) . '</th><th>' . esc_html__( 'Recommendation', 'publishrank' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $items as $attachment ) {
			$info = $this->info( $attachment->ID );
			echo '<tr><td><strong>' . esc_html( get_the_title( $attachment ) ) . '</strong></td><td>' . esc_html( $info['size'] ) . ' KB · ' . esc_html( $info['format'] ) . '</td><td>' . esc_html( $info['dimensions'] ) . '</td><td>' . esc_html( $info['recommendation'] ) . '</td><td>' . ( $info['needs'] ? '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="publishrank_optimize_image" /><input type="hidden" name="attachment_id" value="' . esc_attr( $attachment->ID ) . '" />' . wp_kses(
				wp_nonce_field( 'publishrank_optimize_image_' . $attachment->ID, '_wpnonce', true, false ),
				array(
					'input' => array(
						'type'  => true,
						'name'  => true,
						'value' => true,
					),
				)
			) . '<button class="button button-primary" type="submit">' . esc_html__( 'Create optimized copy', 'publishrank' ) . '</button></form>' : '<span class="dashicons dashicons-yes-alt" aria-label="' . esc_attr__( 'Optimized', 'publishrank' ) . '"></span>' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}
	private function info( $id ) {
		$file  = get_attached_file( $id );
		$size  = $file && is_readable( $file ) ? filesize( $file ) : 0;
		$meta  = wp_get_attachment_metadata( $id );
		$w     = (int) ( $meta['width'] ?? 0 );
		$h     = (int) ( $meta['height'] ?? 0 );
		$ext   = $file ? strtoupper( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
		$needs = ( $size > 350000 || $w > (int) get_option( 'publishrank_image_max_width', 2000 ) || ( 'WEBP' !== $ext && 'AVIF' !== $ext ) );
		return array(
			'size'           => (string) round( $size / 1024 ),
			'dimensions'     => $w . ' × ' . $h,
			'format'         => ! empty( $ext ) ? $ext : '—',
			'needs'          => $needs,
			'recommendation' => $needs ? __( 'Resize, compress, or convert before publishing.', 'publishrank' ) : __( 'Within the configured policy.', 'publishrank' ),
		);
	}
	public function optimize() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The sanitized attachment ID selects the action nonce, which AdminAssets::guard validates before any change.
		$id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		AdminAssets::guard( 'publishrank_optimize_image_' . $id );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You cannot optimize this image.', 'publishrank' ) ); }
		$file   = get_attached_file( $id );
		$editor = $file ? wp_get_image_editor( $file ) : new \WP_Error( 'missing_file' );
		if ( is_wp_error( $editor ) ) {
			AdminAssets::done( self::SLUG, 'error', __( 'The image editor could not open this file.', 'publishrank' ) ); }
		$size = $editor->get_size();
		$max  = (int) get_option( 'publishrank_image_max_width', 2000 );
		if ( ! empty( $size['width'] ) && $size['width'] > $max ) {
			$editor->resize( $max, null, false );
		} $editor->set_quality( (int) get_option( 'publishrank_image_quality', 82 ) );
		$format = (string) get_option( 'publishrank_image_format', 'webp' );
		$mime   = 'preserve' === $format ? get_post_mime_type( $id ) : ( 'jpeg' === $format ? 'image/jpeg' : 'image/webp' );
		$dir    = pathinfo( $file, PATHINFO_DIRNAME );
		$base   = pathinfo( $file, PATHINFO_FILENAME ) . '-publishrank';
		$ext    = 'image/webp' === $mime ? 'webp' : ( 'image/jpeg' === $mime ? 'jpg' : strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) );
		$target = wp_unique_filename( $dir, $base . '.' . $ext );
		$saved  = $editor->save( trailingslashit( $dir ) . $target, $mime );
		if ( is_wp_error( $saved ) ) {
			AdminAssets::done( self::SLUG, 'error', __( 'The optimized derivative could not be created.', 'publishrank' ) ); }
		$target_path = trailingslashit( $dir ) . $target;
		update_attached_file( $id, $target_path );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $target_path ) );
		AdminAssets::done( self::SLUG, 'success', __( 'Optimized image copy created and attached. The original file remains on disk.', 'publishrank' ) );
	}
}
