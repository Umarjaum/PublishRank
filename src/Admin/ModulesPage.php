<?php
/**
 * Features screen (formerly "SEO Modules"): switch PublishRank features on or off from one place.
 *
 * Toggles save instantly over AJAX. Without JavaScript the same cards submit as a normal form.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\ModuleSwitches;

defined( 'ABSPATH' ) || exit;

final class ModulesPage {
	const SLUG        = 'publishrank-modules';
	const AJAX_ACTION = 'publishrank_toggle_module';
	const SAVE_ACTION = 'publishrank_save_modules';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_toggle' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 1000 );
	}

	public function menu() {
		add_submenu_page( Branding::MENU_SLUG, __( 'Features', 'publishrank' ), __( 'Features', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function enqueue() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! AdminShell::is_shell_page( $page ) && Branding::MENU_SLUG !== $page ) {
			return;
		}
		$css = PUBLISHRANK_SEO_PATH . 'assets/css/modules.css';
		$js  = PUBLISHRANK_SEO_PATH . 'assets/js/modules.js';
		wp_enqueue_style( 'publishrank-modules', Branding::asset_url( 'css/modules.css' ), array(), Branding::version() . '.' . ( file_exists( $css ) ? (string) filemtime( $css ) : '0' ) );
		wp_enqueue_script( 'publishrank-modules', Branding::asset_url( 'js/modules.js' ), array(), Branding::version() . '.' . ( file_exists( $js ) ? (string) filemtime( $js ) : '0' ), true );
		wp_localize_script(
			'publishrank-modules',
			'publishrankModules',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'i18n'    => array(
					'on'        => __( 'On', 'publishrank' ),
					'off'       => __( 'Off', 'publishrank' ),
					'saving'    => __( 'Saving…', 'publishrank' ),
					/* translators: %s: module name. */
					'enabled'   => __( '%s turned on.', 'publishrank' ),
					/* translators: %s: module name. */
					'disabled'  => __( '%s turned off.', 'publishrank' ),
					'failed'    => __( 'Could not save. Check your connection and try again.', 'publishrank' ),
					/* translators: 1: active modules, 2: total modules. */
					'count'     => __( '%1$d of %2$d on', 'publishrank' ),
					'noResults' => __( 'No features match.', 'publishrank' ),
				),
			)
		);
	}

	/** Instant toggle from a card switch. */
	public function ajax_toggle() {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'publishrank' ) ), 403 );
		}
		$key = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$on  = isset( $_POST['enabled'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
		if ( ! ModuleSwitches::exists( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown module.', 'publishrank' ) ), 400 );
		}
		$lock = ModuleSwitches::lock( $key );
		if ( $lock ) {
			/* translators: %s: plugin name, e.g. bbPress. */
			wp_send_json_error( array( 'message' => sprintf( __( 'Locked until %s is installed and active.', 'publishrank' ), $lock['plugin'] ) ), 409 );
		}
		ModuleSwitches::set( $key, $on );
		wp_send_json_success(
			array(
				'module'  => $key,
				'enabled' => ModuleSwitches::is_on( $key ),
				'active'  => ModuleSwitches::active_count(),
				'total'   => count( ModuleSwitches::definitions() ),
			)
		);
	}

	/** No-JavaScript fallback: save every switch from the submitted form. */
	public function save() {
		AdminAssets::guard( self::SAVE_ACTION );
		$posted = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['modules'] ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$scope  = isset( $_POST['scope'] ) && is_array( $_POST['scope'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['scope'] ) ) : array_keys( ModuleSwitches::definitions() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( array_keys( ModuleSwitches::definitions() ) as $key ) {
			if ( in_array( $key, $scope, true ) ) {
				ModuleSwitches::set( $key, in_array( $key, $posted, true ) );
			}
		}
		$return = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : self::SLUG;
		$allowed = array( Branding::MENU_SLUG, self::SLUG, 'publishrank-sitemap-health' );
		AdminAssets::done( in_array( $return, $allowed, true ) ? $return : self::SLUG, 'success', __( 'Features saved.', 'publishrank' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$modules = ModuleSwitches::definitions();
		$active  = ModuleSwitches::active_count();
		$total   = count( $modules );

		echo '<div class="wrap pr-modules-wrap">';
		AdminAssets::header( __( 'Features', 'publishrank' ), __( 'Turn PublishRank features on or off. Changes save instantly.', 'publishrank' ) );
		AdminAssets::render_flash();
		echo '<div class="pr-modules-layout">';
		AdminShell::nav( 'modules' );

		echo '<div class="pr-modules-main">';
		echo '<div class="pr-modules-intro"><div><p class="pr-shell-kicker">' . esc_html__( 'Control center', 'publishrank' ) . '</p><h2 class="pr-modules-title">' . esc_html__( 'Features', 'publishrank' ) . '</h2>';
		echo '<p class="pr-modules-lead">' . esc_html__( 'Choose which PublishRank tools run on your site. Switch a feature off and its output stops right away; switch it back on at any time. Use Configure on a card to fine-tune that feature once it is on.', 'publishrank' ) . '</p></div>';
		echo '<span class="pr-modules-count" data-pr-modules-count data-total="' . esc_attr( (string) $total ) . '">' . esc_html( sprintf( /* translators: 1: features switched on, 2: total features. */ __( '%1$d of %2$d on', 'publishrank' ), $active, $total ) ) . '</span></div>';

		echo '<div class="pr-modules-toolbar">';
		echo '<div class="pr-modules-filter" role="group" aria-label="' . esc_attr__( 'Show features', 'publishrank' ) . '">';
		foreach ( array( 'all' => __( 'All', 'publishrank' ), 'on' => __( 'On', 'publishrank' ), 'off' => __( 'Off', 'publishrank' ) ) as $value => $label ) {
			echo '<button type="button" class="pr-modules-filter__btn' . ( 'all' === $value ? ' is-active' : '' ) . '" data-pr-modules-filter="' . esc_attr( $value ) . '" aria-pressed="' . ( 'all' === $value ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</button>';
		}
		echo '</div>';
		echo '<label class="screen-reader-text" for="pr-modules-search">' . esc_html__( 'Search features', 'publishrank' ) . '</label>';
		echo '<span class="pr-modules-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="pr-modules-search" placeholder="' . esc_attr__( 'Search features…', 'publishrank' ) . '" autocomplete="off" data-pr-modules-search /></span>';
		echo '</div>';

		self::render_form( self::SLUG );
		echo '<p class="pr-modules-empty" data-pr-modules-empty hidden>' . esc_html__( 'No features match.', 'publishrank' ) . '</p>';
		echo '</div></div>';
		echo '<div class="pr-modules-toast" data-pr-modules-toast role="status" aria-live="polite"></div>';
		echo '</div>';
	}

	/** Compact module section for the main dashboard. */
	public static function render_dashboard_section() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$total  = count( ModuleSwitches::definitions() );
		$active = ModuleSwitches::active_count();
		echo '<section class="pr-modules-dash" aria-labelledby="pr-modules-dash-title">';
		echo '<div class="pr-modules-dash__head"><div><p class="pr-dashboard-kicker">' . esc_html__( 'Control center', 'publishrank' ) . '</p><h2 id="pr-modules-dash-title">' . esc_html__( 'Features', 'publishrank' ) . '</h2>';
		echo '<p class="pr-modules-lead">' . esc_html__( 'Switch features on or off. Changes save instantly.', 'publishrank' ) . '</p></div>';
		echo '<div class="pr-modules-dash__meta"><span class="pr-modules-count" data-pr-modules-count data-total="' . esc_attr( (string) $total ) . '">' . esc_html( sprintf( /* translators: 1: active modules, 2: total modules. */ __( '%1$d of %2$d on', 'publishrank' ), $active, $total ) ) . '</span>';
		echo '<a class="pr-modules-link" href="' . esc_url( Branding::page_url( self::SLUG ) ) . '">' . esc_html__( 'Manage features', 'publishrank' ) . ' <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div></div>';
		self::render_form( Branding::MENU_SLUG, true );
		echo '<div class="pr-modules-toast" data-pr-modules-toast role="status" aria-live="polite"></div>';
		echo '</section>';
	}

	/** The module cards inside a form that also works without JavaScript. */
	/**
	 * Module cards inside a form that also works without JavaScript.
	 *
	 * @param string   $return_page Page slug to return to after a no-JS save.
	 * @param bool     $compact     Compact dashboard cards.
	 * @param string[] $only        Limit to these module ids (the no-JS save then only touches these).
	 */
	public static function render_form( $return_page, $compact = false, array $only = array() ) {
		$modules = ModuleSwitches::definitions();
		if ( $only ) {
			$modules = array_intersect_key( $modules, array_flip( $only ) );
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pr-modules-form" data-pr-modules>';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		echo '<input type="hidden" name="return_page" value="' . esc_attr( $return_page ) . '" />';
		wp_nonce_field( self::SAVE_ACTION );
		foreach ( array_keys( $modules ) as $key ) {
			echo '<input type="hidden" name="scope[]" value="' . esc_attr( (string) $key ) . '" />';
		}
		if ( $compact ) {
			echo '<div class="pr-modules-grid pr-modules-grid--compact">';
			foreach ( $modules as $key => $module ) {
				self::card( (string) $key, $module, $compact );
			}
			echo '</div>';
		} else {
			self::render_groups( $modules );
		}
		echo '<p class="pr-modules-save hide-if-js"><button type="submit" class="button button-primary">' . esc_html__( 'Save modules', 'publishrank' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Full Features screen: cards grouped by purpose. Integrations come last, and the ones that depend on another plugin (bbPress, BuddyPress) sit at the very bottom.
	 *
	 * @param array<string,array> $modules Module definitions.
	 */
	private static function render_groups( array $modules ) {
		$labels  = ModuleSwitches::groups();
		$buckets = array();
		foreach ( $modules as $key => $module ) {
			$group = isset( $module['group'] ) && isset( $labels[ $module['group'] ] ) ? (string) $module['group'] : 'other';
			$buckets[ $group ][ $key ] = $module;
		}
		$labels['other'] = __( 'Other', 'publishrank' );
		foreach ( $labels as $group => $label ) {
			if ( empty( $buckets[ $group ] ) ) {
				continue;
			}
			$items = $buckets[ $group ];
			if ( 'integrations' === $group ) {
				$free  = array();
				$bound = array();
				foreach ( $items as $key => $module ) {
					if ( in_array( (string) $key, array( 'bbpress', 'buddypress' ), true ) ) {
						$bound[ $key ] = $module;
					} else {
						$free[ $key ] = $module;
					}
				}
				$items = $free + $bound; // bbPress, then BuddyPress, always last.
			}
			$heading_id = 'pr-modules-group-' . sanitize_html_class( $group );
			echo '<section class="pr-modules-group" data-pr-modules-group aria-labelledby="' . esc_attr( $heading_id ) . '">';
			echo '<h3 class="pr-modules-group__title" id="' . esc_attr( $heading_id ) . '">' . esc_html( $label ) . '</h3>';
			echo '<div class="pr-modules-grid">';
			foreach ( $items as $key => $module ) {
				self::card( (string) $key, $module, false );
			}
			echo '</div></section>';
		}
	}

	private static function card( $key, array $module, $compact ) {
		$lock    = ModuleSwitches::lock( $key );
		$locked  = null !== $lock;
		$on      = ! $locked && ModuleSwitches::is_on( $key );
		$id      = 'pr-module-' . sanitize_html_class( $key ) . ( $compact ? '-dash' : '' );
		$title   = isset( $module['title'] ) ? (string) $module['title'] : $key;
		$desc    = isset( $module['description'] ) ? (string) $module['description'] : '';
		$icon    = isset( $module['icon'] ) ? sanitize_html_class( (string) $module['icon'] ) : 'fa-puzzle-piece';
		$page    = isset( $module['page'] ) ? (string) $module['page'] : '';
		$search  = strtolower( $title . ' ' . $desc );
		echo '<article class="pr-module' . ( $on ? ' is-on' : '' ) . ( $locked ? ' is-locked' : '' ) . '" data-pr-module-card="' . esc_attr( $key ) . '" data-search="' . esc_attr( $search ) . '">';
		echo '<span class="pr-module__icon" aria-hidden="true"><i class="fa-solid ' . esc_attr( $icon ) . '"></i></span>';
		echo '<div class="pr-module__body">';
		echo '<h3 class="pr-module__title" id="' . esc_attr( $id ) . '-title">' . esc_html( $title ) . '</h3>';
		if ( ! $compact && '' !== $desc ) {
			echo '<p class="pr-module__desc" id="' . esc_attr( $id ) . '-desc">' . esc_html( $desc ) . '</p>';
		}
		if ( $locked ) {
			$plugin_name = (string) $lock['plugin'];
			if ( ! empty( $lock['installed'] ) ) {
				/* translators: %s: plugin name. */
				$note = sprintf( __( 'Locked: %s is installed but not active. Activate it to unlock this feature.', 'publishrank' ), $plugin_name );
				$link = current_user_can( 'activate_plugins' ) ? '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Open Plugins', 'publishrank' ) . '</a>' : '';
			} else {
				/* translators: %s: plugin name. */
				$note = sprintf( __( 'Locked: %s is not installed. Install and activate it to unlock this feature.', 'publishrank' ), $plugin_name );
				$link = current_user_can( 'install_plugins' ) ? '<a href="' . esc_url( admin_url( 'plugin-install.php?s=' . rawurlencode( $plugin_name ) . '&tab=search&type=term' ) ) . '">' . esc_html__( 'Get the plugin', 'publishrank' ) . '</a>' : '';
			}
			echo '<p class="pr-module__lock"><i class="fa-solid fa-lock" aria-hidden="true"></i><span>' . esc_html( $note ) . ' ' . $link . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is built from escaped parts.
		}
		echo '<div class="pr-module__meta"><span class="pr-module__state" data-pr-module-state>' . ( $locked ? esc_html__( 'Locked', 'publishrank' ) : ( $on ? esc_html__( 'On', 'publishrank' ) : esc_html__( 'Off', 'publishrank' ) ) ) . '</span>';
		if ( '' !== $page && ! $locked ) {
			echo '<a class="pr-module__configure" href="' . esc_url( Branding::page_url( $page ) ) . '">' . esc_html__( 'Configure', 'publishrank' ) . '<span class="screen-reader-text"> ' . esc_html( $title ) . '</span></a>';
		}
		echo '</div></div>';
		echo '<label class="pr-switch" for="' . esc_attr( $id ) . '">';
		echo '<input type="checkbox" role="switch" id="' . esc_attr( $id ) . '" name="modules[' . esc_attr( $key ) . ']" value="1" data-pr-module="' . esc_attr( $key ) . '" data-label="' . esc_attr( $title ) . '" aria-labelledby="' . esc_attr( $id ) . '-title"' . ( ! $compact && '' !== $desc ? ' aria-describedby="' . esc_attr( $id ) . '-desc"' : '' ) . checked( $on, true, false ) . disabled( $locked, true, false ) . ' />';
		echo '<span class="pr-switch__track" aria-hidden="true"><span class="pr-switch__thumb"></span></span>';
		echo '</label>';
		echo '</article>';
	}
}
