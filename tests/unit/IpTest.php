<?php

use PHPUnit\Framework\TestCase;

final class IpTest extends TestCase {

	public function test_normalize() {
		$this->assertSame( '1.2.3.4', IPScanner_IP::normalize( ' 1.2.3.4 ' ) );
		$this->assertSame( '1.2.3.4', IPScanner_IP::normalize( '1.2.3.4:8080' ) );
		$this->assertSame( '2001:db8::1', IPScanner_IP::normalize( '2001:DB8:0:0::1' ) );
		$this->assertSame( '2001:db8::1', IPScanner_IP::normalize( '[2001:db8::1]:443' ) );
		$this->assertSame( '5.9.0.1', IPScanner_IP::normalize( '::ffff:5.9.0.1' ) );
		$this->assertSame( '', IPScanner_IP::normalize( 'unknown' ) );
		$this->assertSame( '', IPScanner_IP::normalize( '1.2.3.256' ) );
		$this->assertSame( '', IPScanner_IP::normalize( '[::1' ) );
		$this->assertSame( '', IPScanner_IP::normalize( null ) );
	}

	public function test_is_public() {
		$this->assertTrue( IPScanner_IP::is_public( '185.220.101.1' ) );
		$this->assertTrue( IPScanner_IP::is_public( '2a01:4f8::1' ) );
		$this->assertFalse( IPScanner_IP::is_public( '10.0.0.1' ) );
		$this->assertFalse( IPScanner_IP::is_public( '172.18.0.1' ) );
		$this->assertFalse( IPScanner_IP::is_public( '127.0.0.1' ) );
		$this->assertFalse( IPScanner_IP::is_public( '::1' ) );
	}

	/**
	 * @dataProvider ranges
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('ranges')]
	public function test_in_range( $ip, $range, $expected ) {
		$this->assertSame( $expected, IPScanner_IP::in_range( $ip, $range ) );
	}

	public static function ranges() {
		return array(
			array( '10.1.2.3', '10.0.0.0/8', true ),
			array( '11.1.2.3', '10.0.0.0/8', false ),
			array( '192.168.1.130', '192.168.1.128/25', true ),
			array( '192.168.1.127', '192.168.1.128/25', false ),
			array( '172.31.255.255', '172.16.0.0/12', true ),
			array( '172.32.0.0', '172.16.0.0/12', false ),
			array( '1.2.3.4', '1.2.3.4', true ),
			array( '1.2.3.5', '1.2.3.4', false ),
			array( '1.2.3.4', '0.0.0.0/0', true ),
			array( '1.2.3.4', '1.2.3.4/33', false ),
			array( '2001:db8::1', '2001:db8::/32', true ),
			array( '2001:db9::1', '2001:db8::/32', false ),
			array( '2a06:98c7::1', '2a06:98c0::/29', true ),
			array( '2a06:98c8::1', '2a06:98c0::/29', false ),
			array( '1.2.3.4', '2001:db8::/32', false ),
			array( '2001:db8::1', '1.0.0.0/8', false ),
			array( 'bad', '1.0.0.0/8', false ),
			array( '1.2.3.4', 'bad/8', false ),
			array( '1.2.3.4', '1.2.3.0/x', false ),
		);
	}

	public function test_in_list() {
		$list = array( '203.0.113.7', '2001:db8::/32' );
		$this->assertTrue( IPScanner_IP::in_list( '203.0.113.7', $list ) );
		$this->assertTrue( IPScanner_IP::in_list( '2001:db8:1::5', $list ) );
		$this->assertFalse( IPScanner_IP::in_list( '203.0.113.8', $list ) );
		$this->assertFalse( IPScanner_IP::in_list( '203.0.113.7', array() ) );
	}

	public function test_parse_list() {
		$text = "203.0.113.7\n10.0.0.0/8, 2001:DB8::/32 # office\r\nnot-an-ip\n# comment\n203.0.113.7\n1.2.3.4/32";
		$this->assertSame( array( '203.0.113.7', '10.0.0.0/8', '2001:db8::/32', '1.2.3.4' ), IPScanner_IP::parse_list( $text ) );
		$this->assertSame( array( 'not-an-ip' ), IPScanner_IP::invalid_entries( $text ) );
		$this->assertTrue( IPScanner_IP::is_valid_range( '::/0' ) );
		$this->assertFalse( IPScanner_IP::is_valid_range( '::/129' ) );
	}
}
