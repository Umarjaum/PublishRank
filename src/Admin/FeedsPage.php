<?php
/**
 * PublishRank → RSS Feeds: feed settings, endpoint list, validation and a reader-style preview.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Feeds\FeedManager;

defined( 'ABSPATH' ) || exit;

final class FeedsPage {
	const SLUG   = 'publishrank-feeds';
	const ACTION = 'publishrank_feeds_save';
	const CHECK  = 'publishrank_feeds_check';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 31 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'RSS Feeds', 'publishrank' ), __( 'RSS Feeds', 'publishrank' ), 'publishrank_manage_feeds', self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG === $page ) {
			ModuleUi::enqueue();
		}
	}

	public function save() {
		AdminAssets::guard( self::ACTION, 'publishrank_manage_feeds' );
		check_admin_referer( self::ACTION );
		$raw    = isset( $_POST['feeds'] ) && is_array( $_POST['feeds'] ) ? map_deep( wp_unslash( $_POST['feeds'] ), 'sanitize_textarea_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// Unchecked checkboxes are absent from the POST: store them as off (otherwise a default of 1 could never be turned off).
		foreach ( array( 'append_author', 'append_publisher', 'append_copyright', 'append_categories', 'news_feed', 'discovery', 'exclude_noindex', 'websub', 'json_feed', 'utm' ) as $flag ) {
			if ( ! isset( $raw[ $flag ] ) ) {
				$raw[ $flag ] = 0; }
		}
		$before = FeedManager::settings();
		$clean  = FeedManager::sanitize( $raw );
		update_option( FeedManager::OPTION, $clean, false );
		// Shared with General Settings → Feeds: one option each, never duplicated.
		update_option( 'publishrank_feed_images', empty( $_POST['feed_images'] ) ? 0 : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_option( 'publishrank_feed_attribution', empty( $_POST['feed_attribution'] ) ? 0 : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( (int) $before['json_feed'] !== (int) $clean['json_feed'] ) {
			global $wp_rewrite;
			if ( $clean['json_feed'] ) {
				add_feed( \PublishRankSEO\Feeds\FeedEnhancer::JSON_FEED, '__return_null' );
			} else {
				$wp_rewrite->feeds = array_values( array_diff( (array) $wp_rewrite->feeds, array( \PublishRankSEO\Feeds\FeedEnhancer::JSON_FEED ) ) );
			}
			flush_rewrite_rules( false );
		}
		if ( (int) $before['news_feed'] !== (int) $clean['news_feed'] ) {
			global $wp_rewrite;
			if ( $clean['news_feed'] ) {
				add_feed( FeedManager::NEWS_FEED, array( new FeedManager(), 'render_news_feed' ) );
			} else {
				$wp_rewrite->feeds = array_values( array_diff( (array) $wp_rewrite->feeds, array( FeedManager::NEWS_FEED ) ) );
			}
			flush_rewrite_rules( false );
		}
		AdminAssets::done( self::SLUG, 'success', __( 'Feed settings saved.', 'publishrank' ) );
	}

	public function render() {
		if ( ! \PublishRankSEO\Core\Capabilities::can( 'manage_feeds' ) ) {
			return;
		}
		$s         = FeedManager::settings();
		$endpoints = FeedManager::endpoints();
		$selected  = '';
		$report    = null;
		if ( isset( $_GET['feed'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::CHECK ) ) {
			$selected = sanitize_key( wp_unslash( $_GET['feed'] ) );
			if ( isset( $endpoints[ $selected ] ) ) {
				$report = FeedManager::diagnose( $endpoints[ $selected ]['url'] );
			}
		}
		$f = static function ( $key ) {
			return 'feeds[' . $key . ']';
		};
		echo '<div class="wrap pr21">';
		AdminAssets::header( __( 'RSS Feeds', 'publishrank' ), __( 'Control what your RSS and Atom feeds contain, check that every feed is valid XML and preview them the way a feed reader shows them.', 'publishrank' ) );
		AdminAssets::render_flash();
		?>
		<div class="pr21-strip">
			<div><span class="pr21-kicker"><?php esc_html_e( 'Main feed', 'publishrank' ); ?></span><a href="<?php echo esc_url( get_feed_link() ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( get_feed_link() ); ?></code></a></div>
			<div><span class="pr21-kicker"><?php esc_html_e( 'Items per feed', 'publishrank' ); ?></span><strong><?php echo esc_html( (string) ( $s['item_limit'] > 0 ? $s['item_limit'] : get_option( 'posts_per_rss', 10 ) ) ); ?></strong></div>
			<div><span class="pr21-kicker"><?php esc_html_e( 'Content', 'publishrank' ); ?></span><strong><?php echo esc_html( 'excerpt' === $s['content_mode'] || ( '' === $s['content_mode'] && get_option( 'rss_use_excerpt' ) ) ? __( 'Excerpt', 'publishrank' ) : __( 'Full text', 'publishrank' ) ); ?></strong></div>
			<div><span class="pr21-kicker"><?php esc_html_e( 'Endpoints', 'publishrank' ); ?></span><strong><?php echo esc_html( (string) count( $endpoints ) ); ?></strong></div>
		</div>

		<div class="pr21-cols">
		<form class="pr21-main" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION ); ?>
			<section class="pr21-card">
				<h2><span class="fa-solid fa-rss" aria-hidden="true"></span> <?php esc_html_e( 'Feed content', 'publishrank' ); ?></h2>
				<div class="pr21-row">
					<label for="pr21-limit"><?php esc_html_e( 'Items per feed', 'publishrank' ); ?></label>
					<input id="pr21-limit" type="number" min="0" max="200" name="<?php echo esc_attr( $f( 'item_limit' ) ); ?>" value="<?php echo esc_attr( (string) $s['item_limit'] ); ?>" />
					<p class="description"><?php esc_html_e( '0 keeps the WordPress Reading setting. Applies to feeds only.', 'publishrank' ); ?></p>
				</div>
				<div class="pr21-row">
					<label for="pr21-mode"><?php esc_html_e( 'Each item shows', 'publishrank' ); ?></label>
					<select id="pr21-mode" name="<?php echo esc_attr( $f( 'content_mode' ) ); ?>">
						<option value="" <?php selected( $s['content_mode'], '' ); ?>><?php esc_html_e( 'WordPress Reading setting', 'publishrank' ); ?></option>
						<option value="full" <?php selected( $s['content_mode'], 'full' ); ?>><?php esc_html_e( 'Full text', 'publishrank' ); ?></option>
						<option value="excerpt" <?php selected( $s['content_mode'], 'excerpt' ); ?>><?php esc_html_e( 'Excerpt', 'publishrank' ); ?></option>
					</select>
				</div>
				<label class="pr21-check"><input type="checkbox" name="feed_images" value="1" <?php checked( (bool) get_option( 'publishrank_feed_images', 1 ) ); ?> /> <?php esc_html_e( 'Featured image in every item (media:content and at the top of the item)', 'publishrank' ); ?></label>
				<label class="pr21-check"><input type="checkbox" name="feed_attribution" value="1" <?php checked( (bool) get_option( 'publishrank_feed_attribution', 1 ) ); ?> /> <?php esc_html_e( 'Link back to the original article ("The post … appeared first on …")', 'publishrank' ); ?></label>
			</section>

			<section class="pr21-card">
				<h2><span class="fa-solid fa-id-card" aria-hidden="true"></span> <?php esc_html_e( 'Channel identity', 'publishrank' ); ?></h2>
				<div class="pr21-row"><label for="pr21-title"><?php esc_html_e( 'Main feed title', 'publishrank' ); ?></label><input id="pr21-title" class="regular-text" type="text" name="<?php echo esc_attr( $f( 'title' ) ); ?>" value="<?php echo esc_attr( $s['title'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo_rss( 'name' ) ); ?>" /></div>
				<div class="pr21-row"><label for="pr21-desc"><?php esc_html_e( 'Main feed description', 'publishrank' ); ?></label><textarea id="pr21-desc" class="large-text" rows="2" name="<?php echo esc_attr( $f( 'description' ) ); ?>" placeholder="<?php echo esc_attr( get_bloginfo_rss( 'description' ) ); ?>"><?php echo esc_textarea( $s['description'] ); ?></textarea></div>
				<div class="pr21-row"><label for="pr21-copy"><?php esc_html_e( 'Copyright', 'publishrank' ); ?></label><input id="pr21-copy" class="regular-text" type="text" name="<?php echo esc_attr( $f( 'copyright' ) ); ?>" value="<?php echo esc_attr( $s['copyright'] ); ?>" placeholder="<?php echo esc_attr( FeedManager::copyright_text() ); ?>" /><p class="description"><?php esc_html_e( 'Printed as the channel <copyright> (RSS) and <rights> (Atom). %%year%% and %%sitename%% are replaced. Empty fields keep the WordPress values.', 'publishrank' ); ?></p></div>
			</section>

			<section class="pr21-card">
				<h2><span class="fa-solid fa-signature" aria-hidden="true"></span> <?php esc_html_e( 'Lines added under every item', 'publishrank' ); ?></h2>
				<label class="pr21-check"><input type="checkbox" name="<?php echo esc_attr( $f( 'append_author' ) ); ?>" value="1" <?php checked( $s['append_author'] ); ?> /> <?php esc_html_e( 'Author', 'publishrank' ); ?></label>
				<label class="pr21-check"><input type="checkbox" name="<?php echo esc_attr( $f( 'append_publisher' ) ); ?>" value="1" <?php checked( $s['append_publisher'] ); ?> /> <?php esc_html_e( 'Publisher', 'publishrank' ); ?></label>
				<label class="pr21-check"><input type="checkbox" name="<?php echo esc_attr( $f( 'append_categories' ) ); ?>" value="1" <?php checked( $s['append_categories'] ); ?> /> <?php esc_html_e( 'Categories', 'publishrank' ); ?></label>
				<label class="pr21-check"><input type="checkbox" name="<?php echo esc_attr( $f( 'append_copyright' ) ); ?>" value="1" <?php checked( $s['append_copyright'] ); ?> /> <?php esc_html_e( 'Copyright notice', 'publishrank' ); ?></label>
				<div class="pr21-row"><label for="pr21-custom"><?php esc_html_e( 'Custom attribution text', 'publishrank' ); ?></label><textarea id="pr21-custom" class="large-text" rows="2" name="<?php echo esc_attr( $f( 'custom_text' ) ); ?>" placeholder="<?php esc_attr_e( 'Read %%title%% on %%sitename%%.', 'publishrank' ); ?>"><?php echo esc_textarea( $s['custom_text'] ); ?></textarea><p class="description"><?php esc_html_e( 'Plain text. Variables: %%title%%, %%permalink%%, %%sitename%%, %%author%%, %%date%%, %%year%%. Unknown variables are removed.', 'publishrank' ); ?></p></div>
				<?php $this->render_item_preview(); ?>
			</section>

			<section class="pr21-card">
				<h2><span class="fa-solid fa-filter" aria-hidden="true"></span> <?php esc_html_e( 'What feeds leave out', 'publishrank' ); ?></h2>
				<label class="pr21-check"><input type="checkbox" name="<?php echo esc_attr( $f( 'exclude_noindex' ) ); ?>" value="1" <?php checked( $s['exclude_noindex'] ); ?> /> <?php esc_html_e( 'Leave out articles set to noindex', 'publishrank' ); ?></label>
				<div class="pr21-row"><label for="pr21-xterms"><?php esc_html_e( 'Leave out these categories or tags (IDs)', 'publishrank' ); ?></label><input id="pr21-xterms" class="regular-text" type="text" inputmode="numeric" name="<?php echo esc_attr( $f( 'exclude_terms' ) ); ?>" value="<?php echo esc_attr( $s['exclude_terms'] ); ?>" placeholder="12, 48" /><p class="description"><?php esc_html_e( 'Comma-separated IDs, for example sponsored or internal sections. Applies to every feed, including category feeds.', 'publishrank' ); ?></p></div>
				<div class="pr21-row"><label for="pr21-xposts"><?php esc_html_e( 'Leave out these articles (IDs)', 'publishrank' ); ?></label><input id="pr21-xposts" class="regular-text" type="text" inputmode="numeric" name="<?php echo esc_attr( $f( 'exclude_posts' ) ); ?>" value="<?php echo esc_attr( $s['exclude_posts'] ); ?>" /></div>
				<div class="pr21-row"><label for="pr21-delay"><?php esc_html_e( 'Delay new articles in feeds (minutes)', 'publishrank' ); ?></label><input id="pr21-delay" type="number" min="0" max="1440" name="<?php echo esc_attr( $f( 'delay_minutes' ) ); ?>" value="<?php echo esc_attr( (string) $s['delay_minutes'] ); ?>" /><p class="description"><?php esc_html_e( 'Search engines see the article first; scrapers that copy your feed get it later. 0 = no delay.', 'publishrank' ); ?></p></div>
			</section>

			<section class="pr21-card">
				<h2><span class="fa-solid fa-bolt" aria-hidden="true"></span> <?php esc_html_e( 'Instant delivery and formats', 'publishrank' ); ?></h2>
				<label class="pr21-check"><input type="checkbox" name="<?php echo esc_attr( $f( 'websub' ) ); ?>" value="1" <?php checked( $s['websub'] ); ?> /> <?php esc_html_e( 'WebSub: notify feed readers and aggregators the moment an article is published or updated', 'publishrank' ); ?></label>
				<div class="pr21-row"><label for="pr21-hub"><?php esc_html_e( 'WebSub hub', 'publishrank' ); ?></label><input id="pr21-hub" class="regular-text" type="url" name="<?php echo esc_attr( $f( 'hub_url' ) ); ?>" value="<?php echo esc_attr( $s['hub_url'] ); ?>" placeholder="<?php echo esc_attr( \PublishRankSEO\Feeds\FeedEnhancer::DEFAULT_HUB ); ?>" />
				<?php
				$last = get_option( 'publishrank_websub_last' );
				if ( is_array( $last ) && ! empty( $last['results'] ) ) {
					$ok = count( array_filter( $last['results'], static function ( $c ) { return is_int( $c ) && $c >= 200 && $c < 300; } ) );
					/* translators: 1: time ago 2: ok count 3: total */
					echo '<p class="description">' . esc_html( sprintf( __( 'Last notification %1$s ago: %2$d of %3$d feeds accepted by the hub.', 'publishrank' ), human_time_diff( (int) $last['time'] ), $ok, count( $last['results'] ) ) ) . '</p>';
				}
				?>
				</div>
				<label class="pr21-check"><input type="checkbox" name="<?php echo esc_attr( $f( 'json_feed' ) ); ?>" value="1" <?php checked( $s['json_feed'] ); ?> /> <?php echo esc_html( sprintf( /* translators: %s: URL */ __( 'Publish a JSON Feed 1.1 at %s', 'publishrank' ), get_feed_link( \PublishRankSEO\Feeds\FeedEnhancer::JSON_FEED ) ) ); ?></label>
				<label class="pr21-check"><input type="checkbox" name="<?php echo esc_attr( $f( 'utm' ) ); ?>" value="1" <?php checked( $s['utm'] ); ?> /> <?php esc_html_e( 'Tag item links for analytics (utm_source=rss, utm_medium=feed); item IDs stay unchanged', 'publishrank' ); ?></label>
				<div class="pr21-row"><label for="pr21-utm"><?php esc_html_e( 'Campaign name', 'publishrank' ); ?></label><input id="pr21-utm" type="text" name="<?php echo esc_attr( $f( 'utm_campaign' ) ); ?>" value="<?php echo esc_attr( $s['utm_campaign'] ); ?>" placeholder="feed" /></div>
			</section>

			<section class="pr21-card">
				<h2><span class="fa-solid fa-newspaper" aria-hidden="true"></span> <?php esc_html_e( 'News feed, discovery and caching', 'publishrank' ); ?></h2>
				<label class="pr21-check"><input type="checkbox" name="<?php echo esc_attr( $f( 'news_feed' ) ); ?>" value="1" <?php checked( $s['news_feed'] ); ?> /> <?php echo esc_html( sprintf( /* translators: %s: feed URL. */ __( 'Publish a news feed of recent articles at %s', 'publishrank' ), FeedManager::news_feed_url() ) ); ?></label>
				<div class="pr21-row"><label for="pr21-hours"><?php esc_html_e( 'News window (hours)', 'publishrank' ); ?></label><input id="pr21-hours" type="number" min="1" max="168" name="<?php echo esc_attr( $f( 'news_hours' ) ); ?>" value="<?php echo esc_attr( (string) $s['news_hours'] ); ?>" /><p class="description"><?php esc_html_e( 'Uses the News sitemap post types. Password-protected and noindex articles are left out.', 'publishrank' ); ?></p></div>
				<label class="pr21-check"><input type="checkbox" name="<?php echo esc_attr( $f( 'discovery' ) ); ?>" value="1" <?php checked( $s['discovery'] ); ?> /> <?php esc_html_e( 'Print feed discovery links when the theme does not (respects Crawl optimization removals)', 'publishrank' ); ?></label>
				<p class="description"><?php echo esc_html( current_theme_supports( 'automatic-feed-links' ) ? __( 'Your theme already prints discovery links, so PublishRank does not add duplicates.', 'publishrank' ) : __( 'Your theme does not print discovery links.', 'publishrank' ) ); ?></p>
				<div class="pr21-row"><label for="pr21-cache"><?php esc_html_e( 'Cache-Control max-age (seconds)', 'publishrank' ); ?></label><input id="pr21-cache" type="number" min="0" max="86400" name="<?php echo esc_attr( $f( 'cache_max_age' ) ); ?>" value="<?php echo esc_attr( (string) $s['cache_max_age'] ); ?>" /><p class="description"><?php esc_html_e( '0 = off. WordPress already answers feed readers with ETag and Last-Modified, which change as soon as you publish or edit a post. Signed-in users never get a shared-cache header.', 'publishrank' ); ?></p></div>
			</section>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save feed settings', 'publishrank' ); ?></button></p>
		</form>

		<aside class="pr21-side">
			<section class="pr21-card">
				<h2><span class="fa-solid fa-stethoscope" aria-hidden="true"></span> <?php esc_html_e( 'Feed endpoints', 'publishrank' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Validate fetches the feed from your server like a feed reader would, then checks the XML, dates and required elements.', 'publishrank' ); ?></p>
				<table class="widefat striped pr21-table"><tbody>
				<?php
				$group = '';
				foreach ( $endpoints as $key => $ep ) {
					if ( $ep['group'] !== $group ) {
						$group = $ep['group'];
						echo '<tr class="pr21-group"><th colspan="2">' . esc_html( $group ) . '</th></tr>';
					}
					$check = wp_nonce_url( add_query_arg( array( 'page' => self::SLUG, 'feed' => $key ), admin_url( 'admin.php' ) ), self::CHECK );
					echo '<tr' . ( $selected === $key ? ' class="is-current"' : '' ) . '><td><strong>' . esc_html( $ep['label'] ) . '</strong><br /><a href="' . esc_url( $ep['url'] ) . '" target="_blank" rel="noopener"><code>' . esc_html( wp_make_link_relative( $ep['url'] ) ) . '</code></a></td><td class="pr21-actions"><a class="button button-small" href="' . esc_url( $check ) . '#pr21-report">' . esc_html__( 'Preview & validate', 'publishrank' ) . '</a></td></tr>';
				}
				?>
				</tbody></table>
			</section>
		</aside>
		</div>
		<?php
		if ( $report ) {
			$this->render_report( $endpoints[ $selected ], $report );
		}
		echo '</div>';
	}

	/** Server-rendered example of the lines added to an item, using the latest post and the saved settings. */
	private function render_item_preview() {
		$latest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'suppress_filters' => true ) );
		if ( ! $latest ) {
			return;
		}
		$post = $latest[0];
		$s    = FeedManager::settings();
		$out  = array();
		$author = (string) get_the_author_meta( 'display_name', (int) $post->post_author );
		if ( $s['append_author'] && '' !== $author ) {
			$out[] = sprintf( /* translators: %s: author name. */ __( 'Written by %s.', 'publishrank' ), $author );
		}
		if ( $s['append_publisher'] ) {
			$out[] = sprintf( /* translators: %s: publication name. */ __( 'Published by %s.', 'publishrank' ), FeedManager::publisher_name() );
		}
		if ( $s['append_categories'] ) {
			$out[] = sprintf( /* translators: %s: categories. */ __( 'Filed under: %s', 'publishrank' ), implode( ', ', wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ) ) );
		}
		if ( '' !== $s['custom_text'] ) {
			$out[] = FeedManager::replace_vars( $s['custom_text'], $post );
		}
		if ( $s['append_copyright'] ) {
			$out[] = FeedManager::copyright_text();
		}
		echo '<div class="pr21-preview"><span class="pr21-kicker">' . esc_html__( 'Preview with your latest post', 'publishrank' ) . '</span><strong>' . esc_html( get_the_title( $post ) ) . '</strong>';
		echo $out ? '<p>' . implode( '<br />', array_map( 'esc_html', $out ) ) . '</p>' : '<p class="description">' . esc_html__( 'No extra lines are added.', 'publishrank' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped per line.
		echo '</div>';
	}

	private function render_report( array $ep, array $r ) {
		echo '<section id="pr21-report" class="pr21-card pr21-report">';
		echo '<h2><span class="fa-solid fa-square-rss" aria-hidden="true"></span> ' . esc_html( sprintf( /* translators: %s: feed label. */ __( 'Feed report: %s', 'publishrank' ), $ep['label'] ) ) . '</h2>';
		echo '<p class="pr21-badges"><span class="pr21-badge ' . ( $r['ok'] ? 'is-good' : 'is-bad' ) . '">' . esc_html( $r['ok'] ? __( 'Valid feed', 'publishrank' ) : __( 'Problems found', 'publishrank' ) ) . '</span>';
		echo '<span class="pr21-badge">HTTP ' . esc_html( (string) $r['status'] ) . '</span>';
		if ( '' !== $r['format'] ) {
			echo '<span class="pr21-badge">' . esc_html( $r['format'] ) . '</span>';
		}
		echo '<span class="pr21-badge">' . esc_html( $r['type'] ) . '</span><span class="pr21-badge">' . esc_html( sprintf( /* translators: %d: milliseconds. */ __( '%d ms', 'publishrank' ), $r['ms'] ) ) . '</span>';
		echo '<span class="pr21-badge">' . esc_html( sprintf( /* translators: %d: number of items. */ _n( '%d item', '%d items', count( $r['items'] ), 'publishrank' ), count( $r['items'] ) ) ) . '</span></p>';
		foreach ( array( 'errors' => 'is-bad', 'warnings' => 'is-warn' ) as $k => $cls ) {
			if ( $r[ $k ] ) {
				echo '<ul class="pr21-issues ' . esc_attr( $cls ) . '">';
				foreach ( $r[ $k ] as $msg ) {
					echo '<li>' . esc_html( $msg ) . '</li>';
				}
				echo '</ul>';
			}
		}
		if ( $r['channel'] ) {
			echo '<div class="pr21-reader"><div class="pr21-reader__head"><strong>' . esc_html( $r['channel']['title'] ) . '</strong><span>' . esc_html( $r['channel']['description'] ) . '</span>';
			if ( '' !== $r['channel']['copyright'] ) {
				echo '<small>' . esc_html( $r['channel']['copyright'] ) . '</small>';
			}
			echo '</div>';
			foreach ( array_slice( $r['items'], 0, 6 ) as $item ) {
				echo '<article class="pr21-reader__item">';
				if ( '' !== $item['image'] ) {
					echo '<img src="' . esc_url( $item['image'] ) . '" alt="" loading="lazy" />';
				}
				echo '<div><a href="' . esc_url( $item['link'] ) . '" target="_blank" rel="noopener"><strong>' . esc_html( $item['title'] ) . '</strong></a><small>' . esc_html( trim( $item['date'] . ( '' !== $item['author'] ? ' · ' . $item['author'] : '' ) ) ) . '</small><p>' . esc_html( $item['summary'] ) . '</p></div></article>';
			}
			echo '</div>';
		}
		if ( '' !== $r['raw'] ) {
			echo '<details class="pr21-raw"><summary>' . esc_html__( 'Show raw XML (first 20 KB)', 'publishrank' ) . '</summary><pre>' . esc_html( $r['raw'] ) . '</pre></details>';
		}
		echo '</section>';
	}
}
