<?php
/**
 * Just enough of WordPress to run the License and Updater logic from the CLI.
 * Fake HTTP responses are queued in $GLOBALS['fake_http'].
 */

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();
$GLOBALS['fake_http']  = array();
$GLOBALS['http_log']   = array();
$GLOBALS['actions']    = array();

class WP_Error {
	private string $message;
	public function __construct( $code = '', $message = '' ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text ) { return $text; }
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( $name, $value ) { $GLOBALS['options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['options'][ $name ] ); return true; }
function get_site_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_site_transient( $k, $v ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_site_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function apply_filters( $hook, $value ) { return $value; }
function do_action( $hook, ...$args ) { $GLOBALS['actions'][] = $hook; }
function add_filter() {}
function add_action() {}
function home_url() { return 'https://customer-site.test'; }
function wp_parse_url( $url, $part ) { return parse_url( $url, $part ); }
function get_bloginfo() { return '6.8'; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function get_file_data() { return array( 'Name' => 'Acme Pro', 'Author' => 'Acme' ); }
function wp_kses_post( $s ) { return $s; }

function add_query_arg( array $args, string $url ) {
	$pairs = array();
	foreach ( $args as $k => $v ) {
		$pairs[] = $k . '=' . $v;
	}
	return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . implode( '&', $pairs );
}

function fake_http_next( string $method, string $url, array $args ) {
	$GLOBALS['http_log'][] = array( 'method' => $method, 'url' => $url, 'body' => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null );
	if ( ! $GLOBALS['fake_http'] ) {
		throw new RuntimeException( "Unexpected HTTP request: $method $url" );
	}
	return array_shift( $GLOBALS['fake_http'] );
}
function wp_remote_post( $url, $args ) { return fake_http_next( 'POST', $url, $args ); }
function wp_remote_get( $url, $args ) { return fake_http_next( 'GET', $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

/** Queue a fake response. */
function respond( int $code, $body = null ) {
	$GLOBALS['fake_http'][] = array( 'code' => $code, 'body' => null === $body ? '' : json_encode( $body ) );
}
function respond_error( string $message ) {
	$GLOBALS['fake_http'][] = new WP_Error( 'http_request_failed', $message );
}
