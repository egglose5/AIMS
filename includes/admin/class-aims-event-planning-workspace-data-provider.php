<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Planning_Workspace_Data_Provider {
	private $service;
	private $model;

	public function __construct( AIMS_Event_Planning_Workspace_Service $service = null ) {
		$this->service = $service ?: new AIMS_Event_Planning_Workspace_Service();
	}

	public function get_page_model(): array {
		if ( null === $this->model ) {
			$this->model = $this->service->get_page_model( $_GET );
		}

		return $this->model;
	}

	public function get_authorized_events(): array {
		$model = $this->get_page_model();

		return (array) ( $model['authorized_events'] ?? array() );
	}

	public function get_selected_event(): array {
		$model = $this->get_page_model();

		return is_array( $model['selected_event'] ?? null ) ? $model['selected_event'] : array();
	}

	public function get_workspace(): array {
		$model = $this->get_page_model();

		return (array) ( $model['workspace'] ?? array() );
	}

	public function get_selection_message(): string {
		$model = $this->get_page_model();

		return (string) ( $model['selection_message'] ?? '' );
	}
}
