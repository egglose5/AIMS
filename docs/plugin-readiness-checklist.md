# AIMS Plugin Readiness Checklist

This checklist maps release tasks to concrete code areas in this repository.

## Milestone A - Module Boot Parity

- [x] Define a shared module contract in `includes/core/class-aims-module.php`.
- [x] Update modules to implement that contract:
  - `includes/modules/vendor-manage/class-aims-vendor-module.php`
  - `includes/modules/event-manage/class-aims-event-module.php`
  - `includes/modules/square-sync/class-aims-square-sync-module.php`
  - `includes/modules/reports-analytics/class-aims-reports-module.php`
- [x] Centralize module registration in `AIMS_Plugin::boot()` via a single module list in `includes/core/class-aims-plugin.php`.
- [x] Stop ad hoc module construction during admin page rendering by injecting module instances into `includes/admin/class-aims-admin-menu.php`.
- [x] Route Vendor shell rendering through module instance parity (`AIMS_Vendor_Module::render_shell()`).

## Milestone B - Operational Admin Workflows

- [x] Replace Vendors shell page output with real data table + create/edit/archive flows:
  - `includes/admin/class-aims-admin-menu.php`
  - `includes/modules/vendor-manage/*`
  - `includes/services/class-aims-vendor-service.php`
  - `includes/repositories/class-aims-vendor-repository.php`
- [x] Turn Square Sync shell into actionable tooling (manual ingest run, mode selection, replay/undo links):
  - `includes/modules/square-sync/class-aims-square-sync-module.php`
  - `includes/admin/class-aims-square-sync-runs-page.php`
  - `includes/services/class-aims-square-import-service.php`
  - `includes/services/class-aims-square-replay-service.php`
  - `includes/services/class-aims-sync-undo-service.php`
- [x] Promote Reports shell to at least one useful operator report with filters/export:
  - `includes/modules/reports-analytics/class-aims-reports-module.php`
  - `includes/admin/*report*` (new files likely)

## Milestone C - Event Manage Completion

- [x] Confirm event management workflows are fully module-owned and not only notice-driven:
  - `includes/modules/event-manage/class-aims-event-module.php`
  - `includes/admin/class-aims-event-planning-events-page.php`
  - `includes/admin/class-aims-event-planning-workspace-page.php`
  - `includes/services/class-aims-event-planning-workspace-service.php`
  - `includes/services/class-aims-event-execution-service.php`
- [x] Validate end-to-end event CRUD and assignment lifecycle capabilities and nonce protections.
  - Planning workspace now includes hierarchy-scoped team visibility, bulk actions, assignment timeline with age-band analytics, team activity visibility, planner-level filtering, and warehouse telemetry hooks.
  - Age-band metrics (Staged > 24h, Open > 8h) are informational KPI analytics only; no time-based threshold triggers any inventory movement or operational action.
  - Stock movement is driven exclusively by real-world action transitions: `loaded`/`in_transit`, `vendor_event_checkin`, and `event_return`.

## Milestone D - Square Safety and Operator UX

- [x] Add explicit capability gates and nonce-protected actions for all sync triggers.
- [x] Add status telemetry on admin screens (last sync, affected rows, error count).
- [x] Enforce idempotency and duplicate protection in visible workflows.
- [x] Define and enforce canonical inventory movement events so stock changes only occur on physical movement actions.

## Milestone E - Packaging and Release Hardening

- [x] Finalize plugin metadata in `ai-man-sys.php` (name, URI, author, version cadence).
- [x] Update `readme.txt` for distribution-ready language and changelog discipline.
- [x] Remove foundation-phase messaging from admin shells and notices.
- [x] Run lint/tests for touched areas and document upgrade path.
  - Touched-area test coverage validated via PHPUnit.
  - Upgrade notes documented in `docs/upgrade-path.md`.
  - Full-suite run currently passes (`76/76`).

## Milestone F - Frontend Vendor Portal Navigation

- [x] Add a frontend vendor portal navigation layer that fits the active site theme instead of recreating a wp-admin-style vendor shell.
- [x] Prefer a dynamic sidebar widget, block, or shortcode for OceanWP so vendor navigation lives inside the existing frontend sidebar and mobile layout.
- [x] Show vendor portal links conditionally based on login state, vendor assignment, and event timing.
- [x] Surface `Event Check-In` only when the vendor has an assigned event inside the allowed pre-event window.
- [x] Cover vendor portal navigation authorization and timing behavior with unit tests.
  - Frontend nav entry points: `includes/modules/vendor-manage/class-aims-vendor-portal-navigation-controller.php`, `includes/modules/vendor-manage/class-aims-vendor-portal-navigation-widget.php`, and `templates/vendor-portal-navigation.php`.
  - Authorization and timing logic: `includes/modules/vendor-manage/class-aims-vendor-portal-navigation-service.php` and `includes/repositories/class-aims-vendor-event-assignment-repository.php`.
  - Test coverage: `tests/Unit/VendorPortalNavigationServiceTest.php` (check-in window opens at the event start-time boundary three days before the event).

## Next Milestone - Inventory Transfers v1 (Distributed Custody)

- [ ] Model inventory transfers as custody changes between operational nodes instead of a single warehouse-out / warehouse-back loop.
  - Main warehouse remains the central stocking and prepack node.
  - Supervisor custody nodes (for example Abby) can hold stock and redistribute it to their subordinate vendors or events.
  - Direct vendor custody nodes (for example Melissa) can receive stock without an intermediate supervisor layer.
- [ ] Add transfer workflows for warehouse-to-supervisor, warehouse-to-direct-vendor, and supervisor-to-subordinate movements.
- [ ] Add receive/return workflows so downstream custody pools can confirm handoff without forcing every event flow back through the main warehouse.
- [ ] Preserve movement-only inventory authority so transfer out and receipt in are the physical events that write ledger changes.
- [ ] Keep the design expandable for multiple concurrent shows and future replenishment into downstream custody pools.
- [ ] Shape the transfer and receipt seams so they can later be driven by a mobile fulfillment app / API without changing the operational truth model.

## Following Milestone - Execution Telemetry and Exception Visibility

- [x] Add explicit `loaded_at` and `in_transit_at` timestamps to assignment records so elapsed transit time is trackable as analytics.
  - Schema and index coverage added on `aims_event_bucket_assignments`.
  - Execution transition logic now stamps `loaded_at`/`in_transit_at` when assignment status moves to `in_transit`.
  - Planning workspace timeline now surfaces Assigned, Loaded, and In Transit timestamps for operator visibility.
- [ ] Extend execution-side exception visibility into planning (check-in failures, return anomalies) for faster intervention.
- [ ] Expand Square replay and fulfillment wiring after planning/commitment workflow stability under team usage is confirmed.
- [ ] Keep optional WooCommerce order projection behind AIMS-side operational reconciliation.

