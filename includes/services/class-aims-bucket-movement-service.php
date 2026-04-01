<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Bucket_Movement_Service {
	private $movements;
	private $positions;
	private $auth_service;
	private $person_identity;
	private $movement_lifecycle;

	public function __construct( $movements, $positions = null, AIMS_Responsibility_Authorization_Service $auth_service = null, AIMS_Person_Identity_Service $person_identity = null, AIMS_Movement_Lifecycle_Service $movement_lifecycle = null ) {
		$this->movements          = $movements;
		$this->positions          = $positions;
		$this->auth_service       = $auth_service ?: new AIMS_Responsibility_Authorization_Service();
		$this->person_identity    = $person_identity ?: new AIMS_Person_Identity_Service();
		$this->movement_lifecycle = $movement_lifecycle ?: new AIMS_Movement_Lifecycle_Service();
	}

	public function record_stock_in( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? 'stock_in';

		return $this->record_movement( $data );
	}

	public function record_stock_out( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? 'stock_out';

		return $this->record_movement( $data );
	}

	public function record_transfer( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? AIMS_Inventory_Movement_Events::WAREHOUSE_TRANSFER;

		return $this->record_movement( $data );
	}

	public function record_event_load_out( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? 'event_load_out';

		return $this->record_movement( $data );
	}

	public function record_event_return( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? AIMS_Inventory_Movement_Events::RETURN_FROM_EVENT;

		return $this->record_movement( $data );
	}

	public function record_pick( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? 'warehouse_pick';

		return $this->record_movement( $data );
	}

	public function record_adjustment( array $data ) {
		$data['movement_type'] = $data['movement_type'] ?? 'adjustment';

		return $this->record_movement( $data );
	}

	public function record_movement( array $data ) {
		$data['applied_by'] = (int) ( $data['applied_by'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) );

		try {
			$core_service = AIMS_Core_Movement_Bridge::make_service(
				$this->movements,
				$this->positions,
				$this->auth_service,
				$this->person_identity,
				$this->movement_lifecycle
			);

			return $core_service->recordMovement( $data );
		} catch ( \AmesCore\Inventory\MovementException $exception ) {
			return new WP_Error(
				$exception->getErrorCode(),
				$exception->getMessage(),
				$exception->getContext()
			);
		}
	}
}
