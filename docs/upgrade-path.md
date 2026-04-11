# AIMS Upgrade Path

This repository is a full rebuild of the operations stack.

Older plugins are reference-only and are not treated as runtime-compatible upgrade targets.
Use this procedure for safe rollout and rollback.

For the binary-stream lane, AIMS intentionally limits SKUs to 32 UTF-8 bytes and stores sale-side prices and tax snapshots as integer cents. The ingest flow should read Square transactional data at sale time, strip it down to SKU-first operational facts for the hot ledger, and keep the ledger lean while pushing verbose Square metadata to colder storage. Keep only the minimal transaction reference needed for idempotency or reconciliation so the model stays lean without becoming lossy. Internal movement records should stay price-free, inbound intake records remain the place where cost values are captured, and outbound sale records should preserve the actual amount paid for the item. Records that do not fit that contract must be rejected into the exception lane rather than truncated. See `docs/ames-binary-stream-spec.md` for the packet contract and rollout details.

The current headless `ames-core` path should be treated as an `IONOS-style` or filesystem-capable shared-host deployment profile. It is not yet documented as universally safe for all WordPress hosts because it currently assumes writable local directories, standalone PHP routing, and the active SQLite/file-backed storage path. See `docs/headless-deployment-profiles.md` for the formal portability note and planned adapter fork.

## Scope

- Supported upgrade target: prior releases of this same `ai-man-sys` plugin line.
- Not supported: in-place migration from unrelated or legacy predecessor plugins.
- Data model authority remains in AIMS custom tables.

## Pre-upgrade Checklist

1. Confirm WordPress and PHP versions satisfy plugin requirements.
2. Export a full database backup.
3. Snapshot the `wp-content/plugins/ai-man-sys` directory.
4. Stage the upgrade in a non-production environment first.
5. Confirm scheduler/cron is healthy before running sync-related workflows.

## First-Time Manual Install

If this is a first manual install rather than an upgrade, use the current filesystem-capable shared-host path:

1. Upload the plugin to `wp-content/plugins/ai-man-sys`.
2. Deploy `ames-core/` to a reachable URL or subpath and ensure `sink/`, `vault/`, `logs/`, and `config/` are writable.
3. Copy or mirror `ames-core/.env.example` and set `AIMS_SHARED_SECRET`, `AIMS_ARCHIVE_SECRET`, and `AIMS_ENCRYPTION_KEY` before activation. If you want explicit binary-lane controls, also set `AIMS_BINARY_STREAM_MODE`, `AIMS_BINARY_FLUSH_PACKET_LIMIT`, `AIMS_BINARY_FLUSH_BYTE_LIMIT`, `AIMS_HOT_RETENTION_DAYS`, and `AIMS_VAULT_RETENTION_DAYS`.
4. Activate the plugin in wp-admin.
5. Open `AIMS > Settings` and point the plugin at the deployed headless URL, using the same shared secret as the `AIMS Token` value.
6. Open `AIMS > Dashboard` and verify the core connection before any live movement, sync, or archive action.

## Upgrade Steps

1. Put the site in maintenance mode.
2. Deactivate the existing `ai-man-sys` plugin.
3. Deploy the new plugin build to `wp-content/plugins/ai-man-sys`.
4. Activate the plugin.
5. Visit wp-admin once as an administrator so installer hooks can apply schema updates.
6. Verify role and capability registration on admin, manager, and supervisor accounts.
7. Verify any custom AIMS roles created from templates still carry the expected capabilities and person-subtype behavior after the upgrade.

## Post-upgrade Verification

1. Event Planning
- Open Event Planning list and workspace.
- Confirm authorized users only see/edit authorized events.
- Confirm assignment action forms submit successfully with nonce checks.
- Confirm event-specific setup materials such as signage, tape, and check-in supplies remain visible in planning context without mutating stock.
- Confirm the planning workspace surfaces execution exceptions for pending/void check-ins and return anomalies, and that the counts match the visible rows.

2. Square Sync Safety
- Open Square Sync and Sync Runs pages.
- Confirm replay/undo controls are visible only to authorized users.
- Confirm invalid run IDs are rejected and duplicate replay/undo requests are blocked.
- Confirm telemetry panel shows run count, processed rows, error count, and last status.

3. Vendor and Reporting
- Verify vendor create/edit/archive flow still works.
- Run event sales report filter/export flow.

4. Inventory Transfers (Distributed Custody)
- Open Inventory workspace and verify outgoing/incoming transfer panels render.
- Create a transfer draft, add line items, dispatch, and confirm receipt.
- Confirm transfer records persist with node endpoint fields and status transitions.
- Confirm malformed endpoint posts and same-source/target drafts are rejected before persistence.
- Confirm dispatch and receipt create custody movement ledger rows.
- Confirm route guidance/audit notes reflect the selected source and target custody endpoints.

5. Binary Stream Rollout
- Review `docs/ames-binary-stream-spec.md` before enabling the binary hot path.
- Keep `AIMS_BINARY_STREAM_MODE=shadow` while the canonical ledger remains the operational source of truth.
- Open `AIMS > Dashboard` and confirm the **Binary Shadow Status** card shows expected pointer counts, exception counts, and active segment files.
- Use the headless history lane (`GET /history?source=binary`) or the dashboard visibility to spot-check rehydrated packet rows by `show_id`, `reference_id`, `sku`, or `event_id`.
- Verify that SKU validation, realized sale price snapshots, idempotency anchors, packet counts, per-event totals, and exception counts reconcile cleanly before promotion.
- Confirm the chosen hot/vault retention thresholds appear in archive manifests and match your operating expectations before the first live archive cycle.

6. Capability-First Role Model
- Open the AIMS Role Editor.
- Verify built-in AIMS roles appear as templates.
- Verify custom roles cloned from templates retain their capability set.
- Verify a user assigned only a custom AIMS role can still reach the expected AIMS workflow screens.

7. Square Projection Observability and Monte Carlo Export
- Open Square Sync > Sync Runs and confirm the **Projection Detail** and **Projection Drill-In** columns are present.
- Confirm the **Projection Effects** action link resolves to `?aims_projection_run_id=N` and renders a normalized effects table scoped to that run.
- Confirm the **Export Parquet** form button is visible per row and is nonce-protected.
- Trigger a Parquet export for a run that has projection effects and confirm a `.parquet` file downloads directly (no intermediate vault file created on disk).
- Verify the exported file contains both `projection_effect` rows and `hot_position` rows, each with a populated `point_state` field.
- Confirm the export is rejected for unauthorized users (no `manage_square_sync` capability) and that mismatched or missing nonces are blocked.
- Confirm `AIMS_Bucket_Inventory_Position_Repository::get_all_positions()` returns positions ordered by `updated_at DESC` and that the row cap is respected.

## Rollback Procedure

1. Deactivate the upgraded plugin.
2. Restore the previous plugin directory snapshot.
3. Restore database backup if schema/data changes must be reverted.
4. Reactivate prior plugin version and validate critical workflows.

## Testing Notes For This Phase

- Touched-area unit tests for planning, sync safety/telemetry, and inventory transfers pass.
- Current operator-hardening coverage includes transfer route validation, stage-specific dispatch/receipt authorization, and planning-side execution exception visibility.
- Full-suite PHPUnit baseline currently passes in this repository state.
- Binary stream rollout should remain shadow-only until the staging soak confirms clean packet counts, per-event totals, exception counts, and zero-drift rereads across the live or replayed sale window.
- The current shadow tooling now includes packet reread, reconciliation summaries, dashboard visibility, configurable flush thresholds, and archive retention metadata; the remaining gate is operator sign-off for promotion, not missing core mechanics.
- Headless deployment claims should stay profile-specific until a generic-host storage/runtime path exists beside the current IONOS-style profile.
- Square projection observability is fully covered: import-side sync effects (with `sync_run_id` context), per-run drill-in, forensic effects viewer, and Parquet export (both file-persist and direct-stream modes) all pass in the unit suite.
- The Parquet export controller uses `stream_to_resource()` — no vault file survives the request. Tests for this path use a `php://memory` sink and assert that no `path` key is returned and that bytes arrive in the resource.
- The Monte Carlo snapshot includes hot inventory positions as `hot_position` rows alongside projection-effect rows, unified under the `point_state` field for LLM/FLM simulation ingestion.
