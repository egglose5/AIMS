<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Loader {
	public static function init(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
		require_once AIMS_PLUGIN_PATH . 'includes/core/class-aims-plugin.php';
	}

	private static function autoload( string $class_name ): void {
		if ( strpos( $class_name, 'AIMS_' ) !== 0 ) {
			return;
		}

		$relative_class = strtolower( str_replace( '_', '-', substr( $class_name, 5 ) ) );
		$paths          = array(
			AIMS_PLUGIN_PATH . 'includes/core/class-aims-' . $relative_class . '.php',
			AIMS_PLUGIN_PATH . 'includes/repositories/class-aims-' . $relative_class . '.php',
			AIMS_PLUGIN_PATH . 'includes/services/class-aims-' . $relative_class . '.php',
			AIMS_PLUGIN_PATH . 'includes/admin/class-aims-' . $relative_class . '.php',
			AIMS_PLUGIN_PATH . 'includes/modules/vendor-manage/class-aims-' . $relative_class . '.php',
			AIMS_PLUGIN_PATH . 'includes/modules/event-manage/class-aims-' . $relative_class . '.php',
			AIMS_PLUGIN_PATH . 'includes/modules/stitch-manage/class-aims-' . $relative_class . '.php',
			AIMS_PLUGIN_PATH . 'includes/modules/square-sync/class-aims-' . $relative_class . '.php',
			AIMS_PLUGIN_PATH . 'includes/modules/reports-analytics/class-aims-' . $relative_class . '.php',
		);

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}

