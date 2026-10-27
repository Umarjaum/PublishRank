<?php
/** Publisher link audit: broken internal links, redirected targets and optional external checks. */
namespace PublishRankSEO\InternalLinks;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Admin\AdminAssets;
use PublishRankSEO\Redirect\RedirectStore;

defined( 'ABSPATH' ) || exit;

final class PublisherLinkIntelligence {
	const SLUG           = 'publishrank-link-intelligence';
	const RESULTS_OPTION = 'publishrank_link_intelligence_results';
	const STATE_KEY      = 'publishrank_link_intelligence_state';
	const MAX_RESULTS    = 1000;

	public function register() {
		if ( ! is_admin() ) {
			return; }
		add_action( 'admin_menu', array( $this, 'menu' ), 14 );
		add_action( 'admin_post_publishrank_link_intelligence_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_publishrank_link_intelligence_batch', array( $this, 'handle_batch' ) );
		add_action( 'admin_post_publishrank_link_intelligence_clear', array( $this, 'handle_clear' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Publisher Link Intelligence', 'publishrank' ), __( 'Link Intelligence', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run link audits.', 'publishrank' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
	}

	public function handle_start() {
		$this->guard( 'publishrank_link_intelligence_start' );
		check_admin_referer( 'publishrank_link_intelligence_start' ); // Explicit nonce verification for the request data read below.
		$types = Store::allowed_types();
		$ids   = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => false,
			)
		);
		$total = 0;
		if ( $ids ) {
			$q     = new \WP_Query(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'no_found_rows'  => false,
				)
			);
			$total = (int) $q->found_posts;
		}
		$state = array(
			'last_id'          => 0,
			'processed'        => 0,
			'total'            => $total,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The matching action nonce is validated by the handler guard before request data is processed.
			'external'         => ! empty( $_POST['check_external'] ),
			'external_checked' => 0,
			'started'          => time(),
		);
		set_transient( self::STATE_KEY, $state, DAY_IN_SECONDS );
		update_option( self::RESULTS_OPTION, array(), false );
		$this->redirect( __( 'Link audit started. Continue in batches to keep large sites responsive.', 'publishrank' ) );
	}

	public function handle_batch() {
		$this->guard( 'publishrank_link_intelligence_batch' );
		$state = get_transient( self::STATE_KEY );
		if ( ! is_array( $state ) ) {
			$this->redirect( __( 'No active audit. Start a new audit first.', 'publishrank' ), 'error' ); }
		$types = Store::allowed_types();
		// Use an explicit ID keyset query rather than offset pagination on large publisher archives.
		global $wpdb;
		$ids = array();
		if ( $types ) {
			$type_placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$sql               = "SELECT ID FROM %i WHERE post_status = 'publish' AND post_type IN ({$type_placeholders}) AND ID > %d ORDER BY ID ASC LIMIT 15";
			$args              = array_merge( array( $wpdb->posts ), $types, array( (int) $state['last_id'] ) );
			$ids               = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Local SQL template uses %i and generated %s placeholders; all values are passed to prepare().
		}
		$results                  = get_option( self::RESULTS_OPTION, array() );
		$results                  = is_array( $results ) ? $results : array();
		$external_budget          = 5;
		$state['external_queue']  = isset( $state['external_queue'] ) && is_array( $state['external_queue'] ) ? $state['external_queue'] : array();
		$state['external_queued'] = isset( $state['external_queued'] ) && is_array( $state['external_queued'] ) ? $state['external_queued'] : array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				$state['last_id'] = max( (int) $state['last_id'], $id );
				continue; }
			$state['last_id'] = max( (int) $state['last_id'], $id );
			++$state['processed'];
			foreach ( self::extract_links( (string) $post->post_content ) as $link ) {
				$kind = self::classify_link( $link['url'] );
				if ( 'external' === $kind && ! empty( $state['external'] ) ) {
					$external_key = md5( (int) $post->ID . '|' . $link['url'] );
					if ( empty( $state['external_queued'][ $external_key ] ) && count( $state['external_queue'] ) < 2000 ) {
						$state['external_queue'][]                 = array(
							'source_id' => (int) $post->ID,
							'url'       => $link['url'],
							'anchor'    => $link['anchor'],
							'key'       => $external_key,
						);
						$state['external_queued'][ $external_key ] = 1;
					}
					continue;
				}
				if ( 'broken_internal' === $kind || 'redirected_internal' === $kind ) {
					self::add_result( $results, $post, $link, $kind, self::link_detail( $link['url'], $kind ) );
				}
			}
		}
		while ( $external_budget > 0 && ! empty( $state['external_queue'] ) ) {
			$queued = array_shift( $state['external_queue'] );
			--$external_budget;
			++$state['external_checked'];
			$post = get_post( (int) $queued['source_id'] );
			if ( ! $post ) {
				continue; }
			$check = self::check_external( $queued['url'] );
			if ( 'broken_external' === $check['status'] || 'external_error' === $check['status'] ) {
				self::add_result(
					$results,
					$post,
					array(
						'url'    => $queued['url'],
						'anchor' => $queued['anchor'],
					),
					$check['status'],
					$check['detail']
				);
			}
		}
		update_option( self::RESULTS_OPTION, array_slice( $results, -self::MAX_RESULTS, null, true ), false );
		$more = ! empty( $ids ) || ! empty( $state['external_queue'] );
		if ( ! $more ) {
			delete_transient( self::STATE_KEY ); } else {
			set_transient( self::STATE_KEY, $state, DAY_IN_SECONDS ); }
			$this->redirect( $more ? __( 'Batch complete. Continue the audit to process the next group of posts or queued external links.', 'publishrank' ) : __( 'Audit complete. Review broken links, redirecting links and redirect rules below.', 'publishrank' ) );
	}

	public function handle_clear() {
		$this->guard( 'publishrank_link_intelligence_clear' );
		update_option( self::RESULTS_OPTION, array(), false );
		delete_transient( self::STATE_KEY );
		$this->redirect( __( 'Link audit results cleared.', 'publishrank' ) );
	}

	private function redirect( $message, $type = 'success' ) {
		$url = add_query_arg(
			array(
				'page'       => self::SLUG,
				'pr_message' => rawurlencode( $message ),
				'pr_type'    => $type,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public static function extract_links( $content ) {
		$out = array();
		if ( ! preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			return $out; }
		foreach ( $matches as $match ) {
			if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $match[1], $href ) && ! preg_match( '/\bhref\s*=\s*([^\s>]+)/is', $match[1], $href ) ) {
				continue; }
			$url = trim( html_entity_decode( $href[2] ?? $href[1] ?? '', ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $url || 0 === strpos( $url, '#' ) || preg_match( '#^(mailto:|tel:|javascript:|data:)#i', $url ) ) {
				continue; }
			$anchor = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $match[2] ) ) );
			$out[]  = array(
				'url'    => esc_url_raw( $url ),
				'anchor' => function_exists( 'mb_substr' ) ? mb_substr( $anchor, 0, 255, 'UTF-8' ) : substr( $anchor, 0, 255 ),
			);
		}
		return $out;
	}

	public static function classify_link( $url ) {
		$url       = trim( (string) $url );
		$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( ! $host ) {
			$url  = home_url( '/' . ltrim( $url, '/' ) );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ); }
		if ( ! $host || $host !== $home_host ) {
			return 'external'; }
		$path = RedirectStore::normalise_path( $url );
		$rule = self::redirect_rule_for_path( $path );
		if ( $rule ) {
			return 'redirected_internal'; }
		$post_id = url_to_postid( $url );
		if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
			return 'valid_internal'; }
		// Home page and valid public endpoints are not treated as broken post links.
		if ( '/' === $path || preg_match( '#^/(wp-content|wp-includes)/#', $path ) ) {
			return 'valid_internal'; }
		return 'broken_internal';
	}

	private static function redirect_rule_for_path( $path ) {
		foreach ( RedirectStore::all() as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue; }
			if ( empty( $rule['regex'] ) && RedirectStore::normalise_path( $rule['source'] ?? '' ) === $path ) {
				return $rule; }
			if ( ! empty( $rule['regex'] ) ) {
				$pattern = (string) ( $rule['source'] ?? '' );
				if ( '' !== $pattern ) {
					if ( 0 !== strpos( $pattern, '~' ) ) {
						$pattern = '~' . str_replace( '~', '\\~', $pattern ) . '~'; }
					$pattern_ok = true;
					set_error_handler(
						static function () use ( &$pattern_ok ) {
							$pattern_ok = false;
							return true;
						}
					); // Invalid admin-supplied patterns must not emit warnings.
					$matched = preg_match( $pattern, $path );
					restore_error_handler();
					if ( $pattern_ok && 1 === $matched ) {
						return $rule; }
				}
			}
		}
		return null;
	}

	private static function link_detail( $url, $kind ) {
		if ( 'broken_internal' === $kind ) {
			return __( 'Same-site URL does not resolve to a published post/page and has no matching PublishRank redirect.', 'publishrank' ); }
		$path = RedirectStore::normalise_path( $url );
		$rule = self::redirect_rule_for_path( $path );
		return $rule ? sprintf( /* translators: %s: redirect destination. */ __( 'Link points to a redirect rule targeting %s. Update the source link to the final destination.', 'publishrank' ), (string) ( $rule['target'] ?? ( $rule['status'] ?? '' ) ) ) : __( 'Link points to a redirected URL.', 'publishrank' );
	}

	private static function check_external( $url ) {
		if ( ! preg_match( '#^https?://#i', (string) $url ) ) {
			return array(
				'status' => 'external_error',
				'detail' => __( 'External URL is not HTTP or HTTPS.', 'publishrank' ),
			); }
		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'             => 3,
				'redirection'         => 3,
				'limit_response_size' => 1024,
				'user-agent'          => 'PublishRankLinkAudit/' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '1.0' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'external_error',
				'detail' => $response->get_error_message(),
			); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 405 === $code || 501 === $code ) {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 3,
					'redirection'         => 3,
					'limit_response_size' => 1024,
					'user-agent'          => 'PublishRankLinkAudit/' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '1.0' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				return array(
					'status' => 'external_error',
					'detail' => $response->get_error_message(),
				); }
			$code = (int) wp_remote_retrieve_response_code( $response );
		}
		if ( $code >= 400 ) {
			return array(
				'status' => 'broken_external',
				'detail' => sprintf( /* translators: %d: HTTP status code. */ __( 'External URL returned HTTP %d.', 'publishrank' ), $code ),
			); }
		return array(
			'status' => 'external_ok',
			'detail' => sprintf( /* translators: %d: HTTP status code. */ __( 'External URL returned HTTP %d.', 'publishrank' ), $code ),
		);
	}

	private static function add_result( &$results, $post, $link, $status, $detail ) {
		$key             = md5( (int) $post->ID . '|' . $link['url'] . '|' . $status );
		$results[ $key ] = array(
			'key'          => $key,
			'source_id'    => (int) $post->ID,
			'source_title' => (string) get_the_title( $post ),
			'source_url'   => (string) get_permalink( $post ),
			'url'          => (string) $link['url'],
			'anchor'       => (string) $link['anchor'],
			'status'       => (string) $status,
			'detail'       => (string) $detail,
			'checked_at'   => time(),
		);
	}

	private function render_redirect_audit() {
		$rules  = RedirectStore::all();
		$issues = array();
		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['regex'] ) || empty( $rule['target'] ) || empty( $rule['enabled'] ) ) {
				continue; }
			$source = RedirectStore::normalise_path( $rule['source'] ?? '' );
			$target = RedirectStore::normalise_path( $rule['target'] ?? '' );
			if ( $source === $target ) {
				$issues[] = array( $rule, __( 'Source and destination are identical.', 'publishrank' ) );
				continue; }
			$next = self::redirect_rule_for_path( $target );
			if ( $next && RedirectStore::normalise_path( $next['source'] ?? '' ) !== $source ) {
				$issues[] = array( $rule, sprintf( /* translators: %s: next redirect destination. */ __( 'Redirect chain detected: destination redirects again to %s.', 'publishrank' ), (string) ( $next['target'] ?? $next['status'] ?? '' ) ) ); }
		}
		echo '<h2>' . esc_html__( 'Redirect rule audit', 'publishrank' ) . '</h2>';
		if ( ! $issues ) {
			echo '<p>' . esc_html__( 'No obvious exact-path redirect chains or self-redirects were found. Regex rules are not exhaustively evaluated.', 'publishrank' ) . '</p>';
			return; }
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Source', 'publishrank' ) . '</th><th>' . esc_html__( 'Destination', 'publishrank' ) . '</th><th>' . esc_html__( 'Issue', 'publishrank' ) . '</th></tr></thead><tbody>';
		foreach ( $issues as $issue ) {
			echo '<tr><td><code>' . esc_html( $issue[0]['source'] ?? '' ) . '</code></td><td><code>' . esc_html( $issue[0]['target'] ?? '' ) . '</code></td><td>' . esc_html( $issue[1] ) . '</td></tr>'; }
		echo '</tbody></table>';
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$results = get_option( self::RESULTS_OPTION, array() );
		$results = is_array( $results ) ? array_values( $results ) : array();
		$state   = get_transient( self::STATE_KEY );
		echo '<div class="wrap">';
		AdminAssets::header( __( 'Publisher Link Intelligence', 'publishrank' ), __( 'Audit broken internal links, identify links that point through redirects, review redirect chains, and optionally check external URLs. Uses local content analysis; no AI API required.', 'publishrank' ) );
		if ( isset( $_GET['pr_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
			$type = isset( $_GET['pr_type'] ) ? sanitize_key( wp_unslash( $_GET['pr_type'] ) ) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
			echo '<div class="notice notice-' . esc_attr( in_array( $type, array( 'error', 'success', 'warning' ), true ) ? $type : 'success' ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['pr_message'] ) ) ) . '</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display/pagination/OAuth-callback parameter; sanitized here, nothing is saved.
		echo '<div class="rhs-grid">';
		$counts = array(
			'broken_internal'     => 0,
			'redirected_internal' => 0,
			'broken_external'     => 0,
			'external_error'      => 0,
		);
		foreach ( $results as $row ) {
			if ( isset( $counts[ $row['status'] ?? '' ] ) ) {
				++$counts[ $row['status'] ]; }
		}
		foreach ( array(
			__( 'Broken internal links', 'publishrank' )   => $counts['broken_internal'],
			__( 'Links through redirects', 'publishrank' ) => $counts['redirected_internal'],
			__( 'Broken external links', 'publishrank' )   => $counts['broken_external'],
			__( 'External check errors', 'publishrank' )   => $counts['external_error'],
		) as $label => $count ) {
			echo '<div class="rhs-card"><p class="rhs-label">' . esc_html( $label ) . '</p><div class="rhs-stat">' . esc_html( number_format_i18n( $count ) ) . '</div></div>'; }
		$graph_stats     = Store::stats();
		$not_found_log   = get_option( 'publishrank_seo_404_log', array() );
		$not_found_count = is_array( $not_found_log ) ? count( $not_found_log ) : 0;
		foreach ( array(
			__( 'Internal links in graph', 'publishrank' ) => (int) ( $graph_stats['edges'] ?? 0 ),
			__( 'Orphan / low-inbound pages', 'publishrank' ) => (int) ( $graph_stats['orphans'] ?? 0 ),
			__( 'Link opportunities', 'publishrank' )      => (int) ( ( $graph_stats['pending'] ?? 0 ) + ( $graph_stats['approved'] ?? 0 ) ),
			__( 'Recorded 404 paths', 'publishrank' )      => $not_found_count,
		) as $label => $count ) {
			echo '<div class="rhs-card"><p class="rhs-label">' . esc_html( $label ) . '</p><div class="rhs-stat">' . esc_html( number_format_i18n( $count ) ) . '</div></div>'; }
		echo '</div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'publishrank_link_intelligence_start' );
		echo '<input type="hidden" name="action" value="publishrank_link_intelligence_start" />';
		echo '<p><label><input type="checkbox" name="check_external" value="1" /> ' . esc_html__( 'Also check external HTTP/HTTPS links (slower; checks at most five external URLs per batch).', 'publishrank' ) . '</label></p><p><button class="button button-primary">' . esc_html__( 'Start new audit', 'publishrank' ) . '</button></p></form>';
		if ( is_array( $state ) ) {
			echo '<p>' . esc_html( sprintf( /* translators: 1: posts processed, 2: total posts. */ __( 'Audit progress: %1$d of approximately %2$d posts processed.', 'publishrank' ), (int) $state['processed'], (int) $state['total'] ) ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'publishrank_link_intelligence_batch' );
			echo '<input type="hidden" name="action" value="publishrank_link_intelligence_batch" /><button class="button button-primary">' . esc_html__( 'Continue audit batch', 'publishrank' ) . '</button></form>'; }
		echo '<h2>' . esc_html__( 'Audit findings', 'publishrank' ) . '</h2><p>' . esc_html__( 'Findings are capped at 1,000 rows. A URL may be reported once for each source page that links to it. Some endpoints intentionally do not map to a WordPress post and may need manual review.', 'publishrank' ) . '</p>';
		if ( ! $results ) {
			echo '<p>' . esc_html__( 'No findings yet. Start an audit to build a report.', 'publishrank' ) . '</p>'; } else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Finding', 'publishrank' ) . '</th><th>' . esc_html__( 'Linked URL', 'publishrank' ) . '</th><th>' . esc_html__( 'Source page', 'publishrank' ) . '</th><th>' . esc_html__( 'Anchor', 'publishrank' ) . '</th><th>' . esc_html__( 'Details', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( $results ) as $row ) {
				$status = (string) ( $row['status'] ?? '' );
				$label  = isset( $counts[ $status ] ) ? $status : $status;
				echo '<tr><td><strong>' . esc_html( ucwords( str_replace( '_', ' ', $label ) ) ) . '</strong></td><td><a href="' . esc_url( $row['url'] ?? '' ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $row['url'] ?? '' ) . '</code></a></td><td><a href="' . esc_url( get_edit_post_link( (int) ( $row['source_id'] ?? 0 ), 'raw' ) ) . '">' . esc_html( $row['source_title'] ?? '' ) . '</a></td><td>' . esc_html( $row['anchor'] ?? '' ) . '</td><td>' . esc_html( $row['detail'] ?? '' ) . '</td></tr>'; }
			echo '</tbody></table>'; }
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px">';
			wp_nonce_field( 'publishrank_link_intelligence_clear' );
			echo '<input type="hidden" name="action" value="publishrank_link_intelligence_clear" /><button class="button" onclick="return confirm(\'' . esc_js( __( 'Clear all link audit findings?', 'publishrank' ) ) . '\')">' . esc_html__( 'Clear findings', 'publishrank' ) . '</button></form>';
			$this->render_redirect_audit();
			echo '<h2>' . esc_html__( 'Related tools', 'publishrank' ) . '</h2><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=publishrank-seo-internal-links' ) ) . '">' . esc_html__( 'Internal-link opportunities and orphan pages', 'publishrank' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=publishrank-seo-404' ) ) . '">' . esc_html__( '404 Monitor', 'publishrank' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=publishrank-seo-redirects' ) ) . '">' . esc_html__( 'Redirect Manager', 'publishrank' ) . '</a></p></div>';
	}
}
