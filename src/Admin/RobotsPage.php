<?php
/** robots.txt manager screen. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Robots\RobotsTxt;

final class RobotsPage {
	const SLUG = 'publishrank-seo-robots';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 13 );
		add_action( 'admin_init', array( $this, 'settings' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Robots.txt', 'publishrank' ), __( 'Robots.txt', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function settings() {
		foreach ( array( 'publishrank_robots_enabled', 'publishrank_robots_sitemaps', 'publishrank_robots_block_ai' ) as $option ) {
			register_setting( 'publishrank_seo_robots', $option, array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => 'publishrank_robots_block_ai' !== $option ) );
		}
		register_setting( 'publishrank_seo_robots', 'publishrank_robots_custom', array( 'type' => 'string', 'sanitize_callback' => array( RobotsTxt::class, 'clean_rules' ), 'default' => '' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap">';
		AdminAssets::header( __( 'Robots.txt', 'publishrank' ), __( 'Advertise your sitemaps, control AI crawlers and add custom rules.', 'publishrank' ) );
		settings_errors();
		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A physical robots.txt file exists in your site root. WordPress serves that file instead, so the rules below will not appear until it is removed.', 'publishrank' ) . '</p></div>';
		}
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'WordPress is set to discourage search engines, so robots.txt additions are paused.', 'publishrank' ) . '</p></div>';
		}
		$bool = static function ( $key, $default ) {
			return (bool) get_option( $key, $default );
		};
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'publishrank_seo_robots' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Manage robots.txt', 'publishrank' ); ?></th><td>
					<input type="hidden" name="publishrank_robots_enabled" value="0" />
					<label><input type="checkbox" name="publishrank_robots_enabled" value="1" <?php checked( $bool( 'publishrank_robots_enabled', true ) ); ?> /> <?php esc_html_e( 'Let PublishRank extend the virtual robots.txt', 'publishrank' ); ?></label>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Sitemaps', 'publishrank' ); ?></th><td>
					<input type="hidden" name="publishrank_robots_sitemaps" value="0" />
					<label><input type="checkbox" name="publishrank_robots_sitemaps" value="1" <?php checked( $bool( 'publishrank_robots_sitemaps', true ) ); ?> /> <?php esc_html_e( 'Add the PublishRank sitemap index and News sitemap', 'publishrank' ); ?></label>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'AI training crawlers', 'publishrank' ); ?></th><td>
					<input type="hidden" name="publishrank_robots_block_ai" value="0" />
					<label><input type="checkbox" name="publishrank_robots_block_ai" value="1" <?php checked( $bool( 'publishrank_robots_block_ai', false ) ); ?> /> <?php esc_html_e( 'Disallow known AI training crawlers', 'publishrank' ); ?></label>
					<p class="description"><?php echo esc_html( implode( ', ', RobotsTxt::ai_bots() ) ); ?>. <?php esc_html_e( 'Well-behaved crawlers honour this; it does not affect Googlebot or Google News.', 'publishrank' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="publishrank_robots_custom"><?php esc_html_e( 'Custom rules', 'publishrank' ); ?></label></th><td>
					<textarea id="publishrank_robots_custom" name="publishrank_robots_custom" rows="7" class="large-text code" placeholder="User-agent: *&#10;Disallow: /private/"><?php echo esc_textarea( (string) get_option( 'publishrank_robots_custom', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Supported lines: User-agent, Disallow, Allow, Crawl-delay, Sitemap and # comments. Anything else is removed on save.', 'publishrank' ); ?></p>
				</td></tr>
			</tbody></table>
			<?php submit_button(); ?>
		</form>
		<h2><?php esc_html_e( 'Preview', 'publishrank' ); ?></h2>
		<pre class="rhs-preview"><?php echo esc_html( RobotsTxt::preview() ); ?></pre>
		<p><a class="button" href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open live robots.txt', 'publishrank' ); ?></a></p>
		</div>
		<?php
	}
}
