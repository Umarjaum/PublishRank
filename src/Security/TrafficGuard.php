<?php
/**
 * Traffic Guard — PublishRank's front-end traffic protection engine.
 *
 * Every decision is explainable: evaluate() returns the decision plus a step-by-step trace, and the
 * same function powers live enforcement, the rule tester and the automated tests.
 *
 * Order of evaluation for an anonymous front-end page request:
 *  1. Never-blocked request types (admin, REST, AJAX, cron, feeds, sitemaps, robots.txt, previews,
 *     WooCommerce API callbacks) and signed-in users who can manage security are skipped entirely.
 *  2. Allow-listed IPs are allowed.
 *  3. Active temporary blocks.
 *  4. Custom rules by priority (lowest number first). "Test" rules only record what they would do.
 *  5. IP block list.
 *  6. Country rules (block list or allow-only), with a configurable policy for unknown locations.
 *  7. IP reputation (only when a provider is registered through `publishrank_traffic_guard_ip_reputation`).
 *  8. Suspicious-request detection according to the protection level.
 *  9. Rate limiting.
 * A block decision is cancelled for genuine search-engine crawlers (reverse + forward DNS verified)
 * when crawler protection is on. In Monitor mode nothing is blocked; would-be blocks are logged.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Security;

use PublishRankSEO\Core\Countries;

defined( 'ABSPATH' ) || exit;

final class TrafficGuard {
	const OPTION    = 'publishrank_traffic_guard';
	const MAX_RULES = 500;
	const MAX_CUSTOM_RULES = 100;
	/** Narrowest-allowed prefix breadth: networks broader than /8 (IPv4) or /16 (IPv6) are rejected as unsafe. */
	const MIN_PREFIX_V4 = 8;
	const MIN_PREFIX_V6 = 16;
	/** Reverse-DNS domains that genuine search-engine crawlers resolve to. */
	const CRAWLER_DOMAINS = array( 'googlebot.com', 'google.com', 'googleusercontent.com', 'search.msn.com', 'applebot.apple.com', 'crawl.baidu.com', 'crawl.baidu.jp', 'yandex.com', 'yandex.ru', 'yandex.net', 'duckduckgo.com', 'petalsearch.com' );
	const CRAWLER_UA      = '/googlebot|google-inspectiontool|googleother|storebot-google|adsbot-google|mediapartners-google|bingbot|msnbot|adidxbot|applebot|baiduspider|yandex(?:bot|images|news)|duckduckbot|petalbot/i';
	const TOOL_UA         = '/\b(?:curl|wget|python-requests|python-urllib|aiohttp|httpx|go-http-client|java\/|okhttp|libwww-perl|lwp::|scrapy|httpclient|axios|node-fetch|guzzlehttp|headlesschrome|phantomjs|masscan|zgrab|nikto|sqlmap|nmap|wpscan|nuclei|dirbuster|gobuster)\b/i';
	const LEVELS          = array( 'off', 'low', 'balanced', 'strict' );

	/** @var array|null */
	private static $settings = null;

	public function register() {
		add_action( 'template_redirect', array( $this, 'enforce' ), 0 );
		add_action( TrafficGuardStore::CRON, array( TrafficGuardStore::class, 'daily' ) );
		add_action( 'init', array( TrafficGuardStore::class, 'schedule' ) );
	}

	/** Default configuration: everything off until the owner turns it on. */
	public static function defaults() {
		return array(
			'enabled'           => false,
			'mode'              => 'monitor', // monitor | enforce.
			'level'             => 'low',
			'allow_crawlers'    => true,
			'blocked_ips'       => array(),
			'allow_ips'         => array(),
			'trusted_proxies'   => array(),
			'blocked_countries' => array(),
			'allowed_countries' => array(),
			'country_mode'      => 'block', // block | allow_only.
			'unknown_country'   => 'allow', // allow | block (allow-only mode only).
			'geo_source'        => 'auto',
			'rate_enabled'      => false,
			'rate_requests'     => 120,
			'rate_window'       => 60,
			'rate_action'       => 'temp_block', // log | throttle | temp_block.
			'rate_block_minutes'=> 15,
			'reputation_threshold' => 0,
			'rules'             => array(),
			'block_message'     => '',
			'contact_email'     => '',
			'retention_days'    => 30,
			'log_cap_minute'    => 120,
		);
	}

	/** Return normalized settings; malformed stored values are ignored safely. */
	public static function settings() {
		if ( null !== self::$settings ) {
			return self::$settings;
		}
		$saved = get_option( self::OPTION, array() );
		self::$settings = self::normalise( is_array( $saved ) ? $saved : array() );
		return self::$settings;
	}

	/** Forget cached settings (after a save, and in tests). */
	public static function flush() {
		self::$settings = null;
		CountryResolver::reset();
	}

	/** Normalise any stored or submitted array into a complete settings array. */
	public static function normalise( array $saved ) {
		$d   = self::defaults();
		$out = $d;
		$out['enabled']        = ! empty( $saved['enabled'] );
		// 2.25.0 and earlier had no mode: an enabled guard was enforcing, so keep it enforcing after upgrade.
		$out['mode']           = isset( $saved['mode'] ) ? ( 'enforce' === $saved['mode'] ? 'enforce' : 'monitor' ) : ( ! empty( $saved['enabled'] ) ? 'enforce' : 'monitor' );
		$out['level']          = isset( $saved['level'] ) && in_array( $saved['level'], self::LEVELS, true ) ? $saved['level'] : ( isset( $saved['mode'] ) ? 'low' : 'off' );
		$out['allow_crawlers'] = ! array_key_exists( 'allow_crawlers', $saved ) || ! empty( $saved['allow_crawlers'] );
		foreach ( array( 'blocked_ips', 'allow_ips', 'trusted_proxies' ) as $k ) {
			$out[ $k ] = self::inspect_networks( isset( $saved[ $k ] ) ? $saved[ $k ] : '' )['valid'];
		}
		$out['blocked_countries'] = self::inspect_country_codes( isset( $saved['blocked_countries'] ) ? $saved['blocked_countries'] : '' )['valid'];
		$out['allowed_countries'] = Countries::parse_list( isset( $saved['allowed_countries'] ) ? $saved['allowed_countries'] : '' )['valid'];
		$out['country_mode']      = isset( $saved['country_mode'] ) && 'allow_only' === $saved['country_mode'] ? 'allow_only' : 'block';
		$out['unknown_country']   = isset( $saved['unknown_country'] ) && 'block' === $saved['unknown_country'] ? 'block' : 'allow';
		$out['geo_source']        = isset( $saved['geo_source'] ) && in_array( $saved['geo_source'], array( 'auto', 'cdn', 'server', 'filter', 'off' ), true ) ? $saved['geo_source'] : 'auto';
		$out['rate_enabled']      = ! empty( $saved['rate_enabled'] );
		$out['rate_requests']     = isset( $saved['rate_requests'] ) ? max( 10, min( 10000, (int) $saved['rate_requests'] ) ) : $d['rate_requests'];
		$out['rate_window']       = isset( $saved['rate_window'] ) ? max( 10, min( 3600, (int) $saved['rate_window'] ) ) : $d['rate_window'];
		$out['rate_action']       = isset( $saved['rate_action'] ) && in_array( $saved['rate_action'], array( 'log', 'throttle', 'temp_block' ), true ) ? $saved['rate_action'] : $d['rate_action'];
		$out['rate_block_minutes']= isset( $saved['rate_block_minutes'] ) ? max( 1, min( 10080, (int) $saved['rate_block_minutes'] ) ) : $d['rate_block_minutes'];
		$out['reputation_threshold'] = isset( $saved['reputation_threshold'] ) ? max( 0, min( 100, (int) $saved['reputation_threshold'] ) ) : 0;
		$out['rules']             = TrafficGuardRules::sanitize_all( isset( $saved['rules'] ) && is_array( $saved['rules'] ) ? $saved['rules'] : array() );
		$out['block_message']     = isset( $saved['block_message'] ) ? substr( sanitize_textarea_field( (string) $saved['block_message'] ), 0, 500 ) : '';
		$out['contact_email']     = isset( $saved['contact_email'] ) && is_email( (string) $saved['contact_email'] ) ? sanitize_email( (string) $saved['contact_email'] ) : '';
		$out['retention_days']    = isset( $saved['retention_days'] ) ? max( 1, min( 365, (int) $saved['retention_days'] ) ) : $d['retention_days'];
		$out['log_cap_minute']    = isset( $saved['log_cap_minute'] ) ? max( 10, min( 2000, (int) $saved['log_cap_minute'] ) ) : $d['log_cap_minute'];
		return $out;
	}

	/** Persist settings (already validated by the caller) and reset caches. */
	public static function save( array $settings ) {
		$clean = self::normalise( $settings );
		update_option( self::OPTION, $clean, true );
		self::flush();
		return $clean;
	}

	/* ------------------------------------------------------------------ Enforcement */

	/** Apply rules to anonymous public page requests only. */
	public function enforce() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || ! self::is_blockable_request() ) {
			return;
		}
		$ctx = self::request_context( $settings );
		if ( '' === $ctx['ip'] ) {
			return;
		}
		$result = self::evaluate( $ctx, $settings, array( 'live' => true ) );
		self::act( $result, $ctx, $settings );
	}

	/**
	 * Record a request that Access Shield's early request firewall already refused, so Traffic Guard's
	 * dashboard and event log show one complete picture instead of two separate firewalls.
	 *
	 * @return string Event reference, or '' when Traffic Guard is off or logging is unavailable.
	 */
	public static function record_firewall_block( $rule_name ) {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return '';
		}
		$ctx = self::request_context( $settings );
		if ( '' === $ctx['ip'] ) {
			return '';
		}
		return TrafficGuardStore::log( 'blocked', 'firewall', 0, $ctx, $settings );
	}

	/** Facts about the current request used by rules. */
	public static function request_context( ?array $settings = null ) {
		$settings = null === $settings ? self::settings() : $settings;
		$ip       = self::client_ip( $settings['trusted_proxies'] );
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed and length-limited below, never output unescaped.
		$path     = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$query    = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$geo      = CountryResolver::resolve( $ip, $settings['trusted_proxies'], $settings['geo_source'] );
		$ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '';
		return array(
			'ip'         => $ip,
			'country'    => $geo['code'],
			'geo_source' => $geo['source'],
			'method'     => isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( substr( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ), 0, 8 ) ) : 'GET',
			'path'       => substr( rawurldecode( '' === $path ? '/' : $path ), 0, 1024 ),
			'query'      => substr( rawurldecode( $query ), 0, 2048 ),
			'user_agent' => $ua,
			'referer'    => isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 512 ) : '',
			'bot'        => self::bot_class( $ua ),
		);
	}

	/**
	 * Classify a user agent: search (claims a search engine; verified separately), ai (AI crawler),
	 * tool (scripts, scanners, headless browsers), bot (other declared bots), empty (no user agent) or human.
	 */
	public static function bot_class( $ua ) {
		$ua = (string) $ua;
		if ( '' === trim( $ua ) ) {
			return 'empty';
		}
		if ( preg_match( self::CRAWLER_UA, $ua ) ) {
			return 'search';
		}
		if ( class_exists( '\\PublishRankSEO\\AI\\BotRegistry' ) && method_exists( '\\PublishRankSEO\\AI\\BotRegistry', 'identify' ) ) {
			$token = \PublishRankSEO\AI\BotRegistry::identify( $ua );
			if ( is_string( $token ) && '' !== $token ) {
				return 'ai';
			}
		}
		if ( preg_match( self::TOOL_UA, $ua ) ) {
			return 'tool';
		}
		if ( preg_match( '/bot\b|crawl|spider|slurp|fetch|scan|monitor|preview|archiver/i', $ua ) ) {
			return 'bot';
		}
		return 'human';
	}

	/** Labels for bot classes. */
	public static function bot_labels() {
		return array(
			'human'  => __( 'Browser', 'publishrank' ),
			'search' => __( 'Search engine', 'publishrank' ),
			'ai'     => __( 'AI crawler', 'publishrank' ),
			'tool'   => __( 'Script / scanner', 'publishrank' ),
			'bot'    => __( 'Other bot', 'publishrank' ),
			'empty'  => __( 'No user agent', 'publishrank' ),
		);
	}

	/**
	 * Decide what to do with a request. Pure apart from reading temporary blocks, rate counters and
	 * (only when a block is about to happen for a crawler-looking agent) DNS verification.
	 *
	 * @param array $ctx      request_context() shape.
	 * @param array $settings settings() shape.
	 * @param array $opts     live (bool): count rate limits; simulate (bool): never touch storage.
	 * @return array{action:string,reason:string,rule_id:string,trace:array<int,array{step:string,result:string}>,tests:array<int,string>}
	 */
	public static function evaluate( array $ctx, array $settings, array $opts = array() ) {
		$live     = ! empty( $opts['live'] );
		$simulate = ! empty( $opts['simulate'] );
		$trace    = array();
		$tests    = array();
		$add      = static function ( $step, $result ) use ( &$trace ) {
			$trace[] = array( 'step' => $step, 'result' => $result );
		};
		$result = static function ( $action, $reason, $rule_id = '' ) use ( &$trace, &$tests ) {
			return array( 'action' => $action, 'reason' => $reason, 'rule_id' => $rule_id, 'trace' => $trace, 'tests' => $tests );
		};

		if ( self::matches_any( $ctx['ip'], $settings['allow_ips'] ) ) {
			$add( __( 'IP allow list', 'publishrank' ), __( 'Matched — always allowed.', 'publishrank' ) );
			return $result( 'allow', 'allowlist' );
		}
		$add( __( 'IP allow list', 'publishrank' ), __( 'No match.', 'publishrank' ) );

		if ( ! $simulate ) {
			$until = TrafficGuardStore::blocked_until( $ctx['ip'] );
			if ( $until ) {
				$add( __( 'Temporary blocks', 'publishrank' ), sprintf( /* translators: %s: date/time */ __( 'Blocked until %s.', 'publishrank' ), gmdate( 'Y-m-d H:i', $until ) . ' UTC' ) );
				return self::crawler_guard( $result( 'block', 'temp_block' ), $ctx, $settings, $add, $result, $simulate );
			}
			$add( __( 'Temporary blocks', 'publishrank' ), __( 'Not blocked.', 'publishrank' ) );
		}

		foreach ( TrafficGuardRules::ordered( $settings['rules'] ) as $rule ) {
			if ( empty( $rule['enabled'] ) || ! TrafficGuardRules::matches( $rule, $ctx ) ) {
				continue;
			}
			if ( 'test' === $rule['mode'] ) {
				$tests[] = $rule['id'];
				$add( sprintf( /* translators: %s: rule name */ __( 'Rule “%s” (test mode)', 'publishrank' ), $rule['name'] ), sprintf( /* translators: %s: action */ __( 'Would %s — recorded only.', 'publishrank' ), TrafficGuardRules::action_label( $rule['action'] ) ) );
				continue;
			}
			$add( sprintf( /* translators: %s: rule name */ __( 'Rule “%s”', 'publishrank' ), $rule['name'] ), TrafficGuardRules::action_label( $rule['action'] ) );
			if ( 'allow' === $rule['action'] ) {
				return $result( 'allow', 'rule', $rule['id'] );
			}
			if ( 'log' === $rule['action'] ) {
				return $result( 'log', 'rule', $rule['id'] );
			}
			$r = $result( 'temp_block' === $rule['action'] ? 'temp_block' : 'block', 'rule', $rule['id'] );
			$r['minutes'] = (int) $rule['minutes'];
			return self::crawler_guard( $r, $ctx, $settings, $add, $result, $simulate );
		}

		if ( self::matches_any( $ctx['ip'], $settings['blocked_ips'] ) ) {
			$add( __( 'IP block list', 'publishrank' ), __( 'Matched.', 'publishrank' ) );
			return self::crawler_guard( $result( 'block', 'blocklist' ), $ctx, $settings, $add, $result, $simulate );
		}
		$add( __( 'IP block list', 'publishrank' ), __( 'No match.', 'publishrank' ) );

		$cc = (string) $ctx['country'];
		if ( 'allow_only' === $settings['country_mode'] && $settings['allowed_countries'] ) {
			if ( '' === $cc ) {
				$add( __( 'Country (allow only)', 'publishrank' ), 'block' === $settings['unknown_country'] ? __( 'Location unknown — blocked by the unknown-location policy.', 'publishrank' ) : __( 'Location unknown — allowed (safe fallback).', 'publishrank' ) );
				if ( 'block' === $settings['unknown_country'] ) {
					return self::crawler_guard( $result( 'block', 'country_unknown' ), $ctx, $settings, $add, $result, $simulate );
				}
			} elseif ( ! in_array( $cc, $settings['allowed_countries'], true ) ) {
				$add( __( 'Country (allow only)', 'publishrank' ), sprintf( /* translators: %s: country */ __( '%s is not on the allowed list.', 'publishrank' ), Countries::name( $cc ) ) );
				return self::crawler_guard( $result( 'block', 'country' ), $ctx, $settings, $add, $result, $simulate );
			} else {
				$add( __( 'Country (allow only)', 'publishrank' ), sprintf( /* translators: %s: country */ __( '%s is allowed.', 'publishrank' ), Countries::name( $cc ) ) );
			}
		} elseif ( $settings['blocked_countries'] ) {
			if ( '' !== $cc && in_array( $cc, $settings['blocked_countries'], true ) ) {
				$add( __( 'Country block list', 'publishrank' ), sprintf( /* translators: %s: country */ __( '%s is blocked.', 'publishrank' ), Countries::name( $cc ) ) );
				return self::crawler_guard( $result( 'block', 'country' ), $ctx, $settings, $add, $result, $simulate );
			}
			$add( __( 'Country block list', 'publishrank' ), '' === $cc ? __( 'Location unknown — allowed (safe fallback).', 'publishrank' ) : __( 'No match.', 'publishrank' ) );
		}

		if ( $settings['reputation_threshold'] > 0 && has_filter( 'publishrank_traffic_guard_ip_reputation' ) ) {
			$rep = apply_filters( 'publishrank_traffic_guard_ip_reputation', null, $ctx['ip'], $ctx );
			if ( is_array( $rep ) && isset( $rep['score'] ) && (int) $rep['score'] >= $settings['reputation_threshold'] ) {
				$add( __( 'IP reputation', 'publishrank' ), sprintf( /* translators: 1: score, 2: source */ __( 'Risk score %1$d from %2$s.', 'publishrank' ), (int) $rep['score'], isset( $rep['source'] ) ? sanitize_text_field( (string) $rep['source'] ) : __( 'provider', 'publishrank' ) ) );
				return self::crawler_guard( $result( 'block', 'reputation' ), $ctx, $settings, $add, $result, $simulate );
			}
			$add( __( 'IP reputation', 'publishrank' ), __( 'Below threshold.', 'publishrank' ) );
		}

		$suspicious = self::suspicious( $ctx, $settings['level'] );
		if ( '' !== $suspicious ) {
			$add( __( 'Suspicious request detection', 'publishrank' ), self::reason_label( $suspicious ) );
			if ( 'low' === $settings['level'] ) {
				return $result( 'log', $suspicious );
			}
			return self::crawler_guard( $result( 'block', $suspicious ), $ctx, $settings, $add, $result, $simulate );
		}
		if ( 'off' !== $settings['level'] ) {
			$add( __( 'Suspicious request detection', 'publishrank' ), __( 'Nothing suspicious.', 'publishrank' ) );
		}

		if ( $settings['rate_enabled'] ) {
			$count = $simulate ? 0 : TrafficGuardStore::rate_hit( $ctx['ip'], $settings['rate_window'], $live );
			if ( $count > $settings['rate_requests'] ) {
				$add( __( 'Rate limit', 'publishrank' ), sprintf( /* translators: 1: requests, 2: seconds */ __( '%1$d requests in %2$d seconds.', 'publishrank' ), $count, $settings['rate_window'] ) );
				if ( 'log' === $settings['rate_action'] ) {
					return $result( 'log', 'rate_limit' );
				}
				$r            = $result( 'temp_block' === $settings['rate_action'] ? 'temp_block' : 'throttle', 'rate_limit' );
				$r['minutes'] = $settings['rate_block_minutes'];
				return self::crawler_guard( $r, $ctx, $settings, $add, $result, $simulate );
			}
			$add( __( 'Rate limit', 'publishrank' ), $simulate ? __( 'Not counted in a simulation.', 'publishrank' ) : __( 'Within the limit.', 'publishrank' ) );
		}

		return $result( 'allow', 'default' );
	}

	/** Cancel a block for verified search-engine crawlers when crawler protection is on. */
	private static function crawler_guard( array $decision, array $ctx, array $settings, $add, $result, $simulate ) {
		if ( empty( $settings['allow_crawlers'] ) || 'search' !== $ctx['bot'] ) {
			return $decision;
		}
		$verified = $simulate ? ! empty( $ctx['verified'] ) : self::is_verified_crawler( $ctx['ip'], $ctx['user_agent'] );
		if ( $verified ) {
			$add( __( 'Search-engine crawler', 'publishrank' ), __( 'Verified by reverse and forward DNS — the block is cancelled.', 'publishrank' ) );
			$r = $result( 'allow', 'verified_crawler', $decision['rule_id'] );
			return $r;
		}
		$add( __( 'Search-engine crawler', 'publishrank' ), __( 'Claims to be a crawler but DNS verification failed — treated as a normal visitor.', 'publishrank' ) );
		return $decision;
	}

	/**
	 * Suspicious-request heuristics, tuned to avoid false positives on real readers.
	 * low: probes for secrets/backups/admin tools (logged only); balanced: + injection patterns and
	 * scanner user agents; strict: + empty user agents and script clients on page requests.
	 */
	public static function suspicious( array $ctx, $level ) {
		if ( 'off' === $level ) {
			return '';
		}
		$path  = strtolower( (string) $ctx['path'] );
		$query = strtolower( (string) $ctx['query'] );
		if ( preg_match( '~/(?:\.env(?:\.|$)|\.git/|\.svn/|\.hg/|\.ds_store$|\.htpasswd$|wp-config\.php(?:\.|\~|$)|config\.php\.(?:bak|old|save|swp)|(?:backup|db|database|dump|site)\.(?:sql|zip|tar|gz|tgz|rar)$|phpmyadmin|adminer(?:\.php)?$|phpinfo\.php$|server-status$|cgi-bin/|vendor/phpunit/|\.aws/credentials|id_rsa$)~', $path ) ) {
			return 'probe';
		}
		if ( 'low' === $level ) {
			return '';
		}
		$haystack = $path . '?' . $query;
		if ( preg_match( '~(?:\bunion(?:\s|/\*.*?\*/|\+)+(?:all(?:\s|\+)+)?select(?:\s|/\*.*?\*/|\+)+(?:\d|null\b|(?:group_)?concat\b|char\s*\(|0x|@@|\*|\(|[\w`.]+\s*,|[\w`.]+(?:\s|\+)+from\b)|\bselect\b.{1,40}\bfrom\b.{1,40}\binformation_schema\b|\bsleep\s*\(\s*\d+\s*\)|\bbenchmark\s*\(|<script\b|javascript:|\bon(?:error|load)\s*=|\.\./\.\./|/etc/passwd|\bbase64_decode\s*\(|\beval\s*\(|php://(?:input|filter)|\$\{jndi:)~i', $haystack ) ) {
			return 'injection';
		}
		if ( preg_match( '/\b(?:sqlmap|nikto|nmap|masscan|zgrab|wpscan|nuclei|dirbuster|gobuster|acunetix|netsparker|havij)\b/i', (string) $ctx['user_agent'] ) ) {
			return 'scanner';
		}
		if ( 'strict' === $level && in_array( $ctx['bot'], array( 'empty', 'tool' ), true ) ) {
			return 'empty' === $ctx['bot'] ? 'no_user_agent' : 'script_client';
		}
		return '';
	}

	/** Human labels for decision reasons. */
	public static function reason_label( $reason ) {
		$labels = array(
			'allowlist'        => __( 'IP allow list', 'publishrank' ),
			'blocklist'        => __( 'IP block list', 'publishrank' ),
			'temp_block'       => __( 'Temporary block', 'publishrank' ),
			'rule'             => __( 'Custom rule', 'publishrank' ),
			'country'          => __( 'Country rule', 'publishrank' ),
			'country_unknown'  => __( 'Unknown location', 'publishrank' ),
			'reputation'       => __( 'IP reputation', 'publishrank' ),
			'probe'            => __( 'Probe for sensitive files', 'publishrank' ),
			'injection'        => __( 'Injection pattern', 'publishrank' ),
			'scanner'          => __( 'Vulnerability scanner', 'publishrank' ),
			'no_user_agent'    => __( 'No user agent', 'publishrank' ),
			'script_client'    => __( 'Script client', 'publishrank' ),
			'rate_limit'       => __( 'Rate limit', 'publishrank' ),
			'verified_crawler' => __( 'Verified crawler', 'publishrank' ),
			'test_match'       => __( 'Rule in test mode', 'publishrank' ),
			'firewall'         => __( 'Access Shield request firewall', 'publishrank' ),
			'manual'           => __( 'Added by hand', 'publishrank' ),
			'default'          => __( 'No rule matched', 'publishrank' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : (string) $reason;
	}

	/** Carry out a decision for a live request: record events and deny when enforcing. */
	private static function act( array $r, array $ctx, array $settings ) {
		foreach ( $r['tests'] as $rule_id ) {
			TrafficGuardStore::log( 'test_match', 'rule', $rule_id, $ctx, $settings );
		}
		switch ( $r['action'] ) {
			case 'allow':
				return;
			case 'log':
				TrafficGuardStore::log( 'logged', $r['reason'], $r['rule_id'], $ctx, $settings );
				return;
		}
		if ( 'enforce' !== $settings['mode'] ) {
			TrafficGuardStore::log( 'would_block', $r['reason'], $r['rule_id'], $ctx, $settings );
			return;
		}
		if ( 'temp_block' === $r['action'] ) {
			$minutes = ! empty( $r['minutes'] ) ? (int) $r['minutes'] : 60;
			TrafficGuardStore::block_ip( $ctx['ip'], $r['reason'] . ( $r['rule_id'] ? ':' . $r['rule_id'] : '' ), $minutes * MINUTE_IN_SECONDS );
		}
		$decision  = 'throttle' === $r['action'] ? 'rate_limited' : 'blocked';
		$reference = TrafficGuardStore::log( $decision, $r['reason'], $r['rule_id'], $ctx, $settings );
		self::deny( 'throttle' === $r['action'] ? 429 : 403, $reference, $settings, 'throttle' === $r['action'] ? (int) $settings['rate_window'] : 0 );
	}

	/* ------------------------------------------------------------------ Public helpers */

	/** The client IP as Traffic Guard sees it for the current request (used by the settings screen). */
	public static function current_client_ip( $trusted_proxies = array() ) {
		return self::client_ip( $trusted_proxies );
	}

	/** The trusted-source country code for the current request, or an empty string. */
	public static function current_country( $trusted_proxies = array() ) {
		$settings = self::settings();
		return CountryResolver::resolve( self::client_ip( $trusted_proxies ), (array) $trusted_proxies, $settings['geo_source'] )['code'];
	}

	/** Whether an IP matches any rule in a list; used for save-time lock-out checks. */
	public static function ip_matches_list( $ip, $networks ) {
		return self::matches_any( $ip, $networks );
	}

	/** Confirm a crawler claim with reverse DNS plus a matching forward lookup; the verdict is cached. */
	public static function is_verified_crawler( $ip, $agent = null ) {
		if ( null === $agent ) {
			$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		}
		if ( '' === (string) $agent || ! preg_match( self::CRAWLER_UA, (string) $agent ) || '' === self::valid_ip( (string) $ip ) ) {
			return false;
		}
		$key    = 'publishrank_tg_bot_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		$verified = false;
		$host     = strtolower( (string) @gethostbyaddr( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failure simply means "not verified".
		if ( '' !== $host && $host !== $ip ) {
			foreach ( self::CRAWLER_DOMAINS as $domain ) {
				if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
					$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					foreach ( is_array( $records ) ? $records : array() as $record ) {
						$candidate = isset( $record['ip'] ) ? $record['ip'] : ( isset( $record['ipv6'] ) ? $record['ipv6'] : '' );
						if ( '' !== self::valid_ip( (string) $candidate ) && self::valid_ip( (string) $candidate ) === self::valid_ip( $ip ) ) {
							$verified = true;
							break 2;
						}
					}
				}
			}
		}
		// The key is a salted hash, so no visitor IP is stored.
		set_transient( $key, $verified ? '1' : '0', $verified ? DAY_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		return $verified;
	}

	/** Validate and normalize ISO alpha-2/alpha-3 country values plus T1 (Tor). */
	public static function inspect_country_codes( $raw ) {
		return Countries::parse_list( $raw, true );
	}

	/** Never interfere with authenticated use, recovery endpoints, integrations or indexable feeds/maps. */
	public static function is_blockable_request() {
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && \PublishRankSEO\Core\Capabilities::can( 'manage_security' ) ) {
			return false;
		}
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( ( function_exists( 'is_feed' ) && is_feed() ) || ( function_exists( 'is_robots' ) && is_robots() ) || ( function_exists( 'is_preview' ) && is_preview() ) || ( function_exists( 'is_trackback' ) && is_trackback() ) || ( function_exists( 'is_embed' ) && is_embed() ) ) {
			return false;
		}
		if ( isset( $_GET['rest_route'] ) || isset( $_GET['wc-api'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';
		if ( preg_match( '~(?:^|/)wp-admin(?:/|$)|(?:^|/)wp-login\.php$|(?:^|/)wp-cron\.php$|(?:^|/)wp-json(?:/|$)|(?:^|/)wc-api(?:/|$)|(?:^|/)\.well-known/~i', $path ) || preg_match( '/\.(?:xml|xsl|txt)$/i', $path ) ) {
			return false;
		}
		if ( function_exists( 'get_query_var' ) && ( get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) || get_query_var( 'publishrank_sitemap' ) || get_query_var( 'publishrank_xsl' ) ) ) {
			return false;
		}
		return (bool) apply_filters( 'publishrank_traffic_guard_applies', true, $path );
	}

	/** Send the block page. Small, accessible, never cached or indexed. */
	private static function deny( $status, $reference, array $settings, $retry_after = 0 ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$charset = get_option( 'blog_charset', 'UTF-8' );
		if ( ! headers_sent() ) {
			status_header( $status );
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Cache-Control: no-store, private', true );
			header( 'Content-Type: text/html; charset=' . $charset, true );
			if ( $retry_after > 0 ) {
				header( 'Retry-After: ' . (int) $retry_after, true );
			}
		}
		$title   = 429 === $status ? __( 'Too many requests', 'publishrank' ) : __( 'Access restricted', 'publishrank' );
		$message = '' !== $settings['block_message'] ? $settings['block_message'] : ( 429 === $status ? __( 'You are sending requests faster than this site allows. Please wait a moment and try again.', 'publishrank' ) : __( 'Access to this page has been restricted by the site administrator.', 'publishrank' ) );
		$contact = '' !== $settings['contact_email'] ? '<p>' . sprintf( /* translators: %s: e-mail link */ esc_html__( 'If you think this is a mistake, contact %s and include the reference below.', 'publishrank' ), '<a href="mailto:' . esc_attr( antispambot( $settings['contact_email'] ) ) . '?subject=' . rawurlencode( 'Access reference ' . $reference ) . '">' . esc_html( antispambot( $settings['contact_email'] ) ) . '</a>' ) . '</p>' : '<p>' . esc_html__( 'If you think this is a mistake, contact the site owner and include the reference below.', 'publishrank' ) . '</p>';
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="' . esc_attr( $charset ) . '"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title><style>body{margin:0;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f4f1;color:#1d2327;display:grid;place-items:center;min-height:100vh;padding:24px;box-sizing:border-box}main{max-width:520px;background:#fff;border:1px solid #e2ddd5;border-radius:12px;padding:32px}h1{margin:0 0 8px;font-size:24px}code{background:#f0ece6;padding:2px 6px;border-radius:4px;font-size:15px}</style></head><body><main role="main"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p>' . $contact . ( '' !== $reference ? '<p>' . esc_html__( 'Reference:', 'publishrank' ) . ' <code>' . esc_html( $reference ) . '</code></p>' : '' ) . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic part is escaped above.
		exit;
	}

	/* ------------------------------------------------------------------ Network helpers */

	/** Validate and normalize one-IP-or-CIDR-per-line form data. */
	public static function inspect_networks( $raw ) {
		$values  = self::split_values( $raw );
		$valid   = array();
		$invalid = array();
		foreach ( $values as $value ) {
			if ( count( $valid ) >= self::MAX_RULES ) {
				$invalid[] = $value;
				continue;
			}
			$network = self::normalize_network( $value );
			if ( '' !== $network && self::is_too_broad( $network ) ) {
				$network = '';
			}
			if ( '' === $network ) {
				$invalid[] = $value;
			} elseif ( ! in_array( $network, $valid, true ) ) {
				$valid[] = $network;
			}
		}
		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	/** Reject networks so broad (e.g. 0.0.0.0/0) that they would match most of the internet. */
	private static function is_too_broad( $network ) {
		$parts = explode( '/', $network, 2 );
		if ( ! isset( $parts[1] ) ) {
			return false;
		}
		$packed = @inet_pton( $parts[0] );
		$min    = ( is_string( $packed ) && 16 === strlen( $packed ) ) ? self::MIN_PREFIX_V6 : self::MIN_PREFIX_V4;
		return (int) $parts[1] < $min;
	}

	/** Test whether an address belongs to a valid IP or CIDR network. */
	public static function matches_network( $ip, $network ) {
		$target = self::valid_ip( $ip );
		$parts  = explode( '/', trim( (string) $network ), 2 );
		$base   = self::valid_ip( $parts[0] );
		if ( '' === $target || '' === $base ) {
			return false;
		}
		$target_bin = @inet_pton( $target );
		$base_bin   = @inet_pton( $base );
		if ( false === $target_bin || false === $base_bin || strlen( $target_bin ) !== strlen( $base_bin ) ) {
			return false;
		}
		$bits   = strlen( $base_bin ) * 8;
		$prefix = isset( $parts[1] ) ? $parts[1] : (string) $bits;
		if ( ! preg_match( '/^\d+$/', $prefix ) || (int) $prefix < 0 || (int) $prefix > $bits ) {
			return false;
		}
		$prefix     = (int) $prefix;
		$full_bytes = intdiv( $prefix, 8 );
		if ( $full_bytes && substr( $target_bin, 0, $full_bytes ) !== substr( $base_bin, 0, $full_bytes ) ) {
			return false;
		}
		$remaining_bits = $prefix % 8;
		if ( $remaining_bits ) {
			$mask = ( 0xff << ( 8 - $remaining_bits ) ) & 0xff;
			if ( ( ord( $target_bin[ $full_bytes ] ) & $mask ) !== ( ord( $base_bin[ $full_bytes ] ) & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/** Return a canonical valid IPv4/IPv6 address, or an empty string. */
	public static function valid_ip( $ip ) {
		if ( ! is_string( $ip ) || strlen( $ip ) > 45 ) {
			return '';
		}
		$ip = trim( $ip );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return '';
		}
		// Treat IPv4-mapped IPv6 (::ffff:a.b.c.d) as the plain IPv4 address so IPv4 rules cannot be bypassed.
		if ( 16 === strlen( $packed ) && "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $packed, 0, 12 ) ) {
			$packed = substr( $packed, 12 );
		}
		$canonical = @inet_ntop( $packed );
		return is_string( $canonical ) ? strtolower( $canonical ) : '';
	}

	private static function normalize_network( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 64 || substr_count( $value, '/' ) > 1 ) {
			return '';
		}
		$parts = explode( '/', $value, 2 );
		$ip    = self::valid_ip( $parts[0] );
		if ( '' === $ip ) {
			return '';
		}
		if ( ! isset( $parts[1] ) ) {
			return $ip;
		}
		$packed   = @inet_pton( $ip );
		$max_bits = is_string( $packed ) ? strlen( $packed ) * 8 : 0;
		if ( ! preg_match( '/^\d+$/', $parts[1] ) || (int) $parts[1] < 0 || (int) $parts[1] > $max_bits ) {
			return '';
		}
		return $ip . '/' . (int) $parts[1];
	}

	private static function split_values( $raw ) {
		if ( is_array( $raw ) ) {
			$raw = implode( "\n", array_map( 'strval', $raw ) );
		}
		if ( ! is_string( $raw ) || strlen( $raw ) > 65536 ) {
			return array();
		}
		$parts  = preg_split( '/[\r\n,]+/', $raw );
		$values = array();
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$values[] = $part;
			}
		}
		return $values;
	}

	private static function matches_any( $ip, $networks ) {
		foreach ( is_array( $networks ) ? $networks : array() as $network ) {
			if ( self::matches_network( $ip, $network ) ) {
				return true;
			}
		}
		return false;
	}

	/** Trust forwarded addresses only when the direct peer is explicitly trusted. */
	private static function client_ip( $trusted_proxies ) {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? self::valid_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( '' === $remote || ! self::matches_any( $remote, $trusted_proxies ) ) {
			return $remote;
		}

		$cloudflare_ip = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? self::valid_ip( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ) : '';
		if ( '' !== $cloudflare_ip ) {
			return $cloudflare_ip;
		}

		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && is_string( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		if ( '' === $forwarded || strlen( $forwarded ) > 4096 ) {
			return $remote;
		}
		$chain = explode( ',', $forwarded );
		if ( count( $chain ) > 32 ) {
			return $remote;
		}
		$chain[] = $remote;
		for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
			$candidate = self::valid_ip( trim( $chain[ $i ] ) );
			if ( '' === $candidate ) {
				return $remote;
			}
			if ( self::matches_any( $candidate, $trusted_proxies ) ) {
				continue;
			}
			return $candidate;
		}
		return $remote;
	}

}
