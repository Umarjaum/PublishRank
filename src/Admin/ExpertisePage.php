<?php
/** Author identity and expertise coverage for publisher structured data. */
namespace PublishRankSEO\Admin;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Schema\SchemaBuilder;
defined( 'ABSPATH' ) || exit;
final class ExpertisePage {
	const SLUG = 'publishrank-author-expertise';
	public function register() { add_action( 'admin_menu', array( $this, 'menu' ), 11 ); }
	public function menu() { add_submenu_page( Branding::MENU_SLUG, __( 'Author Expertise', 'publishrank' ), __( 'Author Expertise', 'publishrank' ), 'manage_options', self::SLUG, array( $this, 'render' ) ); }
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$users = get_users( array( 'capability' => 'edit_posts', 'number' => 100, 'orderby' => 'display_name', 'order' => 'ASC' ) );
		$complete = 0;
		echo '<div class="wrap">';
		AdminAssets::header( __( 'Author Expertise', 'publishrank' ), __( 'Strengthen author identity and expertise signals that feed connected Person structured data.', 'publishrank' ) );
		echo '<p>' . esc_html__( 'Complete author profiles with a real biography, role, coverage expertise and public identity profiles. These fields describe people; only add accurate, verifiable information. PublishRank exposes expertise as schema.org knowsAbout and social links as sameAs.', 'publishrank' ) . '</p>';
		echo '<div class="rhs-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Author', 'publishrank' ) . '</th><th>' . esc_html__( 'Published posts', 'publishrank' ) . '</th><th>' . esc_html__( 'Biography', 'publishrank' ) . '</th><th>' . esc_html__( 'Role', 'publishrank' ) . '</th><th>' . esc_html__( 'Expertise', 'publishrank' ) . '</th><th>' . esc_html__( 'Profiles', 'publishrank' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $users ) { echo '<tr><td colspan="7">' . esc_html__( 'No author profiles found.', 'publishrank' ) . '</td></tr>'; }
		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User ) { continue; }
			$bio = trim( (string) $user->description );
			$job = trim( (string) get_user_meta( $user->ID, '_publishrank_author_job_title', true ) );
			$expertise = SchemaBuilder::author_expertise( $user->ID );
			$social = SchemaBuilder::author_social( $user->ID );
			$has_posts = count_user_posts( $user->ID, array( 'post' ), true );
			$done = ( $bio ? 1 : 0 ) + ( $job ? 1 : 0 ) + ( $expertise ? 1 : 0 ) + ( $social ? 1 : 0 );
			if ( 4 === $done ) { $complete++; }
			$profile_url = get_edit_user_link( $user->ID );
			echo '<tr><td>'; if ( $profile_url ) { echo '<a href="' . esc_url( $profile_url ) . '">' . esc_html( $user->display_name ) . '</a>'; } else { echo esc_html( $user->display_name ); } echo '</td><td>' . esc_html( (string) $has_posts ) . '</td><td>' . esc_html( $bio ? __( 'Added', 'publishrank' ) : __( 'Missing', 'publishrank' ) ) . '</td><td>' . esc_html( $job ? __( 'Added', 'publishrank' ) : __( 'Missing', 'publishrank' ) ) . '</td><td>' . esc_html( $expertise ? implode( ', ', $expertise ) : __( 'Missing', 'publishrank' ) ) . '</td><td>' . esc_html( $social ? sprintf( _n( /* translators: %d: number of public identity profiles for this author. */ '%d profile', '%d profiles', count( $social ), 'publishrank' ), count( $social ) ) : __( 'Missing', 'publishrank' ) ) . '</td><td>' . ( $profile_url ? '<a class="button button-small" href="' . esc_url( $profile_url ) . '">' . esc_html__( 'Edit profile', 'publishrank' ) . '</a>' : '&mdash;' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="description">' . esc_html( sprintf( __( /* translators: 1: number of complete author profiles, 2: total listed author profiles. */ '%1$d of %2$d listed author profiles have a biography, role, expertise topics and at least one identity profile. Profile coverage is editorial guidance and does not guarantee search features.', 'publishrank' ), $complete, count( $users ) ) ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( Branding::page_url( 'publishrank-schema' ) ) . '">' . esc_html__( 'Open schema settings', 'publishrank' ) . '</a></p></div>';
	}
}
