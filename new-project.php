<?php
/**
 * Plugin Name:       New Project
 * Plugin URI:        https://example.com
 * Description:       Starter plugin scaffold for a new WordPress project.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Your Name
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       new-project
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NEW_PROJECT_VERSION', '0.1.0' );
define( 'NEW_PROJECT_FILE', __FILE__ );
define( 'NEW_PROJECT_PATH', plugin_dir_path( __FILE__ ) );
define( 'NEW_PROJECT_URL', plugin_dir_url( __FILE__ ) );

require_once NEW_PROJECT_PATH . 'includes/class-new-project.php';

function new_project(): New_Project {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new New_Project();
	}

	return $plugin;
}

new_project()->run();

