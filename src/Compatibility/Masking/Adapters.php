<?php
/**
 * Theme compatibility adapters for theme masking.
 *
 * Each adapter lists the identifiers a theme family prints besides its own slug (short class prefixes such as
 * Astra's "ast-"), the vendor hosts its footer credit links to, and the extra folders (companion plugins, files
 * the theme writes to uploads) whose CSS/JS target the same identifiers and must therefore be rewritten with it.
 *
 * "tested" records what was actually exercised in the 2.25.0 test run (a real WordPress install, logged-out and
 * logged-in, home/post/page/category/search/pagination). Premium themes that could not be installed are marked
 * false and use the generic rules only.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\Compatibility\Masking;

defined( 'ABSPATH' ) || exit;

final class Adapters {

	/** @return array<string,array<string,mixed>> */
	public static function all() {
		$list = array(
			'astra'           => array( 'name' => 'Astra', 'prefixes' => array( 'ast', 'ahfb' ), 'hosts' => array( 'wpastra.com' ), 'plugins' => array( 'astra-addon', 'astra-sites' ), 'uploads' => array( 'astra', 'astra-addon' ), 'tested' => true ),
			'generatepress'   => array( 'name' => 'GeneratePress', 'prefixes' => array( 'gp', 'generate' ), 'hosts' => array( 'generatepress.com' ), 'plugins' => array( 'gp-premium', 'generateblocks' ), 'uploads' => array( 'generatepress', 'generateblocks' ), 'tested' => true ),
			'kadence'         => array( 'name' => 'Kadence', 'prefixes' => array( 'kb', 'kt' ), 'hosts' => array( 'kadencewp.com', 'kadencethemes.com' ), 'plugins' => array( 'kadence-blocks', 'kadence-pro', 'kadence-starter-templates' ), 'uploads' => array( 'kadence', 'kadence-blocks' ), 'tested' => true ),
			'blocksy'         => array( 'name' => 'Blocksy', 'prefixes' => array( 'ct' ), 'hosts' => array( 'creativethemes.com' ), 'plugins' => array( 'blocksy-companion', 'blocksy-companion-pro' ), 'uploads' => array( 'blocksy' ), 'tested' => true ),
			'neve'            => array( 'name' => 'Neve', 'prefixes' => array( 'nv', 'hfg' ), 'hosts' => array( 'themeisle.com' ), 'plugins' => array( 'neve-pro-addon' ), 'uploads' => array( 'neve' ), 'tested' => true ),
			'oceanwp'         => array( 'name' => 'OceanWP', 'prefixes' => array( 'owp', 'ocean' ), 'hosts' => array( 'oceanwp.org' ), 'plugins' => array( 'ocean-extra' ), 'uploads' => array( 'oceanwp' ), 'tested' => true ),
			'hello-elementor' => array( 'name' => 'Hello Elementor', 'prefixes' => array(), 'hosts' => array( 'elementor.com' ), 'plugins' => array(), 'uploads' => array(), 'tested' => true ),
			'twentytwentyfive' => array( 'name' => 'Twenty Twenty-Five', 'prefixes' => array(), 'hosts' => array(), 'plugins' => array(), 'uploads' => array(), 'tested' => true ),
			'avada'           => array( 'name' => 'Avada', 'prefixes' => array( 'fusion', 'awb' ), 'hosts' => array( 'avada.com', 'theme-fusion.com' ), 'plugins' => array( 'fusion-builder', 'fusion-core' ), 'uploads' => array( 'fusion-styles', 'fusion-scripts', 'fusion-gfonts', 'fusion-icons' ), 'tested' => false ),
			'newspaper'       => array( 'name' => 'Newspaper (tagDiv)', 'prefixes' => array( 'td', 'tdb', 'tdc', 'tdm' ), 'hosts' => array( 'tagdiv.com' ), 'plugins' => array( 'td-composer', 'td-cloud-library', 'td-standard-pack', 'td-newsletter' ), 'uploads' => array(), 'tested' => false ),
			'jannah'          => array( 'name' => 'Jannah (TieLabs)', 'prefixes' => array( 'tie', 'jannah' ), 'hosts' => array( 'tielabs.com' ), 'plugins' => array( 'jannah-extensions', 'jannah-autoload-posts' ), 'uploads' => array(), 'tested' => false ),
			'online-newspaper' => array( 'name' => 'Online Newspaper', 'prefixes' => array(), 'hosts' => array(), 'plugins' => array(), 'uploads' => array(), 'tested' => false ),
		);
		/**
		 * Filter the theme masking adapters (keyed by template slug).
		 *
		 * @param array $list Adapters.
		 */
		return (array) apply_filters( 'publishrank_theme_mask_adapters', $list );
	}

	/** Adapter for a template slug, or the generic adapter. @return array<string,mixed> */
	public static function for_theme( $template ) {
		$all = self::all();
		$key = strtolower( (string) $template );
		if ( isset( $all[ $key ] ) ) {
			return $all[ $key ] + array( 'key' => $key );
		}
		return array( 'key' => 'generic', 'name' => __( 'Generic theme', 'publishrank' ), 'prefixes' => array(), 'hosts' => array(), 'plugins' => array(), 'uploads' => array(), 'tested' => false );
	}

	/** Folders under wp-content that optimisation plugins write combined CSS/JS to. @return string[] */
	public static function optimizer_dirs() {
		return array( 'cache/autoptimize', 'cache/min', 'cache/wpo-minify', 'cache/busting', 'cache/fvm', 'cache/breeze-minification', 'litespeed/css', 'litespeed/js', 'uploads/elementor/css', 'et-cache' );
	}
}
