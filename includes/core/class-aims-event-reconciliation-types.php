<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Event_Reconciliation_Types {
	public const SNAPSHOT_PLANNED = 'planned';
	public const SNAPSHOT_ACTUAL = 'actual';
	public const SNAPSHOT_COMPARATIVE = 'comparative';

	public const STATUS_PENDING = 'pending';
	public const STATUS_RECONCILED = 'reconciled';
	public const STATUS_VARIANCE_ACCEPTED = 'variance_accepted';

	public const DISCREPANCY_INVENTORY = 'inventory';
	public const DISCREPANCY_FINANCIAL = 'financial';
	public const DISCREPANCY_VENDOR = 'vendor';
	public const DISCREPANCY_MOVEMENT = 'movement';

	public const SEVERITY_INFO = 'info';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_CRITICAL = 'critical';

	public static function allowed_snapshot_types(): array {
		return array(
			self::SNAPSHOT_PLANNED,
			self::SNAPSHOT_ACTUAL,
			self::SNAPSHOT_COMPARATIVE,
		);
	}

	public static function allowed_statuses(): array {
		return array(
			self::STATUS_PENDING,
			self::STATUS_RECONCILED,
			self::STATUS_VARIANCE_ACCEPTED,
		);
	}

	public static function allowed_discrepancy_types(): array {
		return array(
			self::DISCREPANCY_INVENTORY,
			self::DISCREPANCY_FINANCIAL,
			self::DISCREPANCY_VENDOR,
			self::DISCREPANCY_MOVEMENT,
		);
	}

	public static function allowed_severity_levels(): array {
		return array(
			self::SEVERITY_INFO,
			self::SEVERITY_WARNING,
			self::SEVERITY_CRITICAL,
		);
	}

	public static function normalize_snapshot_type( string $type ): string {
		$type = sanitize_key( $type );

		return in_array( $type, self::allowed_snapshot_types(), true ) ? $type : self::SNAPSHOT_PLANNED;
	}

	public static function normalize_status( string $status ): string {
		$status = sanitize_key( $status );

		return in_array( $status, self::allowed_statuses(), true ) ? $status : self::STATUS_PENDING;
	}

	public static function normalize_discrepancy_type( string $type ): string {
		$type = sanitize_key( $type );

		return in_array( $type, self::allowed_discrepancy_types(), true ) ? $type : self::DISCREPANCY_INVENTORY;
	}

	public static function normalize_severity( string $severity ): string {
		$severity = sanitize_key( $severity );

		return in_array( $severity, self::allowed_severity_levels(), true ) ? $severity : self::SEVERITY_INFO;
	}
}
