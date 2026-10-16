<?php
// Minimal WordPress stub: enough to execute PublishRank's wizard + settings page for verification.
define( 'ABSPATH', '/tmp/' ); define( 'PUBLISHRANK_SEO_VERSION', '2.9.47' ); define( 'PUBLISHRANK_SEO_URL', 'http://x/wp-content/plugins/publishrank/' );
class RedirectException extends Exception { public $url; }
$GLOBALS['opts'] = array(); $GLOBALS['registered'] = array(); $GLOBALS['caps'] = true; $GLOBALS['pagenow']='admin.php';
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function add_option( $k, $v ) { if ( ! array_key_exists( $k, $GLOBALS['opts'] ) ) $GLOBALS['opts'][ $k ] = $v; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); }
function get_transient( $k ) { return false; } function delete_transient( $k ) {}
function get_bloginfo( $w ) { return 'name' === $w ? 'Test Daily' : 'Tagline here'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; } function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_timezone_string() { return 'UTC'; } function get_locale() { return 'en_US'; }
function wp_timezone_choice( $s ) { return '<option value="UTC" selected>UTC</option>'; }
function __( $t ) { return $t; } function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; } function esc_html__( $t ) { return $t; } function esc_attr__( $t ) { return $t; } function esc_html_e( $t ) { echo htmlspecialchars( $t ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t ); } function esc_attr( $t ) { return htmlspecialchars( (string) $t ); } function esc_url( $t ) { return htmlspecialchars( (string) $t ); } function esc_textarea( $t ) { return htmlspecialchars( (string) $t ); } function esc_url_raw( $u ) { return filter_var( trim( (string) $u ), FILTER_VALIDATE_URL ) ? trim( $u ) : ''; }
function esc_attr_e( $t ) { echo htmlspecialchars( $t ); }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); } function sanitize_textarea_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ) ); } function sanitize_email( $e ) { return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : ''; }
function wp_unslash( $v ) { return $v; } function absint( $v ) { return abs( (int) $v ); } function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function checked( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $e ) echo $r; return $r; }
function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $e ) echo $r; return $r; }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="n" />'; } function wp_nonce_url( $u, $a ) { return $u . '&_wpnonce=n'; } function check_admin_referer( $a ) {}
function current_user_can( $c ) { return $GLOBALS['caps']; } function wp_die( $m ) { throw new Exception( 'wp_die:' . $m ); }
function wp_safe_redirect( $u ) { $e = new RedirectException( 'redirect' ); $e->url = $u; throw $e; }
function wp_doing_ajax() { return false; } function is_network_admin() { return false; } function is_ssl() { return true; }
function add_query_arg( $k, $v, $u ) { return $u . '?' . $k . '=' . $v; } function rawurlencode_( $x ) { return $x; }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; } function function_exists_( $f ) { return true; }
function set_url_scheme( $u ) { return $u; } function wp_parse_url( $u ) { return parse_url( $u ); } function wp_get_environment_type() { return 'production'; } function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function register_setting( $g, $o, $a = array() ) { $GLOBALS['registered'][ $o ] = $g; }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . $g . '" />'; } function submit_button() {} function settings_errors() {} function wp_enqueue_media() {} function wp_enqueue_style() {} function wp_enqueue_script() {}
function get_post_types( $a = array(), $o = 'names' ) { $p = (object) array( 'labels' => (object) array( 'name' => 'Posts' ) ); $q = (object) array( 'labels' => (object) array( 'name' => 'Pages' ) ); $n = (object) array( 'labels' => (object) array( 'name' => 'News' ) ); return array( 'post' => $p, 'page' => $q, 'news' => $n, 'attachment' => $p ); }
function get_post_type_object( $t ) { return in_array( $t, array( 'post', 'page', 'news' ), true ) ? (object) array( 'public' => true, 'publicly_queryable' => true ) : null; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); } function rest_sanitize_boolean( $v ) { return (bool) $v; } function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_upload_dir() { return array( 'error' => false ); } function is_plugin_active( $f ) { return false; } function wp_generate_password() { return 'k'; }
function do_action() {} function wp_get_attachment_image_url() { return ''; }
spl_autoload_register( function ( $c ) { $p = 'PublishRankSEO\\'; if ( 0 === strpos( $c, $p ) ) { $f = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $c, strlen( $p ) ) ) . '.php'; if ( is_readable( $f ) ) require_once $f; } } );
function call_post( $obj, $step, $post, $edit = false ) { $_POST = array_merge( array( 'step' => $step, '_wpnonce' => 'n' ), $post ); if ( $edit ) $_POST['edit_mode'] = 1; try { $obj->save(); } catch ( RedirectException $e ) { return $e->url; } return null; }
