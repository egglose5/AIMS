# AIMS

AIMS (`ai-man-sys`) is a modular WordPress operations plugin for vendor management, event management, stitch workflow, Square ingestion, and reporting.

This codebase is a full rebuild. Older plugins are reference material only and are not part of the runtime design, schema, or migration path.

## Phase 1 foundation

The repository currently provides:

- plugin bootstrap and class loader
- installer and schema registration
- explicit custom table definitions with indexes
- capability and admin menu shells
- one repository example
- one service example
- one vendor module foundation
- native module placeholders for future implementation
- ledger-first inventory movement backbone
- operational tables for customers, addresses, events, event expenses, assignments, stitch jobs, fulfillment allocations, and Square sales
- native product cost rule storage for COGS-based profitability

## Core rules

- Use custom tables for all AIMS internal entities.
- Do not use custom post types for vendors, events, stitch jobs, mappings, logs, or inventory.
- Keep WordPress users, WooCommerce products, and WooCommerce orders as native objects.
- Treat AIMS as the operations engine.
- Treat WooCommerce as the catalog and order projection layer.
- Treat Square as an integration source, not a source of truth.
- Write imported Square sales into AIMS tables first.
- Avoid any design that can double-apply stock changes.

## Safest next implementation phase

1. Expand the schema for event inventory rules, stitch queue items, and additional operational states.
2. Build native AIMS repositories and services for vendor buckets, event allocation, and backorder fulfillment.
3. Implement Square ingestion into AIMS queue and sync tables with dedupe, watermarking, logging, customer capture, and undo-safe stock application.
4. Add reporting repositories over AIMS sync and operational tables.
5. Add optional WooCommerce order projection only after AIMS-side reconciliation is stable.

## Operational backbone

AIMS now uses a ledger-first inventory design:

- `aims_inventory_movements` is the immutable stock movement ledger.
- `aims_inventory_buckets` is the current aggregate view per vendor/product/bucket.
- stock changes should go through `AIMS_Inventory_Service` only.
- apply-once protection is enforced by the unique movement reference key.
- `aims_customers` and `aims_customer_addresses` store Square customer and address data.
- `aims_events` now carries event-level financial summary fields for gross sales, net sales, vendor payouts, expenses, and profit.
- in-person discounts are stored per sale and rolled up into event-level discount totals.
- Square-integrated tips are stored separately from sales net and rolled up into event tip totals for staff gratuity reporting.
- `aims_event_expenses` stores tax and profitability expenses like booth fees, hotel, mileage, shipping, and other show costs.
- `aims_product_cost_rules` stores per-product and per-category cost mappings for COGS and profitability calculations.
- `aims_sale_fulfillment_allocations` stores event-stock and warehouse-backorder allocations.
- Square sales are intended to land in `aims_square_sales` before any optional WooCommerce projection.
- event automation now matches Square sales to events by Square location and sold-at date window, then recalculates event financials.
