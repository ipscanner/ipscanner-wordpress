<?php
/**
 * Recent decisions, kept in a capped option.
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the last decisions for the settings page and dashboard widget.
 */
final class IPScanner_Log {

	const OPTION = 'ipscanner_log';
	const LIMIT  = 50;

	/**
	 * Adds an entry and drops the oldest past the limit.
	 *
	 * @param array $entry Decision fields.
	 */
	public static function add( array $entry ) {
		$entry = array(
			'time'     => time(),
			'ip'       => isset( $entry['ip'] ) ? (string) $entry['ip'] : '',
			'area'     => isset( $entry['area'] ) ? (string) $entry['area'] : '',
			'class'    => isset( $entry['class'] ) ? (string) $entry['class'] : '',
			'network'  => isset( $entry['network'] ) ? (string) $entry['network'] : '',
			'decision' => isset( $entry['decision'] ) ? (string) $entry['decision'] : '',
			'status'   => isset( $entry['status'] ) ? (string) $entry['status'] : 'ok',
			'path'     => isset( $entry['path'] ) ? substr( (string) $entry['path'], 0, 200 ) : '',
		);
		$log   = self::all();
		array_unshift( $log, $entry );
		update_option( self::OPTION, array_slice( $log, 0, self::LIMIT ), false );
	}

	/**
	 * All entries, newest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Removes every entry.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
