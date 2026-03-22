<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIMS_Schema {
	public function get_table_names(): array {
		global $wpdb;

		return array(
			$wpdb->prefix . 'aims_vendors',
			$wpdb->prefix . 'aims_vendor_user_access',
			$wpdb->prefix . 'aims_customers',
			$wpdb->prefix . 'aims_customer_addresses',
			$wpdb->prefix . 'aims_events',
			$wpdb->prefix . 'aims_event_expenses',
			$wpdb->prefix . 'aims_vendor_event_assignments',
			$wpdb->prefix . 'aims_stitch_jobs',
			$wpdb->prefix . 'aims_inventory_buckets',
			$wpdb->prefix . 'aims_inventory_movements',
			$wpdb->prefix . 'aims_sale_fulfillment_allocations',
			$wpdb->prefix . 'aims_product_cost_rules',
			$wpdb->prefix . 'aims_sync_runs',
			$wpdb->prefix . 'aims_sync_actions',
			$wpdb->prefix . 'aims_square_import_queue',
			$wpdb->prefix . 'aims_square_sales',
		);
	}

	public function get_table_definitions(): array {
		global $wpdb;

		$charset_collate         = $wpdb->get_charset_collate();
		$vendors_table           = $wpdb->prefix . 'aims_vendors';
		$vendor_access_table     = $wpdb->prefix . 'aims_vendor_user_access';
		$customers_table         = $wpdb->prefix . 'aims_customers';
		$customer_addresses_table = $wpdb->prefix . 'aims_customer_addresses';
		$events_table            = $wpdb->prefix . 'aims_events';
		$event_expenses_table    = $wpdb->prefix . 'aims_event_expenses';
		$event_assignments_table = $wpdb->prefix . 'aims_vendor_event_assignments';
		$stitch_jobs_table       = $wpdb->prefix . 'aims_stitch_jobs';
		$inventory_buckets_table = $wpdb->prefix . 'aims_inventory_buckets';
		$inventory_moves_table   = $wpdb->prefix . 'aims_inventory_movements';
		$fulfillment_allocations_table = $wpdb->prefix . 'aims_sale_fulfillment_allocations';
		$product_cost_rules_table = $wpdb->prefix . 'aims_product_cost_rules';
		$sync_runs_table         = $wpdb->prefix . 'aims_sync_runs';
		$sync_actions_table      = $wpdb->prefix . 'aims_sync_actions';
		$square_queue_table      = $wpdb->prefix . 'aims_square_import_queue';
		$square_sales_table      = $wpdb->prefix . 'aims_square_sales';

		return array(
			"CREATE TABLE {$vendors_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_code varchar(64) NOT NULL DEFAULT '',
				vendor_name varchar(255) NOT NULL,
				status varchar(32) NOT NULL DEFAULT 'active',
				square_location_id varchar(191) NOT NULL DEFAULT '',
				default_bucket_code varchar(191) NOT NULL DEFAULT '',
				commission_rate decimal(7,4) NOT NULL DEFAULT 0.0000,
				phone_number varchar(50) NOT NULL DEFAULT '',
				email_address varchar(190) NOT NULL DEFAULT '',
				address_line_1 varchar(255) NOT NULL DEFAULT '',
				address_line_2 varchar(255) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				state_region varchar(100) NOT NULL DEFAULT '',
				postal_code varchar(30) NOT NULL DEFAULT '',
				country_code varchar(2) NOT NULL DEFAULT 'US',
				notes longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY vendor_code (vendor_code),
				UNIQUE KEY vendor_name (vendor_name),
				KEY status (status),
				KEY square_location_id (square_location_id),
				KEY default_bucket_code (default_bucket_code)
			) {$charset_collate};",
			"CREATE TABLE {$vendor_access_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				access_role varchar(50) NOT NULL DEFAULT 'viewer',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY vendor_user (vendor_id, user_id),
				KEY user_id (user_id),
				KEY access_role (access_role)
			) {$charset_collate};",
			"CREATE TABLE {$customers_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				square_customer_id varchar(191) NOT NULL DEFAULT '',
				first_name varchar(100) NOT NULL DEFAULT '',
				last_name varchar(100) NOT NULL DEFAULT '',
				company_name varchar(255) NOT NULL DEFAULT '',
				email_address varchar(190) NOT NULL DEFAULT '',
				phone_number varchar(50) NOT NULL DEFAULT '',
				notes longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY square_customer_id (square_customer_id),
				KEY email_address (email_address),
				KEY phone_number (phone_number)
			) {$charset_collate};",
			"CREATE TABLE {$customer_addresses_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				customer_id bigint(20) unsigned NOT NULL,
				square_address_id varchar(191) NOT NULL DEFAULT '',
				address_type varchar(20) NOT NULL DEFAULT 'shipping',
				is_primary tinyint(1) NOT NULL DEFAULT 0,
				address_line_1 varchar(255) NOT NULL DEFAULT '',
				address_line_2 varchar(255) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				state_region varchar(100) NOT NULL DEFAULT '',
				postal_code varchar(30) NOT NULL DEFAULT '',
				country_code varchar(2) NOT NULL DEFAULT 'US',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY square_address_id (square_address_id),
				KEY customer_id (customer_id),
				KEY address_type (address_type),
				KEY is_primary (is_primary)
			) {$charset_collate};",
			"CREATE TABLE {$events_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_code varchar(64) NOT NULL DEFAULT '',
				event_name varchar(255) NOT NULL,
				status varchar(32) NOT NULL DEFAULT 'draft',
				start_date date NOT NULL,
				end_date date NOT NULL,
				location_name varchar(191) NOT NULL DEFAULT '',
				square_location_id varchar(191) NOT NULL DEFAULT '',
				gross_sales_total decimal(20,2) NOT NULL DEFAULT 0.00,
				discount_total decimal(20,2) NOT NULL DEFAULT 0.00,
				tip_total decimal(20,2) NOT NULL DEFAULT 0.00,
				net_sales_total decimal(20,2) NOT NULL DEFAULT 0.00,
				vendor_payout_total decimal(20,2) NOT NULL DEFAULT 0.00,
				expense_total decimal(20,2) NOT NULL DEFAULT 0.00,
				profit_total decimal(20,2) NOT NULL DEFAULT 0.00,
				notes longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_code (event_code),
				KEY status (status),
				KEY start_date (start_date),
				KEY end_date (end_date),
				KEY square_location_id (square_location_id)
			) {$charset_collate};",
			"CREATE TABLE {$event_expenses_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				expense_type varchar(50) NOT NULL DEFAULT 'other',
				amount decimal(20,2) NOT NULL DEFAULT 0.00,
				note text NULL,
				incurred_at datetime NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event_id (event_id),
				KEY vendor_id (vendor_id),
				KEY expense_type (expense_type),
				KEY incurred_at (incurred_at)
			) {$charset_collate};",
			"CREATE TABLE {$event_assignments_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				vendor_id bigint(20) unsigned NOT NULL,
				assignment_status varchar(32) NOT NULL DEFAULT 'assigned',
				commission_rate decimal(7,4) NOT NULL DEFAULT 0.0000,
				fulfillment_status varchar(32) NOT NULL DEFAULT 'pending',
				notes longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_vendor (event_id, vendor_id),
				KEY vendor_id (vendor_id),
				KEY assignment_status (assignment_status),
				KEY fulfillment_status (fulfillment_status)
			) {$charset_collate};",
			"CREATE TABLE {$stitch_jobs_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_code varchar(64) NOT NULL DEFAULT '',
				vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(32) NOT NULL DEFAULT 'queued',
				priority varchar(20) NOT NULL DEFAULT 'normal',
				due_at datetime NULL DEFAULT NULL,
				notes longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY job_code (job_code),
				KEY vendor_id (vendor_id),
				KEY event_id (event_id),
				KEY assigned_user_id (assigned_user_id),
				KEY status (status)
			) {$charset_collate};",
			"CREATE TABLE {$inventory_buckets_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				bucket_code varchar(191) NOT NULL DEFAULT '',
				bucket_name varchar(191) NOT NULL DEFAULT '',
				quantity decimal(20,4) NOT NULL DEFAULT 0.0000,
				reserved_quantity decimal(20,4) NOT NULL DEFAULT 0.0000,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY vendor_product_bucket (vendor_id, product_id, bucket_code),
				KEY vendor_id (vendor_id),
				KEY product_id (product_id),
				KEY bucket_code (bucket_code)
			) {$charset_collate};",
			"CREATE TABLE {$inventory_moves_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				movement_uuid varchar(36) NOT NULL DEFAULT '',
				reference_type varchar(50) NOT NULL DEFAULT '',
				reference_id varchar(191) NOT NULL DEFAULT '',
				vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				stitch_job_id bigint(20) unsigned NOT NULL DEFAULT 0,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				bucket_code varchar(191) NOT NULL DEFAULT '',
				movement_type varchar(50) NOT NULL DEFAULT '',
				quantity_delta decimal(20,4) NOT NULL DEFAULT 0.0000,
				applied_by bigint(20) unsigned NOT NULL DEFAULT 0,
				note text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY movement_uuid (movement_uuid),
				UNIQUE KEY apply_once_ref (reference_type, reference_id, product_id, bucket_code, movement_type),
				KEY vendor_id (vendor_id),
				KEY event_id (event_id),
				KEY stitch_job_id (stitch_job_id),
				KEY product_id (product_id),
				KEY bucket_code (bucket_code),
				KEY movement_type (movement_type)
			) {$charset_collate};",
			"CREATE TABLE {$fulfillment_allocations_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				square_sale_id bigint(20) unsigned NOT NULL DEFAULT 0,
				square_order_id varchar(191) NOT NULL DEFAULT '',
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source_bucket_code varchar(191) NOT NULL DEFAULT '',
				allocation_type varchar(50) NOT NULL DEFAULT 'event_stock',
				allocation_status varchar(32) NOT NULL DEFAULT 'allocated',
				quantity decimal(20,4) NOT NULL DEFAULT 0.0000,
				notes longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY square_sale_id (square_sale_id),
				KEY square_order_id (square_order_id),
				KEY product_id (product_id),
				KEY vendor_id (vendor_id),
				KEY event_id (event_id),
				KEY source_bucket_code (source_bucket_code),
				KEY allocation_type (allocation_type),
				KEY allocation_status (allocation_status)
			) {$charset_collate};",
			"CREATE TABLE {$product_cost_rules_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				assignment_type varchar(20) NOT NULL DEFAULT 'product',
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				category_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				stitch_job_type varchar(50) NOT NULL DEFAULT '',
				unit_cost decimal(20,4) NOT NULL DEFAULT 0.0000,
				stitching_price decimal(20,4) NOT NULL DEFAULT 0.0000,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY assignment_type (assignment_type),
				KEY product_id (product_id),
				KEY category_term_id (category_term_id),
				KEY vendor_id (vendor_id),
				KEY is_active (is_active)
			) {$charset_collate};",
			"CREATE TABLE {$sync_runs_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_system varchar(50) NOT NULL DEFAULT '',
				started_at datetime NOT NULL,
				completed_at datetime NULL DEFAULT NULL,
				sync_watermark varchar(64) NOT NULL DEFAULT '',
				success tinyint(1) NOT NULL DEFAULT 0,
				processed_records int(11) unsigned NOT NULL DEFAULT 0,
				skipped_records int(11) unsigned NOT NULL DEFAULT 0,
				error_count int(11) unsigned NOT NULL DEFAULT 0,
				message text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY source_system (source_system),
				KEY completed_at (completed_at),
				KEY success (success)
			) {$charset_collate};",
			"CREATE TABLE {$sync_actions_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				external_record_id varchar(191) NOT NULL DEFAULT '',
				action_type varchar(50) NOT NULL DEFAULT '',
				entity_type varchar(50) NOT NULL DEFAULT '',
				entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'success',
				quantity_delta decimal(20,4) NOT NULL DEFAULT 0.0000,
				message text NULL,
				occurred_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY run_id (run_id),
				KEY external_record_id (external_record_id),
				KEY action_type (action_type),
				KEY entity_type (entity_type),
				KEY status (status)
			) {$charset_collate};",
			"CREATE TABLE {$square_queue_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				square_order_id varchar(191) NOT NULL DEFAULT '',
				location_id varchar(191) NOT NULL DEFAULT '',
				import_status varchar(32) NOT NULL DEFAULT 'pending',
				payload longtext NULL,
				imported_at datetime NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY square_order_id (square_order_id),
				KEY location_id (location_id),
				KEY import_status (import_status)
			) {$charset_collate};",
			"CREATE TABLE {$square_sales_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				square_order_id varchar(191) NOT NULL DEFAULT '',
				square_line_item_uid varchar(191) NOT NULL DEFAULT '',
				square_location_id varchar(191) NOT NULL DEFAULT '',
				square_customer_id varchar(191) NOT NULL DEFAULT '',
				customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				shipping_address_id bigint(20) unsigned NOT NULL DEFAULT 0,
				billing_address_id bigint(20) unsigned NOT NULL DEFAULT 0,
				vendor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_id bigint(20) unsigned NOT NULL DEFAULT 0,
				woo_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				woo_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				sku varchar(191) NOT NULL DEFAULT '',
				source varchar(50) NOT NULL DEFAULT 'square',
				delivery_method varchar(32) NOT NULL DEFAULT 'pickup',
				shipping_amount decimal(20,2) NOT NULL DEFAULT 0.00,
				discount_amount decimal(20,2) NOT NULL DEFAULT 0.00,
				discount_label varchar(191) NOT NULL DEFAULT '',
				tip_amount decimal(20,2) NOT NULL DEFAULT 0.00,
				fulfillment_status varchar(32) NOT NULL DEFAULT 'pending',
				quantity decimal(20,4) NOT NULL DEFAULT 0.0000,
				gross_amount decimal(20,2) NOT NULL DEFAULT 0.00,
				net_amount decimal(20,2) NOT NULL DEFAULT 0.00,
				payload longtext NULL,
				sold_at datetime NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY square_line (square_order_id, square_line_item_uid),
				KEY square_location_id (square_location_id),
				KEY square_customer_id (square_customer_id),
				KEY customer_id (customer_id),
				KEY shipping_address_id (shipping_address_id),
				KEY billing_address_id (billing_address_id),
				KEY vendor_id (vendor_id),
				KEY event_id (event_id),
				KEY woo_order_id (woo_order_id),
				KEY woo_product_id (woo_product_id),
				KEY sku (sku),
				KEY delivery_method (delivery_method),
				KEY shipping_amount (shipping_amount),
				KEY discount_amount (discount_amount),
				KEY tip_amount (tip_amount),
				KEY fulfillment_status (fulfillment_status),
				KEY sold_at (sold_at)
			) {$charset_collate};",
		);
	}

	public function drop_tables(): void {
		global $wpdb;

		foreach ( $this->get_table_names() as $table_name ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
