<?php
/**
 * Podcast support: PodcastEpisode / PodcastSeries schema, og:audio tags and an episodes sitemap.
 *
 * Works with Seriously Simple Podcasting, PowerPress, Podlove and plain posts that contain an
 * audio enclosure or an audio block.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Integrations;

defined( 'ABSPATH' ) || exit;

final class PodcastIntegration extends IntegrationBase {
	protected $module = 'podcast';

	/** Podcast support is useful even without a podcast plugin (audio enclosures), so it is always "detected". */
	public function detected() {
		return true;
	}

	public function register() {
		add_filter( 'publishrank_schema_graph', array( $this, 'schema' ), 20 );
		add_action( 'wp_head', array( $this, 'og_audio' ), 5 );
		add_filter( 'publishrank_sitemap_providers', array( $this, 'sitemap' ) );
		add_filter( 'publishrank_sitemap_cpt_excluded', array( $this, 'exclude_cpt' ) );
		add_filter( 'publishrank_sitemap_type_labels', array( $this, 'labels' ) );
	}

	/** Dedicated podcast post types (SSP and Podlove both use "podcast"). */
	private function post_types() {
		$types = array();
		foreach ( array( 'podcast', 'episode' ) as $type ) {
			if ( post_type_exists( $type ) ) {
				$types[] = $type;
			}
		}
		if ( function_exists( 'ssp_post_types' ) ) {
			$types = array_merge( $types, array_diff( (array) ssp_post_types( false ), array( 'post', 'page' ) ) );
		}
		return array_values( array_unique( (array) apply_filters( 'publishrank_podcast_post_types', $types ) ) );
	}

	public function sitemap( $providers ) {
		$self        = $this;
		$providers[] = new PostTypeSitemapProvider(
			'podcast',
			__( 'Podcast episodes', 'publishrank' ),
			array( $this, 'types_public' ),
			function () use ( $self ) {
				return $self->active() && ! empty( $self->types_public() );
			}
		);
		return $providers;
	}

	public function types_public() {
		return $this->post_types();
	}

	public function exclude_cpt( $excluded ) {
		if ( $this->active() ) {
			$excluded = array_merge( (array) $excluded, $this->post_types() );
		}
		return $excluded;
	}

	public function labels( $types ) {
		$types['podcast'] = array(
			'label' => __( 'Podcast', 'publishrank' ),
			'desc'  => __( 'Podcast episodes with audio', 'publishrank' ),
			'abbr'  => 'PC',
		);
		return $types;
	}

	/**
	 * Audio details for a post.
	 *
	 * @return array{url:string,duration:string,type:string,size:int}|null
	 */
	public function episode( $post_id ) {
		$post_id = (int) $post_id;
		$url = $duration = $type = '';
		$size   = 0;
		$source = '';
		// Seriously Simple Podcasting.
		$ssp = (string) get_post_meta( $post_id, 'audio_file', true );
		if ( '' !== $ssp ) {
			$url      = $ssp;
			$source   = 'plugin';
			$duration = (string) get_post_meta( $post_id, 'duration', true );
			$size     = (int) get_post_meta( $post_id, 'filesize_raw', true );
		}
		// PowerPress / core enclosures: "url\nsize\nmime\nserialized-extra".
		if ( '' === $url ) {
			$enclosure = (string) get_post_meta( $post_id, 'enclosure', true );
			if ( '' === $enclosure ) {
				$enclosure = (string) get_post_meta( $post_id, '_podcast', true );
			}
			if ( '' !== $enclosure ) {
				$lines = array_map( 'trim', explode( "\n", $enclosure ) );
				if ( ! empty( $lines[0] ) && 0 === strpos( (string) ( $lines[2] ?? 'audio' ), 'audio' ) ) {
					$url    = $lines[0];
					$source = 'enclosure';
					$size   = (int) ( $lines[1] ?? 0 );
					$type = (string) ( $lines[2] ?? '' );
					$extra = isset( $lines[3] ) ? maybe_unserialize( $lines[3] ) : array();
					if ( is_array( $extra ) && ! empty( $extra['duration'] ) ) {
						$duration = (string) $extra['duration'];
					}
				}
			}
		}
		if ( '' === $url && class_exists( '\\PublishRankSEO\\Meta\\PostMeta' ) ) {
			$own = (string) get_post_meta( $post_id, \PublishRankSEO\Meta\PostMeta::AUDIO_URL, true );
			$own_type = (string) get_post_meta( $post_id, \PublishRankSEO\Meta\PostMeta::SCHEMA_TYPE, true );
			if ( '' !== $own && 'PodcastEpisode' === $own_type ) {
				$url    = $own;
				$source = 'publishrank';
			}
		}
		// Audio block or [audio] shortcode in content.
		if ( '' === $url ) {
			$content = (string) get_post_field( 'post_content', $post_id );
			if ( preg_match( '#<audio[^>]+src=["\']([^"\']+)["\']#i', $content, $m ) || preg_match( '#\[audio[^\]]*(?:mp3|m4a|ogg|wav|src)=["\']([^"\']+)["\']#i', $content, $m ) ) {
				$url    = html_entity_decode( $m[1] );
				$source = 'content';
			}
		}
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return null;
		}
		if ( '' === $type ) {
			$check = wp_check_filetype( wp_parse_url( $url, PHP_URL_PATH ) ?: '' );
			$type  = $check['type'] ? $check['type'] : 'audio/mpeg';
		}
		// PublishRank's own audio fields (Schema Selection box) take part too.
		if ( '' === $duration && class_exists( '\\PublishRankSEO\\Meta\\PostMeta' ) ) {
			$duration = (string) get_post_meta( $post_id, \PublishRankSEO\Meta\PostMeta::AUDIO_DURATION, true );
		}
		$iso = 0 === strpos( strtoupper( trim( $duration ) ), 'P' ) ? strtoupper( trim( $duration ) ) : self::iso_duration( $duration );
		return array( 'url' => $url, 'duration' => $iso, 'type' => $type, 'size' => $size, 'source' => $source );
	}

	public function og_audio() {
		if ( ! $this->active() || ! is_singular() || is_admin() ) {
			return;
		}
		$ep = $this->episode( get_queried_object_id() );
		if ( ! $ep ) {
			return;
		}
		echo '<meta property="og:audio" content="' . esc_url( $ep['url'] ) . '" />' . "\n";
		if ( 0 === strpos( $ep['url'], 'https://' ) ) {
			echo '<meta property="og:audio:secure_url" content="' . esc_url( $ep['url'] ) . '" />' . "\n";
		}
		echo '<meta property="og:audio:type" content="' . esc_attr( $ep['type'] ) . '" />' . "\n";
	}

	/** Episode pages get PodcastEpisode + AudioObject + PodcastSeries/Season; show pages get PodcastSeries. */
	public function schema( $graph ) {
		if ( ! $this->active() ) {
			return $graph;
		}
		return is_singular() ? $this->episode_graph( $graph ) : $this->show_graph( $graph );
	}

	/** Drop empty values (but keep false and 0) so no empty property is ever printed. */
	private static function prune( array $node ) {
		foreach ( $node as $key => $value ) {
			if ( null === $value || '' === $value || array() === $value ) {
				unset( $node[ $key ] );
			}
		}
		return $node;
	}

	private function identity_ref() {
		$base = trailingslashit( \PublishRankSEO\Core\PublicationProfile::primary_url() );
		return array( '@id' => $base . ( \PublishRankSEO\Core\PublicationProfile::identity_is_person() ? '#person' : '#organization' ) );
	}

	/**
	 * Only real podcast episodes get PodcastEpisode markup: dedicated podcast post types, podcast plugin
	 * audio, feed enclosures, or posts the editor marked as Podcast Episode. A normal news article that
	 * merely embeds an audio clip is not an episode (Google: markup must describe the page's main content).
	 */
	private function is_episode( $post, array $ep ) {
		if ( in_array( $post->post_type, $this->post_types(), true ) || in_array( $ep['source'], array( 'plugin', 'enclosure', 'publishrank' ), true ) ) {
			return true;
		}
		$in_podcast_term = has_term( array( 'podcast', 'podcasts' ), 'category', $post );
		return (bool) apply_filters( 'publishrank_podcast_is_episode', $in_podcast_term, $post, $ep );
	}

	private function episode_graph( $graph ) {
		$post_id = (int) get_queried_object_id();
		$ep      = $this->episode( $post_id );
		$post    = get_post( $post_id );
		if ( ! $ep || ! $post || ! $this->is_episode( $post, $ep ) ) {
			return $graph;
		}
		$url         = get_permalink( $post_id );
		$base        = trailingslashit( $url );
		$title       = wp_strip_all_tags( get_the_title( $post_id ) );
		$description = '';
		if ( class_exists( '\\PublishRankSEO\\Meta\\SeoData' ) ) {
			$seo         = \PublishRankSEO\Meta\SeoData::for_post( $post );
			$description = isset( $seo['description'] ) ? wp_strip_all_tags( (string) $seo['description'] ) : '';
		}
		if ( '' === $description ) {
			$description = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ), 60, '…' );
		}
		$language    = str_replace( '_', '-', (string) get_locale() );

		$audio = array(
			'@type'          => 'AudioObject',
			'@id'            => $base . '#audio',
			'name'           => $title,
			'contentUrl'     => $ep['url'],
			'encodingFormat' => $ep['type'],
			'duration'       => $ep['duration'],
			'contentSize'    => $ep['size'] > 0 ? (string) size_format( $ep['size'], 1 ) : '',
			'uploadDate'     => get_post_time( DATE_W3C, true, $post ),
			'description'    => $description,
			'inLanguage'     => $language,
		);
		$transcript = (string) apply_filters( 'publishrank_podcast_transcript', '', $post_id );
		if ( '' !== trim( $transcript ) ) {
			$audio['transcript'] = wp_trim_words( wp_strip_all_tags( $transcript ), 2000, '' );
		}

		$node = array(
			'@type'            => 'PodcastEpisode',
			'@id'              => $base . '#podcast-episode',
			'url'              => $url,
			'name'             => $title,
			'description'      => $description,
			'datePublished'    => get_post_time( DATE_W3C, true, $post ),
			'dateModified'     => get_post_modified_time( DATE_W3C, true, $post ),
			'inLanguage'       => $language,
			'mainEntityOfPage' => array( '@id' => trailingslashit( $this->canonical() ) . '#webpage' ),
			'associatedMedia'  => array( '@id' => $base . '#audio' ),
			'audio'            => array( '@id' => $base . '#audio' ),
			'publisher'        => $this->identity_ref(),
			'duration'         => $ep['duration'],
			'potentialAction'  => array(
				'@type'  => 'ListenAction',
				'target' => $url,
			),
		);
		$number = 0;
		foreach ( array( 'itunes_episode_number', 'episode_number', 'podcast_episode_number' ) as $number_key ) {
			$number = (int) get_post_meta( $post_id, $number_key, true );
			if ( $number > 0 ) {
				break;
			}
		}
		if ( $number > 0 ) {
			$node['episodeNumber'] = $number;
		}
		$author = (string) get_the_author_meta( 'display_name', (int) $post->post_author );
		if ( '' !== $author ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => $author,
				'url'   => (string) get_author_posts_url( (int) $post->post_author ),
			);
		}
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		$thumb    = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'full' ) : false;
		if ( is_array( $thumb ) && ! empty( $thumb[0] ) ) {
			$node['image'] = self::prune(
				array(
					'@type'  => 'ImageObject',
					'url'    => $thumb[0],
					'width'  => ! empty( $thumb[1] ) ? (int) $thumb[1] : null,
					'height' => ! empty( $thumb[2] ) ? (int) $thumb[2] : null,
				)
			);
		}
		$tags = get_the_tags( $post_id );
		if ( is_array( $tags ) && $tags ) {
			$node['keywords'] = implode( ', ', array_slice( wp_list_pluck( $tags, 'name' ), 0, 20 ) );
		}
		$explicit = strtolower( trim( (string) get_post_meta( $post_id, 'explicit', true ) ) );
		if ( in_array( $explicit, array( 'on', 'yes', 'explicit', 'true', '1' ), true ) ) {
			$node['isFamilyFriendly'] = false;
		} elseif ( in_array( $explicit, array( 'off', 'no', 'clean', 'false', '0' ), true ) ) {
			$node['isFamilyFriendly'] = true;
		}
		$series = $this->series_node( $post_id );
		if ( $series ) {
			$graph[]              = $series;
			$node['partOfSeries'] = array( '@id' => $series['@id'] );
			$season               = (int) get_post_meta( $post_id, 'itunes_season_number', true );
			if ( $season > 0 ) {
				$season_id = $series['@id'] . '-season-' . $season;
				$graph[]   = array(
					'@type'        => 'PodcastSeason',
					'@id'          => $season_id,
					/* translators: %d: season number. */
					'name'         => sprintf( __( 'Season %d', 'publishrank' ), $season ),
					'seasonNumber' => $season,
					'partOfSeries' => array( '@id' => $series['@id'] ),
				);
				$node['partOfSeason'] = array( '@id' => $season_id );
			}
		}
		// Chapters: array of array( 'title' => string, 'start' => seconds, 'end' => seconds (optional) ).
		$parts = array();
		foreach ( (array) apply_filters( 'publishrank_podcast_chapters', array(), $post_id ) as $chapter ) {
			if ( ! is_array( $chapter ) || empty( $chapter['title'] ) || ! isset( $chapter['start'] ) ) {
				continue;
			}
			$clip = array(
				'@type'       => 'Clip',
				'name'        => wp_strip_all_tags( (string) $chapter['title'] ),
				'startOffset' => (int) $chapter['start'],
				'url'         => $url,
			);
			if ( isset( $chapter['end'] ) ) {
				$clip['endOffset'] = (int) $chapter['end'];
			}
			$parts[] = $clip;
		}
		if ( $parts ) {
			$node['hasPart'] = $parts;
		}
		// Merge with a PodcastEpisode/AudioObject already built from the Schema Selection box (same @id) instead of duplicating it.
		foreach ( array( self::prune( $audio ), self::prune( $node ) ) as $new ) {
			$merged = false;
			foreach ( $graph as $i => $existing ) {
				if ( is_array( $existing ) && isset( $existing['@id'] ) && $existing['@id'] === $new['@id'] ) {
					$graph[ $i ] = array_merge( $existing, $new );
					$merged      = true;
					break;
				}
			}
			if ( ! $merged ) {
				$graph[] = $new;
			}
		}
		return $graph;
	}

	/** Show pages (series archive, podcast post-type archive) are about the PodcastSeries. */
	private function show_graph( $graph ) {
		$term = null;
		if ( taxonomy_exists( 'series' ) && is_tax( 'series' ) ) {
			$queried = get_queried_object();
			if ( ! $queried instanceof \WP_Term ) {
				return $graph;
			}
			$term = $queried;
		} elseif ( ! ( is_post_type_archive() && array_intersect( (array) get_query_var( 'post_type' ), $this->post_types() ) ) ) {
			return $graph;
		}
		$series = $this->series_node( 0, $term );
		if ( ! $series ) {
			return $graph;
		}
		$graph[] = $series;
		foreach ( $graph as $i => $node ) {
			if ( is_array( $node ) && isset( $node['@id'] ) && '#webpage' === substr( (string) $node['@id'], -8 ) ) {
				$graph[ $i ]['about'] = array( '@id' => $series['@id'] );
				break;
			}
		}
		return $graph;
	}

	/** Seriously Simple Podcasting stores show data per series ("..._<field>_<term id>") with a site-wide fallback. */
	private function ssp_option( $field, $term_id ) {
		$value = '';
		if ( $term_id ) {
			$value = get_option( 'ss_podcasting_data_' . $field . '_' . (int) $term_id, '' );
		}
		if ( '' === $value || false === $value ) {
			$value = get_option( 'ss_podcasting_data_' . $field, '' );
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** PodcastSeries node from the SSP series term, or the site-wide podcast settings; null when no show name is known. */
	private function series_node( $post_id, $term = null ) {
		if ( null === $term && $post_id && taxonomy_exists( 'series' ) ) {
			$terms = get_the_terms( $post_id, 'series' );
			$term  = ( is_array( $terms ) && $terms ) ? reset( $terms ) : null;
		}
		$has_term = $term instanceof \WP_Term;
		$term_id  = $has_term ? (int) $term->term_id : 0;
		$pp       = get_option( 'powerpress_general' );
		$name     = $has_term ? (string) $term->name : '';
		if ( '' === $name ) {
			$name = $this->ssp_option( 'title', 0 );
		}
		if ( '' === $name && is_array( $pp ) && ! empty( $pp['title'] ) ) {
			$name = (string) $pp['title'];
		}
		if ( '' === $name ) {
			$name = (string) apply_filters( 'publishrank_podcast_series_name', '' );
		}
		if ( '' === $name ) {
			return null;
		}
		$link = $has_term ? get_term_link( $term ) : home_url( '/' );
		$url  = is_wp_error( $link ) ? home_url( '/' ) : (string) $link;
		$feed = '';
		if ( function_exists( 'ssp_get_feed_url' ) ) {
			$feed = (string) ssp_get_feed_url( $has_term ? $term->slug : '' );
		} elseif ( is_array( $pp ) ) {
			$feed = (string) get_feed_link( 'podcast' );
		}
		$description = $has_term ? trim( wp_strip_all_tags( (string) $term->description ) ) : '';
		if ( '' === $description ) {
			$description = wp_strip_all_tags( $this->ssp_option( 'description', $term_id ) );
		}
		$image = $this->ssp_option( 'image', $term_id );
		if ( '' !== $image && ctype_digit( $image ) ) {
			$image = (string) wp_get_attachment_url( (int) $image );
		}
		$language = $this->ssp_option( 'language', $term_id );
		$same_as  = array_values( array_filter( array_map( 'esc_url_raw', (array) apply_filters( 'publishrank_podcast_series_same_as', array(), $term_id ) ) ) );
		return self::prune(
			array(
				'@type'       => 'PodcastSeries',
				'@id'         => trailingslashit( $url ) . '#podcast-series',
				'name'        => wp_strip_all_tags( $name ),
				'url'         => $url,
				'description' => $description,
				'image'       => '' !== $image ? esc_url_raw( $image ) : '',
				'webFeed'     => '' !== $feed ? esc_url_raw( $feed ) : '',
				'inLanguage'  => str_replace( '_', '-', '' !== $language ? $language : (string) get_locale() ),
				'publisher'   => $this->identity_ref(),
				'sameAs'      => $same_as,
			)
		);
	}
}
