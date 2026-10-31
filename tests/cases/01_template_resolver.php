<?php
/** Shared SEO template-variable resolver: every variable, global/post parity, fallbacks and edge cases. */

use PublishRankSEO\Meta\MetaGenerator;
use PublishRankSEO\Meta\PostMeta;
use PublishRankSEO\Meta\TemplateResolver;

/** A post with known values for every variable. */
function pr_fixture_post() {
	$news    = (int) wp_insert_term( 'News', 'category' )['term_id'];
	$primary = (int) wp_insert_term( 'Primary Cat', 'category' )['term_id'];
	wp_update_user( array( 'ID' => 1, 'display_name' => 'Jane Editor' ) );
	$id = pr_make_post(
		array(
			'post_title'    => 'Hello World',
			'post_name'     => 'hello-world',
			'post_date'     => '2024-03-05 10:00:00',
			'post_excerpt'  => 'Manual excerpt text.',
			'post_category' => array( $news, $primary ),
		)
	);
	update_post_meta( $id, PostMeta::PRIMARY_CATEGORY, (string) $primary );
	update_post_meta( $id, PostMeta::FOCUS_KEYWORD, 'seo plugin' );
	return $id;
}

return array(
	'every supported variable resolves to the right value' => function () {
		$id  = pr_fixture_post();
		$ctx = array( 'post_id' => $id, 'page' => 2 );
		$exp = array(
			'title'            => 'Hello World',
			'sitename'         => 'Test Site',
			'site_description' => 'Just a tagline',
			'category'         => 'News',
			'primary_category' => 'Primary Cat',
			'author'           => 'Jane Editor',
			'date'             => 'March 5, 2024',
			'modified'         => 'March 5, 2024',
			'excerpt'          => 'Manual excerpt text.',
			'page'             => 'Page 2',
			'id'               => (string) $id,
			'permalink'        => get_permalink( $id ),
			'focus_keyword'    => 'seo plugin',
			'focuskw'          => 'seo plugin',
		);
		foreach ( $exp as $name => $value ) {
			pr_same( $value, TemplateResolver::resolve( "%%{$name}%%", $ctx ), "variable %%{$name}%%" );
		}
		// %%sep%% is the configured separator, not a hard-coded symbol (a lone separator is trimmed away, so wrap it).
		update_option( 'publishrank_seo_title_separator', '›' );
		pr_same( 'A › B', TemplateResolver::resolve( 'A %%sep%% B', $ctx ), 'configured separator' );
		update_option( 'publishrank_seo_title_separator', '–' );
		pr_same( 'A – B', TemplateResolver::resolve( 'A %%sep%% B', $ctx ), 'changed separator is picked up' );
		pr_same( 14, count( $exp ), 'all 14 value variables covered (plus sep)' );
		pr_same( 15, count( TemplateResolver::variables() ), 'resolver advertises exactly the 15 documented variables' );
	},

	'legacy single-percent focus keyword tokens still work' => function () {
		$id = pr_fixture_post();
		pr_same( 'seo plugin seo plugin seo plugin seo plugin', TemplateResolver::resolve( '%focus_keyword% %focuskw% %%focus_keyword%% %%focuskw%%', array( 'post_id' => $id ) ) );
		pr_same( 'unsaved kw', TemplateResolver::resolve( '%focus_keyword%', array( 'post_id' => $id, 'focus_keyword' => 'unsaved kw' ) ), 'editor preview override' );
	},

	'global and post-specific resolution support exactly the same variables' => function () {
		$id = pr_fixture_post();
		$g  = new MetaGenerator();
		foreach ( TemplateResolver::variables() as $name ) {
			delete_post_meta( $id, PostMeta::TITLE );
			update_option( 'publishrank_seo_title_template_post', "x %%{$name}%% y" );
			$global = $g->title_for_post( $id );
			update_post_meta( $id, PostMeta::TITLE, "x %%{$name}%% y" );
			$post = $g->title_for_post( $id );
			pr_same( $global, $post, "%%{$name}%% must resolve identically in the global template and in a post override" );
			pr_not_contains( '%%', $post, "%%{$name}%% left unresolved" );
		}
	},

	'global title template on the front end, post override wins, empty override falls back' => function () {
		$id = pr_fixture_post();
		update_option( 'publishrank_seo_title_template_post', '%%title%% %%sep%% %%sitename%% %%page%%' );
		pr_go_to( get_permalink( $id ) );
		pr_assert( is_singular(), 'request resolved to the post' );
		pr_same( 'Hello World | Test Site', wp_get_document_title(), 'global template' );

		update_post_meta( $id, PostMeta::TITLE, '%%title%% by %%author%% (%%category%%)' );
		pr_same( 'Hello World by Jane Editor (News)', wp_get_document_title(), 'post override wins' );

		update_post_meta( $id, PostMeta::TITLE, '' );
		pr_same( 'Hello World | Test Site', wp_get_document_title(), 'empty override falls back to the global template' );

		update_post_meta( $id, PostMeta::TITLE, '%%nonexistent%%' );
		pr_same( 'Hello World | Test Site', wp_get_document_title(), 'an override that resolves to nothing falls back too' );
	},

	'default templates apply when none is configured and pages/archives use their own template' => function () {
		$id = pr_fixture_post();
		delete_option( 'publishrank_seo_title_template_post' );
		pr_go_to( get_permalink( $id ) );
		pr_same( 'Hello World | Test Site', wp_get_document_title() );
		update_option( 'publishrank_seo_title_template_archive', 'Archive: %%title%% %%sep%% %%sitename%%' );
		$term = get_term_by( 'name', 'News', 'category' );
		pr_go_to( get_term_link( $term ) );
		pr_assert( is_category(), 'category archive' );
		pr_same( 'Archive: Category: News | Test Site', wp_get_document_title() );
		pr_go_to( home_url( '/' ) );
		pr_same( 'Test Site', wp_get_document_title(), 'home template' );
	},

	'meta description template: override resolved, falls back to the automatic description' => function () {
		$id = pr_fixture_post();
		update_post_meta( $id, PostMeta::DESCRIPTION, '%%title%% by %%author%% – %%category%% – %%date%%' );
		pr_go_to( get_permalink( $id ) );
		pr_contains( '<meta name="description" content="Hello World by Jane Editor – News – March 5, 2024" />', pr_head() );
		update_post_meta( $id, PostMeta::DESCRIPTION, '' );
		\PublishRankSEO\Meta\SeoData::flush();
		pr_contains( '<meta name="description" content="Manual excerpt text." />', pr_head(), 'automatic description from the excerpt' );
		// The REST/dashboard view of the description must agree with the front end.
		update_post_meta( $id, PostMeta::DESCRIPTION, 'About %%title%%' );
		\PublishRankSEO\Meta\SeoData::flush();
		pr_same( 'About Hello World', \PublishRankSEO\Meta\SeoData::for_post( $id )['description'], 'SeoData description resolves variables' );
	},

	'empty templates and missing context never produce notices or raw tokens' => function () {
		pr_same( '', TemplateResolver::resolve( '', array() ) );
		pr_same( '', TemplateResolver::resolve( '   ', array() ) );
		pr_same( '', TemplateResolver::resolve( null, array() ) );
		pr_same( '', TemplateResolver::resolve( array( 'x' ), array() ) );
		pr_same( '', TemplateResolver::resolve( '%%title%% %%category%% %%primary_category%% %%author%% %%date%% %%modified%% %%id%% %%page%% %%focus_keyword%%', array() ), 'no post context' );
		pr_same( 'Test Site', TemplateResolver::resolve( '%%sitename%%', array( 'post_id' => 999999 ) ), 'unknown post ID' );
		pr_same( 'Just a tagline', TemplateResolver::resolve( '%%excerpt%%', array() ), 'non-post excerpt is the tagline' );
		pr_same( 'Archive', TemplateResolver::resolve( '%%title%%', array( 'title' => 'Archive' ) ) );
		pr_same( '', TemplateResolver::resolve( '%%permalink%%', array() ) );
		pr_same( 'https://example.test/x/', TemplateResolver::resolve( '%%permalink%%', array( 'permalink' => 'https://example.test/x/' ) ) );
		pr_same( '', TemplateResolver::resolve( '%%permalink%%', array( 'permalink' => 'javascript:alert(1)' ) ), 'unsafe URL scheme rejected' );
	},

	'unknown tokens are removed safely, near-miss text is untouched' => function () {
		pr_same( 'A B', TemplateResolver::resolve( 'A %%nope%% B', array() ) );
		pr_same( 'A B', TemplateResolver::resolve( 'A %%unknown_token_123%% B', array() ) );
		pr_same( '100% sure', TemplateResolver::resolve( '100% sure', array() ), 'single percent is plain text' );
		pr_same( '50%% off', TemplateResolver::resolve( '50%% off', array() ), 'double percent without a token name is plain text' );
		pr_same( 'Test Site', TemplateResolver::resolve( '%%SITENAME%%', array( 'x' => 1 ) ), 'variable names are case-insensitive' );
	},

	'missing category, author and excerpt are handled' => function () {
		$id = pr_make_post( array( 'post_author' => 99999, 'post_excerpt' => '', 'post_content' => '' ) );
		wp_delete_object_term_relationships( $id, 'category' );
		pr_same( '', TemplateResolver::resolve( '%%category%%', array( 'post_id' => $id ) ), 'no category' );
		pr_same( '', TemplateResolver::resolve( '%%primary_category%%', array( 'post_id' => $id ) ), 'no primary category' );
		pr_same( '', TemplateResolver::resolve( '%%author%%', array( 'post_id' => $id ) ), 'author no longer exists' );
		pr_same( '', TemplateResolver::resolve( '%%excerpt%%', array( 'post_id' => $id ) ), 'empty excerpt and content' );
		pr_same( 'Hello World', TemplateResolver::resolve( '%%title%% %%sep%% %%author%% %%sep%% %%category%%', array( 'post_id' => $id ) ), 'dangling separators trimmed' );
		// Primary category that is not assigned to the post falls back to the first assigned category.
		$other = (int) wp_insert_term( 'Unassigned', 'category' )['term_id'];
		$news  = (int) get_term_by( 'name', 'News', 'category' )->term_id;
		wp_set_post_categories( $id, array( $news ) );
		update_post_meta( $id, PostMeta::PRIMARY_CATEGORY, (string) $other );
		pr_same( 'News', TemplateResolver::resolve( '%%primary_category%%', array( 'post_id' => $id ) ), 'invalid primary falls back' );
	},

	'excerpt falls back to the content summary and never leaks protected content' => function () {
		$id = pr_make_post( array( 'post_excerpt' => '', 'post_content' => '<p>Plain <strong>content</strong> [gallery] here.</p>' ) );
		$ex = TemplateResolver::resolve( '%%excerpt%%', array( 'post_id' => $id ) );
		pr_assert( false !== strpos( $ex, 'Plain content' ), 'content summary used: ' . $ex );
		pr_not_contains( '<', $ex );
		pr_not_contains( '[gallery]', $ex );
		wp_update_post( array( 'ID' => $id, 'post_password' => 'secret' ) );
		pr_same( '', TemplateResolver::resolve( '%%excerpt%%', array( 'post_id' => $id ) ), 'password-protected content is never exposed' );
	},

	'unicode survives resolution and storage' => function () {
		$title = 'مرحبا بالعالم — Привет 🌍 日本語 Ünïcödé';
		$id    = pr_make_post( array( 'post_title' => $title, 'post_name' => 'unicode' ) );
		pr_same( $title . ' | Test Site', TemplateResolver::resolve( '%%title%% %%sep%% %%sitename%%', array( 'post_id' => $id ) ) );
		update_post_meta( $id, PostMeta::TITLE, '日本語 %%title%% ✓ %%date%%' );
		$stored = get_post_meta( $id, PostMeta::TITLE, true );
		pr_same( '日本語 %%title%% ✓ %%date%%', $stored, 'tokens and Unicode survive the meta sanitizer' );
		pr_contains( '日本語 ' . $title . ' ✓ ', ( new MetaGenerator() )->title_for_post( $id ) );
		update_option( 'blogname', 'موقع الاختبار' );
		pr_same( 'موقع الاختبار', TemplateResolver::resolve( '%%sitename%%', array() ) );
	},

	'html-sensitive characters are plain text in the resolver and escaped once at output' => function () {
		$id = pr_make_post( array( 'post_title' => 'Tom & Jerry <b>Show</b> "Live"', 'post_name' => 'tom', 'post_content' => 'Body' ) );
		$t  = TemplateResolver::resolve( '%%title%%', array( 'post_id' => $id ) );
		pr_not_contains( '<b>', $t );
		pr_contains( 'Tom & Jerry Show', $t, 'raw ampersand, not &amp;' );
		pr_not_contains( '&amp;', $t );
		update_post_meta( $id, PostMeta::DESCRIPTION, '<script>alert(1)</script>Fish & "chips" <3' );
		pr_go_to( get_permalink( $id ) );
		$head = pr_head();
		pr_not_contains( '<script>alert', $head );
		pr_contains( 'Fish &amp; &quot;chips&quot;', $head, 'escaped exactly once in the attribute' );
		pr_not_contains( '&amp;amp;', $head, 'no double escaping' );
		pr_contains( 'Tom &amp; Jerry', $head );
		$doc_title = wp_get_document_title();
		pr_not_contains( '&amp;', $doc_title, 'document title value is plain text (core escapes it when printing)' );
	},

	'token-like content in values is not expanded again (no recursion)' => function () {
		$id = pr_make_post( array( 'post_title' => 'Use %%sitename%% and %%title%% tokens', 'post_name' => 'tokens' ) );
		$t  = TemplateResolver::resolve( '%%title%%', array( 'post_id' => $id ) );
		pr_same( 'Use %%sitename%% and %%title%% tokens', $t, 'single pass: values are inserted literally' );
		pr_same( '', TemplateResolver::resolve( '%%x%% %%x%% %%x%%', array() ), 'repeated unknown tokens terminate' );
		$long = str_repeat( '%%title%%', 5000 );
		pr_assert( strlen( TemplateResolver::resolve( $long, array( 'title' => 'x' ) ) ) <= 1000, 'template length is capped' );
		update_option( 'blogname', '%%sitename%%' );
		pr_same( '%%sitename%%', TemplateResolver::resolve( '%%sitename%%', array() ), 'a site name that looks like a token is not recursed into' );
	},

	'pagination suffix: only on pages 2+ of the main request' => function () {
		$id = pr_make_post( array( 'post_title' => 'Paged', 'post_name' => 'paged', 'post_content' => "Page one<!--nextpage-->Page two" ) );
		update_option( 'publishrank_seo_title_template_post', '%%title%% %%page%%' );
		pr_go_to( get_permalink( $id ) );
		pr_same( 'Paged', wp_get_document_title(), 'page 1 has no suffix' );
		pr_go_to( get_permalink( $id ) . '2/' );
		pr_same( 'Paged Page 2', wp_get_document_title(), 'page 2 suffix' );
		// A different post resolved for REST/editor use must not inherit the main query's page number.
		$other = pr_make_post( array( 'post_title' => 'Other', 'post_name' => 'other' ) );
		pr_same( 'Other', ( new MetaGenerator() )->title_for_post( $other ), 'no suffix for a non-queried post' );
		// Paginated archive.
		for ( $i = 0; $i < 12; $i++ ) {
			pr_make_post( array( 'post_title' => 'P' . $i, 'post_name' => 'p' . $i ) );
		}
		update_option( 'posts_per_page', 5 );
		update_option( 'publishrank_seo_title_template_home', '%%sitename%% %%page%%' );
		pr_go_to( home_url( '/page/2/' ) );
		pr_same( 'Test Site Page 2', wp_get_document_title(), 'paged home' );
	},

	'canonical permalink variable honours a valid same-site override' => function () {
		$id = pr_fixture_post();
		pr_same( get_permalink( $id ), TemplateResolver::resolve( '%%permalink%%', array( 'post_id' => $id ) ) );
		update_post_meta( $id, PostMeta::CANONICAL, 'https://example.test/custom-canonical/' );
		pr_same( 'https://example.test/custom-canonical/', TemplateResolver::resolve( '%%permalink%%', array( 'post_id' => $id ) ), 'same-host override' );
		update_post_meta( $id, PostMeta::CANONICAL, 'https://evil.example/steal/' );
		pr_same( get_permalink( $id ), TemplateResolver::resolve( '%%permalink%%', array( 'post_id' => $id ) ), 'cross-host override is ignored' );
	},

	'the SEO title is generated once even when the filter runs many times' => function () {
		$id = pr_fixture_post();
		update_option( 'publishrank_seo_title_template_post', '%%title%% %%sep%% %%sitename%%' );
		pr_go_to( get_permalink( $id ) );
		$first = wp_get_document_title();
		for ( $i = 0; $i < 3; $i++ ) {
			pr_same( $first, wp_get_document_title(), 'repeat call ' . $i );
			pr_same( $first, apply_filters( 'pre_get_document_title', '' ), 'raw filter, empty input' );
			pr_same( $first, apply_filters( 'pre_get_document_title', $first ), 'raw filter, already-generated input is not appended to' );
		}
		$head = pr_head();
		pr_count( 1, '<title>', $head, 'exactly one <title> element' );
		pr_count( 1, '<meta property="og:title"', $head );
		pr_count( 1, '<meta name="twitter:title"', $head );
		pr_count( 1, '<link rel="canonical"', $head );
		$head2 = pr_head();
		pr_count( 1, '<meta name="description"', $head2 . '' ) ;
		pr_assert( substr_count( $head2, '<title>' ) <= 1, 'a second wp_head() never adds a second title' );
	},

	'template sanitizer keeps tokens that sanitize_text_field used to corrupt' => function () {
		pr_same( '%te%% x', sanitize_text_field( '%%date%% x' ), 'documents the WordPress behaviour that caused the bug' );
		foreach ( array( '%%date%%', '%%category%%', '%%focus_keyword%%', '%focuskw%', '%%modified%% – %%excerpt%%' ) as $tpl ) {
			pr_same( $tpl, TemplateResolver::sanitize_template( $tpl ), 'sanitize_template ' . $tpl );
		}
		pr_same( 'Alert x', TemplateResolver::sanitize_template( 'Alert <script>alert(1)</script> x' ), 'markup removed' );
		pr_same( 'ab', TemplateResolver::sanitize_template( 'abcdef', 2 ), 'length limit' );
		// Through the registered post-meta sanitizer (what the block editor/REST uses) and the classic meta box.
		$id = pr_make_post();
		update_post_meta( $id, PostMeta::TITLE, '%%title%% %%date%% %%category%%' );
		pr_same( '%%title%% %%date%% %%category%%', get_post_meta( $id, PostMeta::TITLE, true ), 'registered meta sanitizer' );
		$_POST = array( 'publishrank_seo_meta_nonce' => wp_create_nonce( 'publishrank_seo_meta' ), PostMeta::DESCRIPTION => wp_slash( '%%date%% in %%category%%' ) );
		PostMeta::save( $id );
		$_POST = array();
		pr_same( '%%date%% in %%category%%', get_post_meta( $id, PostMeta::DESCRIPTION, true ), 'meta box save' );
		// Global settings sanitizers.
		$settings = new \PublishRankSEO\Admin\SettingsPage();
		pr_same( '%%title%% › %%sep%% %%date%% &', $settings->sanitize_template( '%%title%% › %%sep%% %%date%% &' ), 'global template sanitizer keeps symbols and tokens' );
		update_option( 'publishrank_seo_title_template_post', '%%title%% %%date%% %%category%%' );
		pr_same( '%%title%% %%date%% %%category%%', get_option( 'publishrank_seo_title_template_post' ), 'option round trip through register_setting' );
	},

	'homepage administrative summary resolves the saved template without changing it' => function () {
		update_option( 'publishrank_seo_publication_name', 'The Warden Tribune' );
		update_option( 'publishrank_seo_title_separator', '|' );
		$raw = '%%sitename%% %%sep%% Breaking News';
		update_option( 'publishrank_home_title', $raw );
		pr_same( 'The Warden Tribune | Breaking News', \PublishRankSEO\Admin\OnboardingPage::homepage_title_preview(), 'resolved summary title' );
		pr_same( $raw, get_option( 'publishrank_home_title' ), 'editable template remains stored' );
		update_option( 'publishrank_seo_title_separator', '›' );
		pr_same( 'The Warden Tribune › Breaking News', \PublishRankSEO\Admin\OnboardingPage::homepage_title_preview(), 'summary follows the saved separator' );
	},

);
