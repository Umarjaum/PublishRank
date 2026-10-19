<?php
/** Adds an SEO health column to the posts list. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\SEO\EditorialAudit;

defined( 'ABSPATH' ) || exit;

final class PostListColumns {
	public function register() {
		add_filter( 'manage_post_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_post_posts_custom_column', array( $this, 'render' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** @param array<string,string> $columns Existing columns. @return array<string,string> */
	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['publishrank_seo'] = __( 'SEO', 'publishrank' );
			}
		}
		if ( ! isset( $new['publishrank_seo'] ) ) {
			$new['publishrank_seo'] = __( 'SEO', 'publishrank' );
		}
		return $new;
	}

	public function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( 'edit.php' !== $hook || ! $screen || 'post' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'publishrank-seo-post-list', \PublishRankSEO\Core\Branding::asset_url( 'css/post-list.css' ), array(), \PublishRankSEO\Core\Branding::version() );
	}

	public function render( $column, $post_id ) {
		if ( 'publishrank_seo' !== $column ) {
			return;
		}
		$audit = self::audit( (int) $post_id );
		$class = $audit['score'] >= 80 ? 'good' : ( $audit['score'] >= 50 ? 'ok' : 'bad' );
		$title = $audit['issues'] ? implode( "\n", $audit['issues'] ) : __( 'No issues found.', 'publishrank' );
		echo '<span class="rhs-seo-badge rhs-seo-badge--' . esc_attr( $class ) . '" title="' . esc_attr( $title ) . '">' . esc_html( (string) $audit['score'] ) . '</span>';
		if ( $audit['noindex'] ) {
			echo '<br /><em>' . esc_html__( 'noindex', 'publishrank' ) . '</em>';
		}
	}

	/** Score with the same criteria used by Insights and scheduled audits. @return array{score:int,issues:string[],noindex:bool} */
	public static function audit( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array( 'score' => 0, 'issues' => array( __( 'Article could not be loaded for analysis.', 'publishrank' ) ), 'noindex' => false );
		}
		$report = ( new EditorialAudit() )->analyze( $post );
		$robots = strtolower( (string) get_post_meta( $post_id, '_publishrank_seo_robots', true ) );
		return array(
			'score'   => max( 0, min( 100, (int) $report['score'] ) ),
			'issues'  => array_values( (array) $report['issues'] ),
			'noindex' => false !== strpos( $robots, 'noindex' ),
		);
	}
}
