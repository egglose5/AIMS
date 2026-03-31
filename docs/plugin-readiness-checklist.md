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

## Next Milestone - Frontend Vendor Portal Navigation

- [ ] Add a frontend vendor portal navigation layer that fits the active site theme instead of recreating a wp-admin-style vendor shell.
- [ ] Prefer a dynamic sidebar widget, block, or shortcode for OceanWP so vendor navigation lives inside the existing frontend sidebar and mobile layout.
- [ ] Show vendor portal links conditionally based on login state, vendor assignment, and event timing.
- [ ] Surface `Event Check-In` only when the vendor has an assigned event inside the allowed pre-event window.
- [ ] Keep execution timestamp and exception visibility work aligned behind the vendor portal navigation milestone.

