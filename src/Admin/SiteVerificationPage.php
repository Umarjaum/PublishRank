<?php
/**
 * Site Verification: prove ownership to Google, Bing, Yandex, Baidu and other engines
 * with meta tags, uploaded verification files or custom tags, then test what a crawler sees.
 */
namespace PublishRankSEO\Admin;

use PublishRankSEO\Core\Branding;
use PublishRankSEO\SEO\SiteConnections;

defined( 'ABSPATH' ) || exit;

final class SiteVerificationPage {
	const SLUG = 'publishrank-verification';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 10 );
		add_action( 'admin_post_publishrank_verification_save', array( $this, 'save' ) );
		add_action( 'admin_post_publishrank_verification_upload', array( $this, 'upload' ) );
		add_action( 'admin_post_publishrank_verification_create', array( $this, 'create' ) );
		add_action( 'admin_post_publishrank_verification_delete', array( $this, 'delete' ) );
		add_action( 'admin_post_publishrank_verification_check', array( $this, 'check' ) );
	}

	/** First entry of the PublishRank drop-down (position 0, after the Dashboard menu exists). */
	public function menu() {
		add_submenu_page(
			Branding::MENU_SLUG,
			__( 'Site Verification', 'publishrank' ),
			__( 'Site Verification', 'publishrank' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			0
		);
	}

	/* ---------------------------- handlers ---------------------------- */

	public function save() {
		AdminAssets::guard( 'publishrank_verification_save' );
		$engines  = SiteConnections::engines();
		$posted   = isset( $_POST['token'] ) && is_array( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- normalised below.
		$tokens   = array();
		$moved    = array();
		$warnings = array();
		foreach ( $engines as $key => $engine ) {
			if ( ! array_key_exists( $key, $posted ) ) { $tokens[ $key ] = SiteConnections::normalize_token( get_option( 'publishrank_verification_' . $key, '' ) ); continue; }
			$raw = trim( (string) $posted[ $key ] );
			if ( false !== stripos( $raw, '<meta' ) ) {
				$parsed = SiteConnections::parse_meta_tags( $raw, true );
				$owner  = $parsed ? SiteConnections::engine_for_meta_name( $parsed[0]['name'] ) : '';
				if ( $parsed && '' !== $owner && $owner !== $key ) {
					/* translators: 1: engine the tag belongs to, 2: field it was pasted into. */
					$warnings[] = sprintf( __( 'The tag you pasted in the %2$s field belongs to %1$s, so it was moved there.', 'publishrank' ), $engines[ $owner ]['label'], $engine['label'] );
					$moved[ $owner ] = SiteConnections::normalize_token( $raw );
					$tokens[ $key ]  = '';
					continue;
				}
			}
			$tokens[ $key ] = SiteConnections::normalize_token( $raw );
		}
		foreach ( $moved as $owner => $token ) { $tokens[ $owner ] = $token; }
		// Smart box: recognised engine tags are routed to their field, anything else is kept as a custom tag.
		$smart_raw = isset( $_POST['custom'] ) ? (string) wp_unslash( $_POST['custom'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed and rebuilt below.
		$custom    = array();
		$all       = SiteConnections::parse_meta_tags( $smart_raw, true );
		$safe      = SiteConnections::parse_meta_tags( $smart_raw, false );
		foreach ( $safe as $tag ) {
			$owner = SiteConnections::engine_for_meta_name( $tag['name'] );
			if ( '' !== $owner ) {
				$tokens[ $owner ] = SiteConnections::normalize_token( $tag['content'] );
				/* translators: %s: search engine. */
				$warnings[] = sprintf( __( 'Recognised a %s tag and saved it in its own field.', 'publishrank' ), $engines[ $owner ]['label'] );
				continue;
			}
			$custom[] = '<meta ' . $tag['attr'] . '="' . esc_attr( $tag['name'] ) . '" content="' . esc_attr( $tag['content'] ) . '" />';
		}
		if ( count( $all ) > count( $safe ) ) {
			$warnings[] = __( 'Some tags were ignored because they change crawling or page behaviour (robots, viewport, refresh and similar). Only ownership-verification tags are allowed here.', 'publishrank' );
		}
		if ( '' !== trim( $smart_raw ) && ! $all ) {
			$warnings[] = __( 'No <meta> tag was found in the text you pasted. Paste the complete tag, for example <meta name="google-site-verification" content="…" />.', 'publishrank' );
		}
		foreach ( $tokens as $key => $value ) { update_option( 'publishrank_verification_' . $key, $value, true ); }
		update_option( SiteConnections::OPT_CUSTOM, implode( "\n", array_slice( $custom, 0, 20 ) ), true );
		$scope = isset( $_POST['scope'] ) && 'home' === sanitize_key( wp_unslash( $_POST['scope'] ) ) ? 'home' : 'all'; // phpcs:ignore WordPress.Security.NonceVerification -- nonce verified in guard().
		update_option( SiteConnections::OPT_SCOPE, $scope, true );
		SiteConnections::purge_caches();
		$result  = SiteConnections::run_check();
		$summary = $this->summary_message( $result );
		AdminAssets::done( self::SLUG, 'success', trim( __( 'Verification settings saved.', 'publishrank' ) . ' ' . implode( ' ', $warnings ) . ' ' . $summary ) );
	}

	public function upload() {
		AdminAssets::guard( 'publishrank_verification_upload' );
		$ok = array();
		$bad = array();
		$files = isset( $_FILES['verification_files'] ) && is_array( $_FILES['verification_files'] ) ? $_FILES['verification_files'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each entry is validated below.
		if ( empty( $files['name'] ) ) {
			AdminAssets::done( self::SLUG, 'error', __( 'Choose a verification file first.', 'publishrank' ) );
		}
		$names = (array) $files['name'];
		foreach ( $names as $i => $original ) {
			$name = trim( (string) wp_unslash( $original ) );
			$err  = isset( $files['error'][ $i ] ) ? (int) $files['error'][ $i ] : UPLOAD_ERR_NO_FILE;
			if ( UPLOAD_ERR_NO_FILE === $err ) { continue; }
			$tmp = isset( $files['tmp_name'][ $i ] ) ? (string) $files['tmp_name'][ $i ] : '';
			if ( UPLOAD_ERR_OK !== $err || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				/* translators: %s: file name. */
				$bad[] = sprintf( __( '%s could not be uploaded (the server rejected it).', 'publishrank' ), $name );
				continue;
			}
			if ( (int) filesize( $tmp ) > SiteConnections::MAX_BYTES ) {
				/* translators: %s: file name. */
				$bad[] = sprintf( __( '%s is larger than 64 KB, which is far bigger than any real verification file.', 'publishrank' ), $name );
				continue;
			}
			$content = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$error   = SiteConnections::save_file( basename( $name ), $content );
			if ( '' === $error ) { $ok[] = basename( $name ); } else { $bad[] = basename( $name ) . ': ' . $error; }
		}
		if ( $ok ) { SiteConnections::purge_caches(); }
		$message = '';
		if ( $ok ) {
			/* translators: %s: comma-separated file names. */
			$message .= sprintf( __( 'Verification file saved and live: %s.', 'publishrank' ), implode( ', ', $ok ) ) . ' ';
			$message .= $this->summary_message( SiteConnections::run_check() );
		}
		if ( $bad ) { $message .= ' ' . implode( ' ', $bad ); }
		AdminAssets::done( self::SLUG, $ok && ! $bad ? 'success' : 'error', trim( $message ) );
	}

	public function create() {
		AdminAssets::guard( 'publishrank_verification_create' );
		$name    = isset( $_POST['file_name'] ) ? trim( (string) wp_unslash( $_POST['file_name'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by save_file().
		$content = isset( $_POST['file_content'] ) ? (string) wp_unslash( $_POST['file_content'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by save_file().
		$error   = SiteConnections::save_file( $name, $content );
		if ( '' !== $error ) { AdminAssets::done( self::SLUG, 'error', $error ); }
		SiteConnections::purge_caches();
		/* translators: %s: file name. */
		AdminAssets::done( self::SLUG, 'success', sprintf( __( 'Verification file saved and live: %s.', 'publishrank' ), $name ) . ' ' . $this->summary_message( SiteConnections::run_check() ) );
	}

	public function delete() {
		AdminAssets::guard( 'publishrank_verification_delete' );
		$name = isset( $_POST['file_name'] ) ? trim( (string) wp_unslash( $_POST['file_name'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- matched against stored keys.
		if ( SiteConnections::delete_file( $name ) ) {
			SiteConnections::purge_caches();
			/* translators: %s: file name. */
			AdminAssets::done( self::SLUG, 'success', sprintf( __( 'Removed %s. Keep verification files in place until the search engine has confirmed ownership, and do not remove them afterwards or the property may lose verification.', 'publishrank' ), $name ) );
		}
		AdminAssets::done( self::SLUG, 'error', __( 'That file was not found.', 'publishrank' ) );
	}

	public function check() {
		AdminAssets::guard( 'publishrank_verification_check' );
		SiteConnections::purge_caches();
		AdminAssets::done( self::SLUG, 'success', $this->summary_message( SiteConnections::run_check() ) );
	}

	/** One-sentence outcome of a live check. */
	private function summary_message( $result ) {
		if ( ! is_array( $result ) ) { return ''; }
		$items = array();
		foreach ( array( 'tags', 'files' ) as $group ) {
			foreach ( isset( $result[ $group ] ) && is_array( $result[ $group ] ) ? $result[ $group ] : array() as $row ) { $items[] = $row; }
		}
		if ( ! empty( $result['home']['error'] ) ) {
			/* translators: %s: error message. */
			return sprintf( __( 'Live check could not reach your site: %s', 'publishrank' ), $result['home']['error'] );
		}
		if ( ! $items ) { return __( 'Nothing is configured yet.', 'publishrank' ); }
		$bad = 0; $warn = 0;
		foreach ( $items as $row ) { if ( 'bad' === $row['status'] ) { $bad++; } elseif ( 'warn' === $row['status'] ) { $warn++; } }
		if ( ! $bad && ! $warn ) { return __( 'Live check passed: everything a search engine looks for is visible. You can now click Verify in the search engine.', 'publishrank' ); }
		/* translators: 1: number of problems, 2: number of warnings. */
		return sprintf( __( 'Live check found %1$d problem(s) and %2$d warning(s). See the status column below.', 'publishrank' ), $bad, $warn );
	}

	/* ------------------------------ page ------------------------------ */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$engines = SiteConnections::engines();
		$files   = SiteConnections::files();
		$check   = get_option( SiteConnections::OPT_CHECK, array() );
		$fresh   = is_array( $check ) && isset( $check['hash'] ) && SiteConnections::config_hash() === $check['hash'];
		$custom  = (string) get_option( SiteConnections::OPT_CUSTOM, '' );
		$scope   = SiteConnections::scope();
		$action  = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="wrap">';
		AdminAssets::header( __( 'Site Verification', 'publishrank' ), __( 'Prove ownership to Google, Bing, Yandex, Baidu and more with meta tags or verification files, then test exactly what a crawler sees.', 'publishrank' ) );
		AdminAssets::render_flash();

		// Status overview.
		$configured = 0;
		foreach ( $engines as $key => $engine ) { if ( '' !== SiteConnections::normalize_token( get_option( 'publishrank_verification_' . $key, '' ) ) ) { $configured++; } }
		$configured += count( SiteConnections::parse_meta_tags( $custom ) ) + count( $files );
		echo '<div class="rhs-card rhs-vf-card"><h2>' . esc_html__( 'Status', 'publishrank' ) . '</h2>';
		if ( ! $configured ) {
			echo '<p>' . esc_html__( 'Nothing configured yet. Pick a method below: paste the meta tag the search engine gave you, or upload its verification file.', 'publishrank' ) . '</p>';
		} else {
			echo '<p>' . esc_html( sprintf( /* translators: %d: number of verification items. */ _n( '%d verification item is active.', '%d verification items are active.', $configured, 'publishrank' ), $configured ) ) . ' ';
			if ( is_array( $check ) && ! empty( $check['time'] ) ) {
				echo esc_html( sprintf( /* translators: %s: UTC time. */ __( 'Last live check: %s UTC.', 'publishrank' ), gmdate( 'Y-m-d H:i', (int) strtotime( (string) $check['time'] ) ) ) );
				if ( ! $fresh ) { echo ' <strong>' . esc_html__( 'Settings changed since then, run the check again.', 'publishrank' ) . '</strong>'; }
			} else {
				echo esc_html__( 'Not checked yet.', 'publishrank' );
			}
			echo '</p>';
			echo '<form method="post" action="' . $action . '" class="rhs-vf-inline"><input type="hidden" name="action" value="publishrank_verification_check">'; wp_nonce_field( 'publishrank_verification_check' );
			submit_button( __( 'Run live check now', 'publishrank' ), 'secondary', 'submit', false ); echo '</form>';
			if ( ! empty( $check['home']['error'] ) && $fresh ) { echo '<p class="rhs-vf-error">' . esc_html( $check['home']['error'] ) . '</p>'; }
		}
		echo '</div>';

		// Meta tag form.
		echo '<form method="post" action="' . $action . '"><input type="hidden" name="action" value="publishrank_verification_save">'; wp_nonce_field( 'publishrank_verification_save' );
		echo '<div class="rhs-card rhs-vf-card"><h2>' . esc_html__( 'Method 1: HTML meta tag', 'publishrank' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Paste either the token or the complete <meta> tag the search engine shows you. PublishRank extracts the right value and prints the tag in the <head> of your site.', 'publishrank' ) . '</p><div class="rhs-vf-grid">';
		foreach ( $engines as $key => $engine ) {
			$value = SiteConnections::normalize_token( get_option( 'publishrank_verification_' . $key, '' ) );
			$id    = 'pr-vf-' . $key;
			echo '<div class="rhs-vf-field"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $engine['label'] ) . '</strong></label>';
			echo '<input type="text" class="large-text code" id="' . esc_attr( $id ) . '" name="token[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $engine['ph'] ) . '" autocomplete="off" spellcheck="false">';
			echo '<span class="description"><a href="' . esc_url( $engine['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open console', 'publishrank' ) . '</a>';
			if ( '' !== $value ) {
				$tag = '<meta name="' . $engine['name'] . '" content="' . $value . '" />';
				echo ' &middot; ' . $this->pill( isset( $check['tags'][ $key . ':' . $engine['name'] ] ) && $fresh ? $check['tags'][ $key . ':' . $engine['name'] ] : null );
				echo '</span><code class="rhs-vf-tag">' . esc_html( $tag ) . '</code>';
				echo '<button type="button" class="button button-small rhs-copy" data-copy="' . esc_attr( $tag ) . '">' . esc_html__( 'Copy tag', 'publishrank' ) . '</button>';
			} else {
				echo '</span>';
			}
			echo '</div>';
		}
		echo '</div>';
		echo '<h3>' . esc_html__( 'Any other verification tag', 'publishrank' ) . '</h3><p class="description">' . esc_html__( 'Paste one or more complete <meta> tags. Known engines are recognised automatically; others (Ahrefs, Norton, Yahoo Japan, Apple and so on) are printed as they are. Tags that could change crawling, such as robots or viewport, are refused.', 'publishrank' ) . '</p>';
		echo '<textarea class="large-text code" rows="4" name="custom" spellcheck="false" placeholder="' . esc_attr( '<meta name="example-site-verification" content="abc123" />' ) . '">' . esc_textarea( $custom ) . '</textarea>';
		echo '<h3>' . esc_html__( 'Where to print the tags', 'publishrank' ) . '</h3>';
		echo '<p><label><input type="radio" name="scope" value="all" ' . checked( 'all', $scope, false ) . '> ' . esc_html__( 'Every page (recommended, works for domain and URL-prefix properties)', 'publishrank' ) . '</label><br>';
		echo '<label><input type="radio" name="scope" value="home" ' . checked( 'home', $scope, false ) . '> ' . esc_html__( 'Home page only', 'publishrank' ) . '</label></p>';
		submit_button( __( 'Save and test', 'publishrank' ), 'primary', 'submit', false );
		echo '</div></form>';

		// Files.
		echo '<div class="rhs-card rhs-vf-card"><h2>' . esc_html__( 'Method 2: verification file', 'publishrank' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Upload the file you downloaded from Google (google….html), Bing (BingSiteAuth.xml), Yandex, Baidu or Naver. PublishRank serves it from your site root at the exact address the search engine expects. You do not need FTP and nothing is written to disk.', 'publishrank' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . $action . '" class="rhs-vf-upload"><input type="hidden" name="action" value="publishrank_verification_upload">'; wp_nonce_field( 'publishrank_verification_upload' );
		echo '<input type="file" name="verification_files[]" accept=".html,.htm,.xml,.txt" multiple required> '; submit_button( __( 'Upload and test', 'publishrank' ), 'primary', 'submit', false ); echo '</form>';
		echo '<details class="rhs-vf-details"><summary>' . esc_html__( 'No file to upload? Create it by pasting the content', 'publishrank' ) . '</summary>';
		echo '<form method="post" action="' . $action . '"><input type="hidden" name="action" value="publishrank_verification_create">'; wp_nonce_field( 'publishrank_verification_create' );
		echo '<p><label for="pr-vf-fname"><strong>' . esc_html__( 'File name', 'publishrank' ) . '</strong></label><br><input type="text" class="regular-text code" id="pr-vf-fname" name="file_name" placeholder="google1234567890abcdef.html" spellcheck="false" required></p>';
		echo '<p><label for="pr-vf-fcontent"><strong>' . esc_html__( 'File content', 'publishrank' ) . '</strong></label><br><textarea class="large-text code" rows="4" id="pr-vf-fcontent" name="file_content" placeholder="google-site-verification: google1234567890abcdef.html" spellcheck="false" required></textarea></p>';
		submit_button( __( 'Create and test', 'publishrank' ), 'secondary', 'submit', false ); echo '</form></details>';
		if ( $files ) {
			echo '<div class="rhs-table-wrap"><table class="widefat striped rhs-vf-table"><thead><tr><th>' . esc_html__( 'File', 'publishrank' ) . '</th><th>' . esc_html__( 'Public address', 'publishrank' ) . '</th><th>' . esc_html__( 'Status', 'publishrank' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $files as $name => $file ) {
				$url = SiteConnections::file_url( $name );
				echo '<tr><td><code>' . esc_html( $name ) . '</code><br><small>' . esc_html( size_format( strlen( $file['content'] ) ) ) . '</small></td>';
				echo '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a>' . ( ! empty( $file['physical'] ) ? '<br><small>' . esc_html__( 'Also written to the web root as a real file.', 'publishrank' ) . '</small>' : '' ) . '</td>';
				echo '<td>' . $this->pill( $fresh && isset( $check['files'][ $name ] ) ? $check['files'][ $name ] : null ) . '</td>';
				echo '<td><form method="post" action="' . $action . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Remove this verification file? The search engine may lose verification.', 'publishrank' ) ) ) . ');"><input type="hidden" name="action" value="publishrank_verification_delete"><input type="hidden" name="file_name" value="' . esc_attr( $name ) . '">'; wp_nonce_field( 'publishrank_verification_delete' );
				submit_button( __( 'Delete', 'publishrank' ), 'delete small', 'submit', false ); echo '</form></td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';

		// Help.
		echo '<div class="rhs-card rhs-vf-card"><h2>' . esc_html__( 'If verification fails', 'publishrank' ) . '</h2><ul class="ul-disc">';
		foreach ( array(
			__( 'Clear your page cache and CDN (Cloudflare, LiteSpeed, WP Rocket and similar). PublishRank purges the common ones automatically, but some hosts keep their own.', 'publishrank' ),
			__( 'Turn off maintenance or coming-soon mode and any login wall while verifying; search engines must see the public site.', 'publishrank' ),
			__( 'If another SEO plugin prints the same tag with a different value, the live check shows a warning. Remove the duplicate there.', 'publishrank' ),
			__( 'If a file address returns 403 or 404 in the check, a security plugin, firewall or server rule is blocking it. Allow that exact file name, or use the meta tag method instead (it works on every host).', 'publishrank' ),
			__( 'Keep the tag or file in place after verifying. Removing it can cause the search engine to drop verification later.', 'publishrank' ),
		) as $tip ) { echo '<li>' . esc_html( $tip ) . '</li>'; }
		echo '</ul></div></div>';
	}

	/** Status pill for a check row, or a neutral pill when not checked. */
	private function pill( $row ) {
		if ( ! is_array( $row ) || empty( $row['status'] ) ) { return '<span class="rhs-pill rhs-pill--low">' . esc_html__( 'Not checked', 'publishrank' ) . '</span>'; }
		$map = array( 'ok' => array( 'pass', __( 'Live', 'publishrank' ) ), 'warn' => array( 'warning', __( 'Warning', 'publishrank' ) ), 'bad' => array( 'error', __( 'Problem', 'publishrank' ) ) );
		$cfg = isset( $map[ $row['status'] ] ) ? $map[ $row['status'] ] : $map['bad'];
		return '<span class="rhs-pill rhs-pill--' . esc_attr( $cfg[0] ) . '" title="' . esc_attr( isset( $row['message'] ) ? $row['message'] : '' ) . '">' . esc_html( $cfg[1] ) . '</span>' . ( ! empty( $row['message'] ) && 'ok' !== $row['status'] ? ' <small class="rhs-vf-msg">' . esc_html( $row['message'] ) . '</small>' : '' );
	}
}
