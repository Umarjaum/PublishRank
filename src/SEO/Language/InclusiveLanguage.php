<?php
/**
 * Optional inclusive-language analysis (off by default).
 *
 * Flags potentially exclusionary wording, explains why and suggests alternatives. It never changes the
 * article: the writer decides. Each finding can be dismissed for one post, and each rule can be switched
 * off for the whole site. Findings do not change the SEO score.
 *
 * Rules exist for English only. Arabic and Urdu need native-speaker rule sets that are not shipped, so
 * the check reports that it was not run for those languages instead of pretending to cover them.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\SEO\Language;

defined( 'ABSPATH' ) || exit;

final class InclusiveLanguage {

	const OPTION     = 'publishrank_inclusive_language';
	const DISMISSED  = '_publishrank_inclusive_dismissed';
	const AJAX       = 'publishrank_inclusive_dismiss';

	public function register() {
		add_action( 'wp_ajax_' . self::AJAX, array( $this, 'ajax_dismiss' ) );
	}

	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'enabled'  => ! empty( $s['enabled'] ),
			'disabled' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $s['disabled'] ?? array() ) ) ) ),
		);
	}

	/**
	 * @return array<string,array{lang:string,pattern:string,why:string,alt:string}>
	 */
	public static function rules() {
		$rules = array(
			'mankind'          => array( 'pattern' => '\bmankind\b', 'why' => __( 'Uses "man" for all people.', 'publishrank' ), 'alt' => __( 'humanity, humankind, people', 'publishrank' ) ),
			'manpower'         => array( 'pattern' => '\bmanpower\b', 'why' => __( 'Uses "man" for all workers.', 'publishrank' ), 'alt' => __( 'workforce, staff, personnel', 'publishrank' ) ),
			'man_made'         => array( 'pattern' => '\bman-made\b', 'why' => __( 'Uses "man" for all people.', 'publishrank' ), 'alt' => __( 'artificial, human-made, synthetic', 'publishrank' ) ),
			'chairman'         => array( 'pattern' => '\bchairm[ae]n\b', 'why' => __( 'Gendered job title; fine when it is the person\'s official title.', 'publishrank' ), 'alt' => __( 'chair, chairperson', 'publishrank' ) ),
			'spokesman'        => array( 'pattern' => '\bspokesm[ae]n\b', 'why' => __( 'Gendered job title; fine when it is the person\'s official title.', 'publishrank' ), 'alt' => __( 'spokesperson, representative', 'publishrank' ) ),
			'policeman'        => array( 'pattern' => '\bpolicem[ae]n\b', 'why' => __( 'Gendered job title.', 'publishrank' ), 'alt' => __( 'police officer', 'publishrank' ) ),
			'fireman'          => array( 'pattern' => '\bfirem[ae]n\b', 'why' => __( 'Gendered job title.', 'publishrank' ), 'alt' => __( 'firefighter', 'publishrank' ) ),
			'businessman'      => array( 'pattern' => '\bbusinessm[ae]n\b', 'why' => __( 'Gendered when the gender is unknown or irrelevant.', 'publishrank' ), 'alt' => __( 'businessperson, executive, entrepreneur', 'publishrank' ) ),
			'blacklist'        => array( 'pattern' => '\b(?:black|white)list(?:s|ed|ing)?\b', 'why' => __( 'Associates black with bad and white with good.', 'publishrank' ), 'alt' => __( 'blocklist / allowlist, denylist / safelist', 'publishrank' ) ),
			'master_slave'     => array( 'pattern' => '\bmaster\s*/\s*slave\b|\bslave\s+(?:node|server|device|drive)\b', 'why' => __( 'Technical metaphor based on slavery.', 'publishrank' ), 'alt' => __( 'primary / replica, leader / follower', 'publishrank' ) ),
			'wheelchair_bound' => array( 'pattern' => '\b(?:wheelchair[- ]bound|confined to a wheelchair)\b', 'why' => __( 'Frames a mobility aid as a limitation.', 'publishrank' ), 'alt' => __( 'wheelchair user, uses a wheelchair', 'publishrank' ) ),
			'suffers_from'     => array( 'pattern' => '\b(?:suffers?|suffering) from\b', 'why' => __( 'Assumes a condition means suffering.', 'publishrank' ), 'alt' => __( 'has, lives with, was diagnosed with', 'publishrank' ) ),
			'handicapped'      => array( 'pattern' => '\b(?:the )?handicapped\b', 'why' => __( 'Outdated term for disabled people.', 'publishrank' ), 'alt' => __( 'disabled people, people with disabilities', 'publishrank' ) ),
			'crippled'         => array( 'pattern' => '\bcrippl(?:e|ed|ing)\b', 'why' => __( 'Disability used as a metaphor for damage.', 'publishrank' ), 'alt' => __( 'paralysed, disrupted, severely damaged', 'publishrank' ) ),
			'lame'             => array( 'pattern' => '\blame\b', 'why' => __( 'Disability used as an insult.', 'publishrank' ), 'alt' => __( 'weak, unconvincing, boring', 'publishrank' ) ),
			'crazy_insane'     => array( 'pattern' => '\b(?:crazy|insane|psycho|lunatic)\b', 'why' => __( 'Mental-health terms used casually.', 'publishrank' ), 'alt' => __( 'wild, unbelievable, reckless, absurd', 'publishrank' ) ),
			'committed_suicide' => array( 'pattern' => '\bcommit(?:ted|s)? suicide\b', 'why' => __( '"Committed" implies a crime. Media guidelines recommend neutral wording.', 'publishrank' ), 'alt' => __( 'died by suicide, took their own life', 'publishrank' ) ),
			'the_elderly'      => array( 'pattern' => '\bthe elderly\b', 'why' => __( 'Groups older people as a single category.', 'publishrank' ), 'alt' => __( 'older people, older adults', 'publishrank' ) ),
			'illegal_alien'    => array( 'pattern' => '\billegal (?:aliens?|immigrants?)\b', 'why' => __( 'Describes people as illegal. Many style guides advise against it.', 'publishrank' ), 'alt' => __( 'undocumented immigrants, people without legal status', 'publishrank' ) ),
			'you_guys'         => array( 'pattern' => '\byou guys\b', 'why' => __( 'Gendered address for a mixed group.', 'publishrank' ), 'alt' => __( 'everyone, folks, you all', 'publishrank' ) ),
			'tone_deaf'        => array( 'pattern' => '\b(?:tone[- ]deaf|turn(?:ed|s)? a blind eye|fall(?:s|ing)? on deaf ears)\b', 'why' => __( 'Disability used as a metaphor.', 'publishrank' ), 'alt' => __( 'insensitive, ignored, overlooked', 'publishrank' ) ),
		);
		foreach ( $rules as $id => &$r ) {
			$r['lang'] = 'en';
		}
		unset( $r );
		return (array) apply_filters( 'publishrank_inclusive_rules', $rules );
	}

	/**
	 * Findings for a text. One finding per rule (with the number of matches).
	 *
	 * @return array<int,array{rule:string,term:string,count:int,why:string,alt:string}>
	 */
	public static function scan( $text, $lang, $post_id = 0 ) {
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return array();
		}
		$dismissed = $post_id ? (array) get_post_meta( (int) $post_id, self::DISMISSED, true ) : array();
		$out       = array();
		foreach ( self::rules() as $id => $rule ) {
			if ( $rule['lang'] !== $lang || in_array( $id, $s['disabled'], true ) || in_array( $id, $dismissed, true ) ) {
				continue;
			}
			$n = @preg_match_all( '/' . $rule['pattern'] . '/iu', (string) $text, $m ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Filtered rules may be invalid.
			if ( $n ) {
				$out[] = array( 'rule' => $id, 'term' => (string) $m[0][0], 'count' => (int) $n, 'why' => $rule['why'], 'alt' => $rule['alt'] );
			}
		}
		return $out;
	}

	public static function has_rules_for( $lang ) {
		foreach ( self::rules() as $rule ) {
			if ( $rule['lang'] === $lang ) {
				return true;
			}
		}
		return false;
	}

	/** Dismiss one rule for one post. */
	public function ajax_dismiss() {
		check_ajax_referer( self::AJAX, 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$rule    = isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : '';
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! isset( self::rules()[ $rule ] ) ) {
			wp_send_json_error( null, 403 );
		}
		$list   = (array) get_post_meta( $post_id, self::DISMISSED, true );
		$list[] = $rule;
		update_post_meta( $post_id, self::DISMISSED, array_values( array_unique( array_filter( $list ) ) ) );
		wp_send_json_success();
	}
}
