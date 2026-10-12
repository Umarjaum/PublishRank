<?php
/**
 * JNews compatibility detection without undocumented dependencies.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Compatibility;

defined( 'ABSPATH' ) || exit;

final class JNewsAdapter {
	/**
	 * Whether a verified JNews integration is available.
	 *
	 * @return bool
	 */
	public function is_verified(): bool {
		return false;
	}

	/**
	 * Return diagnostics for the admin screen.
	 *
	 * @return array<string, string>
	 */
	public function diagnostics(): array {
		return array(
			'status' => 'warning',
			'message' => __( 'JNews source was not available for verification; generic WordPress output is active.', 'publishrank' ),
		);
	}
}

// No undocumented JNews hook or option is registered until source verification exists.
class_exists( JNewsAdapter::class );
