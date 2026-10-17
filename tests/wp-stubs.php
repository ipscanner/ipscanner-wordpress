<?php
/**
 * Minimal WordPress stand-ins for the guard tests: options and transients in
 * memory, HTTP answers from a queue keyed by path.
 *
 * @package IPScanner
 */

final class IPScanner_Test_WP {

	public static $options    = array();
	public static $transients = array();
	public static $responses  = array();
	public static $requests   = array();

	public static function reset() {
		self::$options    = array();
		self::$transients = array();
		self::$responses  = array();
		self::$requests   = array();
	}

	public static function respond( $path, array $body, $code = 200 ) {
		self::$responses[ $path ] = array(
			'response' => array( 'code' => $code ),
			'body'     => json_encode( $body ),
		);
	}
}

class WP_Error {

	public $errors = array();

	public function __construct( $code = '', $message = '' ) {
		if ( '' !== $code ) {
			$this->add( $code, $message );
		}
	}

	public function add( $code, $message ) {
		$this->errors[ $code ][] = $message;
	}

	public function get_error_codes() {
		return array_keys( $this->errors );
	}

	public function get_error_message() {
		return '';
	}
}

class WP_User {}

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, IPScanner_Test_WP::$options ) ? IPScanner_Test_WP::$options[ $name ] : $default_value;
}

function update_option( $name, $value, $autoload = null ) {
	if ( array_key_exists( $name, IPScanner_Test_WP::$options ) && IPScanner_Test_WP::$options[ $name ] === $value ) {
		return false;
	}
	IPScanner_Test_WP::$options[ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( IPScanner_Test_WP::$options[ $name ] );
	return true;
}

function get_transient( $name ) {
	return array_key_exists( $name, IPScanner_Test_WP::$transients ) ? IPScanner_Test_WP::$transients[ $name ] : false;
}

function set_transient( $name, $value, $ttl = 0 ) {
	IPScanner_Test_WP::$transients[ $name ] = $value;
	return true;
}

function delete_transient( $name ) {
	unset( IPScanner_Test_WP::$transients[ $name ] );
	return true;
}

function wp_remote_request( $url, array $args ) {
	$path                         = (string) parse_url( $url, PHP_URL_PATH );
	IPScanner_Test_WP::$requests[] = array(
		'path' => $path,
		'args' => $args,
	);
	return isset( IPScanner_Test_WP::$responses[ $path ] ) ? IPScanner_Test_WP::$responses[ $path ] : new WP_Error( 'http', 'no stub for ' . $path );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function wp_generate_uuid4() {
	return '00000000-0000-4000-8000-000000000000';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function add_action() {}

function add_filter() {}

function current_action() {
	return '';
}

function current_filter() {
	return '';
}

function current_user_can() {
	return false;
}

function user_can() {
	return false;
}

function __( $text ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
