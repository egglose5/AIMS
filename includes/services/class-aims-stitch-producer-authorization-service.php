<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Stitch_Producer_Authorization_Service {
	public const RESP_STITCH_ORDER_MANAGEMENT = 'stitch_order_management';

	private $assignments;

	public function __construct( AIMS_Responsibility_Assignment_Repository $assignments = null ) {
		$this->assignments = $assignments ?: new AIMS_Responsibility_Assignment_Repository();
	}

	public function can_manage_stitch_orders( int $user_id = 0 ): bool {
		$user_id = $this->resolve_user_id( $user_id );

		if ( $user_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'user_can' ) && user_can( $user_id, AIMS_Capabilities::CAP_MANAGE_STITCH_ORDERS ) ) {
			return true;
		}

		if ( function_exists( 'current_user_can' ) && function_exists( 'get_current_user_id' ) && (int) get_current_user_id() === $user_id && current_user_can( AIMS_Capabilities::CAP_MANAGE_STITCH_ORDERS ) ) {
			return true;
		}

		return $this->assignments->user_has_responsibility( $user_id, self::RESP_STITCH_ORDER_MANAGEMENT );
	}

	private function resolve_user_id( int $user_id ): int {
		if ( $user_id > 0 ) {
			return $user_id;
		}

		if ( function_exists( 'get_current_user_id' ) ) {
			return (int) get_current_user_id();
		}

		return 0;
	}
}
