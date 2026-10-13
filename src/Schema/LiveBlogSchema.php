<?php
/**
 * LiveBlogPosting support (https://schema.org/LiveBlogPosting).
 *
 * A post becomes a LiveBlogPosting only when an editor explicitly chooses
 * "Live Blog Post" as the schema type (or sets the live news format). Individual
 * live updates are stored as structured entries so each one carries a real
 * timestamp; paragraphs of ordinary content are never treated as updates.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Schema;

use PublishRankSEO\Meta\PostMeta;

defined( 'ABSPATH' ) || exit;

final class LiveBlogSchema {
	const UPDATES        = '_publishrank_seo_live_updates';
	const COVERAGE_START = '_publishrank_seo_live_coverage_start';
	const COVERAGE_END   = '_publishrank_seo_live_coverage_end';
	const NONCE_ACTION   = 'publishrank_live_blog_save';
	const NONCE_FIELD    = 'publishrank_live_blog_nonce';
	const MAX_UPDATES    = 100;

	public function register() {
		add_action( 'save_post', array( $this, 'save' ), 12, 2 );
	}

	/** True only for an explicit editor choice; titles and categories never trigger it. */
	public static function is_live_blog( $post ) {
		if ( ! $post instanceof \WP_Post ) { return false; }
		if ( 'LiveBlogPosting' === SchemaTypes::resolve( PostMeta::get( $post->ID, PostMeta::SCHEMA_TYPE, '' ) ) ) { return true; }
		return 'live' === sanitize_key( (string) get_post_meta( $post->ID, '_publishrank_seo_news_format', true ) );
	}

	/**
	 * Add LiveBlogPosting-only properties to an article node built by GraphOutput.
	 *
	 * @param array<string,mixed> $article
	 * @return array<string,mixed>
	 */
	public static function decorate( array $article, $post ) {
		$article['@type'] = 'LiveBlogPosting';
		unset( $article['coverageStartTime'] ); // Never derived from the publish date.
		$updates = self::updates( $post->ID );
		$start   = self::time_meta( $post->ID, self::COVERAGE_START );
		if ( '' === $start && $updates ) { $start = $updates[0]['time']; } // Earliest real update.
		$end = self::time_meta( $post->ID, self::COVERAGE_END );
		if ( '' !== $start ) { $article['coverageStartTime'] = $start; }
		if ( '' !== $end && ( '' === $start || strtotime( $end ) >= strtotime( $start ) ) ) { $article['coverageEndTime'] = $end; }
		if ( $updates ) {
			$base = trailingslashit( (string) get_permalink( $post ) );
			$list = array();
			// Newest first, the convention for live coverage.
			foreach ( array_reverse( $updates ) as $update ) {
				$node = array(
					'@type'         => 'BlogPosting',
					'@id'           => $base . '#live-update-' . $update['id'],
					'url'           => $base . '#live-update-' . $update['id'],
					'datePublished' => $update['time'],
					'articleBody'   => $update['body'],
				);
				if ( '' !== $update['headline'] ) { $node['headline'] = $update['headline']; }
				if ( '' !== $update['author'] ) { $node['author'] = array( '@type' => 'Person', 'name' => $update['author'] ); }
				$list[] = $node;
			}
			$article['liveBlogUpdate'] = $list;
		}
		return $article;
	}

	/**
	 * Stored updates, oldest first, validated.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function updates( $post_id ) {
		$raw = get_post_meta( $post_id, self::UPDATES, true );
		if ( ! is_array( $raw ) ) { return array(); }
		$out = array();
		foreach ( $raw as $item ) {
			$clean = self::clean_update( $item );
			if ( $clean ) { $out[] = $clean; }
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $a['time'], $b['time'] ); } );
		return array_slice( $out, 0, self::MAX_UPDATES );
	}

	/** @return array<string,string>|null Null when the entry has no usable body or timestamp. */
	public static function clean_update( $item ) {
		if ( ! is_array( $item ) ) { return null; }
		$body = trim( wp_strip_all_tags( (string) ( $item['body'] ?? '' ) ) );
		$time = self::to_utc( (string) ( $item['time'] ?? '' ) );
		if ( '' === $body || '' === $time ) { return null; }
		$id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $item['id'] ?? '' ) ) );
		if ( '' === $id ) { $id = substr( md5( $time . $body ), 0, 10 ); }
		return array(
			'id'       => substr( $id, 0, 20 ),
			'time'     => $time,
			'headline' => substr( trim( wp_strip_all_tags( (string) ( $item['headline'] ?? '' ) ) ), 0, 110 ),
			'body'     => substr( $body, 0, 5000 ),
			'author'   => substr( trim( wp_strip_all_tags( (string) ( $item['author'] ?? '' ) ) ), 0, 100 ),
		);
	}

	/** Parse a datetime and return an ISO-8601 UTC string; empty when it is not a valid date. Naive values are read in the site timezone. */
	public static function to_utc( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 40 ) { return ''; }
		try {
			$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$date = new \DateTimeImmutable( $value, $tz );
		} catch ( \Exception $e ) {
			return '';
		}
		$ts = $date->getTimestamp();
		return $ts > 0 ? gmdate( 'c', $ts ) : '';
	}

	private static function time_meta( $post_id, $key ) {
		return self::to_utc( (string) get_post_meta( $post_id, $key, true ) );
	}

	/** Editor panel, rendered inside the existing Schema meta box (shown when "Live Blog Post" is selected). */
	public static function render_panel( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$local = static function ( $iso ) use ( $tz ) { if ( '' === $iso ) { return ''; } try { return ( new \DateTimeImmutable( $iso ) )->setTimezone( $tz )->format( 'Y-m-d\TH:i' ); } catch ( \Exception $e ) { return ''; } };
		echo '<div class="pr-schema-panel" data-types="LiveBlogPosting" style="display:none"><hr /><h4>' . esc_html__( 'Live coverage', 'publishrank' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Times use the site timezone. Leave coverage start empty to use the earliest update. Leave coverage end empty while coverage is ongoing. Only add entries that are genuine, separately timestamped updates.', 'publishrank' ) . '</p>';
		echo '<p><label><strong>' . esc_html__( 'Coverage start', 'publishrank' ) . '</strong><br /><input type="datetime-local" name="publishrank_live_start" value="' . esc_attr( $local( self::time_meta( $post->ID, self::COVERAGE_START ) ) ) . '" /></label> ';
		echo '<label><strong>' . esc_html__( 'Coverage end', 'publishrank' ) . '</strong><br /><input type="datetime-local" name="publishrank_live_end" value="' . esc_attr( $local( self::time_meta( $post->ID, self::COVERAGE_END ) ) ) . '" /></label></p>';
		$updates = self::updates( $post->ID );
		$updates[] = array( 'id' => '', 'time' => '', 'headline' => '', 'body' => '', 'author' => '' ); // One blank row for a new update.
		foreach ( $updates as $i => $u ) {
			$n = 'publishrank_live_updates[' . (int) $i . ']';
			echo '<fieldset style="border:1px solid #dcdcde;padding:8px;margin:0 0 8px"><legend>' . esc_html( sprintf( /* translators: %d: update number */ __( 'Update %d', 'publishrank' ), $i + 1 ) ) . '</legend>';
			echo '<input type="hidden" name="' . esc_attr( $n ) . '[id]" value="' . esc_attr( $u['id'] ) . '" />';
			echo '<p><label>' . esc_html__( 'Time', 'publishrank' ) . ' <input type="datetime-local" name="' . esc_attr( $n ) . '[time]" value="' . esc_attr( $local( $u['time'] ) ) . '" /></label></p>';
			echo '<p><input class="widefat" type="text" name="' . esc_attr( $n ) . '[headline]" maxlength="110" value="' . esc_attr( $u['headline'] ) . '" placeholder="' . esc_attr__( 'Headline (optional)', 'publishrank' ) . '" /></p>';
			echo '<p><textarea class="widefat" rows="3" name="' . esc_attr( $n ) . '[body]" placeholder="' . esc_attr__( 'Update text', 'publishrank' ) . '">' . esc_textarea( $u['body'] ) . '</textarea></p>';
			echo '<p><input class="widefat" type="text" name="' . esc_attr( $n ) . '[author]" value="' . esc_attr( $u['author'] ) . '" placeholder="' . esc_attr__( 'Author (optional)', 'publishrank' ) . '" /></p></fieldset>';
		}
		echo '</div>';
	}

	public function save( $post_id, $post = null ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) { return; }
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$clean = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitized in clean_update().
		$posted = isset( $_POST['publishrank_live_updates'] ) && is_array( $_POST['publishrank_live_updates'] ) ? wp_unslash( $_POST['publishrank_live_updates'] ) : array();
		foreach ( array_slice( $posted, 0, self::MAX_UPDATES ) as $item ) {
			$entry = self::clean_update( $item );
			if ( $entry ) { $clean[] = $entry; }
		}
		if ( $clean ) { update_post_meta( $post_id, self::UPDATES, $clean ); } else { delete_post_meta( $post_id, self::UPDATES ); }
		foreach ( array( 'publishrank_live_start' => self::COVERAGE_START, 'publishrank_live_end' => self::COVERAGE_END ) as $field => $meta ) {
			$utc = isset( $_POST[ $field ] ) ? self::to_utc( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ) : '';
			if ( '' !== $utc ) { update_post_meta( $post_id, $meta, $utc ); } else { delete_post_meta( $post_id, $meta ); }
		}
	}
}
