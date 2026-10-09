<?php

use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {

	public function test_verdict_key_is_stable_and_short() {
		$a = IPScanner_API::verdict_key( '', '1.2.3.4', 'Mozilla/5.0' );
		$this->assertSame( $a, IPScanner_API::verdict_key( '', '1.2.3.4', 'Mozilla/5.0' ) );
		$this->assertStringStartsWith( 'ipscanner_v_', $a );
		$this->assertLessThanOrEqual( 172, strlen( $a ) );
	}

	public function test_verdict_key_varies_by_site_ip_and_agent() {
		$base = IPScanner_API::verdict_key( '', '1.2.3.4', 'ua' );
		$this->assertNotSame( $base, IPScanner_API::verdict_key( 'site_x', '1.2.3.4', 'ua' ) );
		$this->assertNotSame( $base, IPScanner_API::verdict_key( '', '1.2.3.5', 'ua' ) );
		$this->assertNotSame( $base, IPScanner_API::verdict_key( '', '1.2.3.4', 'ua2' ) );
	}

	public function test_to_policy_normalizes() {
		$out = IPScanner_API::to_policy(
			array(
				'mode'    => 'enforce',
				'version' => '7',
				'policy'  => array(
					'tor'     => 'block',
					'hosting' => 'flag',
					'human'   => 'allow',
					'bogus'   => 'block',
					'vpn'     => 'explode',
				),
			)
		);
		$this->assertSame(
			array(
				'mode'    => 'enforce',
				'policy'  => array(
					'tor'     => 'block',
					'hosting' => 'flag',
					'human'   => 'allow',
				),
				'version' => 7,
			),
			$out
		);
	}

	public function test_to_policy_defaults_to_monitor() {
		$out = IPScanner_API::to_policy( array( 'mode' => 'weird' ) );
		$this->assertSame( 'monitor', $out['mode'] );
		$this->assertSame( array(), $out['policy'] );
		$this->assertSame( 0, $out['version'] );
	}

	public function test_base_url_default() {
		$this->assertSame( 'https://ipscanner.io', IPScanner_API::base() );
	}
}
