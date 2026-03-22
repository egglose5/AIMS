<?php
/**
 * Plugin Name:       AIMS
 * Plugin URI:        https://example.com
 * Description:       AIMS is a modular operations engine for vendors, events, stitching, Square sync, and reporting.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your Name
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-man-sys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIMS_VERSION', '0.1.0' );
define( 'AIMS_PLUGIN_FILE', __FILE__ );
define( 'AIMS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'AIMS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'AIMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AIMS_PLUGIN_PATH . 'includes/core/class-aims-loader.php';

AIMS_Loader::init();

register_activation_hook( __FILE__, array( 'AIMS_Plugin', 'activate' ) );

function aims(): AIMS_Plugin {
	return AIMS_Plugin::instance();
}

aims()->boot();

