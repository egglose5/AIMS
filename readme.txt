=== AIMS ===
Contributors: aims-team
Tags: operations, inventory, square, vendors, events
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: Apache License 2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

AIMS is a modular operations plugin for vendors, events, stitching, Square sync, reporting, and event execution using custom database tables.

== Description ==

AIMS provides production workflows for vendor management, event planning and execution, Square ingestion and review, and event-centric reporting.

This software is provided "as is", without warranty of any kind, express or implied, including merchantability, fitness for a particular purpose, and non-infringement. Use of this software is at your own risk.

AIMS intentionally uses short SKUs and integer-cent financial snapshots in its binary-stream hot path. The binary packet design limits SKU data to 32 UTF-8 bytes, stores the actual realized event sale price and tax as integer cents, and rejects invalid records into an exception lane instead of truncating them.

See `docs/ames-binary-stream-spec.md` for the binary packet spec and rollout notes.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.

== Changelog ==

= Unreleased =
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
