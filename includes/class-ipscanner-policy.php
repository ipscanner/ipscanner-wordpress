<?php
/**
 * Maps an edge check verdict to allow, flag or block.
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure decision logic shared by the site check and the form checks.
 */
final class IPScanner_Policy {

	const CLASSES = array( 'human', 'verified_bot', 'ai_agent', 'malicious_automation', 'tor', 'vpn', 'proxy', 'hosting', 'relay' );

	const BLOCKABLE = array( 'malicious_automation', 'ai_agent', 'tor', 'vpn', 'proxy', 'hosting', 'relay' );

	const MODE_MONITOR = 'monitor';
	const MODE_ENFORCE = 'enforce';

	/**
	 * The traffic class an IP network class implies, or '' when it implies none.
	 *
	 * @param string $network_class Network class from the IP check.
	 * @return string
	 */
	public static function network_traffic_class( $network_class ) {
		$map = array(
			'tor'               => 'tor',
			'vpn'               => 'vpn',
			'residential_proxy' => 'proxy',
			'relay'             => 'relay',
			'hosting'           => 'hosting',
			'datacenter'        => 'hosting',
		);
		return isset( $map[ $network_class ] ) ? $map[ $network_class ] : '';
	}

	/**
	 * Whether the visitor is a verified crawler. Verified crawlers always pass.
	 *
	 * @param array $verdict Edge check response.
	 * @return bool
	 */
	public static function is_verified( array $verdict ) {
		if ( isset( $verdict['class'] ) && 'verified_bot' === $verdict['class'] ) {
			return true;
		}
		return isset( $verdict['agent']['signals']['allowlist_verified'] ) && true === $verdict['agent']['signals']['allowlist_verified'];
	}

	/**
	 * Policy for the local settings: every selected class blocks.
	 *
	 * @param string[] $block_classes Classes to block.
	 * @return array<string,string>
	 */
	public static function local_policy( array $block_classes ) {
		$out = array();
		foreach ( $block_classes as $class ) {
			if ( in_array( $class, self::BLOCKABLE, true ) ) {
				$out[ $class ] = 'block';
			}
		}
		return $out;
	}

	/**
	 * Decides what to do with a visitor.
	 *
	 * With $match_network the network class counts too, so a bot on a VPN is
	 * blocked when either "malicious_automation" or "vpn" is blocked. The
	 * dashboard policy (site mode) matches the traffic class only, like the
	 * Cloudflare Worker.
	 *
	 * @param array  $verdict       Edge check response.
	 * @param string $mode          monitor or enforce.
	 * @param array  $policy        Class to action map.
	 * @param bool   $match_network Also match the network class.
	 * @return array{class:string,action:string,decision:string}
	 */
	public static function decide( array $verdict, $mode, array $policy, $match_network ) {
		$class = isset( $verdict['class'] ) && is_string( $verdict['class'] ) ? $verdict['class'] : 'human';
		$out   = array(
			'class'    => $class,
			'action'   => 'allow',
			'decision' => 'allow',
		);
		if ( self::is_verified( $verdict ) ) {
			return $out;
		}

		$classes = array( $class );
		if ( $match_network && isset( $verdict['network']['networkClass'] ) && is_string( $verdict['network']['networkClass'] ) ) {
			$implied = self::network_traffic_class( $verdict['network']['networkClass'] );
			if ( '' !== $implied && $implied !== $class ) {
				$classes[] = $implied;
			}
		}

		$rank = array(
			'allow' => 0,
			'flag'  => 1,
			'block' => 2,
		);
		foreach ( $classes as $candidate ) {
			$action = isset( $policy[ $candidate ] ) ? $policy[ $candidate ] : 'allow';
			if ( isset( $rank[ $action ] ) && $rank[ $action ] > $rank[ $out['action'] ] ) {
				$out['action'] = $action;
			}
		}

		if ( 'allow' !== $out['action'] ) {
			$out['decision'] = self::MODE_ENFORCE === $mode ? $out['action'] : 'would_' . $out['action'];
		}
		return $out;
	}
}
