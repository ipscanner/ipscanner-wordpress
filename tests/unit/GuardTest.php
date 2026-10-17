<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-stubs.php';
require_once __DIR__ . '/../../includes/class-ipscanner-settings.php';
require_once __DIR__ . '/../../includes/class-ipscanner-log.php';
require_once __DIR__ . '/../../includes/class-ipscanner-guard.php';

final class GuardTest extends TestCase {

	const IP = '185.220.101.1';

	private $server;

	protected function setUp(): void {
		IPScanner_Test_WP::reset();
		$this->server               = $_SERVER;
		$_SERVER['REMOTE_ADDR']     = self::IP;
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$_SERVER['REQUEST_URI']     = '/wp-login.php';
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
	}

	private function guard( array $settings = array() ) {
		IPScanner_Test_WP::$options['ipscanner_settings'] = array_merge(
			array(
				'api_key'       => 'pk_test',
				'mode'          => 'enforce',
				'block_classes' => array( 'tor' ),
			),
			$settings
		);
		$s = new IPScanner_Settings();
		return new IPScanner_Guard( $s, new IPScanner_API( $s ) );
	}

	private function edge( array $extra = array() ) {
		IPScanner_Test_WP::respond(
			'/v1/edge/check',
			array_merge(
				array(
					'class'   => 'tor',
					'network' => array( 'networkClass' => 'tor' ),
				),
				$extra
			)
		);
	}

	private function site_policy( $mode ) {
		IPScanner_Test_WP::respond(
			'/v1/sites/site_x/policy',
			array(
				'mode'    => $mode,
				'version' => 1,
				'policy'  => array( 'tor' => 'block' ),
			)
		);
	}

	private function edge_calls() {
		return count( array_filter( IPScanner_Test_WP::$requests, function ( $r ) {
			return '/v1/edge/check' === $r['path'];
		} ) );
	}

	private function assert_not_blocked( IPScanner_Guard $guard ) {
		$this->assertNull( $guard->check_login( null, 'admin' ) );
		$this->assertFalse( $guard->check_cf7( false ) );
		$errors = new WP_Error();
		$guard->check_woo_checkout( array(), $errors );
		$guard->check_errors( $errors );
		$this->assertSame( 0, $guard->check_comment( 0 ) );
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	private function assert_blocked( IPScanner_Guard $guard ) {
		$this->assertInstanceOf( WP_Error::class, $guard->check_login( null, 'admin' ) );
		$this->assertTrue( $guard->check_cf7( false ) );
		$errors = new WP_Error();
		$guard->check_woo_checkout( array(), $errors );
		$this->assertSame( array( 'ipscanner_blocked' ), $errors->get_error_codes() );
	}

	public function test_local_enforce_with_edge_monitor_blocks_nothing() {
		$this->edge( array( 'mode' => 'monitor' ) );
		$guard = $this->guard();
		$this->assertSame( 'would_block', $guard->check( 'login' )['decision'] );
		$this->assert_not_blocked( $guard );
		$this->assertSame( 'would_block', IPScanner_Log::all()[0]['decision'] );
		$this->assertSame( 1, IPScanner_Stats::last_day()['would'] );
		$this->assertSame( 0, IPScanner_Stats::last_day()['blocked'] );
	}

	public function test_local_enforce_with_edge_enforce_blocks() {
		$this->edge( array( 'mode' => 'enforce' ) );
		$guard = $this->guard();
		$this->assertSame( 'block', $guard->check( 'login' )['decision'] );
		$this->assert_blocked( $guard );
		$this->assertSame( 'block', IPScanner_Log::all()[0]['decision'] );
	}

	public function test_answer_without_mode_blocks_as_before() {
		$this->edge();
		$guard = $this->guard();
		$this->assertSame( 'block', $guard->check( 'login' )['decision'] );
		$this->assert_blocked( $guard );
	}

	public function test_local_monitor_stays_monitor_with_edge_enforce() {
		$this->edge( array( 'mode' => 'enforce' ) );
		$guard = $this->guard( array( 'mode' => 'monitor' ) );
		$this->assertSame( 'would_block', $guard->check( 'login' )['decision'] );
		$this->assert_not_blocked( $guard );
	}

	public function test_site_policy_enforce_with_edge_monitor_blocks_nothing() {
		$this->site_policy( 'enforce' );
		$this->edge(
			array(
				'mode' => 'monitor',
				'site' => array( 'policyVersion' => 1 ),
			)
		);
		$guard = $this->guard(
			array(
				'site_id' => 'site_x',
				'mode'    => 'monitor',
			)
		);
		$this->assertSame( 'would_block', $guard->check( 'login' )['decision'] );
		$this->assert_not_blocked( $guard );
		$this->assertSame( 'would_block', IPScanner_Log::all()[0]['decision'] );
	}

	public function test_site_policy_enforce_with_edge_enforce_blocks() {
		$this->site_policy( 'enforce' );
		$this->edge(
			array(
				'mode' => 'enforce',
				'site' => array( 'policyVersion' => 1 ),
			)
		);
		$guard = $this->guard( array( 'site_id' => 'site_x' ) );
		$this->assertSame( 'block', $guard->check( 'login' )['decision'] );
		$this->assert_blocked( $guard );
	}

	public function test_cached_verdict_keeps_its_mode() {
		$this->edge( array( 'mode' => 'monitor' ) );
		$this->assertSame( 'would_block', $this->guard()->check( 'login' )['decision'] );

		$this->edge( array( 'mode' => 'enforce' ) );
		$this->assertSame( 'would_block', $this->guard()->check( 'login' )['decision'] );
		$this->assertSame( 1, $this->edge_calls() );
	}

	public function test_last_edge_mode_is_remembered() {
		$this->assertFalse( IPScanner_API::edge_monitor() );

		$this->edge( array( 'mode' => 'monitor' ) );
		$this->guard()->check( 'login' );
		$this->assertTrue( IPScanner_API::edge_monitor() );

		IPScanner_Test_WP::$transients = array();
		$this->edge( array( 'mode' => 'enforce' ) );
		$this->guard()->check( 'login' );
		$this->assertFalse( IPScanner_API::edge_monitor() );

		IPScanner_Test_WP::$transients = array();
		$this->edge( array( 'mode' => 'monitor' ) );
		$this->guard()->check( 'login' );
		IPScanner_Test_WP::$transients = array();
		$this->edge();
		$this->guard()->check( 'login' );
		$this->assertFalse( IPScanner_API::edge_monitor() );
	}

	private function stored_mode() {
		return IPScanner_Test_WP::$options['ipscanner_settings']['mode'];
	}

	public function test_trial_end_switches_local_enforce_to_monitor() {
		$this->edge( array( 'mode' => 'monitor' ) );
		$this->guard()->check( 'login' );
		$this->assertSame( 'monitor', $this->stored_mode() );
		$this->assertSame( 1, get_option( IPScanner_API::ENFORCE_PAUSED ) );
	}

	public function test_upgrade_switches_enforce_back_on() {
		$this->edge( array( 'mode' => 'monitor' ) );
		$this->guard()->check( 'login' );

		IPScanner_Test_WP::$transients = array();
		$this->edge( array( 'mode' => 'enforce' ) );
		$s     = new IPScanner_Settings();
		$guard = new IPScanner_Guard( $s, new IPScanner_API( $s ) );
		$this->assertSame( 'block', $guard->check( 'login' )['decision'] );
		$this->assertSame( 'enforce', $this->stored_mode() );
		$this->assertFalse( get_option( IPScanner_API::ENFORCE_PAUSED ) );
	}

	public function test_upgrade_keeps_a_chosen_monitor() {
		$this->edge( array( 'mode' => 'enforce' ) );
		$this->guard( array( 'mode' => 'monitor' ) )->check( 'login' );
		$this->assertSame( 'monitor', $this->stored_mode() );
		$this->assertFalse( get_option( IPScanner_API::ENFORCE_PAUSED ) );
	}

	public function test_answer_without_mode_keeps_local_enforce() {
		$this->edge();
		$this->guard()->check( 'login' );
		$this->assertSame( 'enforce', $this->stored_mode() );
		$this->assertFalse( get_option( IPScanner_API::ENFORCE_PAUSED ) );
	}

	public function test_settings_cannot_save_enforce_while_the_plan_monitors() {
		IPScanner_Test_WP::$options['ipscanner_settings'] = array( 'api_key' => 'pk_test' );
		update_option( IPScanner_API::EDGE_MODE, 'monitor' );
		$this->assertSame( 'monitor', IPScanner_Settings::sanitize( array( 'mode' => 'enforce' ) )['mode'] );
		// A new key is another account: its plan is not known yet.
		$this->assertSame( 'enforce', IPScanner_Settings::sanitize( array( 'mode' => 'enforce', 'api_key' => 'pk_other' ) )['mode'] );
		update_option( IPScanner_API::EDGE_MODE, 'enforce' );
		$this->assertSame( 'enforce', IPScanner_Settings::sanitize( array( 'mode' => 'enforce' ) )['mode'] );
	}
}
