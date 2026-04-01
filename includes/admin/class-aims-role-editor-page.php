<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Role_Editor_Page {
	public const PAGE_SLUG = AIMS_Role_Editor_Data_Provider::PAGE_SLUG;

	private $data_provider;

	public function __construct( AIMS_Role_Editor_Data_Provider $data_provider = null ) {
		$this->data_provider = $data_provider ?: new AIMS_Role_Editor_Data_Provider();
	}

	public function render(): void {
		$model         = $this->data_provider->get_page_model();
		$template_path = AIMS_PLUGIN_PATH . 'templates/admin/role-editor.php';

		echo '<div class="wrap aims-role-editor">';
		echo '<h1>AIMS Role Editor</h1>';
		echo '<p>Create website-local AIMS roles from built-in templates, then toggle AIMS capabilities the same way people expect from WordPress role-builder plugins. Built-in AIMS roles are template-only; create and edit custom roles instead of changing the built-ins directly.</p>';

		if ( file_exists( $template_path ) ) {
			$aims_role_editor = $model;
			include $template_path;
		} else {
			echo '<div class="notice notice-error"><p>Role editor view is unavailable.</p></div>';
		}

		echo '</div>';
	}
}
