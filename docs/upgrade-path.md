# AIMS Upgrade Path

This repository is a full rebuild of the operations stack.

Older plugins are reference-only and are not treated as runtime-compatible upgrade targets.
Use this procedure for safe rollout and rollback.

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

## Upgrade Steps

1. Put the site in maintenance mode.
2. Deactivate the existing `ai-man-sys` plugin.
3. Deploy the new plugin build to `wp-content/plugins/ai-man-sys`.
4. Activate the plugin.
5. Visit wp-admin once as an administrator so installer hooks can apply schema updates.
6. Verify role and capability registration on admin, manager, and supervisor accounts.

## Post-upgrade Verification

1. Event Planning
- Open Event Planning list and workspace.
- Confirm authorized users only see/edit authorized events.
- Confirm assignment action forms submit successfully with nonce checks.

2. Square Sync Safety
- Open Square Sync and Sync Runs pages.
- Confirm replay/undo controls are visible only to authorized users.
- Confirm invalid run IDs are rejected and duplicate replay/undo requests are blocked.
- Confirm telemetry panel shows run count, processed rows, error count, and last status.

3. Vendor and Reporting
- Verify vendor create/edit/archive flow still works.
- Run event sales report filter/export flow.

## Rollback Procedure

1. Deactivate the upgraded plugin.
2. Restore the previous plugin directory snapshot.
3. Restore database backup if schema/data changes must be reverted.
4. Reactivate prior plugin version and validate critical workflows.

## Testing Notes For This Phase

- Touched-area unit tests for Event Planning authorization and Square Sync safety/telemetry pass.
- Full-suite run currently reports existing baseline failures in movement-related tests unrelated to this phase:
  - `BucketMovementServiceTest::testRecordMovementUsesMovementBalanceAsSourceOfTruth`
  - `BucketMovementServiceTest::testBucketPositionServiceRecaclulateUsesMovementSourceOfTruth`
  - `EventExecutionV1Test::testVendorEventCheckinWritesPhysicalMovementLedger`
  - `EventExecutionV1Test::testEventReturnWritesPhysicalReturnLedger`
