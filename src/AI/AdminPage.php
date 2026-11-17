<?php
/**
 * PublishRank → AI Visibility: crawler control, verified AI-bot visit log, Markdown delivery and
 * the citation tracker.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AI;

use PublishRankSEO\AccessShield\Crypto;
use PublishRankSEO\Admin\Suite;
use PublishRankSEO\Core\Branding;
use PublishRankSEO\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class AdminPage {
	const SLUG = 'publishrank-ai-visibility';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		foreach ( array( 'crawlers', 'preset', 'lists', 'markdown', 'citations', 'run', 'cancel', 'test', 'export' ) as $a ) {
			add_action( 'admin_post_publishrank_ai_' . $a, array( $this, 'handle_' . $a ) );
		}
	}

	public function menu() {
		$cap = Capabilities::can( 'manage_ai' ) ? Capabilities::cap( 'manage_ai' ) : Capabilities::cap( 'view_ai_log' );
		add_submenu_page( Branding::MENU_SLUG, __( 'AI Visibility', 'publishrank' ), __( 'AI Visibility', 'publishrank' ), $cap, self::SLUG, array( $this, 'render' ) );
	}

	public function assets() {
		Suite::enqueue_for( self::SLUG );
	}

	/* ---------------- Handlers ---------------- */

	public function handle_crawlers() {
		Suite::guard( 'publishrank_ai_crawlers', 'manage_ai' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by CrawlerControl::save().
		$in = isset( $_POST['ai'] ) && is_array( $_POST['ai'] ) ? wp_unslash( $_POST['ai'] ) : array();
		foreach ( array( 'block_spoofed', 'log_enabled', 'log_engines', 'log_seo' ) as $k ) {
			$in[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		CrawlerControl::save( $in );
		$verify = ! empty( $_POST['verify'] );
		update_option( BotVerifier::OPT_ON, $verify ? 1 : 0, false );
		if ( $verify && ! BotVerifier::lists() ) {
			BotVerifier::refresh_lists();
		}
		Suite::done( self::SLUG, 'success', __( 'AI crawler rules saved. robots.txt was updated to match.', 'publishrank' ), array(), 'crawlers' );
	}

	public function handle_preset() {
		Suite::guard( 'publishrank_ai_preset', 'manage_ai' );
		$preset = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : '';
		$rules  = array();
		foreach ( BotRegistry::all() as $token => $b ) {
			if ( ! in_array( $b['purpose'], array( 'training', 'search', 'assistant' ), true ) ) {
				continue;
			}
			$cur  = CrawlerControl::rule( $token );
			$mode = 'allow';
			if ( 'protect' === $preset ) {
				$mode = 'training' === $b['purpose'] ? ( $b['robots_only'] ? 'robots' : 'block' ) : 'allow';
			} elseif ( 'block_all' === $preset ) {
				$mode = $b['robots_only'] ? 'robots' : 'block';
			} elseif ( 'robots_training' === $preset ) {
				$mode = 'training' === $b['purpose'] ? 'robots' : 'allow';
			}
			$rules[ $token ] = array( 'mode' => $mode, 'limit' => $cur['limit'], 'markdown' => 'block' === $mode ? 0 : 1 );
		}
		foreach ( CrawlerControl::settings()['rules'] as $token => $r ) {
			if ( ! isset( $rules[ $token ] ) ) {
				$rules[ $token ] = $r;
			}
		}
		CrawlerControl::save( array( 'rules' => $rules ) );
		Suite::done( self::SLUG, 'success', __( 'Preset applied. Review the table and adjust any crawler.', 'publishrank' ), array(), 'crawlers' );
	}

	public function handle_lists() {
		Suite::guard( 'publishrank_ai_lists', 'manage_ai' );
		$res = BotVerifier::refresh_lists();
		$ok  = count( array_filter( $res, static function ( $r ) {
			return 0 !== strpos( $r, 'error' );
		} ) );
		/* translators: 1: ok, 2: total */
		Suite::done( self::SLUG, $ok === count( $res ) ? 'success' : 'warning', sprintf( __( 'Downloaded %1$d of %2$d crawler IP lists.', 'publishrank' ), $ok, count( $res ) ), array(), 'crawlers' );
	}

	public function handle_markdown() {
		Suite::guard( 'publishrank_ai_markdown', 'manage_ai' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by MarkdownDelivery::save().
		MarkdownDelivery::save( isset( $_POST['md'] ) && is_array( $_POST['md'] ) ? wp_unslash( $_POST['md'] ) : array() );
		Suite::done( self::SLUG, 'success', __( 'Markdown delivery saved.', 'publishrank' ), array(), 'markdown' );
	}

	public function handle_citations() {
		Suite::guard( 'publishrank_ai_citations', 'manage_ai' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by CitationTracker::save().
		CitationTracker::save( isset( $_POST['ct'] ) && is_array( $_POST['ct'] ) ? wp_unslash( $_POST['ct'] ) : array() );
		Suite::done( self::SLUG, 'success', __( 'Citation tracker saved.', 'publishrank' ), array(), 'citations' );
	}

	public function handle_run() {
		Suite::guard( 'publishrank_ai_run', 'manage_ai' );
		$run = CitationTracker::start_run();
		if ( is_wp_error( $run ) ) {
			Suite::done( self::SLUG, 'error', $run->get_error_message(), array(), 'citations' );
		}
		$n = CitationTracker::process_batch();
		$j = CitationTracker::job();
		/* translators: 1: done, 2: remaining */
		Suite::done( self::SLUG, 'success', $j ? sprintf( __( 'Run started: %1$d answers checked, %2$d more continue in the background.', 'publishrank' ), $n, count( $j['items'] ) ) : sprintf( __( 'Run finished: %d answers checked.', 'publishrank' ), $n ), array(), 'citations' );
	}

	public function handle_cancel() {
		Suite::guard( 'publishrank_ai_cancel', 'manage_ai' );
		CitationTracker::cancel();
		Suite::done( self::SLUG, 'success', __( 'Run cancelled.', 'publishrank' ), array(), 'citations' );
	}

	public function handle_test() {
		Suite::guard( 'publishrank_ai_test', 'manage_ai' );
		$p = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		if ( ! isset( CitationTracker::providers()[ $p ] ) ) {
			Suite::done( self::SLUG, 'error', __( 'Unknown provider.', 'publishrank' ), array(), 'citations' );
		}
		$r = CitationTracker::test( $p );
		Suite::done( self::SLUG, true === $r ? 'success' : 'error', true === $r ? __( 'Connection works.', 'publishrank' ) : sprintf( /* translators: %s: error */ __( 'Connection failed: %s', 'publishrank' ), $r->get_error_message() ), array(), 'citations' );
	}

	public function handle_export() {
		Suite::guard( 'publishrank_ai_export', 'view_ai_log' );
		$f    = $this->filters( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard().
		$rows = array();
		for ( $page = 1; $page <= 50; $page++ ) {
			$res = CrawlerControl::visits( $f + array( 'paged' => $page, 'per_page' => 1000 ) );
			foreach ( $res['rows'] as $r ) {
				$rows[] = array( $r['created_at'], $r['bot'], $r['operator'], $r['purpose'], BotVerifier::labels()[ (int) $r['verified'] ] ?? '', $r['ip'], $r['method'], $r['path'], $r['status'], $r['action'] );
			}
			if ( count( $res['rows'] ) < 1000 ) {
				break;
			}
		}
		Suite::csv( 'publishrank-ai-bot-visits-' . gmdate( 'Ymd-His' ) . '.csv', array( 'created_at_utc', 'bot', 'operator', 'purpose', 'verification', 'ip', 'method', 'path', 'status', 'action' ), $rows );
	}

	private function filters( $src ) {
		$f = array();
		foreach ( array( 'bot', 'purpose', 'verified', 'action', 'search', 'from', 'to' ) as $k ) {
			$f[ $k ] = isset( $src[ 'v_' . $k ] ) ? sanitize_text_field( wp_unslash( $src[ 'v_' . $k ] ) ) : '';
		}
		return $f;
	}

	/* ---------------- Screen ---------------- */

	public function render() {
		$manage = Capabilities::can( 'manage_ai' );
		if ( ! $manage && ! Capabilities::can( 'view_ai_log' ) ) {
			return;
		}
		Suite::open( __( 'AI Visibility', 'publishrank' ), __( 'Decide which AI crawlers may read your journalism, see which ones really visited, serve them clean Markdown, and track whether AI answers cite you.', 'publishrank' ) );
		$totals = CrawlerControl::totals( 30 );
		$ai     = 0;
		$ver    = 0;
		$spoof  = 0;
		foreach ( $totals as $token => $t ) {
			$b = BotRegistry::get( $token );
			if ( $b && in_array( $b['purpose'], array( 'training', 'search', 'assistant' ), true ) ) {
				$ai    += $t['total'];
				$ver   += $t['verified'];
				$spoof += $t['spoofed'];
			}
		}
		$sum = CitationTracker::summary();
		echo '<div class="prx-grid prx-grid--4">';
		Suite::kpi( __( 'AI crawler requests (30 days)', 'publishrank' ), number_format_i18n( $ai ), '', true );
		Suite::kpi( __( 'Verified', 'publishrank' ), $ai ? round( 100 * $ver / $ai ) . '%' : '—', BotVerifier::enabled() ? sprintf( /* translators: %s: number */ __( '%s spoofed', 'publishrank' ), number_format_i18n( $spoof ) ) : __( 'Verification is off', 'publishrank' ) );
		Suite::kpi( __( 'Markdown answers (7 days)', 'publishrank' ), number_format_i18n( $this->markdown_count() ) );
		Suite::kpi( __( 'Cited in AI answers', 'publishrank' ), $sum['total'] ? round( 100 * $sum['cited'] / $sum['total'] ) . '%' : '—', $sum['total'] ? sprintf( /* translators: 1: cited, 2: total */ __( '%1$d of %2$d answers, last run', 'publishrank' ), $sum['cited'], $sum['total'] ) : __( 'No run yet', 'publishrank' ) );
		echo '</div>';
		$tabs = array(
			'overview'  => __( 'Overview', 'publishrank' ),
			'crawlers'  => __( 'Crawler control', 'publishrank' ),
			'log'       => __( 'Visit log', 'publishrank' ),
			'markdown'  => __( 'Markdown for agents', 'publishrank' ),
			'citations' => __( 'Citation tracker', 'publishrank' ),
		);
		Suite::tabs( $tabs );
		$this->tab_overview( $totals );
		$this->tab_crawlers( $totals, $manage );
		$this->tab_log();
		$this->tab_markdown( $manage );
		$this->tab_citations( $manage, $sum );
		Suite::close();
	}

	private function markdown_count() {
		global $wpdb;
		if ( ! \PublishRankSEO\Core\Database::ready( 'ai_bots' ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \PublishRankSEO\Core\Database::table( 'ai_bots' ) . " WHERE action = 'markdown' AND created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	private function verify_badge( $b ) {
		if ( $b['robots_only'] ) {
			return Suite::badge( __( 'robots.txt token', 'publishrank' ), '' );
		}
		if ( '' !== $b['ips'] ) {
			return Suite::badge( __( 'IP list', 'publishrank' ), 'ok' );
		}
		if ( $b['rdns'] ) {
			return Suite::badge( __( 'Reverse DNS', 'publishrank' ), 'info' );
		}
		return Suite::badge( __( 'No method', 'publishrank' ), 'warn' );
	}

	private function tab_overview( array $totals ) {
		Suite::panel_open( 'overview', false );
		echo '<div class="prx-grid prx-grid--side"><div class="prx-stack">';
		Suite::card_open( __( 'Crawler requests, last 30 days', 'publishrank' ), 'fa-chart-column', __( 'AI crawlers (plum) and classic search engines (green).', 'publishrank' ) );
		echo '<svg class="prx-chart" role="img" aria-label="' . esc_attr__( 'Daily crawler requests', 'publishrank' ) . '" data-prx-chart="' . esc_attr( wp_json_encode( CrawlerControl::series( 30 ) ) ) . '"></svg>';
		Suite::card_close();
		Suite::card_open( __( 'Most-read pages by AI crawlers (7 days)', 'publishrank' ), 'fa-file-lines' );
		$paths = CrawlerControl::top_paths( 12 );
		if ( ! $paths ) {
			echo '<div class="prx-empty"><span class="fa-solid fa-robot" aria-hidden="true"></span><strong>' . esc_html__( 'No AI crawler visits recorded yet', 'publishrank' ) . '</strong><span>' . esc_html__( 'Visits appear here as soon as GPTBot, ClaudeBot, PerplexityBot and others fetch your pages.', 'publishrank' ) . '</span></div>';
		} else {
			echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Page', 'publishrank' ) . '</th><th class="prx-num">' . esc_html__( 'Requests', 'publishrank' ) . '</th><th class="prx-num">' . esc_html__( 'Crawlers', 'publishrank' ) . '</th><th class="prx-num">' . esc_html__( 'As Markdown', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( $paths as $p ) {
				echo '<tr><td class="prx-mono"><a href="' . esc_url( home_url( $p['path'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $p['path'] ) . '</a></td><td class="prx-num">' . esc_html( number_format_i18n( (int) $p['hits'] ) ) . '</td><td class="prx-num">' . (int) $p['bots'] . '</td><td class="prx-num">' . esc_html( number_format_i18n( (int) $p['markdown'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		Suite::card_close();
		echo '</div><div class="prx-stack">';
		Suite::card_open( __( 'Busiest crawlers (30 days)', 'publishrank' ), 'fa-ranking-star' );
		if ( ! $totals ) {
			echo '<p class="prx-help">' . esc_html__( 'Nothing recorded yet.', 'publishrank' ) . '</p>';
		} else {
			echo '<ul class="prx-list">';
			foreach ( array_slice( $totals, 0, 10, true ) as $token => $t ) {
				$b = BotRegistry::get( $token );
				echo '<li><div style="flex:1"><strong>' . esc_html( $token ) . '</strong> <span class="prx-help">' . esc_html( $b ? $b['operator'] . ' · ' . ( BotRegistry::purposes()[ $b['purpose'] ] ?? '' ) : '' ) . '</span><div class="prx-meter prx-meter--ok" style="margin-top:6px"><span style="width:' . (int) ( $t['total'] ? 100 * $t['verified'] / $t['total'] : 0 ) . '%"></span></div></div><span class="prx-num">' . esc_html( number_format_i18n( $t['total'] ) ) . '</span></li>';
			}
			echo '</ul><p class="prx-help">' . esc_html__( 'The green bar shows the verified share of each crawler’s requests.', 'publishrank' ) . '</p>';
		}
		Suite::card_close();
		Suite::card_open( __( 'For AI agents', 'publishrank' ), 'fa-link' );
		echo '<ul class="prx-list"><li><a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener">/llms.txt</a></li><li><a href="' . esc_url( home_url( '/index.md' ) ) . '" target="_blank" rel="noopener">/index.md</a> <span class="prx-help">' . esc_html__( 'site overview in Markdown', 'publishrank' ) . '</span></li><li><a href="' . esc_url( home_url( '/robots.txt' ) ) . '" target="_blank" rel="noopener">/robots.txt</a></li></ul>';
		Suite::card_close();
		echo '</div></div>';
		Suite::panel_close();
	}

	private function tab_crawlers( array $totals, $manage ) {
		Suite::panel_open( 'crawlers' );
		$s = CrawlerControl::settings();
		if ( $manage ) {
			echo '<div class="prx-toolbar">';
			foreach ( array(
				'protect'         => array( __( 'Block training, allow AI search', 'publishrank' ), __( 'Recommended for publishers: archives are protected, you still appear (and get cited) in AI answers.', 'publishrank' ) ),
				'robots_training' => array( __( 'Ask training crawlers to stay away', 'publishrank' ), __( 'robots.txt only; nothing is refused at the server.', 'publishrank' ) ),
				'block_all'       => array( __( 'Block every AI crawler', 'publishrank' ), __( 'You will disappear from AI search answers too.', 'publishrank' ) ),
				'allow_all'       => array( __( 'Allow every AI crawler', 'publishrank' ), '' ),
			) as $k => $p ) {
				Suite::form_open( 'publishrank_ai_preset', 'block_all' === $k ? array( 'data-prx-confirm' => $p[1], 'data-prx-danger' => '1' ) : array() );
				echo '<input type="hidden" name="preset" value="' . esc_attr( $k ) . '" /><button class="prx-btn prx-btn--sm' . ( 'protect' === $k ? ' prx-btn--primary' : '' ) . '" type="submit" title="' . esc_attr( $p[1] ) . '">' . esc_html( $p[0] ) . '</button></form>';
			}
			echo '</div>';
		}
		Suite::form_open( 'publishrank_ai_crawlers' );
		$modes = array( 'allow' => __( 'Allow', 'publishrank' ), 'robots' => __( 'Ask to stay away (robots.txt)', 'publishrank' ), 'block' => __( 'Block (robots.txt + refuse)', 'publishrank' ), 'limit' => __( 'Rate-limit', 'publishrank' ) );
		foreach ( array( 'training', 'search', 'assistant' ) as $purpose ) {
			Suite::card_open( BotRegistry::purposes()[ $purpose ], 'training' === $purpose ? 'fa-graduation-cap' : ( 'search' === $purpose ? 'fa-magnifying-glass' : 'fa-comments' ), 'training' === $purpose ? __( 'Collect content to train AI models. Blocking these does not affect search or AI answers.', 'publishrank' ) : ( 'search' === $purpose ? __( 'Index pages so AI answers can quote and link to you. Blocking these removes you from AI search.', 'publishrank' ) : __( 'Fetch a page only when a person asks an assistant about it.', 'publishrank' ) ) );
			echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Crawler', 'publishrank' ) . '</th><th>' . esc_html__( 'Verification', 'publishrank' ) . '</th><th class="prx-num">' . esc_html__( 'Requests (30 d)', 'publishrank' ) . '</th><th>' . esc_html__( 'Rule', 'publishrank' ) . '</th><th>' . esc_html__( 'Per minute', 'publishrank' ) . '</th><th>' . esc_html__( 'Markdown', 'publishrank' ) . '</th></tr></thead><tbody>';
			foreach ( BotRegistry::all() as $token => $b ) {
				if ( $b['purpose'] !== $purpose ) {
					continue;
				}
				$r  = CrawlerControl::rule( $token );
				$t  = $totals[ $token ] ?? array( 'total' => 0, 'verified' => 0, 'spoofed' => 0 );
				$nm = 'ai[rules][' . $token . ']';
				echo '<tr><td><strong>' . esc_html( $token ) . '</strong><br /><span class="prx-help">' . esc_html( $b['operator'] ) . ( $b['docs'] ? ' · <a href="' . esc_url( $b['docs'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'docs', 'publishrank' ) . '</a>' : '' ) . '</span></td><td>' . $this->verify_badge( $b ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge escapes.
				echo '<td class="prx-num">' . esc_html( number_format_i18n( $t['total'] ) ) . ( $t['spoofed'] ? '<br /><span class="prx-help">' . esc_html( sprintf( /* translators: %s: number */ __( '%s spoofed', 'publishrank' ), number_format_i18n( $t['spoofed'] ) ) ) . '</span>' : '' ) . '</td><td>';
				echo '<select name="' . esc_attr( $nm . '[mode]' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: crawler */ __( 'Rule for %s', 'publishrank' ), $token ) ) . '"' . disabled( ! $manage, true, false ) . '>';
				foreach ( $modes as $k => $l ) {
					if ( $b['robots_only'] && in_array( $k, array( 'block', 'limit' ), true ) ) {
						continue;
					}
					echo '<option value="' . esc_attr( $k ) . '"' . selected( $r['mode'], $k, false ) . '>' . esc_html( $l ) . '</option>';
				}
				echo '</select></td><td>' . ( $b['robots_only'] ? '—' : '<input class="prx-input" style="width:84px" type="number" min="1" max="10000" name="' . esc_attr( $nm . '[limit]' ) . '" value="' . esc_attr( (string) $r['limit'] ) . '" aria-label="' . esc_attr__( 'Requests per minute', 'publishrank' ) . '" />' ) . '</td>';
				echo '<td>' . ( $b['robots_only'] ? '—' : '<label><input type="checkbox" name="' . esc_attr( $nm . '[markdown]' ) . '" value="1"' . checked( $r['markdown'], 1, false ) . ' /> ' . esc_html__( 'Allowed', 'publishrank' ) . '</label>' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
			Suite::card_close();
		}
		echo '<div class="prx-grid prx-grid--2">';
		Suite::card_open( __( 'Verification', 'publishrank' ), 'fa-certificate', __( 'Anyone can claim to be GPTBot. Verification checks the request really came from the operator.', 'publishrank' ) );
		Suite::switch_field( 'verify', BotVerifier::enabled(), __( 'Verify crawlers', 'publishrank' ), __( 'Downloads each operator’s official IP list once a day (OpenAI, Perplexity, Google, Microsoft, Apple, Common Crawl, DuckDuckGo, Mistral) and checks reverse DNS in the background where operators document it.', 'publishrank' ) );
		Suite::switch_field( 'ai[block_spoofed]', ! empty( $s['block_spoofed'] ), __( 'Refuse spoofed crawlers', 'publishrank' ), __( 'Requests that claim a verifiable crawler’s name but come from somewhere else get HTTP 403.', 'publishrank' ) );
		$lists = BotVerifier::lists();
		if ( $lists ) {
			echo '<ul class="prx-list">';
			foreach ( $lists as $url => $l ) {
				echo '<li><span class="prx-mono" style="flex:1;overflow-wrap:anywhere">' . esc_html( preg_replace( '#^https?://#', '', $url ) ) . '</span>' . ( ! empty( $l['prefixes'] ) ? Suite::badge( sprintf( /* translators: %d: ranges */ _n( '%d range', '%d ranges', count( $l['prefixes'] ), 'publishrank' ), count( $l['prefixes'] ) ), 'ok' ) : Suite::badge( __( 'Failed', 'publishrank' ), 'bad' ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul>';
		}
		Suite::card_close();
		Suite::card_open( __( 'Visit log', 'publishrank' ), 'fa-list-ul' );
		Suite::switch_field( 'ai[log_enabled]', ! empty( $s['log_enabled'] ), __( 'Record crawler visits', 'publishrank' ) );
		Suite::switch_field( 'ai[log_engines]', ! empty( $s['log_engines'] ), __( 'Also record Google, Bing and other search engines', 'publishrank' ) );
		Suite::switch_field( 'ai[log_seo]', ! empty( $s['log_seo'] ), __( 'Also record SEO tools (Ahrefs, Semrush…)', 'publishrank' ) );
		echo '<div class="prx-fields">';
		Suite::field( 'number', 'ai[retention_days]', $s['retention_days'], __( 'Keep visits (days)', 'publishrank' ), '', array( 'min' => 1, 'max' => 365 ) );
		Suite::field( 'number', 'ai[max_per_minute]', $s['max_per_minute'], __( 'Detailed rows per minute', 'publishrank' ), __( 'Daily totals stay exact above this.', 'publishrank' ), array( 'min' => 10 ) );
		echo '</div>';
		Suite::card_close();
		echo '</div>';
		if ( $manage ) {
			echo '<div class="prx-card__foot"><button class="prx-btn prx-btn--primary" type="submit"><span class="fa-solid fa-floppy-disk" aria-hidden="true"></span>' . esc_html__( 'Save crawler rules', 'publishrank' ) . '</button></div>';
		}
		echo '</form>';
		if ( $manage && BotVerifier::enabled() ) {
			Suite::form_open( 'publishrank_ai_lists', array( 'style' => 'margin-top:10px' ) );
			echo '<button class="prx-btn prx-btn--sm" type="submit"><span class="fa-solid fa-rotate" aria-hidden="true"></span>' . esc_html__( 'Refresh IP lists now', 'publishrank' ) . '</button></form>';
		}
		Suite::panel_close();
	}

	private function tab_log() {
		Suite::panel_open( 'log' );
		$f     = $this->filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res   = CrawlerControl::visits( $f + array( 'paged' => $paged, 'per_page' => 50 ) );
		echo '<form method="get" class="prx-toolbar"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input class="prx-input prx-search" type="search" name="v_search" value="' . esc_attr( $f['search'] ) . '" placeholder="' . esc_attr__( 'Search path or IP… (press /)', 'publishrank' ) . '" />';
		echo '<select name="v_bot"><option value="">' . esc_html__( 'All crawlers', 'publishrank' ) . '</option>';
		foreach ( array_keys( BotRegistry::all() ) as $t ) {
			echo '<option value="' . esc_attr( $t ) . '"' . selected( $f['bot'], $t, false ) . '>' . esc_html( $t ) . '</option>';
		}
		echo '</select><select name="v_purpose"><option value="">' . esc_html__( 'All purposes', 'publishrank' ) . '</option>';
		foreach ( BotRegistry::purposes() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['purpose'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><select name="v_verified"><option value="">' . esc_html__( 'Any verification', 'publishrank' ) . '</option>';
		foreach ( BotVerifier::labels() as $k => $l ) {
			echo '<option value="' . esc_attr( (string) $k ) . '"' . selected( (string) $f['verified'], (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><select name="v_action"><option value="">' . esc_html__( 'Any outcome', 'publishrank' ) . '</option>';
		foreach ( array( 'allowed' => __( 'Allowed', 'publishrank' ), 'markdown' => __( 'Served Markdown', 'publishrank' ), 'blocked' => __( 'Blocked', 'publishrank' ), 'limited' => __( 'Rate-limited', 'publishrank' ), 'spoofed' => __( 'Refused as spoofed', 'publishrank' ) ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['action'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><input type="date" name="v_from" value="' . esc_attr( $f['from'] ) . '" aria-label="' . esc_attr__( 'From', 'publishrank' ) . '" /><input type="date" name="v_to" value="' . esc_attr( $f['to'] ) . '" aria-label="' . esc_attr__( 'To', 'publishrank' ) . '" /><button class="prx-btn" type="submit">' . esc_html__( 'Filter', 'publishrank' ) . '</button></form>';
		echo '<div class="prx-row" style="margin-bottom:10px">';
		Suite::form_open( 'publishrank_ai_export' );
		foreach ( $f as $k => $v ) {
			echo '<input type="hidden" name="v_' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '" />';
		}
		echo '<button class="prx-btn prx-btn--sm" type="submit"><span class="fa-solid fa-file-csv" aria-hidden="true"></span>' . esc_html__( 'Export CSV', 'publishrank' ) . '</button></form><span class="prx-spacer"></span><span class="prx-help">' . esc_html( sprintf( /* translators: %s: count */ __( '%s visits', 'publishrank' ), number_format_i18n( $res['total'] ) ) ) . '</span></div>';
		echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'When', 'publishrank' ) . '</th><th>' . esc_html__( 'Crawler', 'publishrank' ) . '</th><th>' . esc_html__( 'Verification', 'publishrank' ) . '</th><th>' . esc_html__( 'Page', 'publishrank' ) . '</th><th class="prx-num">' . esc_html__( 'Status', 'publishrank' ) . '</th><th>' . esc_html__( 'Outcome', 'publishrank' ) . '</th><th class="prx-hide-mobile">IP</th></tr></thead><tbody>';
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="7"><div class="prx-empty"><span class="fa-solid fa-robot" aria-hidden="true"></span><strong>' . esc_html__( 'No visits match', 'publishrank' ) . '</strong></div></td></tr>';
		}
		$vt = array( 0 => 'bad', 1 => 'ok', 2 => 'info', 3 => '' );
		$at = array( 'allowed' => '', 'markdown' => 'info', 'blocked' => 'bad', 'limited' => 'warn', 'spoofed' => 'bad' );
		foreach ( $res['rows'] as $r ) {
			echo '<tr><td title="' . esc_attr( $r['created_at'] . ' UTC' ) . '">' . esc_html( Suite::ago( $r['created_at'] ) ) . '</td><td><strong>' . esc_html( $r['bot'] ) . '</strong><br /><span class="prx-help">' . esc_html( $r['operator'] ) . '</span></td><td>' . Suite::badge( BotVerifier::labels()[ (int) $r['verified'] ] ?? '', $vt[ (int) $r['verified'] ] ?? '' ) . '</td><td class="prx-mono">' . esc_html( $r['method'] . ' ' . $r['path'] ) . '</td><td class="prx-num">' . (int) $r['status'] . '</td><td>' . Suite::badge( $r['action'], $at[ $r['action'] ] ?? '' ) . '</td><td class="prx-mono prx-hide-mobile">' . esc_html( $r['ip'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>';
		Suite::pagination( $res['total'], 50, $paged );
		Suite::panel_close();
	}

	private function tab_markdown( $manage ) {
		Suite::panel_open( 'markdown' );
		$s = MarkdownDelivery::settings();
		echo '<div class="prx-grid prx-grid--side"><div class="prx-stack">';
		Suite::card_open( __( 'Markdown delivery', 'publishrank' ), 'fa-file-code', __( 'AI agents read Markdown far more reliably than themed HTML. Each eligible article gets a clean Markdown twin with title, author, dates, tags and canonical link.', 'publishrank' ) );
		Suite::form_open( 'publishrank_ai_markdown' );
		Suite::switch_field( 'md[enabled]', $s['enabled'], __( 'Serve Markdown versions', 'publishrank' ) );
		Suite::switch_field( 'md[suffix]', $s['suffix'], __( 'Add “.md” to any article address', 'publishrank' ), __( 'Example: /my-article.md and /index.md for the whole site.', 'publishrank' ) );
		Suite::switch_field( 'md[query]', $s['query'], __( 'Accept ?format=md', 'publishrank' ) );
		Suite::switch_field( 'md[negotiate]', $s['negotiate'], __( 'Answer “Accept: text/markdown” requests with Markdown', 'publishrank' ), __( 'Agents that ask for Markdown get it on the normal address; browsers keep getting HTML.', 'publishrank' ) );
		Suite::switch_field( 'md[front_matter]', $s['front_matter'], __( 'Include front matter (title, author, dates, tags)', 'publishrank' ) );
		Suite::switch_field( 'md[footer]', $s['footer'], __( 'End each file with a source line linking to the article', 'publishrank' ) );
		Suite::switch_field( 'md[discovery]', $s['discovery'], __( 'Advertise the Markdown version', 'publishrank' ), __( 'Adds <link rel="alternate" type="text/markdown"> and an HTTP Link header.', 'publishrank' ) );
		Suite::switch_field( 'md[noindex]', $s['noindex'], __( 'Keep Markdown out of search results', 'publishrank' ), __( 'Sends X-Robots-Tag: noindex so search engines index only the HTML article.', 'publishrank' ) );
		Suite::switch_field( 'md[humans]', $s['humans'], __( 'Also serve Markdown to people and unknown tools', 'publishrank' ) );
		echo '<fieldset class="prx-field"><legend class="prx-label">' . esc_html__( 'Content types', 'publishrank' ) . '</legend><div class="prx-row">';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name || 'product' === $pt->name ) {
				continue;
			}
			echo '<label><input type="checkbox" name="md[post_types][]" value="' . esc_attr( $pt->name ) . '"' . checked( in_array( $pt->name, $s['post_types'], true ), true, false ) . ' /> ' . esc_html( $pt->labels->name ) . '</label>';
		}
		echo '</div></fieldset>';
		if ( $manage ) {
			echo '<div class="prx-card__foot"><button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Save Markdown settings', 'publishrank' ) . '</button></div>';
		}
		echo '</form>';
		Suite::card_close();
		echo '</div><div class="prx-stack">';
		Suite::card_open( __( 'Try it', 'publishrank' ), 'fa-flask' );
		$recent = get_posts( array( 'post_type' => $s['post_types'], 'post_status' => 'publish', 'posts_per_page' => 6, 'no_found_rows' => true ) );
		echo '<ul class="prx-list">';
		foreach ( $recent as $p ) {
			if ( MarkdownDelivery::eligible( $p ) ) {
				echo '<li><span style="flex:1">' . esc_html( get_the_title( $p ) ) . '</span><a class="prx-btn prx-btn--sm" href="' . esc_url( MarkdownDelivery::url_for( $p ) ) . '" target="_blank" rel="noopener">.md</a></li>';
			}
		}
		echo '</ul><pre class="prx-code">curl -H "Accept: text/markdown" ' . esc_html( $recent ? get_permalink( $recent[0] ) : home_url( '/' ) ) . '</pre>';
		Suite::card_close();
		echo '</div></div>';
		Suite::panel_close();
	}

	private function tab_citations( $manage, array $sum ) {
		Suite::panel_open( 'citations' );
		$s   = CitationTracker::settings();
		$job = CitationTracker::job();
		if ( $job ) {
			echo '<div class="prx-callout prx-callout--info" role="status"><span class="fa-solid fa-spinner fa-spin-pulse" aria-hidden="true"></span><div><p><strong>' . esc_html( sprintf( /* translators: 1: done, 2: total */ __( 'Run in progress: %1$d of %2$d answers checked.', 'publishrank' ), $job['done'], $job['total'] ) ) . '</strong> ' . esc_html__( 'It continues in the background; reload to see progress.', 'publishrank' ) . '</p>';
			if ( $manage ) {
				Suite::form_open( 'publishrank_ai_cancel' );
				echo '<button class="prx-btn prx-btn--sm" type="submit">' . esc_html__( 'Cancel run', 'publishrank' ) . '</button></form>';
			}
			echo '</div></div>';
		}
		echo '<div class="prx-grid prx-grid--side"><div class="prx-stack">';
		$latest = CitationTracker::latest();
		$provs  = CitationTracker::providers();
		Suite::card_open( __( 'Your questions in AI answers', 'publishrank' ), 'fa-quote-left', __( 'Latest answer per question and engine: cited (linked as a source, with its position), mentioned by name, or absent.', 'publishrank' ), $manage && ! $job ? '' : '' );
		if ( ! $s['prompts'] ) {
			echo '<div class="prx-empty"><span class="fa-solid fa-circle-question" aria-hidden="true"></span><strong>' . esc_html__( 'Add the questions your readers ask', 'publishrank' ) . '</strong><span>' . esc_html__( 'For example: “Who covers city council news in Springfield?” Then add an API key and press Run now.', 'publishrank' ) . '</span></div>';
		} else {
			echo '<div class="prx-table-wrap"><table class="prx-table"><thead><tr><th>' . esc_html__( 'Question', 'publishrank' ) . '</th>';
			foreach ( $provs as $k => $p ) {
				echo '<th>' . esc_html( strtok( $p['label'], ' ' ) ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $s['prompts'] as $q ) {
				echo '<tr><td>' . esc_html( $q['text'] ) . '</td>';
				foreach ( $provs as $k => $p ) {
					$r = $latest[ $q['id'] ][ $k ] ?? null;
					echo '<td>';
					if ( ! $r ) {
						echo '<span class="prx-help">—</span>';
					} elseif ( 'error' === $r['status'] ) {
						echo Suite::badge( __( 'Error', 'publishrank' ), 'bad' ) . '<br /><span class="prx-help">' . esc_html( $r['error'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} else {
						echo (int) $r['cited'] ? Suite::badge( sprintf( /* translators: %d: position */ __( 'Cited #%d', 'publishrank' ), (int) $r['position'] ), 'ok' ) : ( (int) $r['mentioned'] ? Suite::badge( __( 'Mentioned', 'publishrank' ), 'info' ) : Suite::badge( __( 'Absent', 'publishrank' ), 'warn' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						$urls = json_decode( (string) $r['cited_urls'], true );
						if ( $urls ) {
							echo '<details><summary class="prx-help">' . esc_html( sprintf( /* translators: %d: sources */ _n( '%d source', '%d sources', count( $urls ), 'publishrank' ), count( $urls ) ) ) . '</summary><ol class="prx-help" style="margin:4px 0 0 16px">';
							foreach ( array_slice( $urls, 0, 10 ) as $u ) {
								echo '<li><a href="' . esc_url( $u['url'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html( '' !== $u['title'] ? $u['title'] : wp_parse_url( $u['url'], PHP_URL_HOST ) ) . '</a></li>';
							}
							echo '</ol><p class="prx-help">' . esc_html( wp_trim_words( (string) $r['excerpt'], 40, '…' ) ) . '</p></details>';
						}
					}
					echo '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}
		Suite::card_close();
		$trend = CitationTracker::trend( 20 );
		if ( $trend ) {
			$pts = array();
			foreach ( $trend as $t ) {
				$ok    = max( 1, (int) $t['ok'] );
				$pts[] = array( 'l' => substr( $t['at'], 0, 10 ), 'v' => round( 100 * (int) $t['cited'] / $ok ), 'v2' => round( 100 * (int) $t['mentioned'] / $ok ) );
			}
			Suite::card_open( __( 'Citation and mention rate by run (%)', 'publishrank' ), 'fa-chart-line', __( 'Cited (plum) and mentioned (green), per run.', 'publishrank' ) );
			echo '<svg class="prx-chart" role="img" aria-label="' . esc_attr__( 'Citation rate per run', 'publishrank' ) . '" data-prx-chart="' . esc_attr( wp_json_encode( $pts ) ) . '"></svg>';
			Suite::card_close();
		}
		if ( $sum['competitors'] ) {
			Suite::card_open( __( 'Competitors in the same answers (last run)', 'publishrank' ), 'fa-people-group' );
			echo '<ul class="prx-list">';
			$you = array( 'cited' => $sum['cited'], 'mentioned' => $sum['mentioned'] );
			echo '<li><strong style="flex:1">' . esc_html__( 'You', 'publishrank' ) . '</strong>' . esc_html( sprintf( /* translators: 1: cited, 2: mentioned */ __( '%1$d cited · %2$d mentioned', 'publishrank' ), $you['cited'], $you['mentioned'] ) ) . '</li>';
			foreach ( $sum['competitors'] as $dom => $c ) {
				echo '<li><span class="prx-mono" style="flex:1">' . esc_html( $dom ) . '</span>' . esc_html( sprintf( /* translators: 1: cited, 2: mentioned */ __( '%1$d cited · %2$d mentioned', 'publishrank' ), $c['cited'], $c['mentioned'] ) ) . '</li>';
			}
			echo '</ul>';
			Suite::card_close();
		}
		echo '</div><div class="prx-stack">';
		if ( $manage ) {
			Suite::card_open( __( 'Run', 'publishrank' ), 'fa-play', sprintf( /* translators: 1: used, 2: cap */ __( '%1$d of %2$d requests used this month.', 'publishrank' ), CitationTracker::usage_this_month(), (int) $s['monthly_cap'] ) );
			Suite::form_open( 'publishrank_ai_run' );
			echo '<button class="prx-btn prx-btn--primary" type="submit"' . disabled( (bool) $job || ! CitationTracker::active_providers() || ! $s['prompts'], true, false ) . '><span class="fa-solid fa-play" aria-hidden="true"></span>' . esc_html__( 'Run now', 'publishrank' ) . '</button></form>';
			Suite::card_close();
			Suite::form_open( 'publishrank_ai_citations' );
			Suite::card_open( __( 'Engines and your API keys', 'publishrank' ), 'fa-key', __( 'Keys are encrypted on this site and sent only to the provider’s own API. You pay the provider directly.', 'publishrank' ) );
			foreach ( $provs as $k => $p ) {
				$cfg = $s['providers'][ $k ];
				echo '<fieldset class="prx-field" style="border-top:1px solid var(--pr-border,#DFD9CF);padding-top:10px"><legend class="prx-label">' . esc_html( $p['label'] ) . '</legend>';
				Suite::switch_field( 'ct[providers][' . $k . '][enabled]', ! empty( $cfg['enabled'] ), __( 'Use this engine', 'publishrank' ) );
				Suite::field( 'password', 'ct[providers][' . $k . '][key]', '', __( 'API key', 'publishrank' ), $cfg['key'] ? sprintf( /* translators: %s: masked key */ __( 'Saved: %s — leave empty to keep it.', 'publishrank' ), Crypto::mask( $cfg['key'] ) ) : sprintf( /* translators: %s: URL */ __( 'Create one at %s', 'publishrank' ), $p['docs'] ), array( 'autocomplete' => 'new-password' ) );
				Suite::field( 'text', 'ct[providers][' . $k . '][model]', $cfg['model'], __( 'Model', 'publishrank' ) );
				if ( $cfg['key'] ) {
					echo '<label class="prx-help"><input type="checkbox" name="ct[providers][' . esc_attr( $k ) . '][clear]" value="1" /> ' . esc_html__( 'Delete the saved key', 'publishrank' ) . '</label>';
				}
				echo '</fieldset>';
			}
			Suite::card_close();
			Suite::card_open( __( 'What to track', 'publishrank' ), 'fa-bullseye' );
			Suite::field( 'textarea', 'ct[prompts]', implode( "\n", wp_list_pluck( $s['prompts'], 'text' ) ), __( 'Questions (one per line, up to 50)', 'publishrank' ), '', array( 'rows' => 6 ) );
			Suite::field( 'text', 'ct[brand]', $s['brand'], __( 'Your names (comma separated)', 'publishrank' ), __( 'Publication name and common short forms.', 'publishrank' ) );
			Suite::field( 'text', 'ct[domains]', $s['domains'], __( 'Your domains', 'publishrank' ) );
			Suite::field( 'text', 'ct[competitors]', $s['competitors'], __( 'Competitor domains', 'publishrank' ), __( 'Comma separated, e.g. rival-news.com, cityherald.com', 'publishrank' ) );
			Suite::field( 'text', 'ct[location]', $s['location'], __( 'Location hint (optional)', 'publishrank' ), __( 'Added to each question, e.g. “Springfield, Illinois”.', 'publishrank' ) );
			echo '<div class="prx-fields">';
			Suite::field( 'select', 'ct[schedule]', $s['schedule'], __( 'Automatic runs', 'publishrank' ), '', array(), array( 'off' => __( 'Off', 'publishrank' ), 'daily' => __( 'Daily', 'publishrank' ), 'weekly' => __( 'Weekly', 'publishrank' ) ) );
			Suite::field( 'number', 'ct[monthly_cap]', $s['monthly_cap'], __( 'Monthly request limit', 'publishrank' ), '', array( 'min' => 1 ) );
			Suite::field( 'number', 'ct[batch]', $s['batch'], __( 'Requests per background step', 'publishrank' ), '', array( 'min' => 1, 'max' => 25 ) );
			echo '</div>';
			echo '<div class="prx-card__foot"><button class="prx-btn prx-btn--primary" type="submit">' . esc_html__( 'Save tracker', 'publishrank' ) . '</button></div>';
			Suite::card_close();
			echo '</form>';
			foreach ( $provs as $k => $p ) {
				if ( $s['providers'][ $k ]['key'] ) {
					Suite::form_open( 'publishrank_ai_test', array( 'style' => 'display:inline-block;margin:4px' ) );
					echo '<input type="hidden" name="provider" value="' . esc_attr( $k ) . '" /><button class="prx-btn prx-btn--sm" type="submit">' . esc_html( sprintf( /* translators: %s: provider */ __( 'Test %s', 'publishrank' ), strtok( $p['label'], ' ' ) ) ) . '</button></form>';
				}
			}
		}
		echo '</div></div>';
		Suite::panel_close();
	}
}
