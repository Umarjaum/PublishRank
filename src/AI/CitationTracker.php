<?php
/**
 * AI citation tracker — runs the publisher's own tracked questions through AI answer engines
 * with the publisher's own API keys (OpenAI, Anthropic, Perplexity, Google Gemini) and records
 * whether the site is cited (linked as a source), mentioned by name, and which competitors appear.
 *
 * Keys are stored encrypted (libsodium) and are only sent to the provider's own HTTPS API with
 * wp_safe_remote_post (TLS verified, response size limited). Nothing runs until the publisher
 * adds a key, a question and presses "Run now" or picks a schedule.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AI;

use PublishRankSEO\AccessShield\Crypto;
use PublishRankSEO\Core\Database;

defined( 'ABSPATH' ) || exit;

final class CitationTracker {
	const OPTION    = 'publishrank_ai_citations';
	const JOB       = 'publishrank_ai_citation_job';
	const CRON      = 'publishrank_ai_citation_batch';
	const SCHEDULE  = 'publishrank_ai_citation_schedule';
	const MAX_PROMPTS = 50;
	const MAX_BYTES   = 2097152;

	public static function providers() {
		return array(
			'openai'     => array( 'label' => 'OpenAI (ChatGPT search)', 'model' => 'gpt-5-mini', 'docs' => 'https://platform.openai.com/api-keys' ),
			'anthropic'  => array( 'label' => 'Anthropic (Claude with web search)', 'model' => 'claude-haiku-4-5', 'docs' => 'https://console.anthropic.com/settings/keys' ),
			'perplexity' => array( 'label' => 'Perplexity (Sonar)', 'model' => 'sonar', 'docs' => 'https://www.perplexity.ai/settings/api' ),
			'gemini'     => array( 'label' => 'Google Gemini (Search grounding)', 'model' => 'gemini-2.5-flash', 'docs' => 'https://aistudio.google.com/apikey' ),
		);
	}

	public static function defaults() {
		$p = array();
		foreach ( self::providers() as $k => $d ) {
			$p[ $k ] = array( 'enabled' => 0, 'key' => '', 'model' => $d['model'] );
		}
		return array(
			'providers'   => $p,
			'prompts'     => array(),
			'brand'       => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'domains'     => wp_parse_url( home_url(), PHP_URL_HOST ),
			'competitors' => '',
			'schedule'    => 'off',
			'batch'       => 8,
			'monthly_cap' => 400,
			'usage'       => array(),
			'location'    => '',
		);
	}

	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		$d = self::defaults();
		$s = array_merge( $d, $s );
		foreach ( $d['providers'] as $k => $v ) {
			$s['providers'][ $k ] = array_merge( $v, isset( $s['providers'][ $k ] ) && is_array( $s['providers'][ $k ] ) ? $s['providers'][ $k ] : array() );
		}
		return $s;
	}

	public static function save( array $in ) {
		$s = self::settings();
		foreach ( self::providers() as $k => $d ) {
			$p                 = isset( $in['providers'][ $k ] ) && is_array( $in['providers'][ $k ] ) ? $in['providers'][ $k ] : array();
			$s['providers'][ $k ]['enabled'] = empty( $p['enabled'] ) ? 0 : 1;
			$model = isset( $p['model'] ) ? preg_replace( '/[^A-Za-z0-9._:\-\/]/', '', (string) $p['model'] ) : '';
			$s['providers'][ $k ]['model'] = '' !== $model ? substr( $model, 0, 80 ) : $d['model'];
			if ( ! empty( $p['clear'] ) ) {
				$s['providers'][ $k ]['key']     = '';
				$s['providers'][ $k ]['enabled'] = 0;
			} elseif ( isset( $p['key'] ) && '' !== trim( (string) $p['key'] ) ) {
				$key = trim( (string) $p['key'] );
				if ( preg_match( '/^[A-Za-z0-9_\-.]{20,300}$/', $key ) ) {
					$s['providers'][ $k ]['key'] = Crypto::encrypt( $key );
				}
			}
			if ( '' === $s['providers'][ $k ]['key'] ) {
				$s['providers'][ $k ]['enabled'] = 0;
			}
		}
		if ( isset( $in['prompts'] ) ) {
			$prompts = array();
			$lines   = is_array( $in['prompts'] ) ? $in['prompts'] : preg_split( '/\r\n|\r|\n/', (string) $in['prompts'] );
			$old     = array();
			foreach ( $s['prompts'] as $p ) {
				$old[ md5( strtolower( $p['text'] ) ) ] = $p['id'];
			}
			foreach ( $lines as $line ) {
				$text = trim( sanitize_text_field( (string) $line ) );
				if ( strlen( $text ) < 5 ) {
					continue;
				}
				$h         = md5( strtolower( $text ) );
				$prompts[] = array( 'id' => isset( $old[ $h ] ) ? $old[ $h ] : substr( $h, 0, 12 ), 'text' => substr( $text, 0, 400 ) );
				if ( count( $prompts ) >= self::MAX_PROMPTS ) {
					break;
				}
			}
			$s['prompts'] = $prompts;
		}
		foreach ( array( 'brand', 'domains', 'competitors', 'location' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$s[ $k ] = substr( sanitize_text_field( (string) $in[ $k ] ), 0, 1000 );
			}
		}
		if ( isset( $in['schedule'] ) ) {
			$s['schedule'] = in_array( $in['schedule'], array( 'off', 'daily', 'weekly' ), true ) ? $in['schedule'] : 'off';
		}
		if ( isset( $in['batch'] ) ) {
			$s['batch'] = min( 25, max( 1, (int) $in['batch'] ) );
		}
		if ( isset( $in['monthly_cap'] ) ) {
			$s['monthly_cap'] = min( 100000, max( 1, (int) $in['monthly_cap'] ) );
		}
		update_option( self::OPTION, $s, false );
		self::reschedule( $s['schedule'] );
	}

	public static function register() {
		add_action( self::CRON, array( __CLASS__, 'process_batch' ) );
		add_action( self::SCHEDULE, array( __CLASS__, 'start_run' ) );
	}

	private static function reschedule( $when ) {
		wp_clear_scheduled_hook( self::SCHEDULE );
		if ( in_array( $when, array( 'daily', 'weekly' ), true ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $when, self::SCHEDULE );
		}
	}

	private static function key( $provider ) {
		$s = self::settings();
		return isset( $s['providers'][ $provider ]['key'] ) ? (string) Crypto::decrypt( $s['providers'][ $provider ]['key'] ) : '';
	}

	/** @return string[] */
	public static function active_providers() {
		$out = array();
		foreach ( self::settings()['providers'] as $k => $p ) {
			if ( ! empty( $p['enabled'] ) && '' !== (string) $p['key'] ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------- Runs */

	public static function usage_this_month() {
		$u = self::settings()['usage'];
		return isset( $u[ gmdate( 'Y-m' ) ] ) ? (int) $u[ gmdate( 'Y-m' ) ] : 0;
	}

	private static function add_usage( $n ) {
		$s                = self::settings();
		$m                = gmdate( 'Y-m' );
		$s['usage'][ $m ] = ( isset( $s['usage'][ $m ] ) ? (int) $s['usage'][ $m ] : 0 ) + $n;
		$s['usage']       = array_slice( $s['usage'], -12, null, true );
		update_option( self::OPTION, $s, false );
	}

	/** Queue every question × provider. @return string|\WP_Error run id */
	public static function start_run() {
		$s     = self::settings();
		$provs = self::active_providers();
		if ( ! $provs ) {
			return new \WP_Error( 'no_provider', __( 'Add at least one API key and switch the provider on.', 'publishrank' ) );
		}
		if ( ! $s['prompts'] ) {
			return new \WP_Error( 'no_prompts', __( 'Add at least one question to track.', 'publishrank' ) );
		}
		$items = array();
		foreach ( $s['prompts'] as $p ) {
			foreach ( $provs as $prov ) {
				$items[] = array( $p['id'], $prov );
			}
		}
		$left = (int) $s['monthly_cap'] - self::usage_this_month();
		if ( $left <= 0 ) {
			return new \WP_Error( 'cap', __( 'This month’s request limit has been reached. Raise it in Settings or wait for next month.', 'publishrank' ) );
		}
		$items = array_slice( $items, 0, $left );
		$run   = wp_generate_uuid4();
		update_option( self::JOB, array( 'run' => $run, 'items' => $items, 'total' => count( $items ), 'done' => 0, 'errors' => 0, 'started' => time() ), false );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 5, self::CRON );
		}
		return $run;
	}

	public static function job() {
		$j = get_option( self::JOB, array() );
		return is_array( $j ) && ! empty( $j['run'] ) ? $j : null;
	}

	public static function cancel() {
		delete_option( self::JOB );
		wp_clear_scheduled_hook( self::CRON );
	}

	/** Process up to "batch" calls (and at most ~25 s). @return int calls made */
	public static function process_batch() {
		$job = self::job();
		if ( ! $job ) {
			return 0;
		}
		$s       = self::settings();
		$prompts = array();
		foreach ( $s['prompts'] as $p ) {
			$prompts[ $p['id'] ] = $p['text'];
		}
		$start = microtime( true );
		$n     = 0;
		while ( $job['items'] && $n < (int) $s['batch'] && microtime( true ) - $start < 25 ) {
			list( $pid, $prov ) = array_shift( $job['items'] );
			if ( ! isset( $prompts[ $pid ] ) ) {
				continue;
			}
			$res = self::ask( $prov, $prompts[ $pid ] );
			self::store( $job['run'], $prov, $pid, $prompts[ $pid ], $res );
			++$job['done'];
			if ( is_wp_error( $res ) ) {
				++$job['errors'];
			}
			++$n;
		}
		self::add_usage( $n );
		if ( $job['items'] ) {
			update_option( self::JOB, $job, false );
			wp_schedule_single_event( time() + 20, self::CRON );
		} else {
			$runs   = get_option( 'publishrank_ai_citation_runs', array() );
			$runs   = is_array( $runs ) ? $runs : array();
			$runs[] = array( 'run' => $job['run'], 'started' => $job['started'], 'finished' => time(), 'total' => $job['total'], 'errors' => $job['errors'] );
			update_option( 'publishrank_ai_citation_runs', array_slice( $runs, -60 ), false );
			delete_option( self::JOB );
		}
		return $n;
	}

	/* ---------------------------------------------------------------- Provider calls */

	private static function post( $url, array $headers, array $body, $timeout = 60 ) {
		$res = wp_safe_remote_post(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'                => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$msg = is_array( $data ) ? ( $data['error']['message'] ?? ( $data['error']['type'] ?? ( is_string( $data['error'] ?? null ) ? $data['error'] : '' ) ) ) : '';
			return new \WP_Error( 'provider_http', sprintf( 'HTTP %d %s', $code, substr( wp_strip_all_tags( (string) $msg ), 0, 180 ) ) );
		}
		return $data;
	}

	/** Ask one provider. @return array{text:string,urls:array,model:string,tokens:int}|\WP_Error */
	public static function ask( $provider, $prompt, $search = true ) {
		$key = self::key( $provider );
		if ( '' === $key ) {
			return new \WP_Error( 'no_key', __( 'No API key saved for this provider.', 'publishrank' ) );
		}
		$s     = self::settings();
		$model = (string) $s['providers'][ $provider ]['model'];
		$loc   = trim( (string) $s['location'] );
		$q     = '' !== $loc ? $prompt . ' (' . $loc . ')' : $prompt;
		switch ( $provider ) {
			case 'openai':
				$body = array( 'model' => $model, 'input' => $q );
				if ( $search ) {
					$body['tools'] = array( array( 'type' => 'web_search' ) );
				}
				$data = self::post( 'https://api.openai.com/v1/responses', array( 'Authorization' => 'Bearer ' . $key ), $body );
				return is_wp_error( $data ) ? $data : self::parse_openai( $data );
			case 'anthropic':
				$body = array(
					'model'      => $model,
					'max_tokens' => 1200,
					'messages'   => array( array( 'role' => 'user', 'content' => $q ) ),
				);
				if ( $search ) {
					$body['tools'] = array( array( 'type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3 ) );
				}
				$data = self::post( 'https://api.anthropic.com/v1/messages', array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ), $body );
				return is_wp_error( $data ) ? $data : self::parse_anthropic( $data );
			case 'perplexity':
				$data = self::post( 'https://api.perplexity.ai/chat/completions', array( 'Authorization' => 'Bearer ' . $key ), array( 'model' => $model, 'messages' => array( array( 'role' => 'user', 'content' => $q ) ) ) );
				return is_wp_error( $data ) ? $data : self::parse_perplexity( $data );
			case 'gemini':
				$body = array( 'contents' => array( array( 'parts' => array( array( 'text' => $q ) ) ) ) );
				if ( $search ) {
					$body['tools'] = array( array( 'google_search' => new \stdClass() ) );
				}
				$data = self::post( 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent', array( 'x-goog-api-key' => $key ), $body );
				return is_wp_error( $data ) ? $data : self::parse_gemini( $data );
		}
		return new \WP_Error( 'provider', 'Unknown provider.' );
	}

	/** Small, cheap call used by the "Test connection" button. @return true|\WP_Error */
	public static function test( $provider ) {
		$r = self::ask( $provider, 'Reply with the single word OK.', false );
		return is_wp_error( $r ) ? $r : true;
	}

	/* ---------------------------------------------------------------- Parsers (pure) */

	public static function parse_openai( array $d ) {
		$text = '';
		$urls = array();
		foreach ( (array) ( $d['output'] ?? array() ) as $item ) {
			if ( 'message' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $item['content'] ?? array() ) as $c ) {
				if ( 'output_text' === ( $c['type'] ?? '' ) ) {
					$text .= (string) ( $c['text'] ?? '' );
					foreach ( (array) ( $c['annotations'] ?? array() ) as $a ) {
						if ( 'url_citation' === ( $a['type'] ?? '' ) && ! empty( $a['url'] ) ) {
							$urls[] = array( 'url' => (string) $a['url'], 'title' => (string) ( $a['title'] ?? '' ) );
						}
					}
				}
			}
		}
		if ( '' === $text && isset( $d['output_text'] ) ) {
			$text = (string) $d['output_text'];
		}
		return array( 'text' => $text, 'urls' => self::unique_urls( $urls ), 'model' => (string) ( $d['model'] ?? '' ), 'tokens' => (int) ( $d['usage']['total_tokens'] ?? 0 ) );
	}

	public static function parse_anthropic( array $d ) {
		$text = '';
		$urls = array();
		$seen = array();
		foreach ( (array) ( $d['content'] ?? array() ) as $b ) {
			$type = $b['type'] ?? '';
			if ( 'text' === $type ) {
				$text .= (string) ( $b['text'] ?? '' );
				foreach ( (array) ( $b['citations'] ?? array() ) as $c ) {
					if ( ! empty( $c['url'] ) ) {
						$urls[] = array( 'url' => (string) $c['url'], 'title' => (string) ( $c['title'] ?? '' ) );
					}
				}
			} elseif ( 'web_search_tool_result' === $type && is_array( $b['content'] ?? null ) ) {
				foreach ( $b['content'] as $r ) {
					if ( ! empty( $r['url'] ) ) {
						$seen[] = array( 'url' => (string) $r['url'], 'title' => (string) ( $r['title'] ?? '' ) );
					}
				}
			}
		}
		// Prefer the sources Claude actually cited; fall back to the results it read.
		$urls = $urls ? $urls : $seen;
		$tok  = (int) ( $d['usage']['input_tokens'] ?? 0 ) + (int) ( $d['usage']['output_tokens'] ?? 0 );
		return array( 'text' => $text, 'urls' => self::unique_urls( $urls ), 'model' => (string) ( $d['model'] ?? '' ), 'tokens' => $tok );
	}

	public static function parse_perplexity( array $d ) {
		$text = (string) ( $d['choices'][0]['message']['content'] ?? '' );
		$urls = array();
		foreach ( (array) ( $d['search_results'] ?? array() ) as $r ) {
			if ( ! empty( $r['url'] ) ) {
				$urls[] = array( 'url' => (string) $r['url'], 'title' => (string) ( $r['title'] ?? '' ) );
			}
		}
		if ( ! $urls ) {
			foreach ( (array) ( $d['citations'] ?? array() ) as $u ) {
				if ( is_string( $u ) ) {
					$urls[] = array( 'url' => $u, 'title' => '' );
				}
			}
		}
		return array( 'text' => $text, 'urls' => self::unique_urls( $urls ), 'model' => (string) ( $d['model'] ?? '' ), 'tokens' => (int) ( $d['usage']['total_tokens'] ?? 0 ) );
	}

	public static function parse_gemini( array $d ) {
		$text = '';
		$urls = array();
		$cand = $d['candidates'][0] ?? array();
		foreach ( (array) ( $cand['content']['parts'] ?? array() ) as $p ) {
			$text .= (string) ( $p['text'] ?? '' );
		}
		foreach ( (array) ( $cand['groundingMetadata']['groundingChunks'] ?? array() ) as $c ) {
			if ( ! empty( $c['web']['uri'] ) ) {
				// Gemini links through a redirect; its "title" carries the source domain.
				$urls[] = array( 'url' => (string) $c['web']['uri'], 'title' => (string) ( $c['web']['title'] ?? '' ) );
			}
		}
		return array( 'text' => $text, 'urls' => self::unique_urls( $urls ), 'model' => (string) ( $d['modelVersion'] ?? '' ), 'tokens' => (int) ( $d['usageMetadata']['totalTokenCount'] ?? 0 ) );
	}

	private static function unique_urls( array $urls ) {
		$out  = array();
		$seen = array();
		foreach ( $urls as $u ) {
			$k = strtolower( preg_replace( '/[?#].*$/', '', $u['url'] ) );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = array( 'url' => esc_url_raw( $u['url'] ), 'title' => substr( wp_strip_all_tags( $u['title'] ), 0, 200 ) );
		}
		return array_slice( $out, 0, 40 );
	}

	/* ---------------------------------------------------------------- Analysis */

	private static function split_list( $s ) {
		return array_values( array_filter( array_map( static function ( $x ) {
			$x = strtolower( trim( (string) $x ) );
			return preg_replace( '#^https?://(www\.)?#', '', rtrim( $x, '/' ) );
		}, preg_split( '/[,\n]+/', (string) $s ) ) ) );
	}

	private static function host_of( array $u ) {
		$h = strtolower( (string) wp_parse_url( $u['url'], PHP_URL_HOST ) );
		$h = preg_replace( '/^www\./', '', $h );
		// Gemini grounding links go through vertexaisearch.cloud.google.com; the title names the real site.
		if ( false !== strpos( $h, 'vertexaisearch.cloud.google.com' ) && '' !== $u['title'] ) {
			$h = strtolower( preg_replace( '/^www\./', '', trim( $u['title'] ) ) );
		}
		return $h;
	}

	private static function host_matches( $host, array $domains ) {
		foreach ( $domains as $d ) {
			if ( $host === $d || ( strlen( $host ) > strlen( $d ) && substr( $host, -strlen( $d ) - 1 ) === '.' . $d ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array{mentioned:int,cited:int,position:int,competitors:array} */
	public static function analyze( $text, array $urls, $brand, $domains, $competitors ) {
		$doms     = self::split_list( $domains );
		$names    = array_filter( array_map( 'trim', preg_split( '/[,\n]+/', (string) $brand ) ) );
		$position = 0;
		foreach ( $urls as $i => $u ) {
			if ( self::host_matches( self::host_of( $u ), $doms ) ) {
				$position = $i + 1;
				break;
			}
		}
		$lower     = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		$mentioned = 0;
		foreach ( array_merge( $names, $doms ) as $n ) {
			$n = function_exists( 'mb_strtolower' ) ? mb_strtolower( $n, 'UTF-8' ) : strtolower( $n );
			if ( '' !== $n && false !== strpos( $lower, $n ) ) {
				$mentioned = 1;
				break;
			}
		}
		$comp = array();
		foreach ( self::split_list( $competitors ) as $c ) {
			foreach ( $urls as $i => $u ) {
				if ( self::host_matches( self::host_of( $u ), array( $c ) ) ) {
					$comp[ $c ] = $i + 1;
					break;
				}
			}
			if ( ! isset( $comp[ $c ] ) && false !== strpos( $lower, $c ) ) {
				$comp[ $c ] = 0; // Mentioned, not linked.
			}
		}
		return array( 'mentioned' => $mentioned || $position ? 1 : 0, 'cited' => $position ? 1 : 0, 'position' => $position, 'competitors' => $comp );
	}

	private static function store( $run, $provider, $pid, $prompt, $res ) {
		global $wpdb;
		if ( ! Database::ready( 'ai_citations' ) ) {
			return;
		}
		$s   = self::settings();
		$row = array(
			'run_id'     => $run,
			'created_at' => Database::now(),
			'provider'   => $provider,
			'model'      => (string) $s['providers'][ $provider ]['model'],
			'prompt_id'  => $pid,
			'prompt'     => $prompt,
		);
		if ( is_wp_error( $res ) ) {
			$row['status'] = 'error';
			$row['error']  = substr( $res->get_error_message(), 0, 255 );
		} else {
			$a                  = self::analyze( $res['text'], $res['urls'], $s['brand'], $s['domains'], $s['competitors'] );
			$row['model']       = '' !== $res['model'] ? substr( $res['model'], 0, 80 ) : $row['model'];
			$row['mentioned']   = $a['mentioned'];
			$row['cited']       = $a['cited'];
			$row['position']    = $a['position'];
			$row['cited_urls']  = wp_json_encode( $res['urls'] );
			$row['competitors'] = wp_json_encode( $a['competitors'] );
			$row['excerpt']     = wp_trim_words( wp_strip_all_tags( $res['text'] ), 120, '…' );
			$row['tokens']      = (int) $res['tokens'];
			$row['status']      = 'ok';
		}
		$wpdb->insert( Database::table( 'ai_citations' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ---------------------------------------------------------------- Reports */

	/** Latest result for every question × provider. */
	public static function latest() {
		global $wpdb;
		if ( ! Database::ready( 'ai_citations' ) ) {
			return array();
		}
		$t    = Database::table( 'ai_citations' );
		$rows = $wpdb->get_results( "SELECT c.* FROM {$t} c JOIN (SELECT prompt_id, provider, MAX(id) AS id FROM {$t} GROUP BY prompt_id, provider) m ON m.id = c.id ORDER BY c.prompt_id, c.provider", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['prompt_id'] ][ $r['provider'] ] = $r;
		}
		return $out;
	}

	/** Per-run citation and mention rates (for the trend chart). */
	public static function trend( $limit = 20 ) {
		global $wpdb;
		if ( ! Database::ready( 'ai_citations' ) ) {
			return array();
		}
		$t = Database::table( 'ai_citations' );
		return array_reverse( (array) $wpdb->get_results( $wpdb->prepare( "SELECT run_id, MIN(created_at) AS at, COUNT(*) AS n, SUM(status = 'ok') AS ok, SUM(cited) AS cited, SUM(mentioned) AS mentioned FROM {$t} GROUP BY run_id ORDER BY MIN(id) DESC LIMIT %d", (int) $limit ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** Totals for the most recent run, overall and per provider; plus competitor share. */
	public static function summary() {
		global $wpdb;
		$out = array( 'run' => '', 'at' => '', 'total' => 0, 'cited' => 0, 'mentioned' => 0, 'providers' => array(), 'competitors' => array(), 'avg_position' => 0 );
		if ( ! Database::ready( 'ai_citations' ) ) {
			return $out;
		}
		$t   = Database::table( 'ai_citations' );
		$run = $wpdb->get_var( "SELECT run_id FROM {$t} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $run ) {
			return $out;
		}
		$rows        = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE run_id = %s AND status = 'ok'", $run ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out['run']  = $run;
		$out['at']   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at) FROM {$t} WHERE run_id = %s", $run ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$pos         = array();
		foreach ( (array) $rows as $r ) {
			++$out['total'];
			$out['cited']     += (int) $r['cited'];
			$out['mentioned'] += (int) $r['mentioned'];
			if ( (int) $r['position'] ) {
				$pos[] = (int) $r['position'];
			}
			$p = $r['provider'];
			if ( ! isset( $out['providers'][ $p ] ) ) {
				$out['providers'][ $p ] = array( 'total' => 0, 'cited' => 0, 'mentioned' => 0 );
			}
			++$out['providers'][ $p ]['total'];
			$out['providers'][ $p ]['cited']     += (int) $r['cited'];
			$out['providers'][ $p ]['mentioned'] += (int) $r['mentioned'];
			foreach ( (array) json_decode( (string) $r['competitors'], true ) as $dom => $at ) {
				if ( ! isset( $out['competitors'][ $dom ] ) ) {
					$out['competitors'][ $dom ] = array( 'cited' => 0, 'mentioned' => 0 );
				}
				if ( (int) $at > 0 ) {
					++$out['competitors'][ $dom ]['cited'];
				}
				++$out['competitors'][ $dom ]['mentioned'];
			}
		}
		$out['avg_position'] = $pos ? round( array_sum( $pos ) / count( $pos ), 1 ) : 0;
		return $out;
	}
}
