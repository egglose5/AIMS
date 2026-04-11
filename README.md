# AIMS

AIMS (`ai-man-sys`) is a headless operations backbone with a WordPress/WooCommerce thin client. WordPress is the default management surface, Square is the payment and sales thin client, and AIMS owns the physical movement truth.

This codebase is a full rebuild. Older plugins are reference material only and are not part of the runtime design, schema, or migration path.

## Disclaimer

THIS SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT. USE OF THIS SOFTWARE IS AT YOUR OWN RISK.

You are solely responsible for how you install, configure, operate, back up, secure, and use this software. If you lose data, corrupt data, misconfigure your environment, interrupt your business, or otherwise damage your own systems through use of this software, that responsibility remains yours, not the author's. Support, recovery, customization, maintenance, and operational help are only provided by separate paid agreement.

## What AIMS Is

AIMS is designed for micro and small businesses that need physical truth before they need a full ERP.

- AIMS tracks actual physical movements.
- AIMS records chain of custody with lightweight operational proof.
- AIMS tracks only movement-adjacent financial meaning: what inventory cost on the way in, and what it earned on the way out.
- WordPress remains the default control console and identity layer.
- Square remains the payment/sales endpoint and remote location inventory surface, not the authority on physical truth.
- WooCommerce remains a natural product/catalog authoring surface, not the movement ledger.

## Binary Stream

AIMS intentionally treats short SKUs as a product rule in its binary-stream design. The hot path uses a fixed 64-byte packet with `SKU` limited to 32 UTF-8 bytes and all financial snapshots stored as integer cents, not floats. AIMS reads Square transactional data at sale time, strips it down to SKU-first operational facts for the hot ledger, and keeps that ledger lean while pushing verbose Square metadata to colder storage. `PRICE_CENT_SNAPSHOT` must record the actual realized sale price at the moment of sale, including event-specific price adjustments, rather than the catalog price. Invalid records are rejected into an exception lane rather than silently truncated, and compact transaction references are retained as idempotency/reconciliation anchors so the lean model is not lossy.

The first shadow-mode implementation slice is now present in `ames-core`: sale-side writes can emit a 64-byte packet plus a sidecar reference dictionary, append-only pointer index, and exception log under the headless sink path. This keeps the WordPress path out of the hot write loop while giving AIMS an exact byte-offset replay path for later reconciliation work.

See `docs/ames-binary-stream-spec.md` for the packet layout, validation rules, implementation status, and rollout guidance.

## Deployment Profile

The current standalone `ames-core` build should be treated as an `IONOS-style` or filesystem-capable shared-host deployment profile, not as a universally safe profile for every WordPress host. It currently assumes writable local directories, standalone PHP routing, and host support for the storage/runtime path used by the headless core. See `docs/headless-deployment-profiles.md` for the portability boundary and planned fork point.

## Manual Install (Current Recommended Path)

Use this path for a first manual install on the current filesystem-capable shared-host profile.

1. **Confirm the host fits the current profile**
   - WordPress `6.0+`
   - PHP `7.4+`
   - writable directories for `ames-core/sink`, `ames-core/vault`, `ames-core/logs`, and `ames-core/config`
   - `pdo_sqlite` available for the active headless storage path
   - ability to expose `ames-core/index.php` at a stable URL or subpath
2. **Back up before touching production**
   - export the database
   - snapshot the current `wp-content/plugins/ai-man-sys` directory if it already exists
   - prefer staging first
3. **Upload the plugin manually**
   - copy this plugin into `wp-content/plugins/ai-man-sys`
   - keep the bundled `vendor/` directory with it
   - do not activate until the headless side is reachable
4. **Deploy the headless `ames-core` directory**
   - place `ames-core/` in a filesystem-capable location reachable over HTTP, for example `https://example.com/ames-core/`
   - ensure `sink/`, `vault/`, `logs/`, and `config/` are writable by PHP
   - make sure the host honors the included `.htaccess` and/or `web.config` protections where applicable
5. **Configure the headless environment**
   - copy or mirror `ames-core/.env.example`
   - set a strong `AIMS_SHARED_SECRET`
   - set `AIMS_ARCHIVE_SECRET` (it may match the shared secret if you do not want a separate archive secret)
   - set `AIMS_ENCRYPTION_KEY`
   - optionally set `AIMS_SINK_PATH`, `AIMS_VAULT_PATH`, `AIMS_CONFIG_PATH`, `AIMS_LOG_PATH`, `AIMS_SQLITE_PATH`, `AIMS_WOO_URL`, and Square values for your environment
   - for the binary lane, optionally set `AIMS_BINARY_STREAM_MODE`, `AIMS_BINARY_FLUSH_PACKET_LIMIT`, `AIMS_BINARY_FLUSH_BYTE_LIMIT`, `AIMS_HOT_RETENTION_DAYS`, and `AIMS_VAULT_RETENTION_DAYS`
6. **Activate and connect WordPress**
   - activate the `AIMS` plugin in wp-admin
   - open `AIMS > Settings`
   - set **AIMS API URL** to the base URL for your deployed `ames-core`
   - set **AIMS Token** to the same value as `AIMS_SHARED_SECRET`
7. **Run a first connection check**
   - open `AIMS > Dashboard`
   - confirm **Core Status** loads without a connection error
   - confirm the **Hot Data Pressure** card renders
   - only then try a safe manual action such as the archive request or a test movement in a non-production environment

For rollout, rollback, and post-install checks, follow `docs/upgrade-path.md`.

## Current build status

The repository currently provides:

- standalone `ames-core` router with token-authenticated routes for movement, buckets, FIFO, custody, manifest build/push, history, archive, OAuth, and encrypted provider secrets
- WordPress/Woo thin-client bridge through [class-aims-headless-api-client.php](C:/Users/sided/source/repos/AIMS%20Local%20Repo/includes/core/class-aims-headless-api-client.php) and the AIMS cockpit
- bucket-first physical truth with current seal state, current Square location context, custody movement, FIFO receive, FIFO availability, and FIFO pick
- event planning and execution workspace in WordPress, including staged bucket prep, temporary release, dock-safe seal checks, check-in, return flows, and show profitability rollups
- vendor-facing portal tools for upcoming shows, mobile event check-in, and mobile field expense logging with short justification and receipt capture
- a seven-day pre-event mobile access window so assigned vendors can prepare, check in, and log show expenses before the event opens
- execution-side mirroring of real event movements into headless AIMS so standalone positional truth is fed by actual physical actions
- structured WP-side audit logs for operator actions instead of hot-path audit table bloat
- hot-data health gauge in the cockpit with small-business-safe pressure bands
- Square queue/raw event/normalized sale/replay scaffolding with sync-run telemetry
- Square thin-client overlap sync foundation: headless AIMS can pull recent Square orders by location/window, and the WP side can replay them into the existing queue/import flow
- location-aware Square stock push from AIMS so bucket-linked stock can be projected to the correct Square location
- authenticated laser-control batch ingress in both headless AIMS (`POST /internal/laser/batches`) and the WooCommerce REST surface (`POST /wp-json/wc/v3/aims/laser-batches`) so Docker-based production tooling can push batches without using WordPress as the hot write path
- shadow-mode binary sale stream lane in `ames-core` with a fixed-width packet, reusable reference dictionary, byte-offset pointer index, exception lane for invalid hot-path rows, packet reread/reconciliation support, buffered flush thresholds, retention metadata, and dashboard visibility for binary shadow status
- capability-first permissions editor with surface-aware access control, while keeping WordPress as the default management experience
- custom table definitions with indexes across events, demand, buckets, inventory, Square sync, attribution, fulfillment, operational logging, and pre-production laser batch handoff
- PHPUnit coverage across the headless bridge, event execution, FIFO, auth surfaces, audit logging, thin-client Square sync foundations, laser batch ingress, and the new binary stream writer

## Current architecture truth

The current build is intentionally hybrid:

- AIMS Core is already a real headless API and is becoming the long-term operations backend.
- WordPress is still the default operator surface and still owns some planning, reporting, and sync orchestration.
- Square is being pushed toward a thinner role: payment intake, sale capture, webhook source, overlap-window pull source, and remote location inventory surface.

The direction is clear even if the cutover is not 100% complete yet:

- AIMS should own physical movement truth.
- WordPress should be the default management point, not the data engine.
- Square should be a thin client for sales and inventory projection, not the operational authority.

## Core rules

- Use custom tables for all AIMS internal entities.
- Do not use custom post types for vendors, events, stitch jobs, mappings, logs, or inventory.
- Keep WordPress users, WooCommerce products, and WooCommerce orders as native objects.
- Treat WooCommerce as product truth.
- Treat AIMS as the operations, event planning, inventory, and physical control truth.
- Treat Square as payment and sales thin-client truth.
- Write imported Square sales into AIMS tables first.
- Avoid any design that can double-apply stock changes.
- Design for an honest small-business lifecycle, not a pretend shared-host ERP.
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
- AIMS should only care about actual physical movements and the minimum financial meaning attached to those movements.
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
- Event planning should also carry event-specific operational materials such as signage, tape, check-in supplies, and other show-day setup items vendors already track outside the system today.
- These event-specific materials should live as planning-visible mise en place context, even when they are not sale inventory and do not belong in the stock ledger.
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
5. AIMS should also give planners a clear place to note event-specific materials like signage, tape, table setup items, and check-in supplies so they stop living only in side spreadsheets or memory.
6. The planner manually assigns buckets to the event (single or bulk, with optional delegation to subordinate planners).
7. AIMS writes planning assignment records and optional warehouse telemetry timestamps, but not inventory movement records.
8. Assigned vendors can use the frontend mobile portal beginning seven days before show start to complete event check-in, post live updates, and log field expenses with a short justification and receipt attachment.
9. Inventory movement records are created only when physical actions occur: `loaded`/`in_transit` (departure), `vendor_event_checkin` (stock-at-event), and `event_return` (return flows).
10. Age-band metrics (Staged > 24h, Open > 8h) are informational analytics only — planners may keep stock staged for days or weeks; the metric records that fact without implying a violation.

## Square Thin-Client Model

- AIMS should decide stock truth and bucket/location truth.
- Square should expose payment events and remote location inventory surfaces.
- Bucket-linked `square_location_id` values let AIMS push stock counts to the correct Square location.
- Square sales should be ingested through a mixture of webhooks and overlap-window polling so missed events can be recovered without trusting one ingestion lane.
- Imported Square records should land in AIMS tables first, then be normalized, replayed, and attributed from there.

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
- headless AIMS also maintains its own SQLite positional and movement sink for standalone operation and future mobile-facing workflows.
- `aims_movement_batches` groups hot lines into movement batches with inline line metadata so one physical action can later be exported and reread as a single historical unit.
- `aims_movement_archive_manifests` stores archive/export metadata so historical movement batches can be compressed, exported, and rehydrated locally.
- `aims_inventory_buckets` is the current aggregate view per vendor/product/bucket.
- inbound inventory receipts should capture cost values at intake time.
- internal warehouse, custody, and event movement rows should remain lean and should not duplicate sale-price metadata.
- stock changes should be applied through movement services (`AIMS_Inventory_Service` and execution flows that delegate to `AIMS_Bucket_Movement_Service`).
- apply-once protection is enforced by the unique movement reference key.
- `aims_customers` and `aims_customer_addresses` store Square customer and address data.
- `aims_events` now carries event-level financial summary fields for gross sales, net sales, vendor payouts, expenses, and profit, which drive the operator-facing **Total Show Profit** reporting view.
- in-person discounts are stored per sale and rolled up into event-level discount totals.
- Square-integrated tips are stored separately from sales net and rolled up into event tip totals for staff gratuity reporting.
- `aims_event_expenses` stores tax and profitability expenses like booth fees, hotel, mileage, shipping, and other show costs, including new vendor-entered field expenses submitted from the mobile portal.
- `aims_product_cost_rules` stores per-product and per-category cost mappings for COGS and profitability calculations.
- `aims_sale_fulfillment_allocations` stores event-stock and warehouse-backorder allocations.
- Square sales are intended to land in `aims_square_sales` before any optional WooCommerce projection.
- Square location inventory should be treated as a projection target, not the primary stock ledger.
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
