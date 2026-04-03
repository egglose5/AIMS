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

AIMS intentionally uses short SKUs and integer-cent financial snapshots in its planned binary-stream hot path. AIMS reads Square transactional data at sale time, strips it down to SKU-first operational facts for the hot ledger, and keeps that ledger lean while pushing verbose Square metadata to colder storage. The binary packet design limits SKU data to 32 UTF-8 bytes, stores the actual realized event sale price and tax as integer cents, and rejects invalid records into an exception lane instead of truncating them. Compact transaction references stay available as idempotency/reconciliation anchors so the lean model is not lossy.

See `docs/ames-binary-stream-spec.md` for the binary packet spec and rollout notes.

Deployment note: the current standalone `ames-core` path should be treated as an `IONOS-style` or filesystem-capable shared-host profile, not as something already proven safe for all WordPress hosts. It currently assumes writable local directories, standalone PHP routing, and host support for the active headless storage/runtime path. See `docs/headless-deployment-profiles.md` for the portability notes and planned fork point.

Core AIMS rule: track only metadata that is relevant to the current operational step. Inventory entering the company should carry cost values for intake and profitability work. Inventory moving through the company internally should remain lean and should not carry sale-price data; internal movement truth is SKU, quantity, and location/custody reference. Inventory leaving the company through a sale should capture the actual amount paid for that item at that moment.

Current architecture note: WordPress is the default control console, but the long-term operational authority is AIMS Core. Square is being treated more and more like a thin client and payment rail: AIMS can now project bucket-linked stock to Square locations and ingest recent Square sales through both webhook intake and overlap-window polling.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.

== Changelog ==

= Unreleased =
* Updated product description to reflect the current headless-AIMS plus WordPress thin-client architecture.
* Added headless execution mirroring so real event actions feed standalone positional truth.
* Added bucket-linked Square location inventory projection and overlap-window Square thin-client sync foundations.
* Added planning guidance for capability-first AIMS roles where built-in roles act as templates and custom roles can replace them at runtime.
* Added node-centric inventory transfer workflow foundation with source and target custody endpoints.
* Added transfer dispatch and receipt operational flow in Inventory workspace with custody movement authority.
* Updated repository documentation to reflect distributed custody transfer implementation status and next endpoint hardening milestones.

= 1.0.0 =
* Production release with module-boot parity for vendor, event, square sync, and reports.
* Added admin workflows for vendor create/edit/archive and event create/edit/archive.
* Added manual Square ingestion tooling with sync-run logging and replay/undo review surfaces.
* Added event sales and attribution reporting with filters and CSV export.

= 0.1.0 =
* Initial internal rebuild baseline.
