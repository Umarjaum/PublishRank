<?php
/**
 * Central registry of PublishRank capabilities.
 *
 * Every PublishRank screen, handler, REST route and CLI command asks this class whether the current
 * user may act, through Capabilities::can( 'manage_redirects' ). Capabilities are stored on real WordPress
 * roles (WP_Role::add_cap), so third-party role editors list and edit them. Administrators (and network
 * super admins) always pass, which makes a lock-out impossible. Per-user grants and denials are stored in
 * user meta and applied through the user_has_cap filter.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class Capabilities {
	const PREFIX        = 'publishrank_';
	const VERSION       = 5;
	const VERSION_OPT   = 'publishrank_caps_version';
	const SNAPSHOT_OPT  = 'publishrank_caps_role_snapshot';
	const AUDIT_OPT     = 'publishrank_caps_audit';
	const USER_META     = 'publishrank_cap_overrides';
	const AUDIT_MAX     = 500;
	const NEWSROOM_OPT  = 'uj_newsroom_role_caps';

	/** Legacy Newsroom capability names are kept so existing role assignments keep working. */
	const LEGACY = array(
		'newsroom_access' => 'uj_newsroom_access',
		'newsroom_manage' => 'uj_newsroom_manage',
		'newsroom_create' => 'uj_newsroom_create_story',
		'setup_wizard'    => 'uj_publishrank_setup_wizard',
	);

	/** @var bool */
	private static $registered = false;

	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_has_cap' ), 10, 4 );
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		// Allow role editors (Members, User Role Editor, ...) to group our capabilities.
		add_filter( 'members_get_capabilities', array( __CLASS__, 'all_cap_names' ) );
		add_filter( 'ure_full_capabilites', array( __CLASS__, 'all_cap_names' ) );
		// Settings API screens post to options.php, which defaults to manage_options.
		foreach ( self::option_groups() as $group => $key ) {
			add_filter(
				'option_page_capability_' . $group,
				static function () use ( $key ) {
					return Capabilities::cap( $key );
				}
			);
		}
	}

	/**
	 * Capability definitions keyed by short name.
	 *
	 * @return array<string,array{label:string,group:string,desc:string}>
	 */
	public static function definitions() {
		$d = array(
			'manage_settings'      => array( __( 'Manage general settings', 'publishrank' ), 'core', __( 'Titles, meta defaults, identity, setup wizard and import/export.', 'publishrank' ) ),
			'manage_modules'       => array( __( 'Turn features on or off', 'publishrank' ), 'core', __( 'The Features screen.', 'publishrank' ) ),
			'manage_roles'         => array( __( 'Manage roles and permissions', 'publishrank' ), 'core', __( 'This permission matrix, presets and per-user overrides.', 'publishrank' ) ),
			'edit_seo'             => array( __( 'Edit SEO fields on posts', 'publishrank' ), 'content', __( 'The SEO panel in the editor for posts the user can already edit.', 'publishrank' ) ),
			'bulk_edit'            => array( __( 'Bulk-edit SEO', 'publishrank' ), 'content', __( 'Publisher toolkit bulk editing and the Content Graph.', 'publishrank' ) ),
			'manage_media_links'   => array( __( 'Manage media links', 'publishrank' ), 'content', __( 'Media Link Manager: scan media references, relocate files, change media URLs, roll back changes and set the media host.', 'publishrank' ) ),
			'manage_schema'        => array( __( 'Manage schema', 'publishrank' ), 'discovery', __( 'Structured data defaults and the schema library.', 'publishrank' ) ),
			'manage_sitemaps'      => array( __( 'Manage sitemaps', 'publishrank' ), 'discovery', __( 'Sitemap Hub, news sitemap and sitemap cache.', 'publishrank' ) ),
			'manage_robots'        => array( __( 'Manage robots.txt and crawl rules', 'publishrank' ), 'discovery', __( 'Robots.txt, crawl optimization and breadcrumbs.', 'publishrank' ) ),
			'manage_indexing'      => array( __( 'Manage indexing services', 'publishrank' ), 'discovery', __( 'IndexNow, Search Console, Baidu and search-engine submission.', 'publishrank' ) ),
			'manage_feeds'         => array( __( 'Manage RSS feeds', 'publishrank' ), 'discovery', __( 'Feed content, JSON Feed, WebSub and feed analytics.', 'publishrank' ) ),
			'manage_navigation'    => array( __( 'Manage site navigation', 'publishrank' ), 'discovery', __( 'Important pages and the navigation list.', 'publishrank' ) ),
			'manage_ai'            => array( __( 'Manage AI search visibility', 'publishrank' ), 'ai', __( 'AI crawler rules, Markdown delivery, llms.txt and the citation tracker.', 'publishrank' ) ),
			'view_ai_log'          => array( __( 'View AI bot log and citations', 'publishrank' ), 'ai', __( 'Read-only access to verified AI-bot visits and citation results.', 'publishrank' ) ),
			'manage_redirects'     => array( __( 'Manage redirects', 'publishrank' ), 'maintain', __( 'Create, import, edit, roll back and delete redirect rules.', 'publishrank' ) ),
			'view_404'             => array( __( 'View the 404 monitor', 'publishrank' ), 'maintain', __( 'Read-only list of broken URLs.', 'publishrank' ) ),
			'manage_404'           => array( __( 'Manage the 404 monitor', 'publishrank' ), 'maintain', __( 'Ignore, delete, export and purge 404 entries and noise rules.', 'publishrank' ) ),
			'manage_integrations'  => array( __( 'Manage integrations', 'publishrank' ), 'maintain', __( 'Mastodon, Preferred Sources, Local SEO, verification and third-party plugins.', 'publishrank' ) ),
			'view_reports'         => array( __( 'View reports', 'publishrank' ), 'maintain', __( 'Dashboard, analytics, insights, SEO score, diagnostics and change history.', 'publishrank' ) ),
			'manage_security'      => array( __( 'Manage Access Shield', 'publishrank' ), 'security', __( 'Login address, hardening, brute-force rules, CAPTCHA, two-factor and Traffic Guard.', 'publishrank' ) ),
			'view_security_log'    => array( __( 'View the security log', 'publishrank' ), 'security', __( 'Failed logins, lockouts and security events.', 'publishrank' ) ),
			'newsroom_access'      => array( __( 'Use the Newsroom', 'publishrank' ), 'newsroom', __( 'See the Newsroom and stories assigned to the user.', 'publishrank' ) ),
			'newsroom_create'      => array( __( 'Create stories', 'publishrank' ), 'newsroom', __( 'Pitch and create new stories.', 'publishrank' ) ),
			'newsroom_edit_any'    => array( __( 'Edit any story', 'publishrank' ), 'newsroom', __( 'Edit stories not assigned to the user.', 'publishrank' ) ),
			'newsroom_assign'      => array( __( 'Assign reporters and editors', 'publishrank' ), 'newsroom', __( 'Change who works on a story.', 'publishrank' ) ),
			'newsroom_publish_handoff' => array( __( 'Hand stories off for publishing', 'publishrank' ), 'newsroom', __( 'Create the real WordPress post from a story.', 'publishrank' ) ),
			'newsroom_delete'      => array( __( 'Delete stories', 'publishrank' ), 'newsroom', __( 'Move stories to the trash and delete them permanently.', 'publishrank' ) ),
			'newsroom_restore'     => array( __( 'Restore stories', 'publishrank' ), 'newsroom', __( 'Restore stories from the trash.', 'publishrank' ) ),
			'newsroom_manage'      => array( __( 'Manage the Newsroom', 'publishrank' ), 'newsroom', __( 'Workflow stages, desks, every story and configuration.', 'publishrank' ) ),
			'setup_wizard'         => array( __( 'Run the setup wizard', 'publishrank' ), 'core', __( 'First-run setup.', 'publishrank' ) ),
			'manage_branding'      => array( __( 'Manage site identity and logo', 'publishrank' ), 'core', __( 'Site title, tagline, description, logo and site icon (kept in sync with WordPress).', 'publishrank' ) ),
			'manage_theme_masking' => array( __( 'Manage theme SEO and masking', 'publishrank' ), 'security', __( 'Theme SEO cleanup, theme masking and the fingerprint scanner.', 'publishrank' ) ),
			'manage_woocommerce_seo' => array( __( 'Manage WooCommerce SEO', 'publishrank' ), 'maintain', __( 'Product schema, templates, store rules, product audit and merchant feeds.', 'publishrank' ) ),
			'view_analytics'       => array( __( 'View visitor analytics', 'publishrank' ), 'maintain', __( 'First-party traffic analytics, live visitors and CSV export.', 'publishrank' ) ),
			'view_news'            => array( __( 'Read News & What\'s New', 'publishrank' ), 'core', __( 'Official PublishRank announcements, release notes and update notices.', 'publishrank' ) ),
		);
		$out = array();
		foreach ( $d as $key => $row ) {
			$out[ $key ] = array( 'label' => $row[0], 'group' => $row[1], 'desc' => $row[2] );
		}
		return $out;
	}

	/** Settings API option groups and the capability that may save them. @return array<string,string> */
	public static function option_groups() {
		return array(
			'publishrank_seo'                => 'manage_settings',
			'publishrank_seo_unmanaged'      => 'manage_settings',
			'publishrank_schema'             => 'manage_schema',
			'publishrank_seo_robots'         => 'manage_robots',
			'publishrank_crawl_optimization' => 'manage_robots',
			'publishrank_internal_links'     => 'bulk_edit',
		);
	}

	/** @return array<string,string> */
	public static function groups() {
		return array(
			'core'      => __( 'Core', 'publishrank' ),
			'content'   => __( 'Content', 'publishrank' ),
			'discovery' => __( 'Search & discovery', 'publishrank' ),
			'ai'        => __( 'AI search visibility', 'publishrank' ),
			'maintain'  => __( 'Measure & maintain', 'publishrank' ),
			'security'  => __( 'Security', 'publishrank' ),
			'newsroom'  => __( 'Newsroom', 'publishrank' ),
		);
	}

	/** Real WordPress capability name for a short key. */
	public static function cap( $key ) {
		$key = (string) $key;
		if ( isset( self::LEGACY[ $key ] ) ) {
			return self::LEGACY[ $key ];
		}
		if ( 0 === strpos( $key, self::PREFIX ) || in_array( $key, self::LEGACY, true ) ) {
			return $key;
		}
		return self::PREFIX . $key;
	}

	/** Short key for a real capability name, or '' when it is not one of ours. */
	public static function key_for( $cap ) {
		$cap  = (string) $cap;
		$flip = array_flip( self::LEGACY );
		if ( isset( $flip[ $cap ] ) ) {
			return $flip[ $cap ];
		}
		if ( 0 === strpos( $cap, self::PREFIX ) ) {
			$key = substr( $cap, strlen( self::PREFIX ) );
			return isset( self::definitions()[ $key ] ) ? $key : '';
		}
		return '';
	}

	/** @return string[] Every real capability name. */
	public static function all_cap_names( $existing = array() ) {
		$names = array();
		foreach ( array_keys( self::definitions() ) as $key ) {
			$names[] = self::cap( $key );
		}
		if ( is_array( $existing ) && $existing ) {
			// Members / URE pass their own list in; append ours.
			return array_values( array_unique( array_merge( $existing, $names ) ) );
		}
		return $names;
	}

	/**
	 * Whether a user may perform an action.
	 *
	 * @param string   $key     Short key (manage_redirects) or full capability.
	 * @param int|null $user_id Defaults to the current user.
	 */
	public static function can( $key, $user_id = null ) {
		$cap = self::cap( $key );
		if ( null === $user_id ) {
			return current_user_can( $cap );
		}
		return user_can( (int) $user_id, $cap );
	}

	/** Die with a 403 when the current user lacks a capability. */
	public static function require_cap( $key ) {
		if ( ! self::can( $key ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'publishrank' ), esc_html__( 'Permission denied', 'publishrank' ), array( 'response' => 403 ) );
		}
	}

	/** REST permission callback factory. */
	public static function rest( $key ) {
		return static function () use ( $key ) {
			if ( self::can( $key ) ) {
				return true;
			}
			return new \WP_Error( 'publishrank_forbidden', __( 'You do not have permission to do this.', 'publishrank' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
		};
	}

	/** Whether a user is an administrator who always passes (site admin, or network super admin). */
	public static function is_admin_user( $user ) {
		if ( ! $user instanceof \WP_User || ! $user->exists() ) {
			return false;
		}
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return true;
		}
		return in_array( 'administrator', (array) $user->roles, true ) || ! empty( $user->allcaps['manage_options'] );
	}

	/**
	 * Administrators always pass; per-user overrides apply to everyone else.
	 *
	 * @param array<string,bool> $allcaps All capabilities of the user.
	 * @param string[]           $caps    Required primitive capabilities.
	 * @param array              $args    Arguments.
	 * @param \WP_User           $user    User.
	 */
	public static function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! is_array( $caps ) || ! $user instanceof \WP_User ) {
			return $allcaps;
		}
		$ours = array();
		foreach ( $caps as $cap ) {
			if ( '' !== self::key_for( $cap ) ) {
				$ours[] = $cap;
			}
		}
		if ( ! $ours ) {
			return $allcaps;
		}
		$is_admin = ( is_multisite() && is_super_admin( $user->ID ) ) || ! empty( $allcaps['manage_options'] ) || in_array( 'administrator', (array) $user->roles, true );
		if ( $is_admin ) {
			foreach ( $ours as $cap ) {
				$allcaps[ $cap ] = true;
			}
			return $allcaps;
		}
		$overrides = self::user_overrides( $user->ID );
		foreach ( $ours as $cap ) {
			$key = self::key_for( $cap );
			if ( isset( $overrides[ $key ] ) ) {
				$allcaps[ $cap ] = 'grant' === $overrides[ $key ];
			}
		}
		return $allcaps;
	}

	/** @return array<string,string> key => grant|deny */
	public static function user_overrides( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::USER_META, true );
		$out = array();
		if ( is_array( $raw ) ) {
			$defs = self::definitions();
			foreach ( $raw as $key => $mode ) {
				if ( isset( $defs[ $key ] ) && in_array( $mode, array( 'grant', 'deny' ), true ) ) {
					$out[ $key ] = $mode;
				}
			}
		}
		return $out;
	}

	/**
	 * Built-in presets.
	 *
	 * @return array<string,array{label:string,desc:string,base:string,caps:string[]}>
	 */
	public static function presets() {
		$all = array_keys( self::definitions() );
		return array(
			'administrator'    => array( 'label' => __( 'Administrator', 'publishrank' ), 'desc' => __( 'Everything. Administrators always pass every check.', 'publishrank' ), 'base' => 'administrator', 'caps' => $all ),
			'seo_manager'      => array( 'label' => __( 'SEO Manager', 'publishrank' ), 'desc' => __( 'All SEO, discovery, AI and maintenance tools; no security or roles.', 'publishrank' ), 'base' => 'editor', 'caps' => array( 'manage_settings', 'manage_modules', 'edit_seo', 'bulk_edit', 'manage_media_links', 'manage_schema', 'manage_sitemaps', 'manage_robots', 'manage_indexing', 'manage_feeds', 'manage_navigation', 'manage_ai', 'view_ai_log', 'manage_redirects', 'view_404', 'manage_404', 'manage_integrations', 'view_reports', 'newsroom_access', 'manage_branding', 'manage_theme_masking', 'manage_woocommerce_seo', 'view_analytics', 'view_news' ) ),
			'editor_in_chief'  => array( 'label' => __( 'Editor-in-Chief', 'publishrank' ), 'desc' => __( 'Runs the Newsroom and edits SEO on every story.', 'publishrank' ), 'base' => 'editor', 'caps' => array( 'edit_seo', 'bulk_edit', 'view_404', 'view_reports', 'view_ai_log', 'manage_navigation', 'newsroom_access', 'newsroom_create', 'newsroom_edit_any', 'newsroom_assign', 'newsroom_publish_handoff', 'newsroom_delete', 'newsroom_restore', 'newsroom_manage', 'view_analytics', 'view_news' ) ),
			'desk_editor'      => array( 'label' => __( 'Desk Editor', 'publishrank' ), 'desc' => __( 'Assigns and edits stories on a desk and hands them off for publishing.', 'publishrank' ), 'base' => 'editor', 'caps' => array( 'edit_seo', 'view_reports', 'newsroom_access', 'newsroom_create', 'newsroom_edit_any', 'newsroom_assign', 'newsroom_publish_handoff', 'newsroom_restore', 'view_news' ) ),
			'reporter'         => array( 'label' => __( 'Reporter', 'publishrank' ), 'desc' => __( 'Pitches and writes own stories and edits their SEO fields.', 'publishrank' ), 'base' => 'author', 'caps' => array( 'edit_seo', 'newsroom_access', 'newsroom_create' ) ),
			'contributor'      => array( 'label' => __( 'Contributor / Intern', 'publishrank' ), 'desc' => __( 'Pitches stories; no SEO tools.', 'publishrank' ), 'base' => 'contributor', 'caps' => array( 'newsroom_access', 'newsroom_create' ) ),
			'analyst'          => array( 'label' => __( 'Analyst', 'publishrank' ), 'desc' => __( 'Read-only reports, 404 list and AI visibility data.', 'publishrank' ), 'base' => 'subscriber', 'caps' => array( 'view_reports', 'view_404', 'view_ai_log', 'view_analytics', 'view_news' ) ),
			'security_officer' => array( 'label' => __( 'Security Officer', 'publishrank' ), 'desc' => __( 'Access Shield, Traffic Guard and the security log.', 'publishrank' ), 'base' => 'subscriber', 'caps' => array( 'manage_security', 'view_security_log', 'view_reports', 'manage_theme_masking', 'view_news' ) ),
		);
	}

	/** WordPress roles created for presets that have no built-in equivalent. @return array<string,string> role => preset */
	public static function preset_roles() {
		return array(
			'publishrank_seo_manager'      => 'seo_manager',
			'publishrank_desk_editor'      => 'desk_editor',
			'publishrank_analyst'          => 'analyst',
			'publishrank_security_officer' => 'security_officer',
		);
	}

	/** Default preset for built-in WordPress roles. */
	public static function default_role_presets() {
		return array(
			'administrator' => 'administrator',
			'editor'        => 'editor_in_chief',
			'author'        => 'reporter',
			'contributor'   => 'contributor',
		);
	}

	/** Install or upgrade role capabilities once per registry version. Idempotent. */
	public static function maybe_install() {
		if ( (int) get_option( self::VERSION_OPT, 0 ) >= self::VERSION ) {
			return;
		}
		self::install();
	}

	public static function install() {
		$previous = (int) get_option( self::VERSION_OPT, 0 );
		$presets  = self::presets();
		// Custom preset roles.
		foreach ( self::preset_roles() as $role_name => $preset_key ) {
			if ( ! get_role( $role_name ) ) {
				$base      = get_role( $presets[ $preset_key ]['base'] );
				$base_caps = $base ? $base->capabilities : array( 'read' => true );
				add_role( $role_name, sprintf( /* translators: %s: preset name */ __( 'PublishRank %s', 'publishrank' ), $presets[ $preset_key ]['label'] ), $base_caps );
			}
			if ( 0 === $previous ) {
				self::apply_preset( $role_name, $preset_key, false );
			}
		}
		if ( 0 === $previous ) {
			foreach ( self::default_role_presets() as $role_name => $preset_key ) {
				if ( get_role( $role_name ) ) {
					self::apply_preset( $role_name, $preset_key, false );
				}
			}
			self::import_newsroom_roles();
			self::audit( 'install', '', '', sprintf( 'Registry version %d installed with default presets.', self::VERSION ) );
		} else {
			// Upgrades only add capabilities that are new in this registry version, never remove choices.
			$admin = get_role( 'administrator' );
			if ( $admin ) {
				foreach ( self::all_cap_names() as $cap ) {
					$admin->add_cap( $cap );
				}
			}
			// 2.25 (registry 4) split new permissions out of broader ones. Roles keep exactly the access they had:
			// whoever held the broader permission receives the new, narrower one.
			if ( $previous < 4 ) {
				$split = array(
					'manage_settings'     => array( 'manage_branding', 'manage_theme_masking' ),
					'manage_integrations' => array( 'manage_woocommerce_seo' ),
					'view_reports'        => array( 'view_analytics', 'view_news' ),
				);
				foreach ( wp_roles()->role_objects as $role ) {
					foreach ( $split as $from => $tos ) {
						if ( $role->has_cap( self::cap( $from ) ) ) {
							foreach ( $tos as $to ) {
								$role->add_cap( self::cap( $to ) );
							}
						}
					}
				}
				foreach ( get_users( array( 'meta_key' => self::USER_META, 'fields' => 'ID', 'number' => 2000 ) ) as $uid ) {
					$o = self::user_overrides( (int) $uid );
					foreach ( $split as $from => $tos ) {
						if ( isset( $o[ $from ] ) ) {
							foreach ( $tos as $to ) {
								if ( ! isset( $o[ $to ] ) ) {
									$o[ $to ] = $o[ $from ];
								}
							}
						}
					}
					update_user_meta( (int) $uid, self::USER_META, $o );
				}
			}
			// 2.26.1 (registry 5): the Media Link Manager goes to roles that could already change site-wide settings.
			if ( $previous < 5 ) {
				foreach ( wp_roles()->role_objects as $role ) {
					if ( $role->has_cap( self::cap( 'manage_settings' ) ) ) {
						$role->add_cap( self::cap( 'manage_media_links' ) );
					}
				}
			}
			self::audit( 'upgrade', '', '', sprintf( 'Registry upgraded from %d to %d.', $previous, self::VERSION ) );
		}
		update_option( self::SNAPSHOT_OPT, self::role_matrix(), false );
		self::sync_newsroom_option();
		update_option( self::VERSION_OPT, self::VERSION, true );
	}

	/** Bring the legacy Newsroom role screen's choices into the registry. */
	public static function import_newsroom_roles() {
		$legacy = get_option( self::NEWSROOM_OPT, false );
		if ( ! is_array( $legacy ) ) {
			return;
		}
		$map = array( 'access' => 'newsroom_access', 'manage' => 'newsroom_manage', 'create' => 'newsroom_create', 'setup' => 'setup_wizard' );
		foreach ( $legacy as $role_name => $flags ) {
			$role = get_role( (string) $role_name );
			if ( ! $role || ! is_array( $flags ) || 'administrator' === $role_name ) {
				continue;
			}
			foreach ( $map as $flag => $key ) {
				if ( ! empty( $flags[ $flag ] ) ) {
					$role->add_cap( self::cap( $key ) );
				} else {
					$role->remove_cap( self::cap( $key ) );
				}
			}
			if ( ! empty( $flags['manage'] ) ) {
				foreach ( array( 'newsroom_edit_any', 'newsroom_assign', 'newsroom_publish_handoff', 'newsroom_delete', 'newsroom_restore' ) as $key ) {
					$role->add_cap( self::cap( $key ) );
				}
			}
		}
	}

	/** Keep the legacy Newsroom option in step so the Newsroom's own role sync never reverts the matrix. */
	public static function sync_newsroom_option() {
		$out = array();
		foreach ( wp_roles()->roles as $role_name => $data ) {
			$caps              = isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ? $data['capabilities'] : array();
			$out[ $role_name ] = array(
				'access' => ! empty( $caps[ self::cap( 'newsroom_access' ) ] ) ? 1 : 0,
				'manage' => ! empty( $caps[ self::cap( 'newsroom_manage' ) ] ) ? 1 : 0,
				'create' => ! empty( $caps[ self::cap( 'newsroom_create' ) ] ) ? 1 : 0,
				'setup'  => ! empty( $caps[ self::cap( 'setup_wizard' ) ] ) ? 1 : 0,
			);
		}
		update_option( self::NEWSROOM_OPT, $out );
	}

	/**
	 * Apply a preset to a role: grant its caps and remove the others.
	 *
	 * @param bool $log Write an audit entry.
	 */
	public static function apply_preset( $role_name, $preset_key, $log = true ) {
		$presets = self::presets();
		$role    = get_role( (string) $role_name );
		if ( ! $role || ! isset( $presets[ $preset_key ] ) ) {
			return false;
		}
		$grant = array_flip( $presets[ $preset_key ]['caps'] );
		foreach ( array_keys( self::definitions() ) as $key ) {
			if ( isset( $grant[ $key ] ) || 'administrator' === $role_name ) {
				$role->add_cap( self::cap( $key ) );
			} else {
				$role->remove_cap( self::cap( $key ) );
			}
		}
		if ( $log ) {
			self::audit( 'preset', $role_name, $preset_key, sprintf( 'Preset "%s" applied to role "%s".', $presets[ $preset_key ]['label'], $role_name ) );
			self::sync_newsroom_option();
		}
		return true;
	}

	/** @return array<string,array<string,bool>> role => key => granted */
	public static function role_matrix() {
		$out = array();
		foreach ( wp_roles()->roles as $role_name => $data ) {
			$caps = isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ? $data['capabilities'] : array();
			foreach ( array_keys( self::definitions() ) as $key ) {
				$out[ $role_name ][ $key ] = 'administrator' === $role_name || ! empty( $caps[ self::cap( $key ) ] );
			}
		}
		return $out;
	}

	/**
	 * Save a whole matrix. Administrators keep everything; the acting user may not remove their own
	 * ability to manage roles.
	 *
	 * @param array<string,array<string,mixed>> $matrix role => key => truthy.
	 * @return true|\WP_Error
	 */
	public static function save_matrix( array $matrix ) {
		$defs    = self::definitions();
		$before  = self::role_matrix();
		$current = wp_get_current_user();
		// Simulate the result for the acting user before writing anything.
		if ( ! self::is_admin_user( $current ) && $current->exists() ) {
			$keeps = false;
			foreach ( (array) $current->roles as $role_name ) {
				if ( ! empty( $matrix[ $role_name ]['manage_roles'] ) ) {
					$keeps = true;
				}
			}
			$ov = self::user_overrides( $current->ID );
			if ( isset( $ov['manage_roles'] ) ) {
				$keeps = 'grant' === $ov['manage_roles'];
			}
			if ( ! $keeps ) {
				return new \WP_Error( 'publishrank_self_lockout', __( 'You cannot remove your own permission to manage roles.', 'publishrank' ) );
			}
		}
		$changes = 0;
		foreach ( wp_roles()->roles as $role_name => $data ) {
			if ( 'administrator' === $role_name ) {
				continue;
			}
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( array_keys( $defs ) as $key ) {
				$want = ! empty( $matrix[ $role_name ][ $key ] );
				$had  = ! empty( $before[ $role_name ][ $key ] );
				if ( $want === $had ) {
					continue;
				}
				if ( $want ) {
					$role->add_cap( self::cap( $key ) );
				} else {
					$role->remove_cap( self::cap( $key ) );
				}
				++$changes;
				self::audit( $want ? 'grant' : 'revoke', $role_name, $key, sprintf( '%s "%s" for role "%s".', $want ? 'Granted' : 'Revoked', $key, $role_name ) );
			}
		}
		if ( $changes ) {
			self::sync_newsroom_option();
		}
		return true;
	}

	/**
	 * Set per-user overrides.
	 *
	 * @param array<string,string> $overrides key => grant|deny|inherit.
	 * @return true|\WP_Error
	 */
	public static function save_user_overrides( $user_id, array $overrides ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'publishrank_user', __( 'User not found.', 'publishrank' ) );
		}
		if ( get_current_user_id() === $user_id && isset( $overrides['manage_roles'] ) && 'deny' === $overrides['manage_roles'] && ! self::is_admin_user( $user ) ) {
			return new \WP_Error( 'publishrank_self_lockout', __( 'You cannot remove your own permission to manage roles.', 'publishrank' ) );
		}
		$defs  = self::definitions();
		$old   = self::user_overrides( $user_id );
		$clean = array();
		foreach ( $overrides as $key => $mode ) {
			if ( isset( $defs[ $key ] ) && in_array( $mode, array( 'grant', 'deny' ), true ) ) {
				$clean[ $key ] = $mode;
			}
		}
		if ( $clean ) {
			update_user_meta( $user_id, self::USER_META, $clean );
		} else {
			delete_user_meta( $user_id, self::USER_META );
		}
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $clean ) ) ) as $key ) {
			$from = isset( $old[ $key ] ) ? $old[ $key ] : 'inherit';
			$to   = isset( $clean[ $key ] ) ? $clean[ $key ] : 'inherit';
			if ( $from !== $to ) {
				self::audit( 'user_override', 'user:' . $user_id, $key, sprintf( 'User #%d "%s": %s → %s.', $user_id, $key, $from, $to ) );
			}
		}
		return true;
	}

	/**
	 * Explain why a user can or cannot do something.
	 *
	 * @return array{allowed:bool,reasons:string[]}
	 */
	public static function explain( $user_id, $key ) {
		$user    = get_userdata( (int) $user_id );
		$reasons = array();
		if ( ! $user ) {
			return array( 'allowed' => false, 'reasons' => array( __( 'User not found.', 'publishrank' ) ) );
		}
		$cap = self::cap( $key );
		if ( self::is_admin_user( $user ) ) {
			$reasons[] = is_multisite() && is_super_admin( $user->ID ) ? __( 'Network super admin: always allowed.', 'publishrank' ) : __( 'Administrator: always allowed.', 'publishrank' );
		}
		foreach ( (array) $user->roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && $role->has_cap( $cap ) ) {
				/* translators: %s: role name */
				$reasons[] = sprintf( __( 'Granted by the "%s" role.', 'publishrank' ), $role_name );
			} elseif ( $role ) {
				/* translators: %s: role name */
				$reasons[] = sprintf( __( 'Not granted by the "%s" role.', 'publishrank' ), $role_name );
			}
		}
		$ov = self::user_overrides( $user->ID );
		if ( isset( $ov[ $key ] ) ) {
			$reasons[] = 'grant' === $ov[ $key ] ? __( 'Per-user override: granted.', 'publishrank' ) : __( 'Per-user override: denied.', 'publishrank' );
		}
		$allowed = user_can( $user, $cap );
		return array( 'allowed' => (bool) $allowed, 'reasons' => $reasons );
	}

	/** Append an audit entry. */
	public static function audit( $event, $subject, $key, $message ) {
		$log = get_option( self::AUDIT_OPT, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'at'      => gmdate( 'c' ),
				'user'    => get_current_user_id(),
				'event'   => sanitize_key( $event ),
				'subject' => sanitize_text_field( (string) $subject ),
				'cap'     => sanitize_key( (string) $key ),
				'message' => sanitize_text_field( (string) $message ),
			)
		);
		update_option( self::AUDIT_OPT, array_slice( $log, 0, self::AUDIT_MAX ), false );
	}

	/** @return array<int,array<string,mixed>> */
	public static function audit_log( $limit = 100 ) {
		$log = get_option( self::AUDIT_OPT, array() );
		return array_slice( is_array( $log ) ? $log : array(), 0, max( 1, (int) $limit ) );
	}

	/** Uninstall: remove every PublishRank capability and preset role. */
	public static function uninstall() {
		foreach ( wp_roles()->roles as $role_name => $data ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all_cap_names() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		foreach ( array_keys( self::preset_roles() ) as $role_name ) {
			remove_role( $role_name );
		}
		delete_option( self::VERSION_OPT );
		delete_option( self::SNAPSHOT_OPT );
		delete_option( self::AUDIT_OPT );
		delete_metadata( 'user', 0, self::USER_META, '', true );
	}
}
