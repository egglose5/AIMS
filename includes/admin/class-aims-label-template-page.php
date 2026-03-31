<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Label_Template_Page {
	public const PAGE_SLUG = 'aims-stitch-label-templates';

	private $data_provider;

	public function __construct( AIMS_Label_Template_Data_Provider $data_provider ) {
		$this->data_provider = $data_provider;
	}

	public function render(): void {
		$model = $this->data_provider->get_page_model();
		$template_path = AIMS_PLUGIN_PATH . 'templates/admin/label-template-settings.php';

		echo '<div class="wrap aims-label-template-settings">';
		echo '<h1>Stitch Labels</h1>';
		echo '<p>Configure the default stitch label layout and print labels with template-driven presentation. The built-in template mirrors the legacy 2in x 0.75in label flow while keeping the data layer separate from the print layout.</p>';

		if ( file_exists( $template_path ) ) {
			$label_template_page = $model;
			include $template_path;
		} else {
			echo '<div class="notice notice-error"><p>Label template settings view is unavailable.</p></div>';
		}

		echo '</div>';
	}
}
