<?php
/**
 * Cross-plugin SEO metadata portability.
 *
 * Reads third-party post meta without deleting or disabling the source plugin.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Meta\LanguageAlternates;
use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class SeoPortability {
	const IMPORT_ACTION = 'publishrank_seo_compat_import';
	const EXPORT_ACTION = 'publishrank_seo_compat_export';
	const SCAN_ACTION   = 'publishrank_seo_compat_scan';
	const ROLLBACK_ACTION = 'publishrank_seo_compat_rollback';
	const RESULT_KEY    = 'publishrank_seo_compat_result';
	const BACKUP_OPTION = 'publishrank_seo_compat_backups';

	/** @var array<string,array<string,mixed>> */
	private $adapters;

	public function __construct() {
		$this->adapters = array(
			'yoast' => array(
				'label' => 'Yoast SEO',
				'plugin' => 'wordpress-seo/wp-seo.php',
				'keys' => array(
					'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc',
					'focus_keyword' => '_yoast_wpseo_focuskw', 'canonical' => '_yoast_wpseo_canonical',
					'og_title' => '_yoast_wpseo_opengraph-title', 'og_description' => '_yoast_wpseo_opengraph-description',
					'og_image' => '_yoast_wpseo_opengraph-image', 'twitter_title' => '_yoast_wpseo_twitter-title',
					'twitter_description' => '_yoast_wpseo_twitter-description', 'robots' => '_yoast_wpseo_meta-robots-noindex',
					'robots_nofollow' => '_yoast_wpseo_meta-robots-nofollow', 'primary_category' => '_yoast_wpseo_primary_category', 'cornerstone' => '_yoast_wpseo_is_cornerstone',
				),
			),
			'rank_math' => array(
				'label' => 'Rank Math', 'plugin' => 'seo-by-rank-math/rank-math.php',
				'keys' => array(
					'title' => 'rank_math_title', 'description' => 'rank_math_description',
					'focus_keyword' => 'rank_math_focus_keyword', 'canonical' => 'rank_math_canonical_url',
					'og_title' => 'rank_math_facebook_title', 'og_description' => 'rank_math_facebook_description',
					'og_image' => 'rank_math_facebook_image', 'twitter_title' => 'rank_math_twitter_title',
					'twitter_description' => 'rank_math_twitter_description', 'robots' => 'rank_math_robots',
					'primary_category' => 'rank_math_primary_category', 'cornerstone' => 'rank_math_pillar_content',
				),
			),
			'aioseo' => array(
				'label' => 'All in One SEO', 'plugin' => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
				'keys' => array(
					'title' => '_aioseo_title', 'description' => '_aioseo_description', 'focus_keyword' => '_aioseo_keywords',
					'canonical' => '_aioseo_canonical_url', 'og_title' => '_aioseo_og_title',
					'og_description' => '_aioseo_og_description', 'og_image' => '_aioseo_og_image',
					'twitter_title' => '_aioseo_twitter_title', 'twitter_description' => '_aioseo_twitter_description',
				),
			),
			'seopress' => array(
				'label' => 'SEOPress', 'plugin' => 'wp-seopress/seopress.php',
				'keys' => array(
					'title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc',
					'focus_keyword' => '_seopress_analysis_target_kw', 'canonical' => '_seopress_robots_canonical',
					'og_title' => '_seopress_social_fb_title', 'og_description' => '_seopress_social_fb_desc',
					'og_image' => '_seopress_social_fb_img', 'twitter_title' => '_seopress_social_twitter_title',
					'twitter_description' => '_seopress_social_twitter_desc', 'robots' => '_seopress_robots_index', 'robots_nofollow' => '_seopress_robots_follow',
					'primary_category' => '_seopress_robots_primary_cat',
				),
			),
			'tsf' => array(
				'label' => 'The SEO Framework', 'plugin' => 'autodescription/autodescription.php',
				'keys' => array(
					'title' => '_genesis_title', 'description' => '_genesis_description',
					'canonical' => '_genesis_canonical_uri', 'robots' => '_genesis_noindex', 'robots_nofollow' => '_genesis_nofollow',
					'og_title' => '_open_graph_title', 'og_description' => '_open_graph_description', 'og_image' => '_social_image_url',
					'twitter_title' => '_twitter_title', 'twitter_description' => '_twitter_description',
				),
			),
		);
		foreach ( $this->adapters as $id => $adapter ) { $this->adapters[ $id ]['id'] = $id; }
	}

	/** Register actions and the compatibility admin page. */
	public function register() {
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'import' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'export' ) );
		add_action( 'admin_post_' . self::SCAN_ACTION, array( $this, 'scan' ) );
		add_action( 'admin_post_' . self::ROLLBACK_ACTION, array( $this, 'rollback' ) );
		add_action( 'admin_menu', array( $this, 'menu' ), 25 );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'SEO Migration & Portability', 'publishrank' ), __( 'Migration & Portability', 'publishrank' ), 'manage_options', 'publishrank-seo-portability', array( $this, 'page' ) );
	}

	/** @return array<string,array<string,mixed>> */
	public function adapters() { return $this->adapters; }

	/** @return array<string,bool> */
	public function detected() {
		$active = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', array() ) : array();
		if ( is_multisite() ) { $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ); }
		$found = array();
		foreach ( $this->adapters as $id => $adapter ) {
			$found[ $id ] = in_array( $adapter['plugin'], $active, true ) || ( function_exists( 'is_plugin_active' ) && is_plugin_active( $adapter['plugin'] ) );
		}
		return $found;
	}

	/** Render the dedicated migration screen. */
	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$detected = $this->detected();
		$result   = get_transient( self::RESULT_KEY . '_' . get_current_user_id() );
		if ( false !== $result ) { delete_transient( self::RESULT_KEY . '_' . get_current_user_id() ); }
		?>
			<div class="wrap publishrank-settings-page pr-migration-page">
				<div class="pr-migration-hero"><div><p class="pr-migration-kicker"><?php esc_html_e( 'Controlled SEO transfer', 'publishrank' ); ?></p><h1><?php esc_html_e( 'SEO Migration & Portability', 'publishrank' ); ?></h1><p><?php esc_html_e( 'Move post-level SEO data into PublishRank without deleting source data. Preview the impact, choose fields deliberately, and keep a rollback snapshot before production writes.', 'publishrank' ); ?></p></div><div class="pr-migration-hero__mark" aria-hidden="true"><span class="dashicons dashicons-migrate"></span></div></div>
				<?php if ( is_array( $result ) ) : ?><div class="notice notice-<?php echo ! empty( $result['error'] ) ? 'error' : 'success'; ?> pr-migration-notice"><p><?php echo esc_html( $result['message'] ); ?></p></div><?php endif; ?>
				<section class="pr-migration-section pr-migration-detected"><div class="pr-migration-section__head"><div><p class="pr-migration-kicker"><?php esc_html_e( 'Compatibility check', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Detected SEO plugins', 'publishrank' ); ?></h2></div><span class="pr-migration-section__hint"><?php esc_html_e( 'Source data stays untouched', 'publishrank' ); ?></span></div><div class="pr-migration-plugin-grid"><?php foreach ( $this->adapters as $id => $adapter ) : ?><div class="pr-migration-plugin <?php echo ! empty( $detected[ $id ] ) ? 'is-active' : 'is-muted'; ?>"><span class="dashicons <?php echo ! empty( $detected[ $id ] ) ? 'dashicons-yes-alt' : 'dashicons-minus'; ?>" aria-hidden="true"></span><div><strong><?php echo esc_html( $adapter['label'] ); ?></strong><small><?php echo ! empty( $detected[ $id ] ) ? esc_html__( 'Ready to import', 'publishrank' ) : esc_html__( 'Not detected', 'publishrank' ); ?></small></div></div><?php endforeach; ?></div></section>
				<section class="pr-migration-section"><div class="pr-migration-section__head"><div><p class="pr-migration-kicker"><?php esc_html_e( 'Step 1', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Import into PublishRank', 'publishrank' ); ?></h2><p><?php esc_html_e( 'Start with Dry run to see posts, fields, and unmatched records before anything is written.', 'publishrank' ); ?></p></div><span class="pr-migration-badge"><span class="dashicons dashicons-shield" aria-hidden="true"></span><?php esc_html_e( 'Rollback protected', 'publishrank' ); ?></span></div>
				<form class="pr-migration-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" /><?php wp_nonce_field( self::IMPORT_ACTION ); ?>
					<div class="pr-migration-fields"><label><span><?php esc_html_e( 'Source plugin', 'publishrank' ); ?></span><select name="source" required><option value="json"><?php esc_html_e( 'Normalized JSON upload', 'publishrank' ); ?></option><?php foreach ( $this->adapters as $id => $adapter ) : ?><option value="<?php echo esc_attr( $id ); ?>" <?php disabled( empty( $detected[ $id ] ) ); ?>><?php echo esc_html( $adapter['label'] ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Import policy', 'publishrank' ); ?></span><select name="mode"><option value="merge"><?php esc_html_e( 'Merge — keep existing values', 'publishrank' ); ?></option><option value="overwrite"><?php esc_html_e( 'Overwrite — replace matching values', 'publishrank' ); ?></option></select></label><label><span><?php esc_html_e( 'Content types', 'publishrank' ); ?></span><input type="text" name="post_types" value="post,page" class="regular-text" /><small><?php esc_html_e( 'Comma-separated public post types; leave blank for all supported types.', 'publishrank' ); ?></small></label></div>
					<fieldset class="pr-migration-fieldset"><legend><?php esc_html_e( 'Fields to migrate', 'publishrank' ); ?></legend><div class="pr-migration-checks"><?php foreach ( $this->field_labels() as $field => $label ) : ?><label><input type="checkbox" name="fields[]" value="<?php echo esc_attr( $field ); ?>" checked="checked" /> <span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></div></fieldset>
					<div class="pr-migration-options"><label><input type="checkbox" name="include_empty" value="1" /> <span><?php esc_html_e( 'Import empty source fields too', 'publishrank' ); ?></span></label><label class="is-dry-run"><input type="checkbox" name="dry_run" value="1" /> <span><strong><?php esc_html_e( 'Dry run only', 'publishrank' ); ?></strong><small><?php esc_html_e( 'Do not write anything', 'publishrank' ); ?></small></span></label></div>
					<div class="pr-migration-upload"><span class="dashicons dashicons-upload" aria-hidden="true"></span><label><strong><?php esc_html_e( 'Or import a normalized JSON export', 'publishrank' ); ?></strong><small><?php esc_html_e( 'Use an export from another site for cross-site migration and URL matching.', 'publishrank' ); ?></small><input type="file" name="seo_json_file" accept="application/json,.json" /></label></div><div class="pr-migration-actions"><?php submit_button( __( 'Run migration', 'publishrank' ), 'primary', 'submit', false ); ?></div>
				</form></section>
				<?php $backups = $this->backups(); if ( ! empty( $backups ) ) : ?><section class="pr-migration-section pr-migration-rollback"><div class="pr-migration-section__head"><div><p class="pr-migration-kicker"><?php esc_html_e( 'Safety net', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Rollback snapshots', 'publishrank' ); ?></h2><p><?php esc_html_e( 'Restore PublishRank metadata from a previous migration. Source plugin data is never removed.', 'publishrank' ); ?></p></div></div><div class="pr-migration-backups"><?php foreach ( $backups as $backup ) : ?><div><span class="dashicons dashicons-backup" aria-hidden="true"></span><span><?php echo esc_html( $backup['label'] . ' — ' . (int) $backup['posts'] . ' posts' ); ?></span><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::ROLLBACK_ACTION ); ?>" /><input type="hidden" name="backup_id" value="<?php echo esc_attr( $backup['id'] ); ?>" /><?php wp_nonce_field( self::ROLLBACK_ACTION ); ?><button class="button-link-delete" type="submit"><?php esc_html_e( 'Rollback', 'publishrank' ); ?></button></form></div><?php endforeach; ?></div></section><?php endif; ?>
				<section class="pr-migration-tools"><div class="pr-migration-tool"><p class="pr-migration-kicker"><?php esc_html_e( 'Step 2', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Export normalized SEO data', 'publishrank' ); ?></h2><p><?php esc_html_e( 'Create a versioned JSON backup or a compatible export for Yoast SEO, Rank Math, or another supported target.', 'publishrank' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::EXPORT_ACTION ); ?>" /><?php wp_nonce_field( self::EXPORT_ACTION ); ?><label><span><?php esc_html_e( 'Target format', 'publishrank' ); ?></span><select name="target"><option value="publishrank">PublishRank (canonical)</option><?php foreach ( $this->adapters as $id => $adapter ) : ?><option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $adapter['label'] ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Content types', 'publishrank' ); ?></span><input type="text" name="post_types" value="post,page" class="regular-text" /></label><?php submit_button( __( 'Download normalized export', 'publishrank' ), 'secondary', 'submit', false ); ?></form></div><div class="pr-migration-tool"><p class="pr-migration-kicker"><?php esc_html_e( 'Coverage check', 'publishrank' ); ?></p><h2><?php esc_html_e( 'Preflight scan', 'publishrank' ); ?></h2><p><?php esc_html_e( 'Count source records before changing anything so you can choose the right source and policy.', 'publishrank' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( self::SCAN_ACTION ); ?>" /><?php wp_nonce_field( self::SCAN_ACTION ); ?><?php submit_button( __( 'Run preflight scan', 'publishrank' ), 'secondary', 'submit', false ); ?></form></div></section>
		</div>
		<?php
	}

	public function scan() {
		$this->guard( self::SCAN_ACTION );
		$counts = array();
		foreach ( $this->adapters as $id => $adapter ) { $counts[ $id ] = $this->count_source_records( $adapter ); }
		$message = __( 'Preflight complete: ', 'publishrank' );
		foreach ( $counts as $id => $count ) { $message .= $this->adapters[ $id ]['label'] . ' ' . (int) $count . '; '; }
		$this->finish( $message );
	}

	public function import() {
		$this->guard( self::IMPORT_ACTION );
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$json_records = $this->uploaded_records();
		if ( 'json' !== $source && ! isset( $this->adapters[ $source ] ) ) { $this->finish( __( 'Unsupported source plugin.', 'publishrank' ), true ); return; }
		if ( 'json' === $source && null === $json_records ) { $this->finish( __( 'Select a normalized JSON file when using JSON upload mode.', 'publishrank' ), true ); return; }
		$source_label = 'json' === $source ? __( 'normalized JSON', 'publishrank' ) : $this->adapters[ $source ]['label'];
		$mode = ( isset( $_POST['mode'] ) && 'overwrite' === $_POST['mode'] ) ? 'overwrite' : 'merge';
		$types = $this->post_types( isset( $_POST['post_types'] ) ? wp_unslash( $_POST['post_types'] ) : '' );
		$fields = $this->selected_fields();
		$dry_run = ! empty( $_POST['dry_run'] );
		$stats = array( 'scanned' => 0, 'changed' => 0, 'fields' => 0, 'skipped' => 0, 'unmatched' => 0 );
		$backup = array( 'id' => uniqid( 'migration_', true ), 'label' => current_time( 'mysql' ) . ' — ' . $source_label, 'created' => time(), 'posts' => 0, 'records' => array() );
		$process = function ( $post_id, $row ) use ( &$stats, &$backup, $fields, $mode, $dry_run ) {
			$stats['scanned']++;
			$before = array( 'localized' => get_post_meta( $post_id, \PublishRankSEO\Meta\LocalizedSeoMeta::META_KEY, true ) );
			foreach ( $fields as $field ) { $key = $this->field_meta_key( $field ); $before[ $field ] = get_post_meta( $post_id, $key, true ); }
			$changed = $this->count_changes( $post_id, $row, $mode, $fields, ! empty( $_POST['include_empty'] ) );
			if ( isset( $row['localized'] ) && is_array( $row['localized'] ) && ! empty( $row['localized'] ) ) { $changed++; }
			if ( $changed > 0 ) { $backup['posts']++; $backup['records'][ (int) $post_id ] = $before; if ( ! $dry_run ) { $this->write_row( $post_id, $row, $mode, ! empty( $_POST['include_empty'] ), $fields ); if ( isset( $row['localized'] ) && is_array( $row['localized'] ) ) { update_post_meta( $post_id, \PublishRankSEO\Meta\LocalizedSeoMeta::META_KEY, $this->sanitize_localized( $row['localized'] ) ); } } }
			$stats['fields'] += $changed; $stats['changed'] += $changed > 0 ? 1 : 0; $stats['skipped'] += $changed ? 0 : 1;
		};
		if ( null !== $json_records ) {
			foreach ( $json_records as $record ) { $post_id = $this->match_record( $record ); if ( ! $post_id ) { $stats['unmatched']++; continue; } $process( $post_id, isset( $record['fields'] ) && is_array( $record['fields'] ) ? $record['fields'] : array() ); }
		} else {
			$page = 1;
			do {
				$query = new \WP_Query( array( 'post_type' => $types, 'post_status' => 'any', 'posts_per_page' => 250, 'paged' => $page, 'fields' => 'ids', 'no_found_rows' => true, 'update_post_meta_cache' => true ) );
				foreach ( (array) $query->posts as $post_id ) { $process( (int) $post_id, $this->read_row( (int) $post_id, $this->adapters[ $source ] ) ); }
				$page++;
			} while ( ! empty( $query->posts ) );
		}
		wp_reset_postdata();
		if ( ! $dry_run && $backup['posts'] > 0 ) { $this->save_backup( $backup ); }
		$prefix = $dry_run ? __( 'Dry run: ', 'publishrank' ) : '';
		$this->finish( sprintf( __( '%1$s%2$d fields across %3$d posts would change/changed from %4$s (%5$d unchanged, %6$d unmatched).', 'publishrank' ), $prefix, $stats['fields'], $stats['changed'], $source_label, $stats['skipped'], $stats['unmatched'] ) );
	}

	public function rollback() {
		$this->guard( self::ROLLBACK_ACTION );
		$id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
		foreach ( $this->backups() as $backup ) { if ( $id !== $backup['id'] ) { continue; } foreach ( $backup['records'] as $post_id => $values ) { foreach ( $values as $field => $value ) { $key = 'localized' === $field ? \PublishRankSEO\Meta\LocalizedSeoMeta::META_KEY : $this->field_meta_key( $field ); if ( ! $key ) { continue; } if ( '' === (string) $value || ( is_array( $value ) && empty( $value ) ) ) { delete_post_meta( (int) $post_id, $key ); } else { update_post_meta( (int) $post_id, $key, $value ); } } } $this->finish( __( 'Migration rollback completed.', 'publishrank' ) ); return; }
		$this->finish( __( 'Rollback snapshot not found.', 'publishrank' ), true );
	}

	public function export() {
		$this->guard( self::EXPORT_ACTION );
		$target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'publishrank';
		$types  = $this->post_types( isset( $_POST['post_types'] ) ? wp_unslash( $_POST['post_types'] ) : '' );
		$rows = array();
		$page = 1;
		do {
			$query = new \WP_Query( array( 'post_type' => $types, 'post_status' => 'any', 'posts_per_page' => 250, 'paged' => $page, 'fields' => 'ids', 'no_found_rows' => true, 'update_post_meta_cache' => true ) );
		foreach ( (array) $query->posts as $post_id ) { $row = $this->read_row( (int) $post_id, null ); $localized = get_post_meta( (int) $post_id, \PublishRankSEO\Meta\LocalizedSeoMeta::META_KEY, true ); if ( $this->has_values( $row ) || is_array( $localized ) ) { $rows[] = array( 'post_id' => (int) $post_id, 'post_type' => get_post_type( $post_id ), 'post_url' => get_permalink( $post_id ), 'locale' => LanguageAlternates::post_locale( (int) $post_id ), 'translations' => $this->translation_urls( (int) $post_id ), 'fields' => $row, 'localized' => is_array( $localized ) ? $localized : array(), 'target' => $this->target_fields( $row, $target ) ); } }
			$page++;
		} while ( ! empty( $query->posts ) );
		wp_reset_postdata();
			$payload = array( '_schema' => 'publishrank/seo-portability/v1', 'schema_revision' => 2, 'generated_at' => gmdate( 'c' ), 'source' => 'PublishRank ' . ( defined( 'PUBLISHRANK_SEO_VERSION' ) ? PUBLISHRANK_SEO_VERSION : '' ), 'target' => $target, 'global_settings' => $this->global_export(), 'records' => $rows );
		nocache_headers(); header( 'Content-Type: application/json; charset=' . get_bloginfo( 'charset' ) ); header( 'Content-Disposition: attachment; filename=publishrank-seo-portability-' . gmdate( 'Ymd-His' ) . '.json' ); echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); exit;
	}

	/** Canonical PublishRank field => post meta key. @return array<string,string> */
	private function meta_map() {
		return array( 'title' => PostMeta::TITLE, 'description' => PostMeta::DESCRIPTION, 'focus_keyword' => PostMeta::FOCUS_KEYWORD, 'canonical' => PostMeta::CANONICAL, 'og_title' => PostMeta::OG_TITLE, 'og_description' => PostMeta::OG_DESCRIPTION, 'og_image' => PostMeta::OG_IMAGE, 'twitter_title' => PostMeta::X_TITLE, 'twitter_description' => PostMeta::X_DESCRIPTION, 'robots' => PostMeta::ROBOTS, 'primary_category' => PostMeta::PRIMARY_CATEGORY, 'cornerstone' => PostMeta::CORNERSTONE );
	}

	/** @param array<string,mixed>|null $adapter @return array<string,string> */
	private function read_row( $post_id, $adapter ) {
		$fields = array( 'title' => '', 'description' => '', 'focus_keyword' => '', 'canonical' => '', 'og_title' => '', 'og_description' => '', 'og_image' => '', 'twitter_title' => '', 'twitter_description' => '', 'robots' => '', 'primary_category' => '', 'cornerstone' => '' );
		if ( null === $adapter ) {
			foreach ( $this->meta_map() as $field => $key ) { $fields[ $field ] = (string) get_post_meta( $post_id, $key, true ); }
			if ( '0' === $fields['cornerstone'] ) { $fields['cornerstone'] = ''; }
			return $fields;
		}
		$raw = array();
		foreach ( $adapter['keys'] as $field => $key ) { $raw[ $field ] = get_post_meta( $post_id, $key, true ); }
		if ( isset( $adapter['id'] ) && 'aioseo' === $adapter['id'] ) { $raw = array_merge( $raw, array_filter( $this->read_aioseo_table( $post_id ), static function ( $v ) { return '' !== $v && null !== $v; } ) ); }
		return self::normalize_row( isset( $adapter['id'] ) ? $adapter['id'] : '', $raw, $fields );
	}

	/**
	 * Normalize one source plugin's raw values into PublishRank fields. Pure: no WordPress state, so it is unit-testable.
	 *
	 * @param string              $source Adapter id.
	 * @param array<string,mixed> $raw    Field => raw source value.
	 * @param array<string,string> $fields Defaults.
	 * @return array<string,string>
	 */
	public static function normalize_row( $source, array $raw, array $fields = array() ) {
		foreach ( $raw as $field => $value ) {
			if ( in_array( $field, array( 'robots', 'robots_nofollow' ), true ) ) { continue; }
			if ( is_array( $value ) ) { $value = implode( ',', array_filter( array_map( 'strval', $value ) ) ); }
			if ( ! is_scalar( $value ) ) { continue; }
			$value = trim( (string) $value );
			if ( in_array( $field, array( 'title', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description' ), true ) ) { $value = self::convert_template( $source, $value ); }
			if ( 'primary_category' === $field ) { $value = absint( $value ) ? (string) absint( $value ) : ''; }
			if ( 'cornerstone' === $field ) { $value = in_array( strtolower( $value ), array( '1', 'on', 'yes', 'true' ), true ) ? '1' : ''; }
			$fields[ $field ] = $value;
		}
		$fields['robots'] = self::normalize_robots( $source, isset( $raw['robots'] ) ? $raw['robots'] : '', isset( $raw['robots_nofollow'] ) ? $raw['robots_nofollow'] : '' );
		return $fields;
	}

	/** Convert robots flags from any supported source into a PublishRank directive list ('' = default index,follow). */
	public static function normalize_robots( $source, $noindex, $nofollow = '' ) {
		$directives = array();
		if ( is_array( $noindex ) ) {
			$directives = array_map( 'strtolower', array_map( 'strval', $noindex ) );
		} else {
			$value = strtolower( trim( (string) $noindex ) );
			if ( 'seopress' === $source ) { $value = 'yes' === $value ? 'noindex' : ''; }
			if ( in_array( $value, array( '1', 'true', 'yes', 'on', 'noindex' ), true ) ) { $directives[] = 'noindex'; }
			elseif ( ! in_array( $value, array( '', '0', '-1', '2', 'false', 'no', 'off', 'index', 'follow', 'index,follow' ), true ) ) { $directives = array_map( 'trim', preg_split( '/[,|;\s]+/', $value ) ); }
		}
		$follow = strtolower( trim( is_scalar( $nofollow ) ? (string) $nofollow : '' ) );
		if ( in_array( $follow, array( '1', 'true', 'yes', 'on', 'nofollow' ), true ) ) { $directives[] = 'nofollow'; }
		$allowed = array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'notranslate' );
		$directives = array_values( array_unique( array_intersect( $directives, $allowed ) ) );
		return implode( ',', $directives );
	}

	/** Translate another plugin's title/description variables into PublishRank %%tokens%%; unknown variables are kept verbatim. */
	public static function convert_template( $source, $value ) {
		$value = (string) $value;
		if ( '' === $value ) { return ''; }
		$common = array( 'sitename' => 'sitename', 'sitetitle' => 'sitename', 'site_title' => 'sitename', 'blog_title' => 'sitename', 'sitedesc' => 'site_description', 'tagline' => 'site_description', 'site_description' => 'site_description', 'sep' => 'sep', 'separator' => 'sep', 'separator_sa' => 'sep', 'title' => 'title', 'post_title' => 'title', 'excerpt' => 'excerpt', 'excerpt_only' => 'excerpt', 'post_excerpt' => 'excerpt', 'seo_excerpt' => 'excerpt', 'url' => 'permalink', 'permalink' => 'permalink', 'post_url' => 'permalink', 'id' => 'id', 'post_id' => 'id', 'page' => 'page', 'pagenumber' => 'page', 'page_number' => 'page', 'name' => 'author', 'author' => 'author', 'author_name' => 'author', 'post_author' => 'author', 'category' => 'category', 'categories' => 'category', 'post_category' => 'category', 'primary_category' => 'primary_category', 'date' => 'date', 'post_date' => 'date', 'publish_date' => 'date', 'modified' => 'modified', 'post_modified_date' => 'modified', 'focuskw' => 'focus_keyword', 'focus_keyword' => 'focus_keyword', 'keyphrase' => 'focus_keyword', 'target_keyword' => 'focus_keyword' );
		$swap = static function ( $match ) use ( $common ) {
			$key = strtolower( $match[1] );
			return isset( $common[ $key ] ) ? '%%' . $common[ $key ] . '%%' : $match[0];
		};
		if ( 'rank_math' === $source ) { return (string) preg_replace_callback( '/(?<!%)%([a-z_]+)%(?!%)/i', $swap, $value ); }
		if ( 'aioseo' === $source ) { return (string) preg_replace_callback( '/#([a-z_]+)/i', $swap, $value ); }
		if ( 'tsf' === $source ) { return $value; }
		return (string) preg_replace_callback( '/%%([a-z_]+)%%/i', $swap, $value ); // Yoast, SEOPress, JSON.
	}

	/** AIOSEO 4+ stores post SEO in its own table, not post meta. @return array<string,mixed> */
	private function read_aioseo_table( $post_id ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! $this->aioseo_table_exists() ) { return array(); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'aioseo_posts WHERE post_id = %d', (int) $post_id ), ARRAY_A );
		if ( ! is_array( $row ) ) { return array(); }
		$keyphrase = '';
		if ( ! empty( $row['keyphrases'] ) ) { $decoded = json_decode( (string) $row['keyphrases'], true ); $keyphrase = isset( $decoded['focus']['keyphrase'] ) ? (string) $decoded['focus']['keyphrase'] : ''; }
		return array(
			'title' => $row['title'] ?? '', 'description' => $row['description'] ?? '', 'focus_keyword' => $keyphrase,
			'canonical' => $row['canonical_url'] ?? '', 'og_title' => $row['og_title'] ?? '', 'og_description' => $row['og_description'] ?? '',
			'og_image' => $row['og_image_custom_url'] ?? '', 'twitter_title' => $row['twitter_title'] ?? '', 'twitter_description' => $row['twitter_description'] ?? '',
			'robots' => ! empty( $row['robots_noindex'] ) ? '1' : '', 'robots_nofollow' => ! empty( $row['robots_nofollow'] ) ? '1' : '',
			'cornerstone' => ! empty( $row['pillar_content'] ) ? '1' : '',
		);
	}

	private function aioseo_table_exists() {
		static $exists = null;
		if ( null === $exists ) { global $wpdb; $table = $wpdb->prefix . 'aioseo_posts'; $exists = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); }
		return $exists;
	}

	private function field_labels() { return array( 'title' => __( 'Title', 'publishrank' ), 'description' => __( 'Description', 'publishrank' ), 'focus_keyword' => __( 'Focus keyword', 'publishrank' ), 'canonical' => __( 'Canonical', 'publishrank' ), 'og_title' => __( 'Open Graph title', 'publishrank' ), 'og_description' => __( 'Open Graph description', 'publishrank' ), 'og_image' => __( 'Open Graph image', 'publishrank' ), 'twitter_title' => __( 'X title', 'publishrank' ), 'twitter_description' => __( 'X description', 'publishrank' ), 'robots' => __( 'Robots', 'publishrank' ), 'primary_category' => __( 'Primary category', 'publishrank' ), 'cornerstone' => __( 'Cornerstone', 'publishrank' ) ); }
	private function selected_fields() { $fields = isset( $_POST['fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) : array_keys( $this->field_labels() ); return array_values( array_intersect( array_keys( $this->field_labels() ), $fields ) ); }
	private function field_meta_key( $field ) { $map = $this->meta_map(); return isset( $map[ $field ] ) ? $map[ $field ] : ''; }
	private function count_changes( $post_id, $row, $mode, $fields, $include_empty ) { $count = 0; foreach ( $fields as $field ) { $value = isset( $row[ $field ] ) ? trim( (string) $row[ $field ] ) : ''; if ( '' === $value && ! $include_empty ) { continue; } if ( 'merge' === $mode && '' !== (string) get_post_meta( $post_id, $this->field_meta_key( $field ), true ) ) { continue; } $count++; } return $count; }
	private function write_row( $post_id, $row, $mode, $include_empty, $fields = null ) {
		$map = $this->meta_map();
		if ( null === $fields ) { $fields = array_keys( $map ); }
		$changed = 0;
		foreach ( $map as $field => $key ) { if ( ! in_array( $field, $fields, true ) ) { continue; } $value = isset( $row[ $field ] ) ? trim( (string) $row[ $field ] ) : ''; if ( '' === $value && ! $include_empty ) { continue; } if ( 'merge' === $mode && '' !== (string) get_post_meta( $post_id, $key, true ) ) { continue; } $value = $this->sanitize_field( $field, $value ); if ( '' === $value ) { delete_post_meta( $post_id, $key ); } else { update_post_meta( $post_id, $key, $value ); } $changed++; }
		return $changed;
	}
	private function uploaded_records() {
		if ( empty( $_FILES['seo_json_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['seo_json_file']['tmp_name'] ) ) { return null; }
		if ( ! empty( $_FILES['seo_json_file']['size'] ) && (int) $_FILES['seo_json_file']['size'] > 20 * 1024 * 1024 ) { wp_die( esc_html__( 'The JSON migration file is too large.', 'publishrank' ), 400 ); }
		$data = json_decode( file_get_contents( $_FILES['seo_json_file']['tmp_name'] ), true );
		if ( ! is_array( $data ) || 'publishrank/seo-portability/v1' !== ( $data['_schema'] ?? '' ) || ! is_array( $data['records'] ?? null ) ) { wp_die( esc_html__( 'The normalized migration JSON is invalid.', 'publishrank' ), 400 ); }
		return array_slice( $data['records'], 0, 100000 );
	}
	private function match_record( $record ) {
		if ( ! is_array( $record ) ) { return 0; }
		$id = ! empty( $record['post_id'] ) ? absint( $record['post_id'] ) : 0;
		if ( $id && get_post( $id ) ) { return $id; }
		$url    = ! empty( $record['post_url'] ) ? esc_url_raw( $record['post_url'] ) : '';
		$locale = ! empty( $record['locale'] ) ? LanguageAlternates::locale( $record['locale'] ) : '';
		if ( ! $url ) { return 0; }
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		// Same path on this site (handles /fr/… language folders and hierarchical pages).
		if ( function_exists( 'url_to_postid' ) ) { $by_url = url_to_postid( home_url( '/' . $path . '/' ) ); if ( $by_url && ( ! $locale || LanguageAlternates::post_locale( $by_url ) === $locale ) ) { return (int) $by_url; } }
		$slug = basename( $path );
		if ( ! $slug ) { return 0; }
		$posts = get_posts( array( 'name' => sanitize_title( $slug ), 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => 20, 'suppress_filters' => true, 'lang' => '' ) );
		if ( $locale && count( $posts ) > 1 ) { $posts = array_values( array_filter( $posts, static function ( $post ) use ( $locale ) { return LanguageAlternates::post_locale( $post->ID ) === $locale; } ) ); }
		return 1 === count( $posts ) ? (int) $posts[0]->ID : 0;
	}
	private function backups() { $items = (array) get_option( self::BACKUP_OPTION, array() ); return array_slice( array_reverse( $items ), 0, 5 ); }
	private function save_backup( $backup ) { $items = (array) get_option( self::BACKUP_OPTION, array() ); $items[] = $backup; update_option( self::BACKUP_OPTION, array_slice( $items, -5 ), false ); }

	private function sanitize_field( $field, $value ) {
		if ( in_array( $field, array( 'canonical', 'og_image' ), true ) ) { return esc_url_raw( $value ); }
		if ( 'robots' === $field ) { return self::normalize_robots( '', $value ); }
		if ( 'primary_category' === $field ) { $id = absint( $value ); return ( $id && term_exists( $id, 'category' ) ) ? (string) $id : ''; }
		if ( 'cornerstone' === $field ) { return '1' === $value ? '1' : ''; }
		return sanitize_textarea_field( $value );
	}
	private function sanitize_localized( $data ) {
		$out = array(); foreach ( $data as $locale => $row ) { $locale = \PublishRankSEO\Meta\LanguageAlternates::locale( $locale ); if ( ! $locale || ! is_array( $row ) ) { continue; } foreach ( array( 'title' => 255, 'description' => 320, 'focus_keyword' => 120, 'og_title' => 255, 'og_description' => 320, 'robots' => 120 ) as $field => $length ) { if ( isset( $row[ $field ] ) && '' !== trim( (string) $row[ $field ] ) ) { $out[ $locale ][ $field ] = 'robots' === $field ? sanitize_text_field( $row[ $field ] ) : \PublishRankSEO\Meta\TemplateResolver::sanitize_template( $row[ $field ], $length ); } } if ( isset( $row['og_image'] ) ) { $out[ $locale ]['og_image'] = \PublishRankSEO\Meta\PostMeta::sanitize_public_url( $row['og_image'] ); } } return $out;
	}

	private function target_fields( $row, $target ) {
		if ( 'publishrank' === $target || ! isset( $this->adapters[ $target ] ) ) { return $row; }
		$out = array(); foreach ( $this->adapters[ $target ]['keys'] as $field => $key ) { if ( isset( $row[ $field ] ) && '' !== $row[ $field ] ) { $out[ $key ] = $row[ $field ]; } } return $out;
	}
	private function global_export() {
		$out = array();
		$allow = array(
			'wpseo_titles' => array( 'title-home-wpseo', 'metadesc-home-wpseo', 'title-post-wpseo', 'metadesc-post-wpseo' ),
			'rank-math-options-titles' => array( 'homepage-title', 'homepage-description', 'pt_post_title', 'pt_post_description' ),
		);
		foreach ( $allow as $option => $keys ) { $value = get_option( $option, array() ); if ( ! is_array( $value ) ) { continue; } foreach ( $keys as $key ) { if ( array_key_exists( $key, $value ) ) { $out[ $option ][ $key ] = sanitize_textarea_field( (string) $value[ $key ] ); } } }
		// PublishRank's own private login address (the on/off switch is left out on purpose).
		$out['publishrank'] = array(
			'publishrank_login_slug'           => \PublishRankSEO\Security\PrivateLogin::current_login_slug(),
			'publishrank_login_redirect' => \PublishRankSEO\Security\PrivateLogin::current_redirect_slug(),
		);
		return $out;
	}

	/** @return array<string,string> locale => permalink of each published translation. */
	private function translation_urls( $post_id ) { $out = array(); foreach ( LanguageAlternates::post_translations( $post_id ) as $locale => $id ) { if ( (int) $id !== (int) $post_id ) { $out[ $locale ] = (string) get_permalink( $id ); } } return $out; }
	private function has_values( $row ) { foreach ( $row as $value ) { if ( '' !== trim( (string) $value ) ) { return true; } } return false; }
	private function post_types( $raw ) { $types = array_filter( array_map( 'sanitize_key', preg_split( '/[,\\s]+/', (string) $raw ) ) ); if ( empty( $types ) ) { $types = PostMeta::post_types(); } return array_values( array_intersect( $types, PostMeta::post_types() ) ); }
	private function count_source_records( $adapter ) { $keys = array_values( $adapter['keys'] ); if ( empty( $keys ) ) { return 0; } global $wpdb; $like = array(); $args = array(); foreach ( $keys as $key ) { $like[] = 'pm.meta_key = %s'; $args[] = $key; } $sql = 'SELECT COUNT(DISTINCT pm.post_id) FROM ' . $wpdb->postmeta . ' pm INNER JOIN ' . $wpdb->posts . ' p ON p.ID = pm.post_id WHERE (' . implode( ' OR ', $like ) . ') AND pm.meta_value <> %s AND p.post_status <> %s'; $args[] = ''; $args[] = 'trash'; $count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); if ( isset( $adapter['id'] ) && 'aioseo' === $adapter['id'] && $this->aioseo_table_exists() ) { $count = max( $count, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "aioseo_posts WHERE title <> '' OR description <> ''" ) ); } return $count; }
	private function guard( $action ) { if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to use SEO portability.', 'publishrank' ), 403 ); } check_admin_referer( $action ); }
	private function finish( $message, $error = false ) { set_transient( self::RESULT_KEY . '_' . get_current_user_id(), array( 'message' => $message, 'error' => $error ), 120 ); wp_safe_redirect( Branding::page_url( 'publishrank-seo-portability' ) ); exit; }
}
/* EOF */
