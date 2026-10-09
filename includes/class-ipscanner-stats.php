<?php
/**
 * Rolling 24 hour counters for the overview and the dashboard widget.
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hourly buckets kept in one small option.
 */
final class IPScanner_Stats {

	const OPTION = 'ipscanner_stats';
	const HOURS  = 24;

	/**
	 * Adds one checked visitor to the buckets and drops buckets older than a day.
	 *
	 * @param array  $buckets       Buckets keyed by hour start.
	 * @param int    $now           Unix time.
	 * @param string $traffic_class Traffic class.
	 * @param string $decision      allow, flag, block, would_flag or would_block.
	 * @return array
	 */
	public static function bump( array $buckets, $now, $traffic_class, $decision ) {
		$hour    = (int) ( floor( $now / 3600 ) * 3600 );
		$buckets = self::prune( $buckets, $now );
		if ( ! isset( $buckets[ $hour ] ) ) {
			$buckets[ $hour ] = array(
				'checked' => 0,
				'blocked' => 0,
				'would'   => 0,
			);
		}
		++$buckets[ $hour ]['checked'];
		if ( 'block' === $decision || 'would_block' === $decision ) {
			$field         = 'block' === $decision ? 'blocked' : 'would';
			$traffic_class = '' === (string) $traffic_class ? 'unknown' : (string) $traffic_class;
			$reasons       = $field . '_reasons';
			++$buckets[ $hour ][ $field ];
			if ( ! isset( $buckets[ $hour ][ $reasons ][ $traffic_class ] ) ) {
				$buckets[ $hour ][ $reasons ][ $traffic_class ] = 0;
			}
			++$buckets[ $hour ][ $reasons ][ $traffic_class ];
		}
		return $buckets;
	}

	/**
	 * Drops buckets that ended more than a day ago.
	 *
	 * @param array $buckets Buckets keyed by hour start.
	 * @param int   $now     Unix time.
	 * @return array
	 */
	public static function prune( array $buckets, $now ) {
		$oldest = (int) ( floor( $now / 3600 ) * 3600 ) - ( self::HOURS - 1 ) * 3600;
		foreach ( array_keys( $buckets ) as $hour ) {
			if ( (int) $hour < $oldest || ! is_array( $buckets[ $hour ] ) ) {
				unset( $buckets[ $hour ] );
			}
		}
		return $buckets;
	}

	/**
	 * Totals for the last 24 hours. top is the most common blocked class,
	 * top_would the most common class that monitor mode would block.
	 *
	 * @param array $buckets Buckets keyed by hour start.
	 * @param int   $now     Unix time.
	 * @return array{checked:int,blocked:int,would:int,top:string,top_would:string}
	 */
	public static function totals( array $buckets, $now ) {
		$out     = array(
			'checked'   => 0,
			'blocked'   => 0,
			'would'     => 0,
			'top'       => '',
			'top_would' => '',
		);
		$reasons = array(
			'blocked' => array(),
			'would'   => array(),
		);
		foreach ( self::prune( $buckets, $now ) as $bucket ) {
			$out['checked'] += isset( $bucket['checked'] ) ? (int) $bucket['checked'] : 0;
			foreach ( array( 'blocked', 'would' ) as $field ) {
				$out[ $field ] += isset( $bucket[ $field ] ) ? (int) $bucket[ $field ] : 0;
				$counts         = isset( $bucket[ $field . '_reasons' ] ) && is_array( $bucket[ $field . '_reasons' ] ) ? $bucket[ $field . '_reasons' ] : array();
				foreach ( $counts as $traffic_class => $count ) {
					$reasons[ $field ][ $traffic_class ] = ( isset( $reasons[ $field ][ $traffic_class ] ) ? $reasons[ $field ][ $traffic_class ] : 0 ) + (int) $count;
				}
			}
		}
		$out['top']       = self::top( $reasons['blocked'] );
		$out['top_would'] = self::top( $reasons['would'] );
		return $out;
	}

	/**
	 * The class with the highest count.
	 *
	 * @param array<string,int> $counts Counts by class.
	 * @return string
	 */
	private static function top( array $counts ) {
		if ( ! $counts ) {
			return '';
		}
		arsort( $counts );
		return (string) key( $counts );
	}

	/**
	 * Records a checked visitor.
	 *
	 * @param string $traffic_class Traffic class.
	 * @param string $decision      Decision.
	 */
	public static function add( $traffic_class, $decision ) {
		update_option( self::OPTION, self::bump( self::buckets(), time(), $traffic_class, $decision ), false );
	}

	/**
	 * Totals for the last 24 hours.
	 *
	 * @return array{checked:int,blocked:int,would:int,top:string}
	 */
	public static function last_day() {
		return self::totals( self::buckets(), time() );
	}

	/**
	 * Stored buckets.
	 *
	 * @return array
	 */
	private static function buckets() {
		$buckets = get_option( self::OPTION, array() );
		return is_array( $buckets ) ? $buckets : array();
	}
}
