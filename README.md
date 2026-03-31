# AIMS

AIMS (`ai-man-sys`) is a modular WordPress operations plugin for vendor management, event management, stitch workflow, Square ingestion, and reporting.

This codebase is a full rebuild. Older plugins are reference material only and are not part of the runtime design, schema, or migration path.

## Current build status

The repository currently provides:

- plugin bootstrap and class loader
- installer and schema registration
- explicit custom table definitions with indexes across events, demand, buckets, inventory, Square sync, attribution, and fulfillment
- capability and admin menu shells
- vendor, event, Square sync, and reporting module bootstraps
- ledger-first inventory movement backbone
- physical bucket, storage, and event-bucket assignment architecture
- Event Demand Intake v1 with account-linked request history
- curated public event projection layer with public catalog/detail shortcodes
- admin demand summary and public projection management pages
- Square queue/raw event/normalized sale/replay scaffolding
- capability-gated, nonce-protected replay/undo triggers with duplicate-request protection per sync run
- Sync Runs operator telemetry (last status, completed timestamp, processed rows, error totals)
- runtime assignment, attribution, sync effect, and exception table foundations
- native product cost rule storage for COGS-based profitability
- PHPUnit harness with passing first unit tests

## Core rules

- Use custom tables for all AIMS internal entities.
- Do not use custom post types for vendors, events, stitch jobs, mappings, logs, or inventory.
- Keep WordPress users, WooCommerce products, and WooCommerce orders as native objects.
- Treat WooCommerce as product truth.
- Treat AIMS as the operations, event planning, inventory, and physical control truth.
- Treat Square as payment and sales truth.
- Write imported Square sales into AIMS tables first.
- Avoid any design that can double-apply stock changes.
- Public event pages must never read directly from internal financial or operational tables.
- Public event output must come from the curated projection layer.
- Event Demand Intake v1 is a core product feature.
- Event demand is login-required.
- No guest demand requests.
- Event demand requests are planning-only, not reservation, payment, or order flows.
- AIMS is SKU-first operationally.
- BOPIS and reservations remain a separate future v2 add-on.
- Inventory is assigned to events only by explicit manager or supervisor planning action. Never automatically.

## Event planning model

- Event demand creates planning signals only.
- Event demand never assigns inventory automatically.
- Event demand never creates reservations, payments, or Square orders.
- Inventory is committed to an event only when a manager or supervisor explicitly assigns one or more physical buckets to that event.
- Event bucket assignment is the planning commitment record.
- Inventory movements are reserved for actual physical execution such as staging, load-out, transfer, return, and adjustment.

## Event execution model

- Assignment to a show can change execution status, but it does not systematically move stock.
- `staged` is a planning/readiness state and does not start an execution SLA clock.
- `loaded` / `in_transit` is the real-world departure point for event execution timing.
- `vendor_event_checkin` is the stock-at-event movement point.
- Return is the move-back point.
- Only physical movement events should write inventory ledger changes.
- Planning writes stay in event bucket assignment records, not movement tables.

## Warehouse telemetry model

- Warehouse/prep timestamps may be captured for internal KPI work without changing operational truth.
- Examples include `staged_at`, `picked_at`, `packed_at`, `loaded_at`, and planner/warehouse ownership markers.
- Warehouse telemetry is intended to support local internal KPIs and team-specific process analysis.
- Warehouse telemetry must not be treated as proof that stock physically arrived at an event.
- Assignment age metrics (Staged > 24h, Open > 8h) are pure KPI analytics — they do not trigger any operational action or movement.
- Stock movement is always driven by an explicit real-world action: `loaded` / `in_transit` for departure, `vendor_event_checkin` for stock-at-event, and `event_return` for return.
- No time-based rule or threshold writes an inventory ledger entry.

## Current workflow target

1. Manager or supervisor opens Event Planning.
2. The planner sees only events assigned to them or their subordinates.
3. The planner can filter by event scope, event search, bucket search, and planner ownership (all/me/subordinate planner).
4. AIMS shows event demand summary by SKU, planning summary metrics, warehouse telemetry, assignment timeline (with age-band analytics), team activity, currently assigned buckets, available buckets, and bucket contents.
5. The planner manually assigns buckets to the event (single or bulk, with optional delegation to subordinate planners).
6. AIMS writes planning assignment records and optional warehouse telemetry timestamps, but not inventory movement records.
7. Inventory movement records are created only when physical actions occur: `loaded`/`in_transit` (departure), `vendor_event_checkin` (stock-at-event), and `event_return` (return flows).
8. Age-band metrics (Staged > 24h, Open > 8h) are informational analytics only — planners may keep stock staged for days or weeks; the metric records that fact without implying a violation.

## Next implementation phase

1. Add explicit `loaded_at` / `in_transit_at` timestamps to assignment records so elapsed transit time can be tracked as an analytics dimension.
2. Extend execution-side exception visibility into planning (check-in failures, return anomalies) for faster intervention.
3. Expand Square replay and fulfillment wiring only after the planning and commitment workflow remains stable under team usage.
4. Keep optional WooCommerce order projection behind AIMS-side operational reconciliation.

## Upgrade path

- Follow the rollout and rollback procedure in `docs/upgrade-path.md`.

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
- `aims_event_bucket_assignments` is the point where inventory is committed to an event by human planning action.
- `aims_inventory_movements` should not be used to represent planning intent.
- `vendor_event_checkin` is the execution movement that marks stock arriving at the event.
- return movement records are the execution point for stock moving back from the event.
