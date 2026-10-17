<?php
/**
 * IPScanner API client.
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calls the edge check, site policy and usage endpoints with caching and backoff.
 */
class IPScanner_API {

	const DEFAULT_BASE   = 'https://ipscanner.io';
	const VERDICT_TTL    = 600;
	const POLICY_TTL     = 30;
	const POLICY_STALE   = 86400;
	const POLICY_RETRY   = 10;
	const BACKOFF_ERROR  = 30;
	const BACKOFF_QUOTA  = 300;
	const EDGE_MODE      = 'ipscanner_edge_mode';
	const ENFORCE_PAUSED = 'ipscanner_enforce_paused';

	/**
	 * Settings.
	 *
	 * @var IPScanner_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param IPScanner_Settings $settings Settings.
	 */
	public function __construct( IPScanner_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * API base URL. The IPSCANNER_API_BASE constant overrides it for testing.
	 *
	 * @return string
	 */
	public static function base() {
		$base = defined( 'IPSCANNER_API_BASE' ) && is_string( IPSCANNER_API_BASE ) && '' !== IPSCANNER_API_BASE ? IPSCANNER_API_BASE : self::DEFAULT_BASE;
		return rtrim( $base, '/' );
	}

	/**
	 * Transient name for a cached verdict.
	 *
	 * @param string $site       Site ID or ''.
	 * @param string $ip         Visitor address.
	 * @param string $user_agent Visitor user agent.
	 * @return string
	 */
	public static function verdict_key( $site, $ip, $user_agent ) {
		return 'ipscanner_v_' . md5( $site . '|' . $ip . '|' . $user_agent );
	}

	/**
	 * Sends one request.
	 *
	 * @param string     $method  GET or POST.
	 * @param string     $path    Path under the base URL.
	 * @param array|null $body    JSON body.
	 * @param float|null $timeout Seconds, or the configured timeout.
	 * @return array{ok:bool,code:int,data:array,status:string,message:string}
	 */
	public function request( $method, $path, $body = null, $timeout = null ) {
		$timeout = null === $timeout ? $this->settings->timeout() : (float) $timeout;
		$args    = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => 'ipscanner-wordpress/' . IPSCANNER_VERSION,
			'headers'     => array(
				'Authorization'      => 'Bearer ' . $this->settings->api_key(),
				'Accept'             => 'application/json',
				'X-IPScanner-Source' => 'wordpress',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::base() . $path, $args );
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$timeout = false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'cURL error 28' );
			return array(
				'ok'      => false,
				'code'    => 0,
				'data'    => array(),
				'status'  => $timeout ? 'timeout' : 'error',
				'message' => $message,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();
		return array(
			'ok'      => $code >= 200 && $code < 300,
			'code'    => $code,
			'data'    => $data,
			'status'  => $code >= 200 && $code < 300 ? 'ok' : 'error',
			'message' => isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : '',
		);
	}

	/**
	 * Checks a visitor. Verdicts are cached per site, address and user agent.
	 *
	 * @param string $ip         Visitor address.
	 * @param string $user_agent Visitor user agent.
	 * @param array  $headers    Forwarded request headers.
	 * @return array{ok:bool,cached:bool,status:string,data:array}
	 */
	public function edge_check( $ip, $user_agent, array $headers ) {
		$site = (string) $this->settings->get( 'site_id' );
		$key  = self::verdict_key( $site, $ip, $user_agent );
		$hit  = get_transient( $key );
		if ( is_array( $hit ) ) {
			return array(
				'ok'     => true,
				'cached' => true,
				'status' => 'ok',
				'data'   => $hit,
			);
		}

		$backoff = get_transient( 'ipscanner_backoff' );
		if ( false !== $backoff ) {
			return array(
				'ok'     => false,
				'cached' => false,
				'status' => 'backoff',
				'data'   => array(),
			);
		}

		$body = array(
			'ip'         => $ip,
			'user_agent' => $user_agent,
			'headers'    => (object) $headers,
			'request_id' => wp_generate_uuid4(),
		);
		if ( '' !== $site ) {
			$body['site'] = $site;
		}
		$res = $this->request( 'POST', '/v1/edge/check', $body );

		if ( ! $res['ok'] || ! isset( $res['data']['class'] ) ) {
			$this->fail( $res );
			return array(
				'ok'     => false,
				'cached' => false,
				'status' => $res['status'],
				'data'   => array(),
			);
		}

		set_transient( $key, $res['data'], self::VERDICT_TTL );
		$this->record_edge_mode( isset( $res['data']['mode'] ) ? $res['data']['mode'] : '' );
		return array(
			'ok'     => true,
			'cached' => false,
			'status' => 'ok',
			'data'   => $res['data'],
		);
	}

	/**
	 * Stores the account's edge mode. When the plan stops allowing Enforce (the
	 * trial ended), a local Enforce switches to Monitor, and back after an upgrade.
	 *
	 * @param mixed $mode monitor, enforce, or anything else from an older API.
	 */
	public function record_edge_mode( $mode ) {
		$mode = in_array( $mode, array( IPScanner_Policy::MODE_MONITOR, IPScanner_Policy::MODE_ENFORCE ), true ) ? $mode : '';
		update_option( self::EDGE_MODE, $mode, false );
		if ( IPScanner_Policy::MODE_MONITOR === $mode && IPScanner_Policy::MODE_ENFORCE === $this->settings->get( 'mode' ) ) {
			update_option( self::ENFORCE_PAUSED, 1, false );
			$this->settings->save_mode( IPScanner_Policy::MODE_MONITOR );
		} elseif ( IPScanner_Policy::MODE_ENFORCE === $mode && get_option( self::ENFORCE_PAUSED ) ) {
			delete_option( self::ENFORCE_PAUSED );
			$this->settings->save_mode( IPScanner_Policy::MODE_ENFORCE );
		}
	}

	/**
	 * Whether the last fresh edge check said the account may only monitor.
	 *
	 * @return bool
	 */
	public static function edge_monitor() {
		return IPScanner_Policy::MODE_MONITOR === get_option( self::EDGE_MODE, '' );
	}

	/**
	 * Records a failed call and pauses checks for a while, so a slow or
	 * unreachable API does not add the timeout to every request.
	 *
	 * @param array $res Result of request().
	 */
	private function fail( array $res ) {
		$code  = $res['code'];
		$pause = in_array( $code, array( 401, 402, 403, 429 ), true ) ? self::BACKOFF_QUOTA : self::BACKOFF_ERROR;
		set_transient( 'ipscanner_backoff', $res['status'], $pause );
		if ( in_array( $code, array( 401, 403, 429 ), true ) ) {
			update_option(
				'ipscanner_last_error',
				array(
					'code'    => $code,
					'message' => $res['message'],
					'time'    => time(),
				),
				false
			);
		}
	}

	/**
	 * The dashboard policy for the configured site.
	 *
	 * Fresh for POLICY_TTL seconds. When a refresh fails, the last policy is
	 * used for up to a day. A 404 means the site is unknown: no policy.
	 *
	 * @param int $min_version Refresh when the cached policy is older than this version.
	 * @return array{policy:array|null,source:string}
	 */
	public function site_policy( $min_version = 0 ) {
		$site  = (string) $this->settings->get( 'site_id' );
		$now   = time();
		$entry = get_option( 'ipscanner_policy_cache', array() );
		$entry = is_array( $entry ) && isset( $entry['site'], $entry['fetched_at'] ) && $entry['site'] === $site ? $entry : null;

		$version = $entry && is_array( $entry['policy'] ) ? (int) $entry['policy']['version'] : 0;
		$age     = $entry ? $now - (int) $entry['fetched_at'] : PHP_INT_MAX;
		// A verdict that saw a newer policy version triggers a refresh, at most every 5 seconds.
		$fresh = $age < self::POLICY_TTL && ( $version >= $min_version || $age < 5 );
		if ( $fresh ) {
			return array(
				'policy' => $entry['policy'],
				'source' => 'cache',
			);
		}

		$stale = function () use ( $entry, $now ) {
			if ( $entry && is_array( $entry['policy'] ) && $now - (int) $entry['fetched_at'] < self::POLICY_STALE ) {
				return array(
					'policy' => $entry['policy'],
					'source' => 'stale',
				);
			}
			return array(
				'policy' => null,
				'source' => 'none',
			);
		};

		if ( false !== get_transient( 'ipscanner_policy_retry' ) ) {
			return $stale();
		}

		$res = $this->request( 'GET', '/v1/sites/' . rawurlencode( $site ) . '/policy' );
		if ( 404 === $res['code'] ) {
			$this->save_policy( $site, null );
			return array(
				'policy' => null,
				'source' => 'none',
			);
		}
		if ( ! $res['ok'] ) {
			set_transient( 'ipscanner_policy_retry', 1, self::POLICY_RETRY );
			return $stale();
		}

		$policy = self::to_policy( $res['data'] );
		$this->save_policy( $site, $policy );
		return array(
			'policy' => $policy,
			'source' => 'fetch',
		);
	}

	/**
	 * Normalizes a policy response.
	 *
	 * @param array $data Response body.
	 * @return array{mode:string,policy:array,version:int}
	 */
	public static function to_policy( array $data ) {
		$policy = array();
		if ( isset( $data['policy'] ) && is_array( $data['policy'] ) ) {
			foreach ( $data['policy'] as $class => $action ) {
				if ( in_array( $class, IPScanner_Policy::CLASSES, true ) && in_array( $action, array( 'allow', 'flag', 'block' ), true ) ) {
					$policy[ $class ] = $action;
				}
			}
		}
		return array(
			'mode'    => isset( $data['mode'] ) && IPScanner_Policy::MODE_ENFORCE === $data['mode'] ? IPScanner_Policy::MODE_ENFORCE : IPScanner_Policy::MODE_MONITOR,
			'policy'  => $policy,
			'version' => isset( $data['version'] ) ? (int) $data['version'] : 0,
		);
	}

	/**
	 * Stores the policy with its fetch time.
	 *
	 * @param string     $site   Site ID.
	 * @param array|null $policy Policy or null.
	 */
	private function save_policy( $site, $policy ) {
		update_option(
			'ipscanner_policy_cache',
			array(
				'site'       => $site,
				'policy'     => $policy,
				'fetched_at' => time(),
			),
			false
		);
	}

	/**
	 * Plan and usage for the connection test. Not metered.
	 *
	 * @return array Result of request().
	 */
	public function usage() {
		return $this->request( 'GET', '/v1/usage/summary', null, 5 );
	}

	/**
	 * Site policy for the connection test, bypassing the cache.
	 *
	 * @return array Result of request().
	 */
	public function fetch_policy() {
		$site = (string) $this->settings->get( 'site_id' );
		$res  = $this->request( 'GET', '/v1/sites/' . rawurlencode( $site ) . '/policy', null, 5 );
		if ( $res['ok'] ) {
			$this->save_policy( $site, self::to_policy( $res['data'] ) );
		}
		return $res;
	}
}
