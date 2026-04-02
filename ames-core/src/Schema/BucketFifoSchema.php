<?php

declare( strict_types=1 );

namespace AmesCore\Schema;

final class BucketFifoSchema {
	public const BUCKET_TABLE = 'aims_core_buckets';
	public const LOT_TABLE = 'aims_core_bucket_lots';
	public const CUSTODY_TABLE = 'aims_core_bucket_custody_movements';
	public const ALLOCATION_TABLE = 'aims_core_fifo_allocations';

	/**
	 * @return array<int, string>
	 */
	public static function migrationSql(): array {
		return array(
			'CREATE TABLE IF NOT EXISTS "' . self::BUCKET_TABLE . '" (
				"id" INTEGER PRIMARY KEY AUTOINCREMENT,
				"bucket_code" TEXT NOT NULL UNIQUE,
				"bucket_label" TEXT NOT NULL DEFAULT "",
				"bucket_type" TEXT NOT NULL DEFAULT "physical",
				"status" TEXT NOT NULL DEFAULT "active",
				"show_id" TEXT NOT NULL DEFAULT "",
				"current_location" TEXT NOT NULL DEFAULT "",
				"current_custody" TEXT NOT NULL DEFAULT "",
				"created_at" TEXT NOT NULL,
				"updated_at" TEXT NOT NULL
			)',
			'CREATE INDEX IF NOT EXISTS "idx_aims_core_buckets_location" ON "' . self::BUCKET_TABLE . '" ("current_location")',
			'CREATE INDEX IF NOT EXISTS "idx_aims_core_buckets_custody" ON "' . self::BUCKET_TABLE . '" ("current_custody")',
			'CREATE TABLE IF NOT EXISTS "' . self::LOT_TABLE . '" (
				"id" INTEGER PRIMARY KEY AUTOINCREMENT,
				"lot_uuid" TEXT NOT NULL UNIQUE,
				"bucket_code" TEXT NOT NULL,
				"sku" TEXT NOT NULL,
				"show_id" TEXT NOT NULL DEFAULT "",
				"receipt_reference" TEXT NOT NULL DEFAULT "",
				"source_reference" TEXT NOT NULL DEFAULT "",
				"original_quantity" REAL NOT NULL DEFAULT 0,
				"remaining_quantity" REAL NOT NULL DEFAULT 0,
				"unit_cost" REAL NOT NULL DEFAULT 0,
				"unit_cost_cents" INTEGER NOT NULL DEFAULT 0,
				"received_at" TEXT NOT NULL,
				"status" TEXT NOT NULL DEFAULT "active",
				"created_at" TEXT NOT NULL,
				"updated_at" TEXT NOT NULL
			)',
			'CREATE INDEX IF NOT EXISTS "idx_aims_core_bucket_lots_fifo" ON "' . self::LOT_TABLE . '" ("sku", "show_id", "received_at", "id")',
			'CREATE INDEX IF NOT EXISTS "idx_aims_core_bucket_lots_bucket" ON "' . self::LOT_TABLE . '" ("bucket_code")',
			'CREATE TABLE IF NOT EXISTS "' . self::CUSTODY_TABLE . '" (
				"id" INTEGER PRIMARY KEY AUTOINCREMENT,
				"movement_uuid" TEXT NOT NULL UNIQUE,
				"bucket_code" TEXT NOT NULL,
				"from_location" TEXT NOT NULL DEFAULT "",
				"to_location" TEXT NOT NULL DEFAULT "",
				"from_custody" TEXT NOT NULL DEFAULT "",
				"to_custody" TEXT NOT NULL DEFAULT "",
				"reference_type" TEXT NOT NULL DEFAULT "",
				"reference_id" TEXT NOT NULL DEFAULT "",
				"movement_type" TEXT NOT NULL DEFAULT "custody_transfer",
				"note" TEXT NOT NULL DEFAULT "",
				"occurred_at" TEXT NOT NULL,
				"created_at" TEXT NOT NULL
			)',
			'CREATE INDEX IF NOT EXISTS "idx_aims_core_bucket_custody_bucket" ON "' . self::CUSTODY_TABLE . '" ("bucket_code", "occurred_at")',
			'CREATE TABLE IF NOT EXISTS "' . self::ALLOCATION_TABLE . '" (
				"id" INTEGER PRIMARY KEY AUTOINCREMENT,
				"allocation_uuid" TEXT NOT NULL UNIQUE,
				"request_reference" TEXT NOT NULL DEFAULT "",
				"sku" TEXT NOT NULL,
				"show_id" TEXT NOT NULL DEFAULT "",
				"bucket_code" TEXT NOT NULL,
				"lot_uuid" TEXT NOT NULL,
				"quantity" REAL NOT NULL DEFAULT 0,
				"movement_type" TEXT NOT NULL DEFAULT "fifo_pick",
				"amount_paid" REAL NOT NULL DEFAULT 0,
				"amount_paid_cents" INTEGER NOT NULL DEFAULT 0,
				"tax_amount" REAL NOT NULL DEFAULT 0,
				"tax_amount_cents" INTEGER NOT NULL DEFAULT 0,
				"created_at" TEXT NOT NULL
			)',
			'CREATE INDEX IF NOT EXISTS "idx_aims_core_fifo_allocations_request" ON "' . self::ALLOCATION_TABLE . '" ("request_reference")',
		);
	}
}
