<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Queue_Data_Provider {
	private $workflow_service;

	public function __construct( AIMS_Stitch_Workflow_Service $workflow_service = null ) {
		$this->workflow_service = $workflow_service ?: new AIMS_Stitch_Workflow_Service(
			new AIMS_Stitch_Job_Repository(),
			new AIMS_Audit_Service()
		);
	}

	public function get_rows(): array {
		return $this->workflow_service->get_queue_rows(
			array(
				'limit' => 50,
			)
		);
	}

	public function get_summary(): array {
		return $this->workflow_service->get_summary();
	}

	public function get_status_options(): array {
		return $this->workflow_service->get_status_options();
	}

	public function get_workflow_service(): AIMS_Stitch_Workflow_Service {
		return $this->workflow_service;
	}
}
