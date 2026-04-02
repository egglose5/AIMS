<?php
/**
 * Plugin Name:       AIMS
 * Plugin URI:        https://aims.local
 * Description:       AIMS thin client for the headless core API, with dashboard, settings, and remote sync controls. Provided "as is" without warranty.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            AIMS Team
 * Author URI:        https://aims.local
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       ai-man-sys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIMS_VERSION', '1.0.0' );
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

