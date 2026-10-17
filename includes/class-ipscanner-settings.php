<?php
/**
 * Plugin settings: defaults, reading and sanitizing.
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the ipscanner_settings option.
 */
final class IPScanner_Settings {

	const OPTION = 'ipscanner_settings';

	const AREAS = array( 'site', 'login', 'register', 'lostpassword', 'comments', 'woo_checkout', 'woo_register', 'forms' );

	/**
	 * Cached settings for this request.
	 *
	 * @var array|null
	 */
	private $values = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'         => '',
			'site_id'         => '',
			'mode'            => IPScanner_Policy::MODE_MONITOR,
			'areas'           => array( 'login', 'register', 'lostpassword', 'comments', 'woo_checkout', 'woo_register', 'forms' ),
			'block_classes'   => array( 'malicious_automation' ),
			'allowlist'       => '',
			'allow_admins'    => true,
			'ip_source'       => IPScanner_Request::SOURCE_REMOTE_ADDR,
			'trusted_proxies' => '',
			'block_message'   => '',
			'show_credit'     => false,
			'timeout'         => 1.5,
			'share_usage'     => false,
		);
	}

	/**
	 * All settings merged over the defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->values ) {
			$stored       = get_option( self::OPTION, array() );
			$this->values = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->values;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Drops the per-request copy after an update.
	 */
	public function flush() {
		$this->values = null;
	}

	/**
	 * Saves the local mode when the plan changes it, without a settings event.
	 *
	 * @param string $mode monitor or enforce.
	 */
	public function save_mode( $mode ) {
		$values         = $this->all();
		$values['mode'] = $mode;
		$quiet          = class_exists( 'IPScanner_Telemetry' );
		if ( $quiet ) {
			IPScanner_Telemetry::$quiet = true;
		}
		update_option( self::OPTION, $values );
		if ( $quiet ) {
			IPScanner_Telemetry::$quiet = false;
		}
		$this->flush();
	}

	/**
	 * Whether an area is protected.
	 *
	 * @param string $area Area name.
	 * @return bool
	 */
	public function protects( $area ) {
		return in_array( $area, (array) $this->get( 'areas' ), true );
	}

	/**
	 * The API key, with the IPSCANNER_API_KEY constant taking precedence.
	 *
	 * @return string
	 */
	public function api_key() {
		if ( defined( 'IPSCANNER_API_KEY' ) && is_string( IPSCANNER_API_KEY ) && '' !== IPSCANNER_API_KEY ) {
			return IPSCANNER_API_KEY;
		}
		return (string) $this->get( 'api_key' );
	}

	/**
	 * Request timeout in seconds.
	 *
	 * @return float
	 */
	public function timeout() {
		return max( 0.5, min( 5.0, (float) $this->get( 'timeout' ) ) );
	}

	/**
	 * Allowlist entries.
	 *
	 * @return string[]
	 */
	public function allowlist() {
		return IPScanner_IP::parse_list( (string) $this->get( 'allowlist' ) );
	}

	/**
	 * Trusted proxy entries.
	 *
	 * @return string[]
	 */
	public function trusted_proxies() {
		return IPScanner_IP::parse_list( (string) $this->get( 'trusted_proxies' ) );
	}

	/**
	 * Sanitizes the submitted settings form. Also runs on already sanitized
	 * values (WordPress calls it twice when the option is first added).
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$previous = get_option( self::OPTION, array() );
		$previous = array_merge( self::defaults(), is_array( $previous ) ? $previous : array() );
		$out      = self::defaults();

		$key = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
		$key = preg_replace( '/[^A-Za-z0-9_]/', '', $key );
		if ( ! empty( $input['api_key_clear'] ) ) {
			$key = '';
		} elseif ( '' === $key ) {
			$key = (string) $previous['api_key'];
		}
		$out['api_key'] = $key;

		$site           = isset( $input['site_id'] ) ? sanitize_text_field( $input['site_id'] ) : '';
		$out['site_id'] = preg_match( '/^site_[A-Za-z0-9]{1,40}$/', $site ) ? $site : '';
		if ( '' !== $site && '' === $out['site_id'] ) {
			add_settings_error( self::OPTION, 'site_id', __( 'Site ID should look like site_ followed by letters and digits. It was not saved.', 'ipscanner-bot-blocker' ) );
		}

		$locked      = IPScanner_API::edge_monitor() && $key === (string) $previous['api_key'];
		$out['mode'] = isset( $input['mode'] ) && IPScanner_Policy::MODE_ENFORCE === $input['mode'] && ! $locked ? IPScanner_Policy::MODE_ENFORCE : IPScanner_Policy::MODE_MONITOR;

		$areas        = isset( $input['areas'] ) && is_array( $input['areas'] ) ? array_map( 'sanitize_key', $input['areas'] ) : array();
		$out['areas'] = array_values( array_intersect( self::AREAS, $areas ) );

		$classes              = isset( $input['block_classes'] ) && is_array( $input['block_classes'] ) ? array_map( 'sanitize_key', $input['block_classes'] ) : array();
		$out['block_classes'] = array_values( array_intersect( IPScanner_Policy::BLOCKABLE, $classes ) );

		foreach ( array( 'allowlist', 'trusted_proxies' ) as $list ) {
			$text = isset( $input[ $list ] ) ? sanitize_textarea_field( $input[ $list ] ) : '';
			$bad  = IPScanner_IP::invalid_entries( $text );
			if ( $bad ) {
				add_settings_error(
					self::OPTION,
					$list,
					sprintf(
						/* translators: %s: list of invalid entries */
						__( 'Skipped entries that are not IP addresses or CIDR ranges: %s', 'ipscanner-bot-blocker' ),
						implode( ', ', array_slice( $bad, 0, 10 ) )
					)
				);
			}
			$out[ $list ] = implode( "\n", IPScanner_IP::parse_list( $text ) );
		}

		$out['allow_admins'] = ! empty( $input['allow_admins'] );
		$out['show_credit']  = ! empty( $input['show_credit'] );
		$out['share_usage']  = ! empty( $input['share_usage'] );

		$sources          = array( IPScanner_Request::SOURCE_REMOTE_ADDR, IPScanner_Request::SOURCE_CLOUDFLARE, IPScanner_Request::SOURCE_FORWARDED );
		$source           = isset( $input['ip_source'] ) ? sanitize_key( $input['ip_source'] ) : '';
		$out['ip_source'] = in_array( $source, $sources, true ) ? $source : IPScanner_Request::SOURCE_REMOTE_ADDR;

		$out['block_message'] = isset( $input['block_message'] ) ? sanitize_textarea_field( $input['block_message'] ) : '';

		$timeout        = isset( $input['timeout'] ) ? (float) $input['timeout'] : 1.5;
		$out['timeout'] = round( max( 0.5, min( 5.0, $timeout ) ), 1 );

		if ( $out['site_id'] !== $previous['site_id'] || $out['api_key'] !== $previous['api_key'] ) {
			delete_option( 'ipscanner_policy_cache' );
			delete_option( 'ipscanner_last_error' );
			delete_option( IPScanner_API::EDGE_MODE );
			delete_option( IPScanner_API::ENFORCE_PAUSED );
			delete_transient( 'ipscanner_backoff' );
			delete_transient( 'ipscanner_usage' );
		}

		return $out;
	}
}
