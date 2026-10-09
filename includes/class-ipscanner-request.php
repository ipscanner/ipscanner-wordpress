<?php
/**
 * Request helpers: client address, forwarded headers, static paths.
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions over a $_SERVER style array.
 */
final class IPScanner_Request {

	const SOURCE_REMOTE_ADDR = 'remote_addr';
	const SOURCE_CLOUDFLARE  = 'cloudflare';
	const SOURCE_FORWARDED   = 'x_forwarded_for';

	/**
	 * Cloudflare edge ranges from https://www.cloudflare.com/ips/.
	 *
	 * @var string[]
	 */
	const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * The visitor's address. Proxy headers are read only when the direct peer
	 * (REMOTE_ADDR) is a trusted proxy, so visitors cannot spoof them.
	 *
	 * @param array    $server  $_SERVER style array.
	 * @param string   $source  One of the SOURCE_* constants.
	 * @param string[] $trusted Trusted proxy addresses or ranges.
	 * @return string Empty when no valid address is known.
	 */
	public static function client_ip( array $server, $source, array $trusted ) {
		$remote = IPScanner_IP::normalize( isset( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '' );
		if ( '' === $remote || self::SOURCE_REMOTE_ADDR === $source ) {
			return $remote;
		}

		if ( self::SOURCE_CLOUDFLARE === $source ) {
			$from_edge = IPScanner_IP::in_list( $remote, self::CLOUDFLARE_RANGES ) || IPScanner_IP::in_list( $remote, $trusted );
			if ( ! $from_edge ) {
				return $remote;
			}
			$ip = IPScanner_IP::normalize( isset( $server['HTTP_CF_CONNECTING_IP'] ) ? $server['HTTP_CF_CONNECTING_IP'] : '' );
			return '' === $ip ? $remote : $ip;
		}

		if ( self::SOURCE_FORWARDED === $source ) {
			if ( ! IPScanner_IP::in_list( $remote, $trusted ) ) {
				return $remote;
			}
			$chain = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] ) : array();
			$first = '';
			// Walk from the nearest hop back; the first untrusted hop is the visitor.
			for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
				$hop = IPScanner_IP::normalize( $chain[ $i ] );
				if ( '' === $hop ) {
					return '' === $first ? $remote : $first;
				}
				$first = $hop;
				if ( ! IPScanner_IP::in_list( $hop, $trusted ) ) {
					return $hop;
				}
			}
			return '' === $first ? $remote : $first;
		}

		return $remote;
	}

	/**
	 * The request headers Agentscan reads: Accept, Accept-Language,
	 * Accept-Encoding, Sec-CH-UA* and Sec-Fetch-*.
	 *
	 * @param array $server $_SERVER style array.
	 * @return array<string,string>
	 */
	public static function forwarded_headers( array $server ) {
		$out = array();
		foreach ( $server as $key => $value ) {
			if ( ! is_string( $key ) || 0 !== strpos( $key, 'HTTP_' ) || ! is_scalar( $value ) ) {
				continue;
			}
			$name = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );
			$keep = in_array( $name, array( 'accept', 'accept-language', 'accept-encoding' ), true )
				|| 0 === strpos( $name, 'sec-ch-ua' )
				|| 0 === strpos( $name, 'sec-fetch-' );
			if ( $keep ) {
				$out[ $name ] = substr( (string) $value, 0, 512 );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Whether the path is a static file that is never worth a check.
	 *
	 * @param string $path Request path without query string.
	 * @return bool
	 */
	public static function is_static_path( $path ) {
		return 1 === preg_match( '/\.(?:css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|bmp|woff2?|ttf|otf|eot|mp4|webm|mp3|ogg|wav|pdf|zip|gz|txt|xml|json)$/i', (string) $path );
	}
}
