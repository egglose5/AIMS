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
- frontend vendor portal navigation layer with theme-friendly output (shortcode + widget)
- vendor portal links conditioned by login state, vendor assignment, and event timing
- Event Check-In visibility gated to the authorized pre-event window (opens at 10:00, three days before the event)
- Square queue/raw event/normalized sale/replay scaffolding
- capability-gated, nonce-protected replay/undo triggers with duplicate-request protection per sync run
- Sync Runs operator telemetry (last status, completed timestamp, processed rows, error totals)
- runtime assignment, attribution, sync effect, and exception table foundations
- native product cost rule storage for COGS-based profitability
- PHPUnit harness with passing first unit tests
- vendor portal navigation service unit coverage for login, assignment, authorization, and timing windows

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

## Distributed custody model

- Mom handles raw-material intake and first-stage production before unfinished work enters the stitch workflow.
- Stitchers receive unfinished work, complete stitching, and return finished goods to the main warehouse.
- The main warehouse receives finished goods, stocks permanent shelf inventory, and builds prepacked stock for concurrent shows.
- Main-warehouse stock is then disseminated to downstream custody nodes such as Melissa or Abby.
- Melissa acts as a direct custody node with no subordinate redistribution layer.
- Abby acts as a supervisor custody node: stock transferred to Abby remains in Abby's custody domain and may be redistributed by Abby to subordinate vendors or events.
- Abby is responsible for what goes to and comes back from her subordinates; those stock flows do not need to route back through the main warehouse after every event.
- Replenishment should be delivered into the appropriate downstream custody pool instead of forcing all inventory to return to the main warehouse between events.

## Next implementation phase

1. Build `Inventory Transfers v1` around a distributed custody model instead of a single warehouse-out / warehouse-back loop.
2. Represent supervisor roles such as Abby as real downstream custody nodes that can hold stock, redistribute it to subordinates, and remain responsible for their local inventory pool.
3. Support warehouse-to-supervisor, warehouse-to-direct-vendor, and supervisor-to-subordinate transfer flows with explicit receive and return actions.
4. Preserve movement-only inventory authority: transfers and receipts are the physical events that write ledger changes.
5. Keep future mobile/API execution in view so these custody transfers can later be driven by a fulfillment app without changing the core model.
6. Extend execution-side exception visibility into planning (check-in failures, return anomalies) for faster intervention after the custody transfer workflow is stable.
7. Expand Square replay and fulfillment wiring only after planning and distributed custody workflows remain stable under team usage.
8. Keep optional WooCommerce order projection behind AIMS-side operational reconciliation.

## Upgrade path

- Follow the rollout and rollback procedure in `docs/upgrade-path.md`.

## Operational backbone

AIMS now uses a ledger-first inventory design:

- `aims_inventory_movements` is the immutable stock movement ledger.
- `aims_inventory_buckets` is the current aggregate view per vendor/product/bucket.
- stock changes should be applied through movement services (`AIMS_Inventory_Service` and execution flows that delegate to `AIMS_Bucket_Movement_Service`).
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
