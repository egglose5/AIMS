# AIMS

AIMS (`ai-man-sys`) is a modular WordPress operations plugin for vendor management, event management, stitch workflow, Square ingestion, and reporting.

This codebase is a full rebuild. Older plugins are reference material only and are not part of the runtime design, schema, or migration path.

## Disclaimer

THIS SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT. USE OF THIS SOFTWARE IS AT YOUR OWN RISK.

You are solely responsible for how you install, configure, operate, back up, secure, and use this software. If you lose data, corrupt data, misconfigure your environment, interrupt your business, or otherwise damage your own systems through use of this software, that responsibility remains yours, not the author's. Support, recovery, customization, maintenance, and operational help are only provided by separate paid agreement.

## Binary Stream

AIMS intentionally treats short SKUs as a product rule in its binary-stream design. The hot path uses a fixed 64-byte packet with `SKU` limited to 32 UTF-8 bytes and all financial snapshots stored as integer cents, not floats. AIMS reads Square transactional data at sale time, strips it down to SKU-first operational facts for the hot ledger, and keeps that ledger lean while pushing verbose Square metadata to colder storage. `PRICE_CENT_SNAPSHOT` must record the actual realized sale price at the moment of sale, including event-specific price adjustments, rather than the catalog price. Invalid records are rejected into an exception lane rather than silently truncated, and compact transaction references are retained as idempotency/reconciliation anchors so the lean model is not lossy.

See `docs/ames-binary-stream-spec.md` for the packet layout, validation rules, and rollout guidance.

## Deployment Profile

The current standalone `ames-core` build should be treated as an `IONOS-style` or filesystem-capable shared-host deployment profile, not as a universally safe profile for every WordPress host. It currently assumes writable local directories, standalone PHP routing, and host support for the storage/runtime path used by the headless core. See `docs/headless-deployment-profiles.md` for the portability boundary and planned fork point.

## Current build status

The repository currently provides:

- plugin bootstrap and class loader
- installer and schema registration
- explicit custom table definitions with indexes across events, demand, buckets, inventory, Square sync, attribution, and fulfillment
- capability and admin menu shells
- custom AIMS role editor with template-based role creation and capability assignment
- vendor, event, Square sync, and reporting module bootstraps
- ledger-first inventory movement backbone with hot-line lifecycle scaffolding
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
- Design for an expected lifecycle of at least 100,000 physical movement writes on the local WordPress server.
- Public event pages must never read directly from internal financial or operational tables.
- Public event output must come from the curated projection layer.
- Event Demand Intake v1 is a core product feature.
- Event demand is login-required.
- No guest demand requests.
- Event demand requests are planning-only, not reservation, payment, or order flows.
- AIMS is SKU-first operationally.
- Track only metadata that is operationally relevant to the current step.
- Inventory entering the company should carry cost values for intake, COGS, and profitability work.
- Inventory moving through the company internally should not carry sale-price data; internal movement truth is `SKU`, quantity, and location/custody reference.
- Inventory leaving the company through a sale should capture the actual amount paid for that item at that moment.
- BOPIS and reservations remain a separate future v2 add-on.
- Inventory is assigned to events only by explicit manager or supervisor planning action. Never automatically.
- Built-in AIMS roles are starter templates, not required runtime identities.
- Runtime authorization should resolve from capabilities first.
- Scoped access should remain assignment-driven for events, vendors, custody nodes, and subordinate trees.

## Capability-first role model

- Built-in AIMS roles should serve as default templates only.
- Site owners should be able to replace built-in AIMS roles entirely with custom website roles.
- AIMS responsibilities should be treated as capabilities so they can be granted through the role editor or any other capability-aware role plugin.
- AIMS runtime checks should not require specific built-in role slugs when an equivalent custom role carries the same capabilities.
- Person subtype and endpoint behavior should resolve from template or role-definition metadata, not from a hard-coded dependency on shipped AIMS roles.
- Scoped assignment records remain important, but they should narrow access after capability checks rather than act as a separate parallel identity system.

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

## Inventory Transfers v1 status

1. Transfer workflow foundation is implemented with draft -> dispatched -> received lifecycle.
2. Transfer persistence now stores source and target as node endpoints while keeping vendor IDs as a compatibility bridge for existing inventory movement authorization paths.
3. Dispatch and receipt remain movement-authority boundaries and write custody transfer ledger events.
4. Inventory admin workspace now supports outgoing and incoming transfer operations in one place.

## Next transfer implementation gaps

1. Add a custody endpoint resolver so logged-in users and responsibility assignments resolve to an operational transfer endpoint consistently.
2. Build a real endpoint directory for transfer targets instead of placeholder or implied node lists.
3. Split source and target bucket queries by endpoint context so each side of a transfer resolves bucket options from its own custody scope.
4. Keep future mobile/API execution in view so these custody transfers can later be driven by a fulfillment app without changing the core model.
5. Extend execution-side exception visibility into planning (check-in failures, return anomalies) for faster intervention after the custody transfer workflow is stable.
6. Expand Square replay and fulfillment wiring only after planning and distributed custody workflows remain stable under team usage.
7. Keep optional WooCommerce order projection behind AIMS-side operational reconciliation.

## Next authorization implementation gaps

1. Finish refactoring any remaining responsibility-specific runtime checks so capability checks are canonical.
2. Remove any remaining runtime dependence on shipped AIMS role slugs where a template-backed custom role should work.
3. Treat built-in AIMS roles as templates in docs, admin copy, and upgrade guidance rather than as required production roles.
4. Keep scoped assignment tables only for narrowing access by event, vendor, custody node, or subordinate tree.
5. Preserve person-subtype metadata resolution for custom roles so vendor, stitch, warehouse, supervisor, and manager behavior remains portable after role replacement.

## Upgrade path

- Follow the rollout and rollback procedure in `docs/upgrade-path.md`.
- Follow `docs/headless-deployment-profiles.md` before describing the current headless build as host-agnostic.

## Operational backbone

AIMS now uses a ledger-first inventory design:

- `aims_inventory_movements` and `aims_bucket_inventory_movements` remain the hot movement-line ledgers used for active balance math.
- `aims_movement_batches` groups hot lines into movement batches with inline line metadata so one physical action can later be exported and reread as a single historical unit.
- `aims_movement_archive_manifests` stores archive/export metadata so historical movement batches can be compressed, exported, and rehydrated locally.
- `aims_inventory_buckets` is the current aggregate view per vendor/product/bucket.
- inbound inventory receipts should capture cost values at intake time.
- internal warehouse, custody, and event movement rows should remain lean and should not duplicate sale-price metadata.
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

## Movement lifecycle model

- AIMS should treat a movement as a physical action batch, not just an unbounded list of hot line rows.
- Hot movement-line rows still exist for fast current-balance calculations.
- Each hot line belongs to a movement batch with inline line metadata that can be exported or archived as one historical object.
- Historical movement access should prefer batch reread/export paths instead of keeping every line in the hottest runtime query path forever.
- Archive manifests should remain local-server friendly so older history can be compressed and reread without losing operational auditability.
