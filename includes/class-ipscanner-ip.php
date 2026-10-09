<?php
/**
 * IP address parsing and range matching.
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for IPv4 and IPv6 addresses and CIDR ranges.
 */
final class IPScanner_IP {

	/**
	 * Returns the address in canonical form, or an empty string when it is not an IP.
	 * Accepts "1.2.3.4:80" and "[2001:db8::1]:443" as sent by some proxies.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function normalize( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( '[' === $value[0] ) {
			$end = strpos( $value, ']' );
			if ( false === $end ) {
				return '';
			}
			$value = substr( $value, 1, $end - 1 );
		} elseif ( 1 === substr_count( $value, ':' ) && false !== strpos( $value, '.' ) ) {
			$value = substr( $value, 0, strpos( $value, ':' ) );
		}
		if ( false === filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$out = inet_ntop( inet_pton( $value ) );
		if ( false === $out ) {
			return '';
		}
		if ( 0 === strpos( $out, '::ffff:' ) && false !== strpos( $out, '.' ) ) {
			$out = substr( $out, 7 );
		}
		return $out;
	}

	/**
	 * Whether the address is a routable public address.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public static function is_public( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether the entry is a valid address or CIDR range.
	 *
	 * @param string $entry Address or range.
	 * @return bool
	 */
	public static function is_valid_range( $entry ) {
		return null !== self::parse_range( $entry );
	}

	/**
	 * Parses "addr" or "addr/bits" into the packed network and prefix length.
	 *
	 * @param string $entry Address or range.
	 * @return array{0:string,1:int}|null
	 */
	private static function parse_range( $entry ) {
		$entry = trim( (string) $entry );
		$bits  = null;
		if ( false !== strpos( $entry, '/' ) ) {
			list( $entry, $suffix ) = explode( '/', $entry, 2 );
			if ( ! preg_match( '/^\d{1,3}$/', $suffix ) ) {
				return null;
			}
			$bits = (int) $suffix;
		}
		$ip = self::normalize( $entry );
		if ( '' === $ip ) {
			return null;
		}
		$packed = inet_pton( $ip );
		$max    = strlen( $packed ) * 8;
		if ( null === $bits ) {
			$bits = $max;
		}
		if ( $bits > $max ) {
			return null;
		}
		return array( $packed, $bits );
	}

	/**
	 * Whether the address is inside the range. A plain address matches itself.
	 *
	 * @param string $ip    Address.
	 * @param string $range Address or CIDR range.
	 * @return bool
	 */
	public static function in_range( $ip, $range ) {
		$ip = self::normalize( $ip );
		if ( '' === $ip ) {
			return false;
		}
		$parsed = self::parse_range( $range );
		if ( null === $parsed ) {
			return false;
		}
		list( $network, $bits ) = $parsed;
		$packed                 = inet_pton( $ip );
		if ( strlen( $packed ) !== strlen( $network ) ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		if ( substr( $packed, 0, $bytes ) !== substr( $network, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
		return ( ord( $packed[ $bytes ] ) & $mask ) === ( ord( $network[ $bytes ] ) & $mask );
	}

	/**
	 * Whether the address is inside any of the ranges.
	 *
	 * @param string   $ip     Address.
	 * @param string[] $ranges Addresses or CIDR ranges.
	 * @return bool
	 */
	public static function in_list( $ip, array $ranges ) {
		foreach ( $ranges as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Splits text on new lines, commas and spaces and keeps valid entries.
	 * Text after "#" on a line is a comment.
	 *
	 * @param string $text Raw list.
	 * @return string[]
	 */
	public static function parse_list( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}
			foreach ( preg_split( '/[\s,]+/', $line, -1, PREG_SPLIT_NO_EMPTY ) as $entry ) {
				$parsed = self::parse_range( $entry );
				if ( null === $parsed ) {
					continue;
				}
				$ip    = inet_ntop( $parsed[0] );
				$max   = strlen( $parsed[0] ) * 8;
				$entry = $parsed[1] === $max ? $ip : $ip . '/' . $parsed[1];
				if ( ! in_array( $entry, $out, true ) ) {
					$out[] = $entry;
				}
			}
		}
		return $out;
	}

	/**
	 * Entries of the text that are not valid addresses or ranges.
	 *
	 * @param string $text Raw list.
	 * @return string[]
	 */
	public static function invalid_entries( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}
			foreach ( preg_split( '/[\s,]+/', $line, -1, PREG_SPLIT_NO_EMPTY ) as $entry ) {
				if ( null === self::parse_range( $entry ) ) {
					$out[] = $entry;
				}
			}
		}
		return $out;
	}
}
