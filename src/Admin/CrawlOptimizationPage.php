<?php
/** Crawl optimization controls inspired by common SEO hygiene features. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;

 defined( 'ABSPATH' ) || exit;

final class CrawlOptimizationPage {
	const SLUG = 'publishrank-crawl-optimization';
	const OPTION = 'publishrank_crawl_optimization';

	/** @var array<string,mixed> */
	private $defaults = array(
		'remove_shortlinks' => 0,
		'remove_rest_links' => 0,
		'remove_rsd_wlw' => 0,
		'remove_oembed_links' => 0,
		'remove_generator' => 0,
		'remove_pingback' => 0,
		'remove_powered_by' => 0,
		'remove_feed_links' => 0,
		'remove_comment_feed_links' => 0,
		'remove_author_feed_links' => 0,
		'remove_post_type_feed_links' => 0,
		'remove_taxonomy_feed_links' => 0,
		'remove_search_feed_links' => 0,
		'remove_legacy_feed_links' => 0,
		'remove_emojis' => 0,
		'disallow_wp_rest_content_api' => 0,
		'limit_search_length' => 0,
		'search_length' => 50,
		'filter_search_special' => 0,
		'filter_search_spam' => 0,
		'redirect_pretty_search' => 0,
		'disallow_search_crawling' => 0,
		'clean_utm_parameters' => 0,
		'remove_unknown_parameters' => 0,
		'allowed_parameters' => '',
	);

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 14 );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'init', array( $this, 'apply_frontend_settings' ), 99 );
		add_filter( 'wp_headers', array( $this, 'filter_headers' ), 20 );
		add_action( 'send_headers', array( $this, 'remove_server_headers' ), 20 );
		add_filter( 'robots_txt', array( $this, 'filter_robots' ), 40, 2 );
		add_filter( 'request', array( $this, 'sanitize_search_request' ), 20 );
		add_action( 'template_redirect', array( $this, 'cleanup_search_and_parameters' ), 1 );
		add_action( 'wp_footer', array( $this, 'clean_utm_from_visible_url' ), 99 );
	}

	public static function defaults() {
		$page = new self();
		return $page->defaults;
	}

	public static function get_options() {
		$options = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $options ) ? $options : array(), self::defaults() );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Crawl Optimization', 'publishrank' ), __( 'Crawl Optimization', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function settings() {
		register_setting(
			'publishrank_crawl_optimization',
			self::OPTION,
			array(
				'type' => 'array',
				'default' => $this->defaults,
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out = $this->defaults;
		$booleans = array_keys( $this->defaults );
		unset( $booleans[ array_search( 'search_length', $booleans, true ) ], $booleans[ array_search( 'allowed_parameters', $booleans, true ) ] );
		foreach ( $booleans as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		$out['search_length'] = isset( $input['search_length'] ) ? max( 1, min( 50, absint( $input['search_length'] ) ) ) : 50;
		$raw = isset( $input['allowed_parameters'] ) ? (string) $input['allowed_parameters'] : '';
		$parts = preg_split( '/[\s,]+/', strtolower( $raw ) );
		$allowed = array();
		foreach ( (array) $parts as $part ) {
			$part = trim( $part );
			if ( preg_match( '/^[a-z0-9_\-]{1,64}$/', $part ) ) {
				$allowed[] = $part;
			}
		}
		$out['allowed_parameters'] = implode( "\n", array_slice( array_unique( $allowed ), 0, 100 ) );
		return $out;
	}

	public function apply_frontend_settings() {
		$o = self::get_options();
		if ( ! is_admin() ) {
			if ( $o['remove_shortlinks'] ) { remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 ); }
			if ( $o['remove_rest_links'] ) { remove_action( 'wp_head', 'rest_output_link_wp_head', 10 ); remove_action( 'template_redirect', 'rest_output_link_header', 11 ); }
			if ( $o['remove_rsd_wlw'] ) { remove_action( 'wp_head', 'rsd_link' ); remove_action( 'wp_head', 'wlwmanifest_link' ); }
			if ( $o['remove_oembed_links'] ) { remove_action( 'wp_head', 'wp_oembed_add_discovery_links' ); remove_action( 'wp_head', 'wp_oembed_add_host_js' ); }
			if ( $o['remove_generator'] ) { remove_action( 'wp_head', 'wp_generator' ); add_filter( 'the_generator', '__return_empty_string', 99 ); }
			if ( $o['remove_feed_links'] ) { remove_action( 'wp_head', 'feed_links', 2 ); }
			if ( $o['remove_comment_feed_links'] ) { remove_action( 'wp_head', 'feed_links_extra', 3 ); }
			if ( $o['remove_author_feed_links'] || $o['remove_post_type_feed_links'] || $o['remove_taxonomy_feed_links'] || $o['remove_search_feed_links'] || $o['remove_legacy_feed_links'] ) { add_action( 'wp_head', array( $this, 'remove_selected_feed_links' ), 1 ); }
			if ( $o['remove_emojis'] ) { $this->disable_emojis(); }
		}
	}

	public function remove_selected_feed_links() {
		$o = self::get_options();
		if ( $o['remove_author_feed_links'] ) { remove_action( 'wp_head', 'feed_links_extra', 3 ); }
		if ( $o['remove_post_type_feed_links'] || $o['remove_taxonomy_feed_links'] || $o['remove_search_feed_links'] || $o['remove_legacy_feed_links'] ) {
			// feed_links_extra emits several context-specific discovery links. Remove it only when at least one of these opt-ins is selected.
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}
		if ( $o['remove_legacy_feed_links'] ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}
	}

	private function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', static function ( $plugins ) { return is_array( $plugins ) ? array_values( array_diff( $plugins, array( 'wpemoji' ) ) ) : array(); } );
		add_filter( 'wp_resource_hints', static function ( $urls, $relation_type ) { if ( 'dns-prefetch' === $relation_type ) { $urls = array_values( array_filter( $urls, static function ( $url ) { return false === strpos( (string) $url, 's.w.org/images/core/emoji' ); } ) ); } return $urls; }, 10, 2 );
	}

	public function remove_server_headers() {
		$o = self::get_options();
		if ( ! empty( $o['remove_powered_by'] ) && function_exists( 'header_remove' ) && ! headers_sent() ) {
			header_remove( 'X-Powered-By' );
		}
	}

	public function filter_headers( $headers ) {
		$o = self::get_options();
		if ( ! is_array( $headers ) ) { return $headers; }
		if ( $o['remove_pingback'] && isset( $headers['X-Pingback'] ) ) { unset( $headers['X-Pingback'] ); }
		if ( $o['remove_powered_by'] ) { unset( $headers['X-Powered-By'] ); unset( $headers['x-powered-by'] ); }
		return $headers;
	}

	public function filter_robots( $output, $public ) {
		$o = self::get_options();
		if ( '1' !== (string) $public ) { return $output; }
		$rules = array();
		if ( $o['disallow_wp_rest_content_api'] ) {
			// Keep PublishRank's schema index API crawlable; only disallow the core content API namespace.
			$rules[] = 'User-agent: *';
			$rules[] = 'Disallow: /wp-json/wp/v2/';
			$rules[] = 'Disallow: /*?rest_route=/wp/v2/';
		}
		if ( $o['disallow_search_crawling'] ) {
			$rules[] = 'User-agent: *';
			$rules[] = 'Disallow: /?s=';
			$rules[] = 'Disallow: /search/';
			$rules[] = 'Disallow: /*/page/*/?s=';
		}
		if ( $rules ) { $output = rtrim( (string) $output ) . "\n\n" . implode( "\n", array_unique( $rules ) ) . "\n"; }
		return $output;
	}

	public function sanitize_search_request( $query_vars ) {
		$o = self::get_options();
		if ( ! is_array( $query_vars ) || empty( $query_vars['s'] ) ) { return $query_vars; }
		$search = (string) $query_vars['s'];
		if ( $o['limit_search_length'] ) { $search = function_exists( 'mb_substr' ) ? mb_substr( $search, 0, (int) $o['search_length'], 'UTF-8' ) : substr( $search, 0, (int) $o['search_length'] ); }
		if ( $o['filter_search_special'] ) {
			$clean = preg_replace( '/[^\p{L}\p{N}\s\-_\'\x{2019}\.]/u', '', $search );
			if ( is_string( $clean ) ) { $search = trim( $clean ); }
		}
		if ( $o['filter_search_spam'] && preg_match( '/(?:\bcasino\b|\bviagra\b|\bpayday\s+loan\b|\bcrypto\s+giveaway\b|<\s*script|https?:\/\/)/iu', $search ) ) {
			$query_vars['s'] = '';
			$query_vars['publishrank_blocked_search'] = 1;
			return $query_vars;
		}
		$query_vars['s'] = $search;
		return $query_vars;
	}


	/** Move UTM tags out of the visible query string without a redirect or second request. */
	public function clean_utm_from_visible_url() {
		$o = self::get_options();
		if ( empty( $o['clean_utm_parameters'] ) || is_admin() || is_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		echo '<script id="publishrank-utm-url-cleanup">(function(){try{var u=new URL(window.location.href),p=new URLSearchParams(),found=[];u.searchParams.forEach(function(v,k){if(/^utm_[a-z0-9_]+$/i.test(k)){p.append(k,v);found.push(k);}});if(!found.length||!window.history||!window.history.replaceState)return;found.forEach(function(k){u.searchParams.delete(k);});var old=u.hash?u.hash.slice(1):"",tracking=p.toString();u.hash=old?(tracking?old+"&"+tracking:old):tracking;window.history.replaceState(window.history.state,"",u.pathname+(u.search?u.search:"")+u.hash);}catch(e){}})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed inline script; dynamic values are read and encoded in the browser.
	}

	public function cleanup_search_and_parameters() {
		$o = self::get_options();
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_preview() ) { return; }
		if ( $o['redirect_pretty_search'] && is_search() ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
			if ( false !== strpos( $path, '/search/' ) ) {
				$url = add_query_arg( 's', get_search_query( false ), home_url( '/' ) );
				wp_safe_redirect( $url, 301, 'PublishRank Crawl Optimization' );
				exit;
			}
		}
		if ( ! $o['remove_unknown_parameters'] || empty( $_GET ) || is_feed() ) { return; }
		$allowed = array( 'gclid', 'gtm_debug', 'utm_campaign', 'utm_content', 'utm_medium', 'utm_source', 'utm_term', 'fbclid', 'msclkid', 's', 'page', 'paged', 'preview', 'preview_id', 'preview_nonce', 'rest_route' );
		$custom = preg_split( '/\s+/', (string) $o['allowed_parameters'] );
		$allowed = array_unique( array_merge( $allowed, array_filter( (array) $custom ) ) );
		$unknown = array_diff( array_map( 'sanitize_key', array_keys( $_GET ) ), $allowed );
		if ( empty( $unknown ) ) { return; }
		// Do not redirect requests carrying WordPress preview, nonce, REST or admin query parameters.
		foreach ( array( 'preview', 'preview_id', 'preview_nonce', 'rest_route', 'nonce', '_wpnonce' ) as $sensitive ) { if ( isset( $_GET[ $sensitive ] ) ) { return; } }
		$target = remove_query_arg( $unknown );
		if ( $target ) {
			wp_safe_redirect( $target, 301, 'PublishRank Crawl Optimization' );
			exit;
		}
	}

	private function checkbox( $key, $label, $description = '' ) {
		$o = self::get_options();
		echo '<label class="pr-co-option"><input type="checkbox" name="' . esc_attr( self::OPTION . '[' . $key . ']' ) . '" value="1" ' . checked( ! empty( $o[ $key ] ), true, false ) . ' /> <strong>' . esc_html( $label ) . '</strong>';
		if ( $description ) { echo '<span class="description">' . esc_html( $description ) . '</span>'; }
		echo '</label>';
	}

	private function section( $title, $description, $fields ) {
		echo '<section class="pr-co-card"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $description ) . '</p><div class="pr-co-options">';
		foreach ( $fields as $field ) { $this->checkbox( $field[0], $field[1], isset( $field[2] ) ? $field[2] : '' ); }
		echo '</div></section>';
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You do not have permission to manage PublishRank settings.', 'publishrank' ) ); }
		$o = self::get_options();
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen ) { $screen->add_help_tab( array( 'id' => 'publishrank-crawl-help', 'title' => __( 'Safe use', 'publishrank' ), 'content' => '<p>' . esc_html__( 'All cleanup options are off by default. Change one group at a time and test the public site. robots.txt rules are crawler hints, not access controls.', 'publishrank' ) . '</p>' ) ); }
		echo '<div class="wrap publishrank-crawl-optimization"><h1>' . esc_html__( 'Crawl Optimization', 'publishrank' ) . '</h1><p>' . esc_html__( 'Reduce unnecessary WordPress discovery markup and clean low-value crawl paths. Options are disabled until you choose them.', 'publishrank' ) . '</p>';
		echo '<style>.publishrank-crawl-optimization{max-width:1100px}.pr-co-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px 24px;margin:18px 0}.pr-co-card h2{margin-top:0}.pr-co-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.pr-co-option{display:block;padding:12px;border:1px solid #e2e4e7;border-radius:7px}.pr-co-option .description{display:block;margin:7px 0 0 25px;line-height:1.5}.pr-co-warning{border-left:4px solid #dba617;background:#fff8e5;padding:12px 15px}</style>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'publishrank_crawl_optimization' );
		$this->section( __( 'Remove unwanted metadata', 'publishrank' ), __( 'Remove discovery links and software headers from public pages. This does not disable the underlying WordPress APIs.', 'publishrank' ), array(
			array( 'remove_shortlinks', __( 'Remove shortlinks', 'publishrank' ) ),
			array( 'remove_rest_links', __( 'Remove REST API discovery links', 'publishrank' ), __( 'Keeps the REST API available, including PublishRank’s schema index endpoint.', 'publishrank' ) ),
			array( 'remove_rsd_wlw', __( 'Remove RSD / WLW links', 'publishrank' ) ),
			array( 'remove_oembed_links', __( 'Remove oEmbed discovery links', 'publishrank' ) ),
			array( 'remove_generator', __( 'Remove generator tag', 'publishrank' ) ),
			array( 'remove_pingback', __( 'Remove X-Pingback header', 'publishrank' ) ),
			array( 'remove_powered_by', __( 'Remove X-Powered-By header', 'publishrank' ), __( 'PHP or the web server may add this header after WordPress runs; host-level configuration may still be needed.', 'publishrank' ) ),
		) );
		$this->section( __( 'Disable unwanted discovery formats', 'publishrank' ), __( 'These controls remove feed discovery links from page headers; they do not delete posts or forcibly disable feed URLs. WordPress groups several contextual feed links together, so contextual selections remove that group.', 'publishrank' ), array(
			array( 'remove_feed_links', __( 'Remove global feed links', 'publishrank' ) ),
			array( 'remove_comment_feed_links', __( 'Remove comment feed links', 'publishrank' ) ),
			array( 'remove_author_feed_links', __( 'Remove author feed links', 'publishrank' ) ),
			array( 'remove_post_type_feed_links', __( 'Remove post type feed links', 'publishrank' ) ),
			array( 'remove_taxonomy_feed_links', __( 'Remove category, tag and taxonomy feed links', 'publishrank' ) ),
			array( 'remove_search_feed_links', __( 'Remove search feed links', 'publishrank' ) ),
			array( 'remove_legacy_feed_links', __( 'Remove Atom / RDF and legacy feed links', 'publishrank' ) ),
			array( 'remove_emojis', __( 'Remove WordPress emoji scripts and styles', 'publishrank' ) ),
		) );
		$this->section( __( 'Crawl directives', 'publishrank' ), __( 'These are robots.txt directives and do not provide security or prevent indexing of URLs discovered elsewhere.', 'publishrank' ), array(
			array( 'disallow_wp_rest_content_api', __( 'Discourage crawling of the core REST content API', 'publishrank' ), __( 'Adds rules for /wp-json/wp/v2/ while leaving PublishRank schema-index endpoints untouched.', 'publishrank' ) ),
		) );
		echo '<section class="pr-co-card"><h2>' . esc_html__( 'Internal site search cleanup', 'publishrank' ) . '</h2><p>' . esc_html__( 'Apply limits to visitor search queries. These controls affect searches, not published article URLs.', 'publishrank' ) . '</p><div class="pr-co-options">';
		$this->checkbox( 'limit_search_length', __( 'Limit search query length', 'publishrank' ) );
		echo '<label class="pr-co-option"><strong>' . esc_html__( 'Maximum search characters', 'publishrank' ) . '</strong><span class="description"><input type="number" min="1" max="50" name="' . esc_attr( self::OPTION . '[search_length]' ) . '" value="' . esc_attr( (string) $o['search_length'] ) . '" /></span></label>';
		$this->checkbox( 'filter_search_special', __( 'Filter emojis and unusual special characters', 'publishrank' ) );
		$this->checkbox( 'filter_search_spam', __( 'Filter common spam patterns', 'publishrank' ) );
		$this->checkbox( 'redirect_pretty_search', __( 'Redirect pretty search URLs to ?s= format', 'publishrank' ) );
		$this->checkbox( 'disallow_search_crawling', __( 'Discourage crawling of internal search URLs', 'publishrank' ) );
		echo '</div></section>';
		echo '<section class="pr-co-card"><h2>' . esc_html__( 'Advanced URL cleanup', 'publishrank' ) . '</h2><p class="pr-co-warning">' . esc_html__( 'Expert feature: redirects can affect tracking and site functionality. Unknown-parameter redirects preserve UTM, gclid and fbclid. UTM cleanup below only changes the visible URL after the page loads; tracking values are moved into the fragment and are not sent to the server on later requests.', 'publishrank' ) . '</p><div class="pr-co-options">';
		$this->checkbox( 'clean_utm_parameters', __( 'Clean UTM parameters from the visible URL after page load', 'publishrank' ), __( 'Uses the History API, not a redirect. Check analytics and hash-based navigation on staging before enabling.', 'publishrank' ) );
		$this->checkbox( 'remove_unknown_parameters', __( 'Redirect URLs with unregistered query parameters', 'publishrank' ), __( 'Known analytics, pagination, preview and REST parameters are preserved.', 'publishrank' ) );
		echo '<label class="pr-co-option"><strong>' . esc_html__( 'Additional parameters to allow', 'publishrank' ) . '</strong><span class="description"><textarea rows="4" style="width:100%;margin-top:8px" name="' . esc_attr( self::OPTION . '[allowed_parameters]' ) . '">' . esc_textarea( (string) $o['allowed_parameters'] ) . '</textarea>' . esc_html__( 'Separate parameter names with commas or new lines.', 'publishrank' ) . '</span></label>';
		echo '</div></section>';
		echo '<p class="submit">';
		submit_button( __( 'Save crawl optimization settings', 'publishrank' ), 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=publishrank-seo-robots' ) ) . '">' . esc_html__( 'Manage AI crawler policies', 'publishrank' ) . '</a></p></form></div>';
	}
}
