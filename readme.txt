=== AIMS ===
Contributors: aims-team
Tags: operations, inventory, square, vendors, events
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: Apache License 2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

AIMS is a headless operations backbone with a WordPress/WooCommerce thin client for vendors, events, physical inventory, Square sync, reporting, and event execution.

== Description ==

AIMS provides a WordPress management surface for a headless AIMS core that owns physical movement truth, custody, buckets, FIFO, and event execution.

This software is provided "as is", without warranty of any kind, express or implied, including merchantability, fitness for a particular purpose, and non-infringement. Use of this software is at your own risk.

You are solely responsible for how you install, configure, operate, back up, secure, and use this software. If you lose data, corrupt data, misconfigure your environment, interrupt your business, or otherwise damage your own systems through use of this software, that responsibility remains yours, not the author's. Support, recovery, customization, maintenance, and operational help are only provided by separate paid agreement.

AIMS intentionally uses short SKUs and integer-cent financial snapshots in its binary-stream hot path. AIMS reads Square transactional data at sale time, strips it down to SKU-first operational facts for the hot ledger, and keeps that ledger lean while pushing verbose Square metadata to colder storage. The binary packet design limits SKU data to 32 UTF-8 bytes, stores the actual realized event sale price and tax as integer cents, and rejects invalid records into an exception lane instead of truncating them. Compact transaction references stay available as idempotency/reconciliation anchors so the lean model is not lossy.

The first headless implementation slice now writes a shadow-mode 64-byte sale packet plus a reusable reference dictionary, append-only pointer index, and exception log under the `ames-core` sink path so replay can happen from exact offsets instead of heap-style scans.

See `docs/ames-binary-stream-spec.md` for the binary packet spec, implementation status, and rollout notes.

Deployment note: the current standalone `ames-core` path should be treated as an `IONOS-style` or filesystem-capable shared-host profile, not as something already proven safe for all WordPress hosts. It currently assumes writable local directories, standalone PHP routing, and host support for the active headless storage/runtime path. See `docs/headless-deployment-profiles.md` for the portability notes and planned fork point.

Core AIMS rule: track only metadata that is relevant to the current operational step. Inventory entering the company should carry cost values for intake and profitability work. Inventory moving through the company internally should remain lean and should not carry sale-price data; internal movement truth is SKU, quantity, and location/custody reference. Inventory leaving the company through a sale should capture the actual amount paid for that item at that moment.

Current architecture note: WordPress is the default control console, but the long-term operational authority is AIMS Core. Square is being treated more and more like a thin client and payment rail: AIMS can now project bucket-linked stock to Square locations and ingest recent Square sales through both webhook intake and overlap-window polling. The same thin-client model now exposes a WooCommerce REST proxy at `/wp-json/wc/v3/aims/laser-batches` so Docker-based laser production software can push prepared job batches into the headless AIMS sink.

== Installation ==

Manual install for the current filesystem-capable profile:

1. Confirm the host supports WordPress 6.0+, PHP 7.4+, writable local directories, and `pdo_sqlite` for the active headless path.
2. Back up the database and the existing `wp-content/plugins/ai-man-sys` directory before changing a live site.
3. Upload this plugin to `/wp-content/plugins/ai-man-sys/`, including the bundled `vendor/` directory.
4. Deploy the `ames-core/` directory to a stable URL or subpath such as `https://example.com/ames-core/` and ensure `sink/`, `vault/`, `logs/`, and `config/` are writable by PHP.
5. Copy or mirror `ames-core/.env.example` and set at least `AIMS_SHARED_SECRET`, `AIMS_ARCHIVE_SECRET`, and `AIMS_ENCRYPTION_KEY`.
6. Activate the plugin through the "Plugins" menu in WordPress.
7. Open `AIMS > Settings`, set **AIMS API URL** to the deployed `ames-core` base URL, and set **AIMS Token** to the same value as `AIMS_SHARED_SECRET`.
8. Open `AIMS > Dashboard` and confirm the core status card loads before using live operational actions.

For rollout, rollback, and post-install verification, follow `docs/upgrade-path.md`.

== Changelog ==

= Unreleased =
* Updated product description to reflect the current headless-AIMS plus WordPress thin-client architecture.
* Added headless execution mirroring so real event actions feed standalone positional truth.
* Added bucket-linked Square location inventory projection and overlap-window Square thin-client sync foundations.
* Added planning guidance for capability-first AIMS roles where built-in roles act as templates and custom roles can replace them at runtime.
* Added node-centric inventory transfer workflow foundation with source and target custody endpoints.
* Added transfer dispatch and receipt operational flow in Inventory workspace with custody movement authority.
* Added vendor mobile field expense logging with short justification, optional receipt capture, and a seven-day pre-event access window.
* Expanded reporting visibility to include event expenses and operator-facing **Total Show Profit** rollups.
* Added authenticated laser batch ingress in headless AIMS plus a WooCommerce REST proxy endpoint (`/wp-json/wc/v3/aims/laser-batches`) for Docker-based production tooling.
* Added the first binary hot-path shadow writer in `ames-core` with 64-byte sale packets, a sidecar pointer index, reusable reference dictionary, and exception-lane logging.
* Updated repository documentation to reflect distributed custody transfer implementation status, vendor mobile workflows, current reporting/profitability coverage, the laser-control batch target, and the new binary hot-path slice.

= 1.0.0 =
* Production release with module-boot parity for vendor, event, square sync, and reports.
* Added admin workflows for vendor create/edit/archive and event create/edit/archive.
* Added manual Square ingestion tooling with sync-run logging and replay/undo review surfaces.
* Added event sales and attribution reporting with filters and CSV export.

= 0.1.0 =
* Initial internal rebuild baseline.
