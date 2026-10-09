<?php

use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase {

	public function test_remote_addr_ignores_proxy_headers() {
		$server = array(
			'REMOTE_ADDR'           => '172.18.0.1',
			'HTTP_X_FORWARDED_FOR'  => '185.220.101.1',
			'HTTP_CF_CONNECTING_IP' => '185.220.101.1',
		);
		$this->assertSame( '172.18.0.1', IPScanner_Request::client_ip( $server, 'remote_addr', array( '172.16.0.0/12' ) ) );
	}

	public function test_cloudflare_from_edge_range() {
		$server = array(
			'REMOTE_ADDR'           => '162.158.1.1',
			'HTTP_CF_CONNECTING_IP' => '84.128.0.1',
		);
		$this->assertSame( '84.128.0.1', IPScanner_Request::client_ip( $server, 'cloudflare', array() ) );
	}

	public function test_cloudflare_from_trusted_proxy() {
		$server = array(
			'REMOTE_ADDR'           => '172.18.0.1',
			'HTTP_CF_CONNECTING_IP' => '2a01:4f8::1',
		);
		$this->assertSame( '2a01:4f8::1', IPScanner_Request::client_ip( $server, 'cloudflare', array( '172.16.0.0/12' ) ) );
	}

	public function test_cloudflare_header_from_untrusted_peer_is_ignored() {
		$server = array(
			'REMOTE_ADDR'           => '84.128.0.1',
			'HTTP_CF_CONNECTING_IP' => '1.1.1.1',
		);
		$this->assertSame( '84.128.0.1', IPScanner_Request::client_ip( $server, 'cloudflare', array() ) );
	}

	public function test_cloudflare_invalid_header_falls_back() {
		$server = array(
			'REMOTE_ADDR'           => '162.158.1.1',
			'HTTP_CF_CONNECTING_IP' => 'garbage',
		);
		$this->assertSame( '162.158.1.1', IPScanner_Request::client_ip( $server, 'cloudflare', array() ) );
	}

	public function test_forwarded_walks_back_past_trusted_hops() {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.2',
			'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 185.220.101.1, 10.0.0.9',
		);
		$this->assertSame( '185.220.101.1', IPScanner_Request::client_ip( $server, 'x_forwarded_for', array( '10.0.0.0/8' ) ) );
	}

	public function test_forwarded_from_untrusted_peer_is_ignored() {
		$server = array(
			'REMOTE_ADDR'          => '84.128.0.1',
			'HTTP_X_FORWARDED_FOR' => '1.1.1.1',
		);
		$this->assertSame( '84.128.0.1', IPScanner_Request::client_ip( $server, 'x_forwarded_for', array( '10.0.0.0/8' ) ) );
	}

	public function test_forwarded_all_trusted_returns_leftmost() {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.2',
			'HTTP_X_FORWARDED_FOR' => '10.0.0.5, 10.0.0.9',
		);
		$this->assertSame( '10.0.0.5', IPScanner_Request::client_ip( $server, 'x_forwarded_for', array( '10.0.0.0/8' ) ) );
	}

	public function test_forwarded_stops_at_garbage() {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.2',
			'HTTP_X_FORWARDED_FOR' => 'spoofed, 10.0.0.9',
		);
		$this->assertSame( '10.0.0.9', IPScanner_Request::client_ip( $server, 'x_forwarded_for', array( '10.0.0.0/8' ) ) );
	}

	public function test_forwarded_missing_header() {
		$this->assertSame( '10.0.0.2', IPScanner_Request::client_ip( array( 'REMOTE_ADDR' => '10.0.0.2' ), 'x_forwarded_for', array( '10.0.0.0/8' ) ) );
	}

	public function test_no_remote_addr() {
		$this->assertSame( '', IPScanner_Request::client_ip( array(), 'cloudflare', array() ) );
	}

	public function test_forwarded_headers() {
		$server = array(
			'HTTP_ACCEPT'             => 'text/html',
			'HTTP_ACCEPT_LANGUAGE'    => 'en',
			'HTTP_SEC_CH_UA_PLATFORM' => '"macOS"',
			'HTTP_SEC_FETCH_MODE'     => 'navigate',
			'HTTP_COOKIE'             => 'secret=1',
			'HTTP_AUTHORIZATION'      => 'Bearer x',
			'REMOTE_ADDR'             => '1.2.3.4',
		);
		$this->assertSame(
			array(
				'accept'             => 'text/html',
				'accept-language'    => 'en',
				'sec-ch-ua-platform' => '"macOS"',
				'sec-fetch-mode'     => 'navigate',
			),
			IPScanner_Request::forwarded_headers( $server )
		);
	}

	public function test_static_paths() {
		$this->assertTrue( IPScanner_Request::is_static_path( '/wp-content/themes/x/style.css' ) );
		$this->assertTrue( IPScanner_Request::is_static_path( '/a/b.WOFF2' ) );
		$this->assertFalse( IPScanner_Request::is_static_path( '/shop/' ) );
		$this->assertFalse( IPScanner_Request::is_static_path( '/wp-login.php' ) );
	}
}
