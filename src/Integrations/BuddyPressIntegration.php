<?php
/**
 * BuddyPress support: members & groups sitemap, ProfilePage schema, noindex for activity/settings screens.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Integrations;

use PublishRankSEO\Sitemap\Providers\SitemapProviderInterface;
use PublishRankSEO\Sitemap\Engine\SitemapRenderer;

defined( 'ABSPATH' ) || exit;

final class BuddyPressIntegration extends IntegrationBase implements SitemapProviderInterface {
	const SHARD = 5000;
	protected $module = 'buddypress';

	public function detected() {
		return function_exists( 'buddypress' ) || class_exists( 'BuddyPress' );
	}

	public function register() {
		add_filter( 'publishrank_sitemap_providers', array( $this, 'sitemap' ) );
		add_filter( 'publishrank_sitemap_type_labels', array( $this, 'labels' ) );
		add_filter( 'publishrank_seo_robots', array( $this, 'robots' ), 20 );
		add_filter( 'publishrank_schema_graph', array( $this, 'schema' ), 20 );
		add_filter( 'publishrank_seo_title_pre', array( $this, 'title_pre' ) );
		add_filter( 'publishrank_seo_description_pre', array( $this, 'description_pre' ) );
	}

	/* ---------- Sitemap (one provider: groups first, then members) ---------- */

	public function sitemap( $providers ) {
		$providers[] = $this;
		return $providers;
	}

	public function labels( $types ) {
		$types['community'] = array(
			'label' => __( 'Community', 'publishrank' ),
			'desc'  => __( 'BuddyPress public groups and member profiles', 'publishrank' ),
			'abbr'  => 'BP',
		);
		return $types;
	}

	public function get_name() {
		return __( 'Community (BuddyPress)', 'publishrank' ); }

	public function get_slug() {
		return 'community'; }

	public function is_enabled() {
		return $this->active() && (bool) get_option( 'publishrank_buddypress_sitemap', 1 ); }

	private function members_enabled() {
		return function_exists( 'bp_is_active' ) && bp_is_active( 'members' ) && (bool) apply_filters( 'publishrank_buddypress_sitemap_members', true ); }

	private function groups_enabled() {
		return function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) && function_exists( 'groups_get_groups' ); }

	private function total_groups() {
		if ( ! $this->groups_enabled() ) {
			return 0; }
		$result = groups_get_groups( array( 'per_page' => 1, 'page' => 1, 'status' => array( 'public' ), 'show_hidden' => false, 'update_meta_cache' => false ) );
		return (int) ( $result['total'] ?? 0 );
	}

	private function total_members() {
		if ( ! $this->members_enabled() ) {
			return 0; }
		$counts = count_users();
		return (int) ( $counts['total_users'] ?? 0 );
	}

	public function get_shard_count() {
		return max( 1, (int) ceil( ( $this->total_groups() + $this->total_members() ) / self::SHARD ) );
	}

	public function render_shard( $shard ) {
		$shard   = max( 1, absint( $shard ) );
		$offset  = ( $shard - 1 ) * self::SHARD;
		$groups  = $this->total_groups();
		$entries = array();
		$need    = self::SHARD;
		if ( $offset < $groups ) {
			$page   = (int) floor( $offset / self::SHARD ) + 1;
			$result = groups_get_groups( array( 'per_page' => self::SHARD, 'page' => $page, 'status' => array( 'public' ), 'show_hidden' => false, 'orderby' => 'date_created', 'order' => 'ASC' ) );
			foreach ( (array) ( $result['groups'] ?? array() ) as $group ) {
				$url = function_exists( 'bp_get_group_url' ) ? bp_get_group_url( $group ) : ( function_exists( 'bp_get_group_permalink' ) ? bp_get_group_permalink( $group ) : '' );
				if ( $url ) {
					$last      = ! empty( $group->last_activity ) ? mysql2date( DATE_W3C, $group->last_activity, false ) : '';
					$entries[] = SitemapRenderer::url( $url, $last );
				}
			}
			$need  -= count( (array) ( $result['groups'] ?? array() ) );
			$offset = 0;
		} else {
			$offset -= $groups;
		}
		if ( $need > 0 && $this->members_enabled() ) {
			$users = get_users( array( 'number' => $need, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => array( 'ID', 'user_registered' ) ) );
			foreach ( $users as $user ) {
				if ( function_exists( 'bp_is_user_spammer' ) && bp_is_user_spammer( $user->ID ) ) {
					continue; }
				if ( function_exists( 'bp_is_user_deleted' ) && bp_is_user_deleted( $user->ID ) ) {
					continue; }
				if ( ! apply_filters( 'publishrank_buddypress_sitemap_include_member', true, (int) $user->ID ) ) {
					continue; }
				$url = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $user->ID ) : ( function_exists( 'bp_core_get_user_domain' ) ? bp_core_get_user_domain( $user->ID ) : '' );
				if ( $url ) {
					$last      = function_exists( 'bp_get_user_last_activity' ) ? bp_get_user_last_activity( $user->ID ) : '';
					$entries[] = SitemapRenderer::url( $url, $last ? mysql2date( DATE_W3C, $last, false ) : '' );
				}
			}
		}
		return SitemapRenderer::urlset( $entries );
	}

	/* ---------- Titles and descriptions ---------- */

	/** The member or public group on the current screen, or null. */
	private function entity() {
		if ( ! $this->active() || ! function_exists( 'is_buddypress' ) || ! is_buddypress() ) {
			return null;
		}
		if ( function_exists( 'bp_is_user' ) && bp_is_user() && function_exists( 'bp_displayed_user_id' ) ) {
			$user_id = (int) bp_displayed_user_id();
			$name    = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $user_id ) : get_the_author_meta( 'display_name', $user_id );
			return $user_id && $name ? array( 'name' => (string) $name, 'text' => (string) get_the_author_meta( 'description', $user_id ) ) : null;
		}
		if ( function_exists( 'bp_is_group' ) && bp_is_group() && function_exists( 'groups_get_current_group' ) ) {
			$group = groups_get_current_group();
			return $group && ! empty( $group->name ) ? array( 'name' => (string) $group->name, 'text' => 'public' === $group->status ? (string) $group->description : '' ) : null;
		}
		return null;
	}

	/** Member and group screens are virtual pages: without this they would all carry the site name as their title. */
	public function title_pre( $title ) {
		$entity = $this->entity();
		if ( ! $entity ) {
			return $title;
		}
		$template = (string) get_option( 'publishrank_seo_title_template_archive', '' );
		if ( '' === $template ) {
			$template = '%%title%% %%sep%% %%sitename%% %%page%%';
		}
		$resolved = ( new \PublishRankSEO\Meta\MetaGenerator() )->resolve_template( $template, 0, 'title', array( 'title' => wp_strip_all_tags( $entity['name'] ) ) );
		return '' !== $resolved ? $resolved : $title;
	}

	public function description_pre( $description ) {
		$entity = $this->entity();
		return ( $entity && '' !== trim( wp_strip_all_tags( $entity['text'] ) ) ) ? wp_strip_all_tags( $entity['text'] ) : $description;
	}

	/* ---------- Robots ---------- */

	public function robots( $robots ) {
		if ( ! $this->active() || ! function_exists( 'is_buddypress' ) || ! is_buddypress() ) {
			return $robots;
		}
		$private = ( function_exists( 'bp_is_user_settings' ) && bp_is_user_settings() )
			|| ( function_exists( 'bp_is_user_messages' ) && bp_is_user_messages() )
			|| ( function_exists( 'bp_is_user_notifications' ) && bp_is_user_notifications() )
			|| ( function_exists( 'bp_is_register_page' ) && bp_is_register_page() )
			|| ( function_exists( 'bp_is_activation_page' ) && bp_is_activation_page() )
			|| ( function_exists( 'bp_is_group_create' ) && bp_is_group_create() )
			|| ( function_exists( 'bp_is_group_admin_page' ) && bp_is_group_admin_page() );
		if ( $private ) {
			return self::robots_add( $robots, array( 'noindex', 'nofollow' ) );
		}
		$thin = ( function_exists( 'bp_is_single_activity' ) && bp_is_single_activity() )
			|| ( function_exists( 'bp_is_activity_directory' ) && bp_is_activity_directory() && (bool) apply_filters( 'publishrank_buddypress_noindex_activity', true ) )
			|| ( function_exists( 'bp_is_user' ) && bp_is_user() && function_exists( 'bp_is_user_profile' ) && ! bp_is_user_profile() && ! ( function_exists( 'bp_is_user_front' ) && bp_is_user_front() ) )
			|| ( function_exists( 'bp_is_group' ) && bp_is_group() && function_exists( 'bp_is_group_home' ) && ! bp_is_group_home() )
			|| ( function_exists( 'bp_is_group' ) && bp_is_group() && function_exists( 'bp_get_current_group_status' ) && 'public' !== bp_get_current_group_status() );
		return $thin ? self::robots_add( $robots, array( 'noindex', 'follow' ) ) : $robots;
	}

	/* ---------- Schema ---------- */

	public function schema( $graph ) {
		if ( ! $this->active() || ! function_exists( 'is_buddypress' ) || ! is_buddypress() ) {
			return $graph;
		}
		$page_id = trailingslashit( $this->canonical() ) . '#webpage';
		if ( function_exists( 'bp_is_user' ) && bp_is_user() && function_exists( 'bp_displayed_user_id' ) ) {
			$user_id = (int) bp_displayed_user_id();
			$url     = function_exists( 'bp_members_get_user_url' ) ? bp_members_get_user_url( $user_id ) : bp_core_get_user_domain( $user_id );
			$person  = array(
				'@type' => 'Person',
				'@id'   => trailingslashit( $url ) . '#person',
				'name'  => function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $user_id ) : get_the_author_meta( 'display_name', $user_id ),
				'url'   => $url,
			);
			if ( function_exists( 'bp_core_fetch_avatar' ) ) {
				$avatar = bp_core_fetch_avatar( array( 'item_id' => $user_id, 'type' => 'full', 'html' => false ) );
				if ( $avatar ) {
					$person['image'] = $avatar; }
			}
			$graph = $this->set_webpage( $graph, $page_id, 'ProfilePage', array( '@id' => $person['@id'] ) );
			$graph[] = $person;
		} elseif ( function_exists( 'bp_is_group' ) && bp_is_group() && function_exists( 'groups_get_current_group' ) ) {
			$group = groups_get_current_group();
			if ( $group && 'public' === $group->status ) {
				$url  = function_exists( 'bp_get_group_url' ) ? bp_get_group_url( $group ) : bp_get_group_permalink( $group );
				$node = array(
					'@type'       => 'Organization',
					'@id'         => trailingslashit( $url ) . '#group',
					'name'        => wp_strip_all_tags( $group->name ),
					'url'         => $url,
					'description' => wp_trim_words( wp_strip_all_tags( (string) $group->description ), 60, '…' ),
				);
				if ( function_exists( 'groups_get_total_member_count' ) ) {
					$node['interactionStatistic'] = array( '@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/JoinAction', 'userInteractionCount' => (int) groups_get_total_member_count( $group->id ) );
				}
				$graph   = $this->set_webpage( $graph, $page_id, 'WebPage', array( '@id' => $node['@id'] ) );
				$graph[] = $node;
			}
		}
		return $graph;
	}

	private function set_webpage( $graph, $page_id, $type, $entity ) {
		foreach ( $graph as $i => $node ) {
			if ( is_array( $node ) && isset( $node['@id'] ) && '#webpage' === substr( (string) $node['@id'], -8 ) ) {
				$graph[ $i ]['@type']      = $type;
				$graph[ $i ]['mainEntity'] = $entity;
				return $graph;
			}
		}
		return $graph;
	}
}
