<?php

use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase {

	private function verdict( $class, $network = null, $verified = false ) {
		$v = array(
			'class' => $class,
			'agent' => array( 'signals' => array( 'allowlist_verified' => $verified ) ),
		);
		if ( null !== $network ) {
			$v['network'] = array( 'networkClass' => $network );
		}
		return $v;
	}

	public function test_local_policy_keeps_blockable_classes() {
		$this->assertSame(
			array(
				'malicious_automation' => 'block',
				'tor'                  => 'block',
			),
			IPScanner_Policy::local_policy( array( 'malicious_automation', 'tor', 'human', 'verified_bot', 'nope' ) )
		);
	}

	public function test_enforce_blocks_selected_class() {
		$policy = IPScanner_Policy::local_policy( array( 'tor' ) );
		$this->assertSame(
			array(
				'class'    => 'tor',
				'action'   => 'block',
				'decision' => 'block',
			),
			IPScanner_Policy::decide( $this->verdict( 'tor', 'tor' ), 'enforce', $policy, true )
		);
	}

	public function test_monitor_never_blocks() {
		$policy = IPScanner_Policy::local_policy( array( 'tor' ) );
		$out    = IPScanner_Policy::decide( $this->verdict( 'tor', 'tor' ), 'monitor', $policy, true );
		$this->assertSame( 'block', $out['action'] );
		$this->assertSame( 'would_block', $out['decision'] );
	}

	public function test_unselected_class_is_allowed() {
		$policy = IPScanner_Policy::local_policy( array( 'malicious_automation' ) );
		$out    = IPScanner_Policy::decide( $this->verdict( 'vpn', 'vpn' ), 'enforce', $policy, true );
		$this->assertSame( 'allow', $out['decision'] );
		$this->assertSame( 'vpn', $out['class'] );
	}

	public function test_network_class_counts_in_local_mode() {
		$policy = IPScanner_Policy::local_policy( array( 'vpn' ) );
		$out    = IPScanner_Policy::decide( $this->verdict( 'ai_agent', 'vpn' ), 'enforce', $policy, true );
		$this->assertSame( 'block', $out['decision'] );
		$this->assertSame( 'ai_agent', $out['class'] );

		$proxy = IPScanner_Policy::decide( $this->verdict( 'malicious_automation', 'residential_proxy' ), 'enforce', IPScanner_Policy::local_policy( array( 'proxy' ) ), true );
		$this->assertSame( 'block', $proxy['decision'] );

		$dc = IPScanner_Policy::decide( $this->verdict( 'human', 'datacenter' ), 'enforce', IPScanner_Policy::local_policy( array( 'hosting' ) ), true );
		$this->assertSame( 'block', $dc['decision'] );
	}

	public function test_network_class_ignored_in_site_mode() {
		$out = IPScanner_Policy::decide( $this->verdict( 'ai_agent', 'vpn' ), 'enforce', array( 'vpn' => 'block' ), false );
		$this->assertSame( 'allow', $out['decision'] );
	}

	public function test_site_policy_flag_and_block() {
		$policy = array(
			'hosting'              => 'flag',
			'malicious_automation' => 'block',
		);
		$this->assertSame( 'flag', IPScanner_Policy::decide( $this->verdict( 'hosting', 'hosting' ), 'enforce', $policy, false )['decision'] );
		$this->assertSame( 'would_flag', IPScanner_Policy::decide( $this->verdict( 'hosting', 'hosting' ), 'monitor', $policy, false )['decision'] );
		$this->assertSame( 'block', IPScanner_Policy::decide( $this->verdict( 'malicious_automation' ), 'enforce', $policy, false )['decision'] );
		$this->assertSame( 'allow', IPScanner_Policy::decide( $this->verdict( 'human', 'residential_clean' ), 'enforce', $policy, false )['decision'] );
	}

	public function test_block_wins_over_flag() {
		$policy = array(
			'ai_agent' => 'flag',
			'tor'      => 'block',
		);
		$this->assertSame( 'block', IPScanner_Policy::decide( $this->verdict( 'ai_agent', 'tor' ), 'enforce', $policy, true )['decision'] );
	}

	public function test_verified_crawlers_always_pass() {
		$policy = array(
			'verified_bot' => 'block',
			'hosting'      => 'block',
		);
		$this->assertSame( 'allow', IPScanner_Policy::decide( $this->verdict( 'verified_bot', 'hosting' ), 'enforce', $policy, true )['decision'] );
		$this->assertSame( 'allow', IPScanner_Policy::decide( $this->verdict( 'hosting', 'hosting', true ), 'enforce', $policy, true )['decision'] );
	}

	public function test_unknown_action_is_allow() {
		$out = IPScanner_Policy::decide( $this->verdict( 'tor' ), 'enforce', array( 'tor' => 'challenge' ), false );
		$this->assertSame( 'allow', $out['decision'] );
	}

	public function test_missing_class_is_human() {
		$out = IPScanner_Policy::decide( array(), 'enforce', array( 'human' => 'block' ), false );
		$this->assertSame( 'human', $out['class'] );
		$this->assertSame( 'block', $out['decision'] );
	}

	public function test_edge_monitor_never_blocks_in_local_mode() {
		$verdict         = $this->verdict( 'tor', 'tor' );
		$verdict['mode'] = 'monitor';
		$out             = IPScanner_Policy::decide( $verdict, 'enforce', IPScanner_Policy::local_policy( array( 'tor' ) ), true );
		$this->assertSame( 'block', $out['action'] );
		$this->assertSame( 'would_block', $out['decision'] );
	}

	public function test_edge_monitor_never_blocks_in_site_mode() {
		$policy          = array(
			'malicious_automation' => 'block',
			'hosting'              => 'flag',
		);
		$bot             = $this->verdict( 'malicious_automation' );
		$bot['mode']     = 'monitor';
		$hosting         = $this->verdict( 'hosting', 'hosting' );
		$hosting['mode'] = 'monitor';
		$this->assertSame( 'would_block', IPScanner_Policy::decide( $bot, 'enforce', $policy, false )['decision'] );
		$this->assertSame( 'would_flag', IPScanner_Policy::decide( $hosting, 'enforce', $policy, false )['decision'] );
	}

	public function test_edge_enforce_keeps_the_configured_mode() {
		$verdict         = $this->verdict( 'tor', 'tor' );
		$verdict['mode'] = 'enforce';
		$policy          = IPScanner_Policy::local_policy( array( 'tor' ) );
		$this->assertSame( 'block', IPScanner_Policy::decide( $verdict, 'enforce', $policy, true )['decision'] );
		$this->assertSame( 'would_block', IPScanner_Policy::decide( $verdict, 'monitor', $policy, true )['decision'] );
		$this->assertSame( 'block', IPScanner_Policy::decide( $verdict, 'enforce', array( 'tor' => 'block' ), false )['decision'] );
	}

	public function test_missing_or_unknown_edge_mode_changes_nothing() {
		$policy = IPScanner_Policy::local_policy( array( 'tor' ) );
		$this->assertSame( 'block', IPScanner_Policy::decide( $this->verdict( 'tor', 'tor' ), 'enforce', $policy, true )['decision'] );
		$verdict         = $this->verdict( 'tor', 'tor' );
		$verdict['mode'] = 'paused';
		$this->assertSame( 'block', IPScanner_Policy::decide( $verdict, 'enforce', $policy, true )['decision'] );
	}

	public function test_network_traffic_class() {
		$this->assertSame( 'proxy', IPScanner_Policy::network_traffic_class( 'residential_proxy' ) );
		$this->assertSame( 'hosting', IPScanner_Policy::network_traffic_class( 'datacenter' ) );
		$this->assertSame( '', IPScanner_Policy::network_traffic_class( 'residential_clean' ) );
		$this->assertSame( '', IPScanner_Policy::network_traffic_class( 'mobile' ) );
	}
}
