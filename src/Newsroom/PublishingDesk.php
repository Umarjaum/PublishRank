<?php
/**
 * Publishing Desk (2.25): news publishing intelligence for the Newsroom.
 *
 * - Duplicate headline detection while writing (block editor and Classic Editor), against recent stories.
 * - Missed-schedule detection and automatic publishing (WordPress's "Missed schedule" problem).
 * - Content freshness label in the posts list.
 * - News publishing diagnostics: Google News sitemap, publisher data, article quality in the news window,
 *   duplicate headlines, scheduling, cron health and publishing velocity.
 *
 * Large-site safe: every query is bounded by a date window and a row limit and uses the
 * (post_type, post_status, post_date) index; the headline list is cached and refreshed on publish.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Newsroom;

use PublishRankSEO\Admin\Suite;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class PublishingDesk {
	const SLUG      = 'publishrank-publishing-desk';
	const TITLES    = 'publishrank_desk_titles';
	const LOCK      = 'publishrank_desk_missed_check';
	const OPT_FIX   = 'publishrank_desk_fix_missed';
	const OPT_DAYS  = 'publishrank_desk_duplicate_days';
	const MAX_TITLE = 110;

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'forget_titles' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'maybe_fix_missed' ), 20 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 13 );
			add_action( 'admin_enqueue_scripts', array( $this, 'editor_assets' ) );
			add_action(
				'admin_enqueue_scripts',
				static function () {
					Suite::enqueue_for( self::SLUG );
				}
			);
			add_action( 'admin_post_publishrank_desk_publish_missed', array( $this, 'handle_publish_missed' ) );
			add_action( 'admin_post_publishrank_desk_settings', array( $this, 'handle_settings' ) );
			add_action( 'admin_init', array( $this, 'columns' ) );
		}
	}

	/** Post types treated as news. */
	public static function types() {
		$raw   = preg_split( '/[\s,]+/', (string) get_option( 'publishrank_seo_news_post_types', 'post' ), -1, PREG_SPLIT_NO_EMPTY );
		$types = array();
		foreach ( array_merge( array( 'post' ), (array) $raw ) as $t ) {
			$t = sanitize_key( $t );
			if ( post_type_exists( $t ) ) {
				$types[] = $t;
			}
		}
		return array_values( array_unique( $types ) );
	}

	/* ---------------- Duplicate headlines ---------------- */

	/** Normalised word set of a headline. @return string[] */
	public static function tokens( $title ) {
		static $stop = null;
		if ( null === $stop ) {
			$stop = array_flip( explode( ' ', 'a an and are as at be by for from has have in into is it its of on or over says say that the their this to up was were will with after before new news live update updates' ) );
		}
		$t   = strtolower( wp_strip_all_tags( html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) ) );
		$t   = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $t );
		$out = array();
		foreach ( preg_split( '/\s+/u', (string) $t, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( isset( $stop[ $w ] ) || ( function_exists( 'mb_strlen' ) ? mb_strlen( $w ) : strlen( $w ) ) < 2 ) {
				continue;
			}
			if ( strlen( $w ) > 4 && 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) {
				$w = substr( $w, 0, -1 ); // crude plural folding: "talks" ~ "talk".
			}
			$out[ $w ] = true;
		}
		return array_keys( $out );
	}

	/** Similarity 0..1 of two token sets (Jaccard, with a containment bonus for short rewrites). */
	public static function similarity( array $a, array $b ) {
		if ( ! $a || ! $b ) {
			return 0.0;
		}
		$shared = count( array_intersect( $a, $b ) );
		if ( ! $shared ) {
			return 0.0;
		}
		$jaccard  = $shared / count( array_unique( array_merge( $a, $b ) ) );
		$contains = $shared / min( count( $a ), count( $b ) );
		return round( max( $jaccard, $shared >= 3 ? $contains * 0.85 : 0 ), 3 );
	}

	/** Recent headlines (cached, with pre-computed word lists). @return array<int,array{0:int,1:string,2:string,3:string,4:string}> id, title, status, date, words */
	public static function recent_titles() {
		$cached = get_transient( self::TITLES );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$types = self::types();
		$days  = max( 1, min( 365, (int) get_option( self::OPT_DAYS, 30 ) ) );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql   = "SELECT ID, post_title, post_status, post_date FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status IN ('publish','future','pending','draft') AND post_date >= %s AND post_title <> '' ORDER BY post_date DESC LIMIT 5000"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $types, array( gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ) ) ) ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$list  = array();
		foreach ( (array) $rows as $r ) {
			$list[] = array( (int) $r[0], (string) $r[1], (string) $r[2], (string) $r[3], implode( ' ', self::tokens( $r[1] ) ) );
		}
		set_transient( self::TITLES, $list, 10 * MINUTE_IN_SECONDS );
		return $list;
	}

	public static function forget_titles( $new, $old, $post ) {
		if ( $new !== $old && $post instanceof \WP_Post && in_array( $post->post_type, self::types(), true ) ) {
			delete_transient( self::TITLES );
		}
	}

	/** Recent stories with a similar headline. @return array<int,array<string,mixed>> */
	public static function duplicates( $title, $exclude = 0, $limit = 5 ) {
		$mine = self::tokens( $title );
		if ( count( $mine ) < 2 ) {
			return array();
		}
		$norm = implode( ' ', $mine );
		$set  = array_flip( $mine );
		$hits = array();
		foreach ( self::recent_titles() as $row ) {
			if ( (int) $row[0] === (int) $exclude ) {
				continue;
			}
			$words = isset( $row[4] ) ? (string) $row[4] : implode( ' ', self::tokens( $row[1] ) );
			if ( $words === $norm ) {
				$score = 1.0;
			} else {
				$theirs = '' === $words ? array() : explode( ' ', $words );
				// Fast reject: fewer than two shared words can never reach the threshold.
				if ( count( array_intersect_key( $set, array_flip( $theirs ) ) ) < 2 ) {
					continue;
				}
				$score = self::similarity( $mine, $theirs );
			}
			if ( $score >= 0.6 ) {
				$hits[] = array( $row, $score );
			}
		}
		usort(
			$hits,
			static function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);
		$out = array();
		foreach ( array_slice( $hits, 0, $limit ) as $h ) {
			// Links are built only for the matches actually returned (permalinks cost a lookup each).
			$row   = $h[0];
			$out[] = array(
				'id'     => $row[0],
				'title'  => html_entity_decode( $row[1], ENT_QUOTES, 'UTF-8' ),
				'status' => $row[2],
				'date'   => $row[3],
				'score'  => $h[1],
				'edit'   => current_user_can( 'edit_post', $row[0] ) ? get_edit_post_link( $row[0], 'raw' ) : '',
				'link'   => 'publish' === $row[2] ? get_permalink( $row[0] ) : '',
			);
		}
		return $out;
	}

	public function routes() {
		register_rest_route(
			'publishrank/v1',
			'/desk/duplicates',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'title'   => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'exclude' => array( 'type' => 'integer', 'default' => 0 ),
				),
				'callback'            => static function ( \WP_REST_Request $r ) {
					return rest_ensure_response( self::duplicates( (string) $r['title'], (int) $r['exclude'] ) );
				},
			)
		);
	}

	/** Live duplicate-headline warning in the block editor and the Classic Editor. */
	public function editor_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::types(), true ) ) {
			return;
		}
		$block = method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
		// Own handle so the code runs after the editor's data stores exist.
		wp_register_script( 'publishrank-desk', false, $block ? array( 'wp-api-fetch', 'wp-data', 'wp-notices', 'wp-editor', 'wp-dom-ready' ) : array( 'wp-api-fetch' ), PUBLISHRANK_SEO_VERSION, true );
		wp_enqueue_script( 'publishrank-desk' );
		$cfg = array(
			'path'  => '/publishrank/v1/desk/duplicates',
			'block' => $block,
			'i18n'  => array(
				/* translators: %d: number of stories */
				'found' => __( 'Possible duplicate headline: %d recent stories look similar.', 'publishrank' ),
				'open'  => __( 'Open', 'publishrank' ),
			),
		);
		$js = <<<'JS'
(function(){var c=window.PRDesk,last='',timer=null;if(!c||!window.wp||!wp.apiFetch)return;
function query(title,id,done){if(!title||title.length<12){done([]);return;}wp.apiFetch({path:c.path+'?title='+encodeURIComponent(title)+'&exclude='+(id||0)}).then(done).catch(function(){done([]);});}
function text(list){return c.i18n.found.replace('%d',list.length);}
if(c.block&&wp.data){var sel=wp.data.select('core/editor'),notices=wp.data.dispatch('core/notices');
wp.data.subscribe(function(){var t=sel.getEditedPostAttribute('title')||'';if(t===last)return;last=t;clearTimeout(timer);timer=setTimeout(function(){query(t,sel.getCurrentPostId(),function(list){notices.removeNotice('publishrank-duplicate');if(!list.length)return;
notices.createWarningNotice(text(list)+' '+list.map(function(h){return '“'+h.title+'” ('+h.status+', '+Math.round(h.score*100)+'%)';}).join(' · '),{id:'publishrank-duplicate',isDismissible:true,actions:list.filter(function(h){return h.edit;}).slice(0,3).map(function(h){return {label:c.i18n.open+': '+h.title.slice(0,40),url:h.edit};})});});},900);});return;}
var input=document.getElementById('title'),idf=document.getElementById('post_ID');if(!input)return;
function run(){var t=input.value;if(t===last)return;last=t;query(t,idf?idf.value:0,function(list){var box=document.getElementById('publishrank-duplicate');if(box)box.remove();if(!list.length)return;box=document.createElement('div');box.id='publishrank-duplicate';box.className='notice notice-warning inline';box.setAttribute('role','status');
var p=document.createElement('p'),s=document.createElement('strong');s.textContent=text(list);p.appendChild(s);box.appendChild(p);var ul=document.createElement('ul');list.forEach(function(h){var li=document.createElement('li'),a=document.createElement(h.edit?'a':'span');a.textContent='“'+h.title+'”';if(h.edit){a.href=h.edit;a.target='_blank';a.rel='noopener';}li.appendChild(a);li.appendChild(document.createTextNode(' — '+h.status+', '+Math.round(h.score*100)+'%'));ul.appendChild(li);});box.appendChild(ul);
var anchor=document.getElementById('titlediv');if(anchor)anchor.parentNode.insertBefore(box,anchor.nextSibling);});}
input.addEventListener('input',function(){clearTimeout(timer);timer=setTimeout(run,900);});input.addEventListener('blur',run);})();
JS;
		wp_add_inline_script( 'publishrank-desk', 'window.PRDesk=' . wp_json_encode( $cfg ) . ';' . ( $block ? 'wp.domReady(function(){' . $js . '});' : $js ) );
	}

	/* ---------------- Missed schedules ---------------- */

	/** Scheduled posts whose time has passed. @return int[] */
	public static function missed( $limit = 50 ) {
		global $wpdb;
		$types = get_post_types( array( 'public' => true ) );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql   = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status = 'future' AND post_date_gmt <= %s ORDER BY post_date_gmt ASC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array_values( $types ), array( gmdate( 'Y-m-d H:i:s', time() - 60 ), (int) $limit ) ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/** At most once a minute: publish stories WP-Cron failed to publish on time. */
	public static function maybe_fix_missed() {
		if ( ! get_option( self::OPT_FIX, 1 ) || false !== get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, MINUTE_IN_SECONDS );
		foreach ( self::missed( 20 ) as $id ) {
			wp_publish_post( $id );
			update_post_meta( $id, '_publishrank_missed_schedule_fixed', time() );
		}
	}

	public function handle_publish_missed() {
		Suite::guard( 'publishrank_desk_publish_missed', 'view_reports' );
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to publish.', 'publishrank' ), '', array( 'response' => 403 ) );
		}
		$n = 0;
		foreach ( self::missed( 200 ) as $id ) {
			if ( current_user_can( 'publish_post', $id ) || current_user_can( 'edit_post', $id ) ) {
				wp_publish_post( $id );
				++$n;
			}
		}
		/* translators: %d: number of stories */
		Suite::done( self::SLUG, 'success', sprintf( _n( '%d missed story published.', '%d missed stories published.', $n, 'publishrank' ), $n ) );
	}

	public function handle_settings() {
		Suite::guard( 'publishrank_desk_settings', 'manage_settings' );
		update_option( self::OPT_FIX, empty( $_POST['fix_missed'] ) ? 0 : 1, true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		update_option( self::OPT_DAYS, isset( $_POST['dup_days'] ) ? max( 1, min( 365, absint( $_POST['dup_days'] ) ) ) : 30, false ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		delete_transient( self::TITLES );
		Suite::done( self::SLUG, 'success', __( 'Publishing Desk settings saved.', 'publishrank' ) );
	}

	/* ---------------- Freshness ---------------- */

	public static function freshness( $modified_gmt ) {
		$age = ( time() - strtotime( $modified_gmt . ' UTC' ) ) / DAY_IN_SECONDS;
		if ( $age <= 30 ) {
			return array( 'fresh', __( 'Fresh', 'publishrank' ) );
		}
		if ( $age <= 365 ) {
			return array( 'ageing', __( 'Ageing', 'publishrank' ) );
		}
		return array( 'stale', __( 'Stale', 'publishrank' ) );
	}

	public function columns() {
		foreach ( self::types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$type}_sortable_columns", array( $this, 'sortable' ) );
		}
		add_action(
			'admin_head-edit.php',
			static function () {
				echo '<style>.column-pr_fresh{width:96px}.pr-fresh{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;line-height:18px}.pr-fresh--fresh{background:#e6f4ea;color:#1e6b34}.pr-fresh--ageing{background:#fff4e0;color:#8a5300}.pr-fresh--stale{background:#fde8e8;color:#9b1c1c}</style>';
			}
		);
	}

	public function add_column( $cols ) {
		$cols['pr_fresh'] = __( 'Freshness', 'publishrank' );
		return $cols;
	}

	public function sortable( $cols ) {
		$cols['pr_fresh'] = 'modified';
		return $cols;
	}

	public function render_column( $col, $post_id ) {
		if ( 'pr_fresh' !== $col ) {
			return;
		}
		$p = get_post( $post_id );
		if ( ! $p || 'publish' !== $p->post_status ) {
			echo '—';
			return;
		}
		list( $key, $label ) = self::freshness( $p->post_modified_gmt );
		/* translators: %s: human time difference */
		echo '<span class="pr-fresh pr-fresh--' . esc_attr( $key ) . '" title="' . esc_attr( sprintf( __( 'Updated %s ago', 'publishrank' ), human_time_diff( strtotime( $p->post_modified_gmt . ' UTC' ) ) ) ) . '">' . esc_html( $label ) . '</span>';
	}

	/* ---------------- Diagnostics ---------------- */

	/** @return array<string,mixed> */
	public static function diagnostics() {
		global $wpdb;
		$types  = self::types();
		$in     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$hours  = max( 1, min( 168, (int) get_option( 'publishrank_seo_news_window', 48 ) ) );
		$since  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $hours * HOUR_IN_SECONDS );
		$sql    = "SELECT ID, post_title, post_author FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status = 'publish' AND post_date >= %s ORDER BY post_date DESC LIMIT 500"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $types, array( $since ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$ids    = wp_list_pluck( $recent, 'ID' );
		if ( $ids ) {
			update_meta_cache( 'post', $ids ); // One query for all articles' meta.
			update_object_term_cache( $ids, 'post' );
		}
		$issues = array( 'no_image' => array(), 'small_image' => array(), 'long_title' => array(), 'no_description' => array(), 'noindex' => array(), 'uncategorised' => array() );
		$thumbs = array();
		foreach ( $recent as $r ) {
			$thumb = (int) get_post_meta( $r->ID, '_thumbnail_id', true );
			if ( ! $thumb ) {
				$issues['no_image'][] = $r;
			} else {
				$thumbs[ $r->ID ] = $thumb;
			}
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $r->post_title ) : strlen( $r->post_title ) ) > self::MAX_TITLE ) {
				$issues['long_title'][] = $r;
			}
			if ( '' === trim( (string) get_post_meta( $r->ID, PostMeta::DESCRIPTION, true ) ) && '' === trim( (string) get_post_field( 'post_excerpt', $r->ID ) ) ) {
				$issues['no_description'][] = $r;
			}
			if ( class_exists( '\PublishRankSEO\Sitemap\News\NewsSitemapProvider' ) && \PublishRankSEO\Sitemap\News\NewsSitemapProvider::is_noindex( $r->ID ) ) {
				$issues['noindex'][] = $r;
			}
			$cats = 'post' === get_post_type( $r->ID ) ? wp_get_post_categories( $r->ID ) : array();
			if ( 'post' === get_post_type( $r->ID ) && ( ! $cats || array( (int) get_option( 'default_category' ) ) === array_map( 'intval', $cats ) ) ) {
				$issues['uncategorised'][] = $r;
			}
		}
		if ( $thumbs ) {
			update_meta_cache( 'post', array_values( $thumbs ) );
			foreach ( $thumbs as $pid => $att ) {
				$m = wp_get_attachment_metadata( $att );
				if ( is_array( $m ) && ! empty( $m['width'] ) && (int) $m['width'] < 1200 ) {
					$issues['small_image'][] = (object) array( 'ID' => $pid, 'post_title' => get_the_title( $pid ) );
				}
			}
		}
		// Duplicate headlines among the last 7 days (bounded pairwise comparison).
		$week  = array_slice(
			array_filter(
				self::recent_titles(),
				static function ( $r ) {
					return 'publish' === $r[2] && strtotime( $r[3] ) >= current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS;
				}
			),
			0,
			400
		);
		$tok   = array();
		foreach ( $week as $r ) {
			$tok[ $r[0] ] = isset( $r[4] ) ? ( '' === $r[4] ? array() : explode( ' ', $r[4] ) ) : self::tokens( $r[1] );
		}
		$pairs = array();
		$keys  = array_keys( $tok );
		for ( $i = 0, $n = count( $keys ); $i < $n && count( $pairs ) < 25; $i++ ) {
			for ( $j = $i + 1; $j < $n && count( $pairs ) < 25; $j++ ) {
				$s = self::similarity( $tok[ $keys[ $i ] ], $tok[ $keys[ $j ] ] );
				if ( $s >= 0.6 ) {
					$pairs[] = array( $keys[ $i ], $keys[ $j ], $s );
				}
			}
		}
		// Scheduling and cron.
		$cron    = _get_cron_array();
		$overdue = 0;
		foreach ( is_array( $cron ) ? $cron : array() as $ts => $hooks ) {
			if ( (int) $ts < time() - 10 * MINUTE_IN_SECONDS ) {
				$overdue += count( $hooks );
			}
		}
		$upcoming_sql = "SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status = 'future' ORDER BY post_date ASC LIMIT 10"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$upcoming     = (array) $wpdb->get_results( $wpdb->prepare( $upcoming_sql, $types ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		// Velocity: articles per day for 7 days (one grouped query on the indexed date range).
		$vel_sql  = "SELECT DATE(post_date) d, COUNT(*) n FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status = 'publish' AND post_date >= %s GROUP BY DATE(post_date)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$vel_rows = (array) $wpdb->get_results( $wpdb->prepare( $vel_sql, array_merge( $types, array( gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - 6 * DAY_IN_SECONDS ) ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$velocity = array();
		for ( $d = 6; $d >= 0; $d-- ) {
			$velocity[ gmdate( 'Y-m-d', current_time( 'timestamp' ) - $d * DAY_IN_SECONDS ) ] = 0;
		}
		foreach ( $vel_rows as $v ) {
			if ( isset( $velocity[ $v->d ] ) ) {
				$velocity[ $v->d ] = (int) $v->n;
			}
		}
		$news = class_exists( '\PublishRankSEO\Sitemap\News\NewsSitemapProvider' ) ? ( new \PublishRankSEO\Sitemap\News\NewsSitemapProvider() )->diagnostics() : array();
		return array(
			'window_hours' => $hours,
			'recent'       => count( $recent ),
			'issues'       => $issues,
			'duplicates'   => $pairs,
			'missed'       => self::missed( 200 ),
			'upcoming'     => $upcoming,
			'cron_off'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'cron_overdue' => $overdue,
			'velocity'     => $velocity,
			'news'         => $news,
			'logo'         => (string) get_option( 'publishrank_identity_logo', '' ),
		);
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Publishing Desk', 'publishrank' ), __( 'Publishing Desk', 'publishrank' ), Capabilities::cap( 'view_reports' ), self::SLUG, array( $this, 'render' ) );
	}

	private static function list_posts( array $rows, $max = 8 ) {
		if ( ! $rows ) {
			return '';
		}
		/* translators: %d: number of articles */
		$out = '<details><summary>' . esc_html( sprintf( _n( 'Show %d article', 'Show %d articles', count( $rows ), 'publishrank' ), count( $rows ) ) ) . '</summary><ul class="prx-list">';
		foreach ( array_slice( $rows, 0, $max ) as $r ) {
			$link = get_edit_post_link( $r->ID );
			$out .= '<li>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( get_the_title( $r->ID ) ) . '</a>' : esc_html( get_the_title( $r->ID ) ) ) . '</li>';
		}
		if ( count( $rows ) > $max ) {
			/* translators: %d: more items */
			$out .= '<li class="prx-help">' . esc_html( sprintf( __( '…and %d more', 'publishrank' ), count( $rows ) - $max ) ) . '</li>';
		}
		return $out . '</ul></details>';
	}

	public function render() {
		if ( ! Capabilities::can( 'view_reports' ) ) {
			return;
		}
		$t0 = microtime( true );
		$d  = self::diagnostics();
		$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		Suite::open( __( 'Publishing Desk', 'publishrank' ), __( 'News publishing diagnostics, duplicate headlines, scheduling and freshness.', 'publishrank' ) );
		$n = $d['news'];

		$checks = array();
		$add    = static function ( $ok, $label, $detail, $fix = '', $warn = false ) use ( &$checks ) {
			$checks[] = array( $ok ? 'ok' : ( $warn ? 'warn' : 'bad' ), $label, $detail, $fix );
		};
		$add( ! empty( $n['enabled'] ), __( 'Google News sitemap is on', 'publishrank' ), $n ? sprintf( /* translators: 1: count, 2: hours */ __( '%1$d articles in the %2$d-hour window.', 'publishrank' ), (int) $n['eligible'], (int) $n['window_hours'] ) : '', __( 'Turn on the News sitemap in Sitemap Hub.', 'publishrank' ) );
		$add( ! empty( $n['publication'] ), __( 'Publication name is set', 'publishrank' ), $n ? (string) $n['publication'] . ' · ' . (string) $n['language'] : '', __( 'Set the publication name in Complete Setup.', 'publishrank' ) );
		$add( '' !== $d['logo'], __( 'Publisher logo is set (Article / NewsArticle schema)', 'publishrank' ), '' !== $d['logo'] ? __( 'Logo is set and synced with the WordPress Site Logo.', 'publishrank' ) : '', __( 'Add a logo in Complete Setup → Identity.', 'publishrank' ) );
		$add( ! $d['missed'], __( 'No missed schedules', 'publishrank' ), $d['missed'] ? sprintf( /* translators: %d: count */ __( '%d scheduled stories are past their publish time.', 'publishrank' ), count( $d['missed'] ) ) : __( 'Every scheduled story published on time.', 'publishrank' ), __( 'Publish them below; turn on automatic fixing.', 'publishrank' ) );
		$add( ! $d['cron_off'] || $d['cron_overdue'] < 5, __( 'Scheduler (WP-Cron) is healthy', 'publishrank' ), sprintf( /* translators: %d: count */ __( '%d overdue scheduled tasks.', 'publishrank' ), $d['cron_overdue'] ) . ( $d['cron_off'] ? ' ' . __( 'DISABLE_WP_CRON is set: make sure a server cron calls wp-cron.php every minute.', 'publishrank' ) : '' ), __( 'Add a real server cron job for wp-cron.php.', 'publishrank' ), true );
		$add( ! $d['duplicates'], __( 'No duplicate headlines this week', 'publishrank' ), sprintf( /* translators: %d: pairs */ __( '%d similar pairs found.', 'publishrank' ), count( $d['duplicates'] ) ), __( 'Merge or differentiate the stories listed below.', 'publishrank' ), true );
		$labels = array(
			'no_image'       => array( __( 'Every recent article has a featured image', 'publishrank' ), __( 'Google News and Discover rarely show articles without an image.', 'publishrank' ), false ),
			'small_image'    => array( __( 'Featured images are at least 1200 px wide', 'publishrank' ), __( 'Discover uses large previews only for images 1200 px or wider.', 'publishrank' ), true ),
			'long_title'     => array( __( 'Headlines are 110 characters or shorter', 'publishrank' ), __( 'Long headlines are truncated in News and Top Stories.', 'publishrank' ), true ),
			'no_description' => array( __( 'Recent articles have a meta description or excerpt', 'publishrank' ), __( 'Write a 1–2 sentence summary for search results and social previews.', 'publishrank' ), true ),
			'noindex'        => array( __( 'No recent article is set to noindex', 'publishrank' ), __( 'Noindexed articles are excluded from News.', 'publishrank' ), false ),
			'uncategorised'  => array( __( 'Recent articles have a real category', 'publishrank' ), __( 'Assign a section so News can classify the story.', 'publishrank' ), true ),
		);
		foreach ( $labels as $key => $l ) {
			$rows = $d['issues'][ $key ];
			$add( ! $rows, $l[0], $rows ? sprintf( /* translators: 1: count, 2: total */ __( '%1$d of %2$d recent articles.', 'publishrank' ), count( $rows ), $d['recent'] ) . self::list_posts( $rows ) : sprintf( /* translators: %d: total */ __( 'All %d recent articles pass.', 'publishrank' ), $d['recent'] ), $l[1], $l[2] );
		}
		$ok = count(
			array_filter(
				$checks,
				static function ( $c ) {
					return 'ok' === $c[0];
				}
			)
		);

		echo '<div class="prx-grid prx-grid--4">';
		Suite::kpi( __( 'News readiness', 'publishrank' ), (int) round( 100 * $ok / max( 1, count( $checks ) ) ) . '%', sprintf( /* translators: 1: passed, 2: total */ __( '%1$d of %2$d checks pass', 'publishrank' ), $ok, count( $checks ) ), true );
		Suite::kpi( __( 'Published today', 'publishrank' ), number_format_i18n( (int) end( $d['velocity'] ) ) );
		Suite::kpi( __( 'Last 7 days', 'publishrank' ), number_format_i18n( array_sum( $d['velocity'] ) ) );
		Suite::kpi( __( 'Missed schedules', 'publishrank' ), number_format_i18n( count( $d['missed'] ) ), get_option( self::OPT_FIX, 1 ) ? __( 'Auto-fix on', 'publishrank' ) : __( 'Auto-fix off', 'publishrank' ) );
		echo '</div>';

		echo '<div class="prx-grid prx-grid--side"><div class="prx-stack">';
		/* translators: %d: hours */
		Suite::card_open( __( 'News publishing diagnostics', 'publishrank' ), 'fa-newspaper', sprintf( __( 'Articles published in the last %d hours (the Google News window).', 'publishrank' ), $d['window_hours'] ) );
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Check', 'publishrank' ) . '</th><th>' . esc_html__( 'Result', 'publishrank' ) . '</th><th>' . esc_html__( 'Details', 'publishrank' ) . '</th></tr></thead><tbody>';
		$names = array( 'ok' => __( 'Pass', 'publishrank' ), 'warn' => __( 'Review', 'publishrank' ), 'bad' => __( 'Fix', 'publishrank' ) );
		foreach ( $checks as $c ) {
			echo '<tr><td>' . esc_html( $c[1] ) . '</td><td>' . Suite::badge( $names[ $c[0] ], $c[0] ) . '</td><td class="prx-help">' . wp_kses_post( $c[2] ) . ( 'ok' !== $c[0] && $c[3] ? '<br><em>' . esc_html( $c[3] ) . '</em>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>';
		Suite::card_close();

		Suite::card_open( __( 'Duplicate headlines (last 7 days)', 'publishrank' ), 'fa-clone', __( 'Writers also see a live warning in the editor while typing a headline.', 'publishrank' ) );
		if ( ! $d['duplicates'] ) {
			echo '<div class="prx-empty"><span class="fa-solid fa-circle-check" aria-hidden="true"></span><strong>' . esc_html__( 'No similar headlines', 'publishrank' ) . '</strong></div>';
		} else {
			echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Story', 'publishrank' ) . '</th><th>' . esc_html__( 'Similar to', 'publishrank' ) . '</th><th>' . esc_html__( 'Match', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( $d['duplicates'] as $p ) {
				echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $p[0] ) ) . '">' . esc_html( get_the_title( $p[0] ) ) . '</a></td><td><a href="' . esc_url( (string) get_edit_post_link( $p[1] ) ) . '">' . esc_html( get_the_title( $p[1] ) ) . '</a></td><td>' . esc_html( (int) round( $p[2] * 100 ) . '%' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		Suite::card_close();
		echo '</div><div class="prx-stack">';

		Suite::card_open( __( 'Publishing velocity', 'publishrank' ), 'fa-chart-column' );
		$max = max( 1, max( $d['velocity'] ) );
		echo '<div class="prx-bars" role="img" aria-label="' . esc_attr__( 'Articles published per day, last 7 days', 'publishrank' ) . '" style="display:flex;align-items:flex-end;gap:6px;height:120px">';
		foreach ( $d['velocity'] as $day => $count ) {
			echo '<div style="flex:1;text-align:center"><div title="' . esc_attr( $day . ': ' . $count ) . '" style="background:var(--prx-accent,#8b1538);border-radius:4px 4px 0 0;height:' . esc_attr( (string) max( 2, (int) round( 100 * $count / $max ) ) ) . 'px"></div><small>' . esc_html( wp_date( 'D', strtotime( $day ) ) ) . '</small></div>';
		}
		echo '</div>';
		Suite::card_close();

		Suite::card_open( __( 'Story scheduling', 'publishrank' ), 'fa-calendar-check' );
		if ( $d['missed'] ) {
			Suite::form_open( 'publishrank_desk_publish_missed' );
			/* translators: %d: count */
			echo '<p><strong>' . esc_html( sprintf( __( '%d stories missed their scheduled time.', 'publishrank' ), count( $d['missed'] ) ) ) . '</strong></p><button class="prx-btn prx-btn--primary prx-btn--sm" type="submit">' . esc_html__( 'Publish missed stories now', 'publishrank' ) . '</button></form>';
		}
		if ( $d['upcoming'] ) {
			echo '<ul class="prx-list">';
			foreach ( $d['upcoming'] as $u ) {
				echo '<li><a href="' . esc_url( (string) get_edit_post_link( $u->ID ) ) . '">' . esc_html( get_the_title( $u->ID ) ) . '</a> <span class="prx-help">' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $u->post_date ) ) . '</span></li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="prx-help">' . esc_html__( 'No stories are scheduled.', 'publishrank' ) . '</p>';
		}
		Suite::card_close();

		if ( Capabilities::can( 'manage_settings' ) ) {
			Suite::card_open( __( 'Settings', 'publishrank' ), 'fa-sliders' );
			Suite::form_open( 'publishrank_desk_settings' );
			Suite::switch_field( 'fix_missed', (bool) get_option( self::OPT_FIX, 1 ), __( 'Automatically publish missed schedules', 'publishrank' ), __( 'Checked at most once a minute with one indexed query.', 'publishrank' ) );
			Suite::field( 'number', 'dup_days', (int) get_option( self::OPT_DAYS, 30 ), __( 'Compare headlines with stories from the last N days', 'publishrank' ), '', array( 'min' => 1, 'max' => 365 ) );
			echo '<div class="prx-card__foot"><button class="prx-btn prx-btn--primary prx-btn--sm" type="submit">' . esc_html__( 'Save', 'publishrank' ) . '</button></div></form>';
			Suite::card_close();
		}
		Suite::card_open( __( 'Content freshness', 'publishrank' ), 'fa-seedling', __( 'The posts list shows a Freshness label: Fresh (updated within 30 days), Ageing (within a year), Stale (older).', 'publishrank' ) );
		echo '<p><a class="prx-btn prx-btn--sm" href="' . esc_url( admin_url( 'edit.php?post_status=publish&orderby=modified&order=asc' ) ) . '">' . esc_html__( 'Show least recently updated first', 'publishrank' ) . '</a></p>';
		Suite::card_close();
		echo '</div></div>';
		/* translators: %d: milliseconds */
		echo '<p class="prx-help">' . esc_html( sprintf( __( 'Diagnostics computed in %d ms.', 'publishrank' ), $ms ) ) . '</p>';
		Suite::close();
	}
}
