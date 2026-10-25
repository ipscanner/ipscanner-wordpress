<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-stubs.php';

final class StatsTest extends TestCase {

	const NOW = 1760000000;

	public function test_counts_checked_and_blocked() {
		$b = array();
		$b = IPScanner_Stats::bump( $b, self::NOW, 'human', 'allow' );
		$b = IPScanner_Stats::bump( $b, self::NOW, 'tor', 'block' );
		$b = IPScanner_Stats::bump( $b, self::NOW, 'vpn', 'would_block' );
		$b = IPScanner_Stats::bump( $b, self::NOW, 'vpn', 'would_flag' );
		$this->assertSame(
			array(
				'checked'   => 4,
				'blocked'   => 1,
				'would'     => 1,
				'top'       => 'tor',
				'top_would' => 'vpn',
			),
			IPScanner_Stats::totals( $b, self::NOW )
		);
	}

	public function test_top_reason_is_most_common_blocked_class() {
		$b = array();
		foreach ( array( 'tor', 'vpn', 'vpn', 'malicious_automation' ) as $class ) {
			$b = IPScanner_Stats::bump( $b, self::NOW, $class, 'block' );
		}
		$b = IPScanner_Stats::bump( $b, self::NOW, 'human', 'allow' );
		$b = IPScanner_Stats::bump( $b, self::NOW, 'human', 'allow' );
		$this->assertSame( 'vpn', IPScanner_Stats::totals( $b, self::NOW )['top'] );
	}

	public function test_monitor_reasons_are_kept_apart() {
		$b      = array();
		$b      = IPScanner_Stats::bump( $b, self::NOW, 'tor', 'block' );
		$b      = IPScanner_Stats::bump( $b, self::NOW, 'ai_agent', 'would_block' );
		$b      = IPScanner_Stats::bump( $b, self::NOW, 'ai_agent', 'would_block' );
		$totals = IPScanner_Stats::totals( $b, self::NOW );
		$this->assertSame( 'tor', $totals['top'] );
		$this->assertSame( 'ai_agent', $totals['top_would'] );
		$this->assertSame( 2, $totals['would'] );
	}

	public function test_no_blocks_means_no_top_reason() {
		$b = IPScanner_Stats::bump( array(), self::NOW, 'human', 'allow' );
		$this->assertSame( '', IPScanner_Stats::totals( $b, self::NOW )['top'] );
		$this->assertSame( 0, IPScanner_Stats::totals( array(), self::NOW )['checked'] );
	}

	public function test_buckets_are_hourly() {
		$b = IPScanner_Stats::bump( array(), self::NOW, 'human', 'allow' );
		$b = IPScanner_Stats::bump( $b, self::NOW + 3600, 'human', 'allow' );
		$this->assertCount( 2, $b );
		$this->assertSame( 2, IPScanner_Stats::totals( $b, self::NOW + 3600 )['checked'] );
	}

	public function test_older_than_a_day_is_dropped() {
		$b = IPScanner_Stats::bump( array(), self::NOW, 'tor', 'block' );
		$this->assertSame( 1, IPScanner_Stats::totals( $b, self::NOW + 23 * 3600 )['checked'] );
		$this->assertSame( 0, IPScanner_Stats::totals( $b, self::NOW + 25 * 3600 )['checked'] );
		$b = IPScanner_Stats::bump( $b, self::NOW + 25 * 3600, 'human', 'allow' );
		$this->assertCount( 1, $b );
	}

	public function test_bad_stored_values_are_ignored() {
		$b = array( 'x' => 'junk' );
		$b = IPScanner_Stats::bump( $b, self::NOW, 'human', 'allow' );
		$this->assertCount( 1, $b );
		$this->assertSame( 1, IPScanner_Stats::totals( $b, self::NOW )['checked'] );
	}

	public function test_flagged_total_counts_blocks_and_would_blocks_for_good() {
		IPScanner_Test_WP::reset();
		IPScanner_Stats::add( 'tor', 'block' );
		IPScanner_Stats::add( 'vpn', 'would_block' );
		IPScanner_Stats::add( 'vpn', 'would_flag' );
		IPScanner_Stats::add( 'human', 'allow' );
		$this->assertSame( 2, IPScanner_Stats::flagged_total() );
		IPScanner_Test_WP::$options[ IPScanner_Stats::OPTION ] = array();
		$this->assertSame( 2, IPScanner_Stats::flagged_total() );
	}

	public function test_flagged_total_starts_from_the_last_day() {
		IPScanner_Test_WP::reset();
		$b = IPScanner_Stats::bump( array(), time(), 'tor', 'block' );
		$b = IPScanner_Stats::bump( $b, time(), 'vpn', 'would_block' );
		IPScanner_Test_WP::$options[ IPScanner_Stats::OPTION ] = $b;
		$this->assertSame( 2, IPScanner_Stats::flagged_total() );
		IPScanner_Stats::add( 'tor', 'block' );
		$this->assertSame( 3, IPScanner_Stats::flagged_total() );
	}
}
