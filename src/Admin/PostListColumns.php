<?php
/** Adds an SEO health column to the posts list. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\SEO\EditorialAudit;

defined( 'ABSPATH' ) || exit;

final class PostListColumns {
	public function register() {
		add_action( 'admin_init', array( $this, 'hook_post_types' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** The SEO column appears on every post type PublishRank analyzes, not only posts. */
	public function hook_post_types() {
		$types = class_exists( '\\PublishRankSEO\\SEO\\ArticleIntelligence' ) ? \PublishRankSEO\SEO\ArticleIntelligence::supported_post_types() : array( 'post' );
		foreach ( array_unique( array_merge( array( 'post' ), (array) $types ) ) as $type ) {
			add_filter( 'manage_' . $type . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'render' ), 10, 2 );
		}
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
		if ( 'edit.php' !== $hook || ! $screen ) {
			return;
		}
		wp_enqueue_style( 'publishrank-seo-post-list', \PublishRankSEO\Core\Branding::asset_url( 'css/post-list.css' ), array(), \PublishRankSEO\Core\Branding::version() );
	}

	public function render( $column, $post_id ) {
		if ( 'publishrank_seo' !== $column ) {
			return;
		}
		$audit = self::audit( (int) $post_id );
		$class = \PublishRankSEO\SEO\SeoAnalysis::rating( $audit['score'] );
		$title = $audit['issues'] ? implode( "\n", $audit['issues'] ) : __( 'No issues found.', 'publishrank' );
		echo '<span class="rhs-seo-badge rhs-seo-badge--' . esc_attr( $class ) . '" data-publishrank-score="' . esc_attr( (string) $audit['score'] ) . '" title="' . esc_attr( $title ) . '">' . esc_html( (string) $audit['score'] ) . '</span>';
		if ( $audit['noindex'] ) {
			echo '<br /><em>' . esc_html__( 'noindex', 'publishrank' ) . '</em>';
		}
	}

	/** The single PublishRank SEO score (same value the editor panel shows). @return array{score:int,issues:string[],noindex:bool} */
	public static function audit( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'score'   => 0,
				'issues'  => array( __( 'Article could not be loaded for analysis.', 'publishrank' ) ),
				'noindex' => false,
			);
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
