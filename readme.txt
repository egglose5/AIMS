=== AIMS ===
Contributors: your-name
Tags: operations, inventory, square, vendors, events
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AIMS is a modular operations plugin for vendors, events, stitching, Square sync, reporting, and event execution using custom database tables.

== Description ==

This repository contains the current AIMS rebuild foundation, including Event Demand Intake v1, public event projection, and the manual event planning and execution model.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.

== Changelog ==

= 0.1.0 =
* Initial AIMS rebuild foundation.
* Event planning and execution model clarified: assignment changes status only, `vendor_event_checkin` is the stock-at-event movement point, and return is the move-back point.
