<?php
/** robots.txt manager screen: settings on the left, a live preview with checks and a crawlability matrix on the right. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Robots\RobotsTxt;

defined( 'ABSPATH' ) || exit;

final class RobotsPage {
	const SLUG = 'publishrank-seo-robots';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 13 );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Robots.txt', 'publishrank' ), __( 'Robots.txt', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function settings() {
		$defaults = RobotsTxt::defaults();
		foreach ( array( RobotsTxt::OPT_ENABLED, RobotsTxt::OPT_SITEMAPS, RobotsTxt::OPT_LEGACY_AI, RobotsTxt::OPT_LOW_VALUE, RobotsTxt::OPT_TRACKING, RobotsTxt::OPT_ENV_BLOCK, RobotsTxt::OPT_HOUSEKEEP ) as $option ) {
			register_setting(
				'publishrank_seo_robots',
				$option,
				array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => isset( $defaults[ $option ] ) ? (bool) $defaults[ $option ] : false,
				)
			);
		}
		register_setting(
			'publishrank_seo_robots',
			RobotsTxt::OPT_AI_POLICY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( RobotsTxt::class, 'sanitize_policy' ),
				'default'           => array(),
			)
		);
		register_setting(
			'publishrank_seo_robots',
			RobotsTxt::OPT_SIGNALS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( RobotsTxt::class, 'sanitize_signals' ),
				'default'           => $defaults[ RobotsTxt::OPT_SIGNALS ],
			)
		);
		register_setting(
			'publishrank_seo_robots',
			RobotsTxt::OPT_AI_PATHS,
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ) {
					return implode( "\n", RobotsTxt::sanitize_paths( $v ) );
				},
				'default'           => '',
			)
		);
		register_setting(
			'publishrank_seo_robots',
			RobotsTxt::OPT_CUSTOM,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( RobotsTxt::class, 'clean_rules' ),
				'default'           => '',
			)
		);
	}

	public function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return; }
		$ver = Branding::version();
		wp_enqueue_style( 'publishrank-robots', Branding::asset_url( 'css/robots-admin.css' ), array( 'publishrank-seo-palette' ), $ver . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/css/robots-admin.css' ) );
		wp_enqueue_script( 'publishrank-robots', Branding::asset_url( 'js/robots-admin.js' ), array(), $ver . '.' . (int) @filemtime( PUBLISHRANK_SEO_PATH . 'assets/js/robots-admin.js' ), true );
		$kinds = array();
		foreach ( RobotsTxt::catalog() as $token => $bot ) {
			$kinds[ $token ] = $bot['kind']; }
		wp_localize_script(
			'publishrank-robots',
			'publishrankRobots',
			array(
				'url'   => esc_url_raw( rest_url( 'publishrank/v1/robots/preview' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'kinds' => $kinds,
				'i18n'  => array(
					'live'     => __( 'Live', 'publishrank' ),
					'updating' => __( 'Updating…', 'publishrank' ),
					'updated'  => __( 'Preview updated', 'publishrank' ),
					'failed'   => __( 'Could not refresh the preview', 'publishrank' ),
					'copied'   => __( 'Copied', 'publishrank' ),
					'copy'     => __( 'Copy', 'publishrank' ),
					'allowed'  => __( 'Allowed', 'publishrank' ),
					'blocked'  => __( 'Blocked', 'publishrank' ),
					'rule'     => __( 'Matching rule', 'publishrank' ),
					'noRule'   => __( 'No rule matches, so crawling is allowed.', 'publishrank' ),
					'group'    => __( 'Group', 'publishrank' ),
					'foreign'  => __( 'This URL is on a different host, so this file does not apply to it.', 'publishrank' ),
					'bytes'    => __( 'bytes', 'publishrank' ),
					'yours'    => __( 'Added by PublishRank', 'publishrank' ),
					'base'     => __( 'WordPress defaults and other plugins', 'publishrank' ),
				),
			)
		);
	}

	/** @param string $name Field name. */
	private function policy_select( $token, $current ) {
		$name = RobotsTxt::OPT_AI_POLICY . '[' . $token . ']';
		echo '<select name="' . esc_attr( $name ) . '" data-bot="' . esc_attr( $token ) . '" aria-label="' . esc_attr( $token ) . '">';
		foreach ( array(
			''      => __( 'Default', 'publishrank' ),
			'allow' => __( 'Allow', 'publishrank' ),
			'block' => __( 'Block', 'publishrank' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	private function toggle( $key, $label, $help, $default ) {
		echo '<p class="pr-rb-toggle"><input type="hidden" name="' . esc_attr( $key ) . '" value="0" /><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( (bool) get_option( $key, $default ), true, false ) . ' /> <strong>' . esc_html( $label ) . '</strong></label><span class="description">' . esc_html( $help ) . '</span></p>';
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$d       = RobotsTxt::defaults();
		$text    = RobotsTxt::preview();
		$issues  = RobotsTxt::analyze( $text );
		$policy  = RobotsTxt::policy();
		$signals = RobotsTxt::sanitize_signals( get_option( RobotsTxt::OPT_SIGNALS, $d[ RobotsTxt::OPT_SIGNALS ] ) );
		$marker  = strpos( $text, RobotsTxt::MARKER );
		$ours    = false === $marker ? -1 : substr_count( substr( $text, 0, $marker ), "\n" );
		echo '<div class="wrap publishrank-robots">';
		AdminAssets::header( __( 'Robots.txt', 'publishrank' ), __( 'Control which crawlers reach your newsroom, advertise your sitemaps and set AI usage rules, with a live preview before anything is saved.', 'publishrank' ) );
		settings_errors();
		?>
		<div class="pr-rb" id="pr-robots">
			<form method="post" action="options.php" id="pr-robots-form" class="pr-rb__form">
				<?php settings_fields( 'publishrank_seo_robots' ); ?>
				<input type="hidden" name="<?php echo esc_attr( RobotsTxt::OPT_LEGACY_AI ); ?>" value="0" />

				<section class="rhs-card">
					<h2><i class="fa-solid fa-toggle-on" aria-hidden="true"></i> <?php esc_html_e( 'Basics', 'publishrank' ); ?></h2>
					<?php
					$this->toggle( RobotsTxt::OPT_ENABLED, __( 'Let PublishRank manage robots.txt', 'publishrank' ), __( 'Adds the sections below to the virtual file WordPress serves. Turn off to leave it untouched.', 'publishrank' ), true );
					$this->toggle( RobotsTxt::OPT_SITEMAPS, __( 'Advertise sitemaps', 'publishrank' ), __( 'Adds the sitemap index and the Google News sitemap as Sitemap lines.', 'publishrank' ), true );
					$this->toggle( RobotsTxt::OPT_ENV_BLOCK, __( 'Block crawlers on staging and development', 'publishrank' ), __( 'When WP_ENVIRONMENT_TYPE is anything other than production, the file asks every crawler to stay away so test copies never get indexed.', 'publishrank' ), true );
					?>
				</section>

				<section class="rhs-card">
					<h2><i class="fa-solid fa-broom" aria-hidden="true"></i> <?php esc_html_e( 'Crawl-budget hygiene', 'publishrank' ); ?></h2>
					<p class="description"><?php esc_html_e( 'News sites publish fast, and crawlers waste visits on URLs that never rank. These rules keep Googlebot focused on articles.', 'publishrank' ); ?></p>
					<?php
					$this->toggle( RobotsTxt::OPT_LOW_VALUE, __( 'Block internal search, trackbacks and comment-reply URLs', 'publishrank' ), __( 'Stops /?s= search pages, /search/, trackback endpoints and ?replytocom= from being crawled.', 'publishrank' ), false );
					$this->toggle( RobotsTxt::OPT_TRACKING, __( 'Block campaign and click-id parameters', 'publishrank' ), __( 'Stops utm_*, fbclid, gclid and msclkid copies of every article from being crawled. Canonical tags still handle consolidation.', 'publishrank' ), false );
					$this->toggle( RobotsTxt::OPT_HOUSEKEEP, __( 'WordPress housekeeping', 'publishrank' ), __( 'Keeps crawlers away from xmlrpc.php, wp-login.php, ?author= user enumeration, the REST users list, comment feeds and the readme and license files.', 'publishrank' ), false );
					?>
				</section>

				<section class="rhs-card">
					<h2><i class="fa-solid fa-robot" aria-hidden="true"></i> <?php esc_html_e( 'AI crawlers', 'publishrank' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Choose per crawler. "Default" adds no rule. Training crawlers copy your archive into models; AI search crawlers decide whether your articles can be cited in AI answers.', 'publishrank' ); ?></p>
					<div class="pr-rb__presets" role="group" aria-label="<?php esc_attr_e( 'Quick presets', 'publishrank' ); ?>">
						<button type="button" class="button" data-preset="training"><?php esc_html_e( 'Block AI training only', 'publishrank' ); ?></button>
						<button type="button" class="button" data-preset="training+search"><?php esc_html_e( 'Block training and AI search', 'publishrank' ); ?></button>
						<button type="button" class="button" data-preset="all"><?php esc_html_e( 'Block all AI', 'publishrank' ); ?></button>
						<button type="button" class="button" data-preset="seo"><?php esc_html_e( 'Block SEO-tool crawlers', 'publishrank' ); ?></button>
						<button type="button" class="button" data-preset="clear"><?php esc_html_e( 'Clear choices', 'publishrank' ); ?></button>
					</div>
					<?php foreach ( RobotsTxt::kinds() as $kind => $label ) : ?>
						<h3 class="pr-rb__kind"><?php echo esc_html( $label ); ?></h3>
						<table class="widefat striped pr-rb__bots"><tbody>
						<?php
						foreach ( RobotsTxt::catalog() as $token => $bot ) :
							if ( $bot['kind'] !== $kind ) {
								continue; }
							?>
							<tr>
								<td class="pr-rb__bot"><code><?php echo esc_html( $token ); ?></code><span><?php echo esc_html( $bot['vendor'] ); ?></span></td>
								<td class="pr-rb__desc"><?php echo esc_html( $bot['desc'] ); ?></td>
								<td class="pr-rb__pick"><?php $this->policy_select( $token, isset( $policy[ $token ] ) ? $policy[ $token ] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody></table>
					<?php endforeach; ?>
					<h3 class="pr-rb__kind"><?php esc_html_e( 'Protected paths', 'publishrank' ); ?></h3>
					<p class="description"><?php esc_html_e( 'One path per line, for example /premium/ or /subscribers/. Every AI crawler that is not fully blocked is asked to stay out of these paths while still reading the rest of your site.', 'publishrank' ); ?></p>
					<textarea name="<?php echo esc_attr( RobotsTxt::OPT_AI_PATHS ); ?>" rows="4" class="large-text code" placeholder="/premium/&#10;/subscribers/"><?php echo esc_textarea( (string) get_option( RobotsTxt::OPT_AI_PATHS, '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Blocking is a request that well-behaved crawlers honour, not a technical barrier. It never affects Googlebot or Google News.', 'publishrank' ); ?></p>
				</section>

				<section class="rhs-card">
					<h2><i class="fa-solid fa-sliders" aria-hidden="true"></i> <?php esc_html_e( 'Content signals', 'publishrank' ); ?></h2>
					<p class="description"><?php esc_html_e( 'An emerging way to state how your content may be used, written as a Content-Signal line. Support is growing but not universal; leave on "Not set" if unsure.', 'publishrank' ); ?></p>
					<div class="pr-rb__signals">
					<?php
					$fields = array(
						'search'   => __( 'Search indexing', 'publishrank' ),
						'ai_input' => __( 'AI answers and citations', 'publishrank' ),
						'ai_train' => __( 'AI model training', 'publishrank' ),
					);
					foreach ( $fields as $key => $label ) {
						echo '<label>' . esc_html( $label ) . '<select name="' . esc_attr( RobotsTxt::OPT_SIGNALS . '[' . $key . ']' ) . '">';
						foreach ( array(
							''    => __( 'Not set', 'publishrank' ),
							'yes' => __( 'Allowed', 'publishrank' ),
							'no'  => __( 'Not allowed', 'publishrank' ),
						) as $value => $text_label ) {
							echo '<option value="' . esc_attr( $value ) . '"' . selected( $signals[ $key ], $value, false ) . '>' . esc_html( $text_label ) . '</option>';
						}
						echo '</select></label>';
					}
					?>
					</div>
				</section>

				<section class="rhs-card">
					<h2><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> <?php esc_html_e( 'Custom rules', 'publishrank' ); ?></h2>
					<textarea id="publishrank_robots_custom" name="<?php echo esc_attr( RobotsTxt::OPT_CUSTOM ); ?>" rows="7" class="large-text code" placeholder="User-agent: *&#10;Disallow: /private/"><?php echo esc_textarea( (string) get_option( RobotsTxt::OPT_CUSTOM, '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Supported lines: User-agent, Disallow, Allow, Crawl-delay, Content-Signal, Sitemap and # comments. Anything else is removed on save. Watch the checks on the right as you type.', 'publishrank' ); ?></p>
				</section>
				<?php submit_button( __( 'Save robots.txt settings', 'publishrank' ) ); ?>
			</form>

			<aside class="pr-rb__side" aria-live="polite">
				<section class="rhs-card pr-rb__preview">
					<div class="pr-rb__bar"><h2><i class="fa-solid fa-file-lines" aria-hidden="true"></i> <?php esc_html_e( 'What crawlers receive', 'publishrank' ); ?></h2>
						<span class="pr-live-pill" data-rb-pill><i class="pr-live-dot"></i><span><?php esc_html_e( 'Live', 'publishrank' ); ?></span></span></div>
					<p class="pr-rb__status" data-rb-status><?php esc_html_e( 'Reflects your current choices, saved or not.', 'publishrank' ); ?></p>
					<pre class="pr-rb__pre" data-rb-text>
					<?php
					foreach ( preg_split( '/\n/', rtrim( $text ) ) as $i => $line ) {
						echo '<span class="' . ( $ours >= 0 && $i >= $ours ? 'is-ours' : '' ) . '">' . esc_html( $line ) . "</span>\n"; }
					?>
					</pre>
					<div class="pr-rb__foot"><span data-rb-bytes><?php echo esc_html( number_format_i18n( strlen( $text ) ) . ' ' . __( 'bytes', 'publishrank' ) ); ?></span>
						<span><button type="button" class="button button-small" data-rb-copy><?php esc_html_e( 'Copy', 'publishrank' ); ?></button> <a class="button button-small" href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open live file', 'publishrank' ); ?></a></span></div>
				</section>

				<section class="rhs-card">
					<h2><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> <?php esc_html_e( 'Checks', 'publishrank' ); ?></h2>
					<ul class="pr-rb__issues" data-rb-issues>
						<?php foreach ( $issues as $issue ) : ?>
							<li class="is-<?php echo esc_attr( $issue['level'] ); ?>"><?php echo esc_html( $issue['msg'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</section>

				<section class="rhs-card">
					<h2><i class="fa-solid fa-table-cells" aria-hidden="true"></i> <?php esc_html_e( 'Crawlability matrix', 'publishrank' ); ?></h2>
					<div class="rhs-table-wrap"><table class="widefat pr-rb__matrix" data-rb-matrix></table></div>
				</section>

				<section class="rhs-card">
					<h2><i class="fa-solid fa-vial" aria-hidden="true"></i> <?php esc_html_e( 'Test a URL', 'publishrank' ); ?></h2>
					<div class="pr-rb__test">
						<input type="text" id="pr-test-url" placeholder="<?php echo esc_attr( home_url( '/example-article/' ) ); ?>" aria-label="<?php esc_attr_e( 'URL or path to test', 'publishrank' ); ?>" />
						<select id="pr-test-agent" aria-label="<?php esc_attr_e( 'Crawler', 'publishrank' ); ?>">
							<?php
							foreach ( array( 'Googlebot', 'Googlebot-News', 'Googlebot-Image', 'bingbot', 'GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'PerplexityBot' ) as $agent ) {
								echo '<option>' . esc_html( $agent ) . '</option>'; }
							?>
						</select>
					</div>
					<p class="pr-rb__result" data-rb-test hidden></p>
				</section>
			</aside>
		</div>
		</div>
		<?php
	}
}
