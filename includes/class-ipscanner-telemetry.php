<?php
/**
 * Usage events: what administrators do in the plugin and a daily summary.
 * Sent only with an API key and the share_usage setting on. No visitor data.
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends usage events to IPScanner.
 */
final class IPScanner_Telemetry {

	const CRON    = 'ipscanner_heartbeat';
	const VERSION = 'ipscanner_version';

	/**
	 * Settings.
	 *
	 * @var IPScanner_Settings
	 */
	private $settings;

	/**
	 * Set while the plugin itself writes the settings, so the write is not
	 * reported a second time as a settings change.
	 *
	 * @var bool
	 */
	public static $quiet = false;

	/**
	 * Constructor.
	 *
	 * @param IPScanner_Settings $settings Settings.
	 */
	public function __construct( IPScanner_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Adds the hooks.
	 */
	public function register() {
		add_action( self::CRON, array( $this, 'heartbeat' ) );
		add_action( 'update_option_' . IPScanner_Settings::OPTION, array( $this, 'settings_changed' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_updated' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Whether events may be sent with this key.
	 *
	 * @param string|null $key API key, or the saved one.
	 * @return bool
	 */
	public function enabled( $key = null ) {
		$key = null === $key ? $this->settings->api_key() : $key;
		return '' !== (string) $key && (bool) $this->settings->get( 'share_usage' );
	}

	/**
	 * Sends one event without waiting for the answer.
	 *
	 * @param string      $name  Event name, one of the wp_* names IPScanner accepts.
	 * @param array       $props Flat properties.
	 * @param string|null $key   API key to send with, or the saved one.
	 */
	public function send( $name, array $props = array(), $key = null ) {
		$key = null === $key ? $this->settings->api_key() : (string) $key;
		if ( ! $this->enabled( $key ) ) {
			return;
		}
		$props = array_merge(
			array(
				'plugin_version' => IPSCANNER_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'site_id'        => (string) $this->settings->get( 'site_id' ),
				'host'           => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			),
			$props
		);
		wp_remote_post(
			IPScanner_Connect::app_base() . '/api/wordpress/events',
			array(
				'timeout'     => 2,
				'blocking'    => false,
				'redirection' => 0,
				'user-agent'  => 'ipscanner-wordpress/' . IPSCANNER_VERSION,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'events' => array(
							array(
								'name'  => $name,
								'props' => $props,
							),
						),
					)
				),
			)
		);
	}

	/**
	 * The settings an event reports: what is protected and how.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	public static function summary( array $s ) {
		return array(
			'mode'          => (string) $s['mode'],
			'areas'         => array_values( (array) $s['areas'] ),
			'block_classes' => array_values( (array) $s['block_classes'] ),
			'has_site_id'   => '' !== (string) $s['site_id'],
			'ip_source'     => (string) $s['ip_source'],
		);
	}

	/**
	 * Reports a settings form save: which settings changed, and a key added or removed.
	 *
	 * @param mixed $old Previous value.
	 * @param mixed $value New value.
	 */
	public function settings_changed( $old, $value ) {
		if ( self::$quiet || ! is_array( $value ) ) {
			return;
		}
		$old     = array_merge( IPScanner_Settings::defaults(), is_array( $old ) ? $old : array() );
		$value   = array_merge( IPScanner_Settings::defaults(), $value );
		$changed = array();
		foreach ( array( 'mode', 'areas', 'block_classes', 'site_id', 'allowlist', 'allow_admins', 'ip_source', 'trusted_proxies', 'block_message', 'show_credit', 'timeout', 'share_usage' ) as $field ) {
			if ( $old[ $field ] != $value[ $field ] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Arrays and numbers from the form.
				$changed[] = $field;
			}
		}
		$this->settings->flush();
		if ( '' === (string) $old['api_key'] && '' !== (string) $value['api_key'] ) {
			$this->send( 'wp_connected', array( 'method' => 'paste' ), $value['api_key'] );
		} elseif ( '' !== (string) $old['api_key'] && '' === (string) $value['api_key'] ) {
			$this->send_with( $old, 'wp_disconnected', array( 'method' => 'settings' ) );
			return;
		}
		if ( $changed ) {
			$this->send( 'wp_settings_saved', array_merge( array( 'changed' => $changed ), self::summary( $value ) ) );
		}
	}

	/**
	 * Sends with a key and Site ID that are no longer saved (a key just removed).
	 *
	 * @param array  $s     Settings the event belongs to.
	 * @param string $name  Event name.
	 * @param array  $props Properties.
	 */
	public function send_with( array $s, $name, array $props ) {
		$props['site_id'] = (string) $s['site_id'];
		$this->send( $name, $props, (string) $s['api_key'] );
	}

	/**
	 * Reports a plugin update once, on the first admin page after it.
	 */
	public function maybe_updated() {
		$from = (string) get_option( self::VERSION, '' );
		if ( IPSCANNER_VERSION === $from ) {
			return;
		}
		update_option( self::VERSION, IPSCANNER_VERSION, false );
		if ( '' !== $from ) {
			$this->send(
				'wp_updated',
				array(
					'from' => $from,
					'to'   => IPSCANNER_VERSION,
				)
			);
		}
	}

	/**
	 * Daily: the last 24 hours, what is protected and the environment.
	 */
	public function heartbeat() {
		$totals = IPScanner_Stats::last_day();
		$this->send(
			'wp_heartbeat',
			array_merge(
				self::summary( $this->settings->all() ),
				array(
					'checked'     => (int) $totals['checked'],
					'blocked'     => (int) $totals['blocked'],
					'would_block' => (int) $totals['would'],
					'top'         => (string) $totals['top'],
					'php_version' => PHP_VERSION,
					'locale'      => get_locale(),
					'multisite'   => is_multisite(),
					'woocommerce' => class_exists( 'WooCommerce' ),
					'cf7'         => defined( 'WPCF7_VERSION' ),
					'wpforms'     => function_exists( 'wpforms' ),
				)
			)
		);
	}

	/**
	 * Plugin deactivated: one last event, and the daily job removed.
	 */
	public static function deactivate() {
		$telemetry = new self( new IPScanner_Settings() );
		$telemetry->send( 'wp_deactivated' );
		wp_clear_scheduled_hook( self::CRON );
	}
}
