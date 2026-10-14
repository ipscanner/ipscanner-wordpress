<?php
/**
 * Plugin Name:       IPScanner – Block Bots, VPNs & Proxies
 * Plugin URI:        https://github.com/ipscanner/ipscanner-wordpress
 * Description:       Checks visitors with IPScanner and blocks bots, Tor, VPN and proxy traffic on your site, login, registration, comments and checkout.
 * Version:           0.1.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            IPScanner
 * Author URI:        https://ipscanner.io
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ipscanner-bot-blocker
 * Domain Path:       /languages
 *
 * @package IPScanner
 */

defined( 'ABSPATH' ) || exit;

define( 'IPSCANNER_VERSION', '0.1.3' );
define( 'IPSCANNER_FILE', __FILE__ );
define( 'IPSCANNER_DIR', plugin_dir_path( __FILE__ ) );

require_once IPSCANNER_DIR . 'includes/class-ipscanner-ip.php';
require_once IPSCANNER_DIR . 'includes/class-ipscanner-request.php';
require_once IPSCANNER_DIR . 'includes/class-ipscanner-policy.php';
require_once IPSCANNER_DIR . 'includes/class-ipscanner-settings.php';
require_once IPSCANNER_DIR . 'includes/class-ipscanner-log.php';
require_once IPSCANNER_DIR . 'includes/class-ipscanner-stats.php';
require_once IPSCANNER_DIR . 'includes/class-ipscanner-api.php';
require_once IPSCANNER_DIR . 'includes/class-ipscanner-guard.php';
require_once IPSCANNER_DIR . 'includes/class-ipscanner-admin.php';

/**
 * Wires the plugin up.
 */
function ipscanner_init() {
	$settings = new IPScanner_Settings();
	$api      = new IPScanner_API( $settings );
	( new IPScanner_Guard( $settings, $api ) )->register();
	if ( is_admin() ) {
		( new IPScanner_Admin( $settings, $api ) )->register();
	}
}
add_action( 'plugins_loaded', 'ipscanner_init' );
