<?php
/**
 * Loads the plugin classes that run without WordPress.
 *
 * @package IPScanner
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'IPSCANNER_VERSION', 'test' );

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/class-ipscanner-ip.php';
require_once __DIR__ . '/../includes/class-ipscanner-request.php';
require_once __DIR__ . '/../includes/class-ipscanner-policy.php';
require_once __DIR__ . '/../includes/class-ipscanner-api.php';
