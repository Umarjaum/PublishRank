<?php
/**
 * Coordinates ownership of technical SEO output.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Core;

defined( 'ABSPATH' ) || exit;

final class OutputRegistry {
	/** @var array<string, string> */
	private $claimed = array();

	/**
	 * Claim an output slot once per request.
	 *
	 * @param string $slot Output slot.
	 * @param string $owner Owning service.
	 * @return bool
	 */
	public function claim( $slot, $owner = 'publishrank' ) {
		$slot = sanitize_key( $slot );
		if ( '' === $slot || isset( $this->claimed[ $slot ] ) ) {
			return false;
		}
		$this->claimed[ $slot ] = sanitize_key( $owner );
		return true;
	}

	/** Forget all claims. Used by the editor Live Preview so it can render the head output more than once per request. */
	public function reset() { $this->claimed = array(); }

	/** @param string $slot Output slot. */
	public function owner( $slot ) {
		return isset( $this->claimed[ $slot ] ) ? $this->claimed[ $slot ] : null;
	}

	/** @param string $slot Output slot. */
	public function has( $slot ) {
		return null !== $this->owner( $slot );
	}

	/** @return array<string, string> */
	public function all() {
		return $this->claimed;
	}
}
