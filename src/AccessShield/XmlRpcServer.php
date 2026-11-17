<?php
/**
 * XML-RPC server without system.multicall.
 *
 * IXR_Server adds system.multicall in setCallbacks(), after the xmlrpc_methods filter has run, so
 * the filter alone cannot remove it. This subclass is loaded only from the wp_xmlrpc_server_class
 * filter, when core has already loaded wp_xmlrpc_server.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AccessShield;

defined( 'ABSPATH' ) || exit;

if ( class_exists( '\\wp_xmlrpc_server' ) && ! class_exists( __NAMESPACE__ . '\\XmlRpcServer', false ) ) {
	class XmlRpcServer extends \wp_xmlrpc_server {
		/** IXR_Server calls this while registering its built-in system.* methods. */
		public function setCallbacks() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
			parent::setCallbacks();
			unset( $this->callbacks['system.multicall'] );
		}

		public function multiCall( $methodcalls ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
			SecurityLog::add( 'xmlrpc_blocked', 'notice', array( 'detail' => 'system.multicall refused.' ) );
			return new \IXR_Error( 405, 'system.multicall is disabled on this site.' );
		}
	}
}
