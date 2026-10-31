<?php
/** Template-variable registry: registry/resolver agreement, dropdown payload, social-field resolution. */

use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\Meta\SeoData;
use PublishRankSEO\Meta\TemplateResolver;
use PublishRankSEO\Meta\TemplateVariables;

return array(
	'registry has no structural problems' => function () {
		pr_same( array(), TemplateVariables::validate(), 'registry validate()' );
	},
	'resolver accepts exactly the registered names' => function () {
		$names = TemplateVariables::names();
		sort( $names );
		$resolver = TemplateResolver::variables();
		sort( $resolver );
		pr_same( $names, $resolver, 'registry vs resolver names' );
		pr_same( count( $names ), count( array_unique( $names ) ), 'no duplicate names' );
	},
	'every resolver case is registered (no implemented variable missing from the dropdown)' => function () {
		$source = (string) file_get_contents( PUBLISHRANK_SEO_PATH . 'src/Meta/TemplateResolver.php' );
		$start  = strpos( $source, 'public static function value(' );
		$end    = strpos( $source, 'public static function sanitize_template(' );
		pr_assert( false !== $start && false !== $end, 'locate value()' );
		preg_match_all( "/case '([a-z_]+)':/", substr( $source, $start, $end - $start ), $m );
		$registered = TemplateVariables::names();
		foreach ( array_unique( $m[1] ) as $name ) {
			pr_assert( in_array( $name, $registered, true ), "resolver case '{$name}' is not in the registry" );
		}
	},
	'every registered variable resolves for a complete post and is in the dropdown payload' => function () {
		$news = (int) wp_insert_term( 'News', 'category' )['term_id'];
		$id   = pr_make_post( array( 'post_title' => 'Hello World', 'post_excerpt' => 'An excerpt.', 'post_category' => array( $news ) ) );
		update_post_meta( $id, PostMeta::FOCUS_KEYWORD, 'seo plugin' );
		$payload = TemplateVariables::js_payload( 'post' );
		$tokens  = array_column( $payload['variables'], 'token' );
		foreach ( TemplateVariables::all() as $def ) {
			pr_assert( in_array( $def['token'], $tokens, true ), $def['token'] . ' missing from payload' );
			if ( in_array( $def['id'], array( 'page', 'sep' ), true ) ) {
				continue; // Empty on page one / depends on the separator option.
			}
			pr_assert( '' !== TemplateResolver::resolve( $def['token'], array( 'post_id' => $id, 'field' => 'description' ) ), $def['token'] . ' resolved to an empty string for a complete post' );
		}
	},
	'category variables are flagged unavailable for post types without categories' => function () {
		$payload = TemplateVariables::js_payload( 'page' );
		foreach ( $payload['variables'] as $v ) {
			if ( 'category' === $v['id'] || 'primary_category' === $v['id'] ) {
				pr_assert( false === $v['available'] && '' !== $v['reason'], $v['id'] . ' should be unavailable for pages' );
			}
		}
	},
	'missing data and unknown tokens never leak into output' => function () {
		$id = pr_make_post( array( 'post_title' => 'Plain', 'post_excerpt' => '' ) );
		update_post_meta( $id, PostMeta::TITLE, '%%title%% %%sep%% %%nope%% %%focus_keyword%%' );
		$out = TemplateResolver::resolve( '%%title%% %%sep%% %%nope%% %%focus_keyword%%', array( 'post_id' => $id ) );
		pr_not_contains( '%%', $out, 'unresolved token marker' );
		pr_same( 'Plain', $out, 'dangling separators removed' );
		pr_same( 'Ünïcødé ✓ Plain', TemplateResolver::resolve( 'Ünïcødé ✓ %%title%%', array( 'post_id' => $id ) ), 'unicode kept' );
	},
	'social title and description overrides resolve variables' => function () {
		$id = pr_make_post( array( 'post_title' => 'Hello World', 'post_excerpt' => 'Short excerpt.' ) );
		update_post_meta( $id, PostMeta::OG_TITLE, 'Read: %%title%% %%unknown%%' );
		update_post_meta( $id, PostMeta::OG_DESCRIPTION, '%%excerpt%% by %%author%%' );
		SeoData::flush();
		$data = SeoData::for_post( $id );
		pr_same( 'Read: Hello World', $data['og_title'], 'og_title' );
		pr_not_contains( '%%', $data['og_description'], 'og_description token markers' );
		pr_contains( 'Short excerpt.', $data['og_description'], 'og_description excerpt' );
		pr_go_to( get_permalink( $id ) );
		$head = pr_head();
		pr_contains( 'property="og:title" content="Read: Hello World"', $head, 'front-end og:title' );
		pr_contains( 'name="twitter:title" content="Read: Hello World"', $head, 'front-end twitter:title' );
		pr_not_contains( '%%', $head, 'no raw tokens in head output' );
	},
	'empty social override falls back to the resolved SEO title' => function () {
		$id = pr_make_post( array( 'post_title' => 'Fallback Me' ) );
		update_post_meta( $id, PostMeta::OG_TITLE, '%%unknown%%' );
		SeoData::flush();
		$data = SeoData::for_post( $id );
		pr_same( $data['title'], $data['og_title'], 'falls back to title' );
	},
	'templates saved with variables survive sanitising' => function () {
		pr_same( '%%date%% %%category%%', PostMeta::sanitize_text( addslashes( '%%date%% %%category%%' ), 255 ) );
	},
	'editor panel renders a variable slot for every template field' => function () {
		$id   = pr_make_post( array( 'post_title' => 'Panel' ) );
		pr_as_admin();
		$post = get_post( $id );
		ob_start();
		( new \PublishRankSEO\SEO\AdvancedFeatures() )->render_meta_box( $post );
		$html = (string) ob_get_clean();
		foreach ( array( PostMeta::TITLE, PostMeta::DESCRIPTION, PostMeta::OG_TITLE, PostMeta::OG_DESCRIPTION ) as $key ) {
			pr_contains( 'class="pr-var-slot" data-target="' . $key . '"', $html, "slot for {$key}" );
		}
		pr_not_contains( 'Insert %focus_keyword%', $html );
	},
);
