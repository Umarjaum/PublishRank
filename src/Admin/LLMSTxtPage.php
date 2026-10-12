<?php
/** Configuration and explanation screen for PublishRank llms.txt. */
namespace PublishRankSEO\Admin;

use PublishRankSEO\AI\LLMSTxt;
use PublishRankSEO\Core\Branding;

defined( 'ABSPATH' ) || exit;

final class LLMSTxtPage {
	const SLUG = 'publishrank-llms-txt';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 18 );
		add_action( 'admin_post_publishrank_llms_save', array( $this, 'save' ) );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'AI & llms.txt', 'publishrank' ), __( 'AI & llms.txt', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function save() {
		AdminAssets::guard( 'publishrank_llms_save' );
		$fields = array(
			LLMSTxt::OPTION_ENABLED      => ! empty( $_POST[ LLMSTxt::OPTION_ENABLED ] ),
			LLMSTxt::OPTION_TITLE        => sanitize_text_field( wp_unslash( $_POST[ LLMSTxt::OPTION_TITLE ] ?? '' ) ),
			LLMSTxt::OPTION_SUMMARY      => sanitize_textarea_field( wp_unslash( $_POST[ LLMSTxt::OPTION_SUMMARY ] ?? '' ) ),
			LLMSTxt::OPTION_INTRO        => sanitize_textarea_field( wp_unslash( $_POST[ LLMSTxt::OPTION_INTRO ] ?? '' ) ),
			LLMSTxt::OPTION_HOME         => ! empty( $_POST[ LLMSTxt::OPTION_HOME ] ),
			LLMSTxt::OPTION_POSTS        => ! empty( $_POST[ LLMSTxt::OPTION_POSTS ] ),
			LLMSTxt::OPTION_PAGES        => ! empty( $_POST[ LLMSTxt::OPTION_PAGES ] ),
			LLMSTxt::OPTION_CATEGORIES   => ! empty( $_POST[ LLMSTxt::OPTION_CATEGORIES ] ),
			LLMSTxt::OPTION_POST_COUNT   => max( 1, min( 100, absint( $_POST[ LLMSTxt::OPTION_POST_COUNT ] ?? 20 ) ) ),
			LLMSTxt::OPTION_CUSTOM       => $this->sanitize_custom( wp_unslash( $_POST[ LLMSTxt::OPTION_CUSTOM ] ?? '' ) ),
			LLMSTxt::OPTION_FULL_ENABLED => ! empty( $_POST[ LLMSTxt::OPTION_FULL_ENABLED ] ),
			LLMSTxt::OPTION_FULL_WORDS   => max( 1000, min( 10000, absint( $_POST[ LLMSTxt::OPTION_FULL_WORDS ] ?? 8000 ) ) ),
		);
		foreach ( $fields as $key => $value ) { update_option( $key, $value ); }
		flush_rewrite_rules( false );
		AdminAssets::done( self::SLUG, 'success', __( 'AI and llms.txt settings saved.', 'publishrank' ) );
	}

	public function render() {
		AdminAssets::render_flash();
		AdminAssets::header( __( 'AI & llms.txt', 'publishrank' ), __( 'Give compatible AI agents a clear, curated map of your publisher content.', 'publishrank' ) );
		$enabled = (bool) get_option( LLMSTxt::OPTION_ENABLED, 1 );
		$url     = home_url( '/llms.txt' );
		$full    = home_url( '/llms-full.txt' );
		?>
		<div class="rhs-ai-intro rhs-card">
			<div class="rhs-ai-intro__icon"><span class="dashicons dashicons-editor-code" aria-hidden="true"></span></div>
			<div><h2><?php esc_html_e( 'What is llms.txt?', 'publishrank' ); ?></h2>
			<p><?php esc_html_e( 'llms.txt is a plain Markdown file that gives compatible AI agents and retrieval systems a short, curated map of the most useful resources on your website. It helps them understand what your site is and where important content lives.', 'publishrank' ); ?></p>
			<p><strong><?php esc_html_e( 'Important:', 'publishrank' ); ?></strong> <?php esc_html_e( 'It is not a replacement for robots.txt, does not grant or deny crawler access, and does not guarantee AI or search-engine usage. Access policies remain in robots.txt and other platform policies.', 'publishrank' ); ?></p></div>
		</div>

		<div class="rhs-grid rhs-ai-links">
			<div class="rhs-card"><p class="rhs-label"><?php esc_html_e( 'Published file', 'publishrank' ); ?></p><code><?php echo esc_html( $url ); ?></code><p><a class="button button-small" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open llms.txt', 'publishrank' ); ?></a></p></div>
			<div class="rhs-card"><p class="rhs-label"><?php esc_html_e( 'Optional extended file', 'publishrank' ); ?></p><code><?php echo esc_html( $full ); ?></code><p><a class="button button-small" href="<?php echo esc_url( $full ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open llms-full.txt', 'publishrank' ); ?></a></p></div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="publishrank_llms_save" />
			<?php wp_nonce_field( 'publishrank_llms_save' ); ?>
			<div class="rhs-card"><h2><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span> <?php esc_html_e( 'Core configuration', 'publishrank' ); ?></h2>
				<p><label><input type="checkbox" name="<?php echo esc_attr( LLMSTxt::OPTION_ENABLED ); ?>" value="1" <?php checked( $enabled ); ?> /> <strong><?php esc_html_e( 'Enable llms.txt', 'publishrank' ); ?></strong></label><br /><span class="description"><?php esc_html_e( 'Publishes /llms.txt on your domain. Enabled by default, but you can disable it at any time.', 'publishrank' ); ?></span></p>
				<p><label><strong><?php esc_html_e( 'Site / project name', 'publishrank' ); ?></strong><br /><input class="large-text" type="text" name="<?php echo esc_attr( LLMSTxt::OPTION_TITLE ); ?>" value="<?php echo esc_attr( get_option( LLMSTxt::OPTION_TITLE, get_bloginfo( 'name' ) ) ); ?>" /></label></p>
				<p><label><strong><?php esc_html_e( 'Short summary', 'publishrank' ); ?></strong><br /><textarea class="large-text" rows="3" name="<?php echo esc_attr( LLMSTxt::OPTION_SUMMARY ); ?>"><?php echo esc_textarea( get_option( LLMSTxt::OPTION_SUMMARY, get_bloginfo( 'description' ) ) ); ?></textarea></label><br /><span class="description"><?php esc_html_e( 'Write one or two sentences explaining who you are, what the site publishes, and its authority/topic.', 'publishrank' ); ?></span></p>
				<p><label><strong><?php esc_html_e( 'Additional context', 'publishrank' ); ?></strong><br /><textarea class="large-text" rows="4" name="<?php echo esc_attr( LLMSTxt::OPTION_INTRO ); ?>"><?php echo esc_textarea( get_option( LLMSTxt::OPTION_INTRO, '' ) ); ?></textarea></label><br /><span class="description"><?php esc_html_e( 'Optional context for compatible agents. Do not put access rules, secrets, API keys, or private information here.', 'publishrank' ); ?></span></p>
			</div>

			<div class="rhs-card"><h2><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> <?php esc_html_e( 'Automatic content sections', 'publishrank' ); ?></h2>
				<p><?php esc_html_e( 'PublishRank builds the Markdown link lists automatically from your public WordPress content.', 'publishrank' ); ?></p>
				<?php $this->check( LLMSTxt::OPTION_HOME, __( 'Homepage', 'publishrank' ), __( 'Include the official homepage.', 'publishrank' ) ); ?>
				<?php $this->check( LLMSTxt::OPTION_POSTS, __( 'Recent articles', 'publishrank' ), __( 'Include your latest published posts.', 'publishrank' ) ); ?>
				<?php $this->check( LLMSTxt::OPTION_PAGES, __( 'Pages', 'publishrank' ), __( 'Include published pages such as About, Contact and Editorial Policy.', 'publishrank' ) ); ?>
				<?php $this->check( LLMSTxt::OPTION_CATEGORIES, __( 'Categories', 'publishrank' ), __( 'Include public category hubs so agents can understand topic structure.', 'publishrank' ) ); ?>
				<p><label><strong><?php esc_html_e( 'Recent articles count', 'publishrank' ); ?></strong><br /><input type="number" min="1" max="100" name="<?php echo esc_attr( LLMSTxt::OPTION_POST_COUNT ); ?>" value="<?php echo esc_attr( get_option( LLMSTxt::OPTION_POST_COUNT, 20 ) ); ?>" /></label> <span class="description"><?php esc_html_e( '1–100. Keep the concise file focused; quality is more useful than dumping the whole site.', 'publishrank' ); ?></span></p>
			</div>

			<div class="rhs-card"><h2><span class="dashicons dashicons-editor-ul" aria-hidden="true"></span> <?php esc_html_e( 'Curated resources', 'publishrank' ); ?></h2>
				<p><?php esc_html_e( 'Add important resources that PublishRank cannot infer reliably. One resource per line:', 'publishrank' ); ?></p>
				<code>Section | Link title | https://example.com/page | Short description</code>
				<textarea class="large-text code" rows="8" name="<?php echo esc_attr( LLMSTxt::OPTION_CUSTOM ); ?>" placeholder="Editorial Policy | Editorial Policy | https://example.com/editorial-policy/ | How editorial decisions and corrections are handled."><?php echo esc_textarea( get_option( LLMSTxt::OPTION_CUSTOM, '' ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Recommended for authoritative pages such as About, Editorial Policy, Fact-Checking Policy, Contact, topic hubs, datasets, documentation or newsroom standards.', 'publishrank' ); ?></p>
			</div>

			<div class="rhs-card"><h2><span class="dashicons dashicons-media-document" aria-hidden="true"></span> <?php esc_html_e( 'Optional llms-full.txt', 'publishrank' ); ?></h2>
				<p><?php esc_html_e( 'An extended companion can expose more article text to clients that explicitly support it. It is optional and should be treated as a convenience resource, not as an access-control mechanism.', 'publishrank' ); ?></p>
				<?php $this->check( LLMSTxt::OPTION_FULL_ENABLED, __( 'Enable /llms-full.txt', 'publishrank' ), __( 'Generate an extended Markdown resource.', 'publishrank' ) ); ?>
				<p><label><strong><?php esc_html_e( 'Maximum words', 'publishrank' ); ?></strong><br /><input type="number" min="1000" max="10000" name="<?php echo esc_attr( LLMSTxt::OPTION_FULL_WORDS ); ?>" value="<?php echo esc_attr( get_option( LLMSTxt::OPTION_FULL_WORDS, 8000 ) ); ?>" /></label> <span class="description"><?php esc_html_e( 'Maximum 10,000 words per generated extended resource.', 'publishrank' ); ?></span></p>
			</div>
			<?php submit_button( __( 'Save AI configuration', 'publishrank' ), 'primary' ); ?>
		</form>

		<div class="rhs-card rhs-ai-guide"><h2><span class="dashicons dashicons-editor-help" aria-hidden="true"></span> <?php esc_html_e( 'How should a publisher configure this?', 'publishrank' ); ?></h2>
			<ol>
				<li><strong><?php esc_html_e( 'Describe the publication clearly.', 'publishrank' ); ?></strong> <?php esc_html_e( 'Use the summary to state your publication name, geographic/topic focus, and what kind of authoritative information you publish.', 'publishrank' ); ?></li>
				<li><strong><?php esc_html_e( 'Keep the concise file curated.', 'publishrank' ); ?></strong> <?php esc_html_e( 'Include your homepage, important pages, topic hubs and a sensible number of recent articles rather than every URL.', 'publishrank' ); ?></li>
				<li><strong><?php esc_html_e( 'Add editorial authority pages.', 'publishrank' ); ?></strong> <?php esc_html_e( 'Editorial policy, corrections policy, fact-checking methodology, About and contact information are useful context for agents.', 'publishrank' ); ?></li>
				<li><strong><?php esc_html_e( 'Do not put private data in it.', 'publishrank' ); ?></strong> <?php esc_html_e( 'Everything published here is public website content.', 'publishrank' ); ?></li>
				<li><strong><?php esc_html_e( 'Remember what it does not do.', 'publishrank' ); ?></strong> <?php esc_html_e( 'It does not replace robots.txt and it does not force an AI system to fetch or cite your content.', 'publishrank' ); ?></li>
			</ol>
		</div>
		<?php
	}

	private function check( $key, $label, $description ) {
		printf( '<p><label><input type="checkbox" name="%1$s" value="1" %2$s /> <strong>%3$s</strong></label><br /><span class="description">%4$s</span></p>', esc_attr( $key ), checked( get_option( $key, 1 ), 1, false ), esc_html( $label ), esc_html( $description ) );
	}

	private function sanitize_custom( $value ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) >= 3 && '' !== $parts[0] && '' !== $parts[1] && wp_http_validate_url( $parts[2] ) ) {
				$lines[] = sanitize_text_field( $parts[0] ) . ' | ' . sanitize_text_field( $parts[1] ) . ' | ' . esc_url_raw( $parts[2] ) . ( isset( $parts[3] ) && '' !== $parts[3] ? ' | ' . sanitize_text_field( $parts[3] ) : '' );
			}
		}
		return implode( "\n", $lines );
	}
}
