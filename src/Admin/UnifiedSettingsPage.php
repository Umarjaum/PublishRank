<?php
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Redirect\RedirectStore;
use PublishRankSEO\Robots\RobotsTxt;

final class UnifiedSettingsPage {
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 10 );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_post_publishrank_unified_redirect_add', array( $this, 'handle_redirect_add' ) );
		add_action( 'admin_post_publishrank_unified_redirect_settings', array( $this, 'handle_redirect_settings' ) );
	}

	public function menu() {
		add_submenu_page(
			Branding::MENU_SLUG,
			__( 'SEO Settings', 'publishrank' ),
			__( 'Settings', 'publishrank' ),
			'manage_options',
			'publishrank',
			array( $this, 'page' )
		);
	}

	public function settings() {
		register_setting(
			'publishrank_unified_seo',
			'publishrank_seo_title_separator',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => '|',
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_seo_default_robots',
			array(
				'type' => 'string',
				'sanitize_callback' => array( $this, 'sanitize_robots' ),
				'default' => 'index,follow',
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_meta_enabled',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_schema_enabled',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_seo_news_enabled',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_indexnow_enabled',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_indexnow_key',
			array(
				'type' => 'string',
				'sanitize_callback' => array( $this, 'sanitize_indexnow_key' ),
				'default' => '',
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_robots_enabled',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_robots_sitemaps',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_robots_block_ai',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_robots_custom',
			array(
				'type' => 'string',
				'sanitize_callback' => array( RobotsTxt::class, 'clean_rules' ),
				'default' => '',
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_redirect_enabled',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_redirect_only_404',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_redirect_preserve_query',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_redirect_x_header',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_redirect_max_hops',
			array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 10,
			)
		);
		register_setting(
			'publishrank_unified_seo',
			'publishrank_image_alt_fallback_enabled',
			array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false,
			)
		);
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$tabs = array(
			'general' => __( 'General', 'publishrank' ),
			'redirects' => __( 'Redirects', 'publishrank' ),
			'robots' => __( 'Robots', 'publishrank' ),
			'toolkit' => __( 'Toolkit', 'publishrank' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}

		echo '<div class="wrap">';
		\PublishRankSEO\Admin\AdminAssets::header(
			__( 'PublishRank SEO', 'publishrank' ),
			__( 'Unified settings for metadata, schema, redirects, robots, sitemaps, and publisher workflows.', 'publishrank' )
		);
		settings_errors();

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$url = add_query_arg( array( 'page' => 'publishrank', 'tab' => $key ), admin_url( 'admin.php' ) );
			$active = $tab === $key ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		switch ( $tab ) {
			case 'redirects':
				$this->redirects_tab();
				break;
			case 'robots':
				$this->robots_tab();
				break;
			case 'toolkit':
				$this->toolkit_tab();
				break;
			default:
				$this->general_tab();
				break;
		}

		echo '</div>';
	}

	public function general_tab() {
		$defaults = array(
			'publishrank_seo_title_separator' => '|',
			'publishrank_seo_default_robots' => 'index,follow',
			'publishrank_meta_enabled' => 1,
			'publishrank_schema_enabled' => 1,
			'publishrank_seo_news_enabled' => 1,
			'publishrank_indexnow_enabled' => 0,
			'publishrank_indexnow_key' => '',
		);
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'publishrank_unified_seo' ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="publishrank_seo_title_separator"><?php esc_html_e( 'Title separator', 'publishrank' ); ?></label></th>
						<td>
							<input id="publishrank_seo_title_separator" name="publishrank_seo_title_separator" type="text" value="<?php echo esc_attr( get_option( 'publishrank_seo_title_separator', $defaults['publishrank_seo_title_separator'] ) ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="publishrank_seo_default_robots"><?php esc_html_e( 'Default robots tag', 'publishrank' ); ?></label></th>
						<td>
							<input id="publishrank_seo_default_robots" name="publishrank_seo_default_robots" type="text" value="<?php echo esc_attr( get_option( 'publishrank_seo_default_robots', $defaults['publishrank_seo_default_robots'] ) ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Core outputs', 'publishrank' ); ?></th>
						<td>
							<label><input type="checkbox" name="publishrank_meta_enabled" value="1" <?php checked( (bool) get_option( 'publishrank_meta_enabled', $defaults['publishrank_meta_enabled'] ) ); ?> /> <?php esc_html_e( 'Enable SEO metadata output', 'publishrank' ); ?></label><br />
							<label><input type="checkbox" name="publishrank_schema_enabled" value="1" <?php checked( (bool) get_option( 'publishrank_schema_enabled', $defaults['publishrank_schema_enabled'] ) ); ?> /> <?php esc_html_e( 'Enable schema.org output', 'publishrank' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Publisher features', 'publishrank' ); ?></th>
						<td>
							<label><input type="checkbox" name="publishrank_seo_news_enabled" value="1" <?php checked( (bool) get_option( 'publishrank_seo_news_enabled', $defaults['publishrank_seo_news_enabled'] ) ); ?> /> <?php esc_html_e( 'Enable Google News sitemap', 'publishrank' ); ?></label><br />
							<label><input type="checkbox" name="publishrank_indexnow_enabled" value="1" <?php checked( (bool) get_option( 'publishrank_indexnow_enabled', $defaults['publishrank_indexnow_enabled'] ) ); ?> /> <?php esc_html_e( 'Enable IndexNow', 'publishrank' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="publishrank_indexnow_key"><?php esc_html_e( 'IndexNow key', 'publishrank' ); ?></label></th>
						<td><input id="publishrank_indexnow_key" name="publishrank_indexnow_key" type="text" value="<?php echo esc_attr( get_option( 'publishrank_indexnow_key', $defaults['publishrank_indexnow_key'] ) ); ?>" class="regular-text" /></td>
					</tr>
				</tbody>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	public function redirects_tab() {
		$rules = RedirectStore::all();
		$total = count( $rules );
		$pages = max( 1, (int) ceil( $total / 50 ) );
		$page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$page = min( $page, $pages );
		$slice = array_slice( $rules, ( $page - 1 ) * 50, 50 );
		?>
		<div class="rhs-grid">
			<div class="rhs-card">
				<h2><?php esc_html_e( 'Add a redirect', 'publishrank' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="publishrank_unified_redirect_add" />
					<?php wp_nonce_field( 'publishrank_unified_redirect_add' ); ?>
					<p><label for="pr-source"><strong><?php esc_html_e( 'Old path or URL', 'publishrank' ); ?></strong></label><br /><input id="pr-source" name="source" type="text" class="large-text" placeholder="/old-page" /></p>
					<p><label for="pr-target"><strong><?php esc_html_e( 'New path or URL', 'publishrank' ); ?></strong></label><br /><input id="pr-target" name="target" type="text" class="large-text" placeholder="/new-page" /></p>
					<p><label for="pr-status"><strong><?php esc_html_e( 'Type', 'publishrank' ); ?></strong></label><br />
					<select id="pr-status" name="status">
						<option value="301"><?php esc_html_e( '301 — Moved permanently', 'publishrank' ); ?></option>
						<option value="302"><?php esc_html_e( '302 — Found', 'publishrank' ); ?></option>
						<option value="307"><?php esc_html_e( '307 — Temporary redirect', 'publishrank' ); ?></option>
						<option value="308"><?php esc_html_e( '308 — Permanent redirect', 'publishrank' ); ?></option>
						<option value="410"><?php esc_html_e( '410 — Content deleted', 'publishrank' ); ?></option>
					</select>
					</p>
					<p><label><input type="checkbox" name="regex" value="1" /> <?php esc_html_e( 'Treat as regex', 'publishrank' ); ?></label></p>
					<?php submit_button( __( 'Save redirect', 'publishrank' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
			<div class="rhs-card">
				<h2><?php esc_html_e( 'Redirect behavior', 'publishrank' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="publishrank_unified_redirect_settings" />
					<?php wp_nonce_field( 'publishrank_unified_redirect_settings' ); ?>
					<p><label><input type="checkbox" name="enabled" value="1" <?php checked( get_option( 'publishrank_redirect_enabled', true ) ); ?> /> <?php esc_html_e( 'Enable redirect engine', 'publishrank' ); ?></label></p>
					<p><label><input type="checkbox" name="only_404" value="1" <?php checked( get_option( 'publishrank_redirect_only_404', true ) ); ?> /> <?php esc_html_e( 'Only process 404 URLs', 'publishrank' ); ?></label></p>
					<p><label><input type="checkbox" name="preserve_query" value="1" <?php checked( get_option( 'publishrank_redirect_preserve_query', true ) ); ?> /> <?php esc_html_e( 'Preserve query string', 'publishrank' ); ?></label></p>
					<p><label><input type="checkbox" name="x_header" value="1" <?php checked( get_option( 'publishrank_redirect_x_header', true ) ); ?> /> <?php esc_html_e( 'Send X-Redirect-By header', 'publishrank' ); ?></label></p>
					<p><label for="pr-max-hops"><strong><?php esc_html_e( 'Redirect chain depth', 'publishrank' ); ?></strong></label><br /><input id="pr-max-hops" name="max_hops" type="number" min="1" max="50" value="<?php echo esc_attr( (string) get_option( 'publishrank_redirect_max_hops', 10 ) ); ?>" /></p>
					<?php submit_button( __( 'Save behavior', 'publishrank' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<h2><?php echo esc_html( sprintf( /* translators: %d: number of redirects. */ _n( '%d redirect', '%d redirects', $total, 'publishrank' ), $total ) ); ?></h2>
		<div class="rhs-table-wrap"><table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Source', 'publishrank' ); ?></th><th><?php esc_html_e( 'Target', 'publishrank' ); ?></th><th><?php esc_html_e( 'Type', 'publishrank' ); ?></th><th><?php esc_html_e( 'Mode', 'publishrank' ); ?></th><th><?php esc_html_e( 'Action', 'publishrank' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $slice ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No redirects yet.', 'publishrank' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $slice as $rule ) : ?>
					<?php $delete = wp_nonce_url( add_query_arg( array( 'action' => 'publishrank_seo_redirect_delete', 'id' => RedirectStore::id( $rule ) ), admin_url( 'admin-post.php' ) ), 'publishrank_seo_redirect_delete' ); ?>
					<tr>
						<td><code><?php echo esc_html( isset( $rule['source'] ) ? $rule['source'] : '' ); ?></code></td>
						<td><?php echo ! empty( $rule['target'] ) ? '<code>' . esc_html( $rule['target'] ) . '</code>' : '&mdash;'; ?></td>
						<td><?php echo esc_html( isset( $rule['status'] ) ? (string) (int) $rule['status'] : '301' ); ?></td>
						<td><?php echo ! empty( $rule['regex'] ) ? esc_html__( 'Regex', 'publishrank' ) : esc_html__( 'Exact', 'publishrank' ); ?></td>
						<td><a class="button-link-delete" href="<?php echo esc_url( $delete ); ?>"><?php esc_html_e( 'Delete', 'publishrank' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table></div>
		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'total' => $pages, 'current' => $page ) ) ); ?></div></div>
		<?php endif; ?>
		<?php
	}

	public function robots_tab() {
		$bool = function ( $key, $default ) {
			return (bool) get_option( $key, $default );
		};
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'publishrank_unified_seo' ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Manage robots.txt', 'publishrank' ); ?></th>
						<td>
							<label><input type="checkbox" name="publishrank_robots_enabled" value="1" <?php checked( $bool( 'publishrank_robots_enabled', true ) ); ?> /> <?php esc_html_e( 'Let PublishRank extend the virtual robots.txt', 'publishrank' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sitemaps', 'publishrank' ); ?></th>
						<td>
							<label><input type="checkbox" name="publishrank_robots_sitemaps" value="1" <?php checked( $bool( 'publishrank_robots_sitemaps', true ) ); ?> /> <?php esc_html_e( 'Add sitemap entries to robots.txt', 'publishrank' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI training crawlers', 'publishrank' ); ?></th>
						<td>
							<label><input type="checkbox" name="publishrank_robots_block_ai" value="1" <?php checked( $bool( 'publishrank_robots_block_ai', false ) ); ?> /> <?php esc_html_e( 'Disallow known AI training bots', 'publishrank' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="publishrank_robots_custom"><?php esc_html_e( 'Custom rules', 'publishrank' ); ?></label></th>
						<td>
							<textarea id="publishrank_robots_custom" name="publishrank_robots_custom" rows="7" class="large-text code" placeholder="User-agent: *"><?php echo esc_textarea( (string) get_option( 'publishrank_robots_custom', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Supported lines: User-agent, Disallow, Allow, Crawl-delay, Sitemap and comments.', 'publishrank' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button(); ?>
		</form>
		<h2><?php esc_html_e( 'Preview', 'publishrank' ); ?></h2>
		<pre class="rhs-preview"><?php echo esc_html( RobotsTxt::preview() ); ?></pre>
		<?php
	}

	public function toolkit_tab() {
		$toolkit_url = admin_url( 'admin.php?page=' . \
				sanitize_key( 'publishrank-publisher-toolkit' ) );
		?>
		<div class="rhs-grid">
			<div class="rhs-card">
				<h2><?php esc_html_e( 'Publisher toolkit', 'publishrank' ); ?></h2>
				<p><?php esc_html_e( 'Use role-aware bulk metadata editing, missing alt text remediation, and workflow controls designed for editorial teams.', 'publishrank' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( $toolkit_url ); ?>"><?php esc_html_e( 'Open toolkit', 'publishrank' ); ?></a></p>
			</div>
			<div class="rhs-card">
				<h2><?php esc_html_e( 'Production checks', 'publishrank' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'Keep one SEO metadata source of truth.', 'publishrank' ); ?></li>
					<li><?php esc_html_e( 'Avoid duplicate title and canonical output.', 'publishrank' ); ?></li>
					<li><?php esc_html_e( 'Validate schema and redirects after every content update.', 'publishrank' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	public function sanitize_robots( $value ) {
		$value = sanitize_text_field( (string) $value );
		return ( new \PublishRankSEO\Meta\MetaGenerator() )->normalize_robots( $value );
	}

	public function sanitize_indexnow_key( $value ) {
		$key = preg_replace( '/[^A-Za-z0-9\-_]/', '', trim( (string) $value ) );
		return substr( (string) $key, 0, 128 );
	}

	public function handle_redirect_add() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to add redirects.', 'publishrank' ), 403 );
		}
		check_admin_referer( 'publishrank_unified_redirect_add' );
		$source = isset( $_POST['source'] ) ? (string) wp_unslash( $_POST['source'] ) : '';
		$target = isset( $_POST['target'] ) ? (string) wp_unslash( $_POST['target'] ) : '';
		$status = isset( $_POST['status'] ) ? absint( $_POST['status'] ) : 301;
		$result = RedirectStore::add( $source, $target, $status, ! empty( $_POST['regex'] ) );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'publishrank', 'tab' => 'redirects', 'message' => 'error' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'publishrank', 'tab' => 'redirects', 'message' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_redirect_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update redirect settings.', 'publishrank' ), 403 );
		}
		check_admin_referer( 'publishrank_unified_redirect_settings' );
		update_option( 'publishrank_redirect_enabled', ! empty( $_POST['enabled'] ) ? 1 : 0, false );
		update_option( 'publishrank_redirect_only_404', ! empty( $_POST['only_404'] ) ? 1 : 0, false );
		update_option( 'publishrank_redirect_preserve_query', ! empty( $_POST['preserve_query'] ) ? 1 : 0, false );
		update_option( 'publishrank_redirect_x_header', ! empty( $_POST['x_header'] ) ? 1 : 0, false );
		update_option( 'publishrank_redirect_max_hops', min( 50, max( 1, absint( $_POST['max_hops'] ?? 10 ) ) ), false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'publishrank', 'tab' => 'redirects' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
