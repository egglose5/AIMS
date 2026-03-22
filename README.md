# AIMS

AIMS (`ai-man-sys`) is a modular WordPress operations plugin for vendor management, event management, stitch workflow, Square ingestion, fulfillment, and reporting.

This codebase is a full rebuild. Older plugins are reference material only and are not part of the runtime design, schema, or migration path.

## Current State

Completed backbone:

- plugin bootstrap, loader, installer, and schema registration
- capability and admin menu shells
- ledger-first inventory movement model
- customer and address storage
- event, assignment, expense, and profitability tables
- fulfillment allocation tables
- Square sales intake tables
- bucket-first inventory orchestration with buckets as first-class operational objects
- product cost rule storage for COGS-based profitability
- shipping marker handling and Needs Shipping queue shell
- event matching and financial recalculation scaffolds
- discount and tip tracking separated from net sales
- event/vendor assignment is request-driven by default, with open-for-request event statuses
- inventory buckets are first-class operational objects, not vendor-owned records
- events should carry a commission cap percent, defaulting to 30%, plus a split policy.

Current rules:

- Square net sales remain authoritative for commission
- tips are separate from commission and tracked for staff gratuity
- shipping marker presence hard-requires full customer, contact, and shipping info
- approved or manual assignments are the only valid vendor linkage for payout
- approved/manual assignments define the eligible vendor set for commission cap logic
- manual admin assignment remains a fallback override and historical backfill path
- vendor requests are approved first-come, first-served with no priorities or preferences
- bucket-first inventory is the orchestration default, not a vendor-owned subrecord
- proportional-to-vendor-rate should be the default split policy, with equal-split as an explicit event option
- stock changes go through `AIMS_Inventory_Service` only
- AIMS owns internal records; WooCommerce remains a catalog/order projection layer

## In Progress

The current milestone is the first complete operational flow:

1. ingest a Square order payload
2. capture customer, address, discount, tip, and shipping-marker data
3. persist sale rows into AIMS
4. match the sale to an event and, when approved, a vendor assignment
5. determine fulfillment state
6. create fulfillment allocations
7. recalculate event financials
8. surface anything incomplete in the Needs Shipping queue

## Next Milestone Checklist

1. Complete the first operational flow end to end.
2. Implement request-first vendor assignment with FCFS approval and admin manual fallback.
3. Preserve the current financial, fulfillment, and shipping rules.
4. Keep bucket-first inventory routing and event participation policy explicit in orchestration.
5. Add Square runtime integration points for later webhook and catalog work.

## Future Phases

Square runtime integration:

- real Square API client
- webhook handling
- dedupe and watermark state
- catalog sync orchestration
- AIMS-owned shipping marker configuration in Square

Twilio notifications:

- vendor SMS when `needs_shipping_info` is triggered
- vendor SMS can also be used for open event requests and assignment approvals
- one-time notification logging and retry tracking
- paid provider adapter, likely Twilio

Customer SMS updates:

- custom order status updates
- shipping and fulfillment updates
- customer-facing text events separate from vendor notifications

Public event showcase layer:

- separate public-facing custom post type for event archive/showcase content
- internal `aims_events` remains the source of truth for operational event data
- public posts link back to internal events via `aims_event_id`
- support booth photos, public descriptions, and event reviews/testimonials
- plan for future widgets, shortcodes, or blocks powered by this layer for archives, previews, and review highlights

## Operational Backbone

AIMS uses a ledger-first inventory and financial design:

- `aims_inventory_movements` is the immutable stock movement ledger.
- `aims_inventory_buckets` is the current aggregate view per product/bucket and is decoupled from vendor ownership.
- an event bucket represents the total inventory going to a show, while movements capture what was allocated, sold, returned, and adjusted.
- `aims_customers` and `aims_customer_addresses` store Square customer and address data.
- `aims_events` carries gross sales, discount totals, tip totals, net sales, vendor payouts, expenses, and profit.
- `aims_event_expenses` stores show costs like booth fees, hotel, mileage, shipping, and other expenses.
- `aims_product_cost_rules` stores per-product and per-category cost mappings for COGS and profitability calculations.
- `aims_sale_fulfillment_allocations` stores event-stock and warehouse-backorder allocations.
- `aims_square_sales` stores imported sales before any optional WooCommerce projection.
- event automation matches Square sales to events by Square location and sold-at date window, then recalculates event financials.
- planned bucket-based RBAC can grant supervisors access to specific inventory buckets without full system access.
