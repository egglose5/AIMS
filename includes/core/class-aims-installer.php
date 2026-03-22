<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Installer {
	private $schema;

	public function __construct( AIMS_Schema $schema ) {
		$this->schema = $schema;
	}

	public function maybe_install(): void {
		$installed_version = (string) get_option( AIMS_Plugin::OPTION_SCHEMA_VERSION, '' );

		if ( version_compare( $installed_version, AIMS_Plugin::SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		$this->install();
	}

	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $this->schema->get_table_definitions() as $sql ) {
			dbDelta( $sql );
		}

		update_option( AIMS_Plugin::OPTION_SCHEMA_VERSION, AIMS_Plugin::SCHEMA_VERSION, false );
		update_option( AIMS_Plugin::OPTION_INSTALLED_AT, current_time( 'mysql' ), false );
	}

	public function uninstall(): void {
		$this->schema->drop_tables();
		$this->cleanup_options();
	}

	public function cleanup_options(): void {
		foreach ( AIMS_Plugin::get_option_keys() as $option_name ) {
			delete_option( $option_name );
		}
	}
}
