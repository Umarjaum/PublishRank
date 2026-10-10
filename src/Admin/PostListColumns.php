<?php
/** Adds an SEO health column to the posts list. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Meta\PostMeta;

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

	/**
	 * Score the editorial SEO basics of a post.
	 *
	 * @return array{score:int,issues:string[],noindex:bool}
	 */
	public static function audit( $post_id ) {
		$issues = array();
		$score  = 0;
		$len    = static function ( $text ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );
		};

		$title = (string) PostMeta::get( $post_id, PostMeta::TITLE, '' );
		$title = '' !== $title ? $title : (string) get_the_title( $post_id );
		$tl    = $len( $title );
		if ( $tl >= 20 && $tl <= 65 ) {
			$score += 25;
		} else {
			/* translators: %d: title length. */
			$issues[] = sprintf( __( 'SEO title is %d characters; aim for 20 to 65.', 'publishrank' ), $tl );
			$score   += $tl > 65 && $tl <= 80 ? 12 : 0;
		}

		$description = (string) PostMeta::get( $post_id, PostMeta::DESCRIPTION, '' );
		$dl          = $len( $description );
		if ( $dl >= 70 && $dl <= 160 ) {
			$score += 30;
		} elseif ( 0 === $dl ) {
			$issues[] = __( 'No meta description; one is generated from the content.', 'publishrank' );
			$score   += 10;
		} else {
			/* translators: %d: description length. */
			$issues[] = sprintf( __( 'Meta description is %d characters; aim for 70 to 160.', 'publishrank' ), $dl );
			$score   += 15;
		}

		if ( '' !== (string) PostMeta::get( $post_id, PostMeta::PRIMARY_KEYPHRASE, '' ) || '' !== (string) PostMeta::get( $post_id, PostMeta::FOCUS_KEYWORD, '' ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'No primary keyphrase.', 'publishrank' );
		}

		if ( has_post_thumbnail( $post_id ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'No featured image for social sharing and Google News.', 'publishrank' );
		}

		$robots = strtolower( (string) PostMeta::get( $post_id, PostMeta::ROBOTS, '' ) );
		return array(
			'score'   => min( 100, $score ),
			'issues'  => $issues,
			'noindex' => false !== strpos( $robots, 'noindex' ),
		);
	}
}
