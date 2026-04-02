# Inventory Movement Events

Stock changes in AIMS must only occur for physical-world inventory movement.

Hot-path movement output may also be written to the fixed-width binary stream described in `docs/ames-binary-stream-spec.md`. That stream uses short SKUs and integer-cent financial snapshots by design, and it records the actual realized sale price for event-specific pricing rather than the catalog price.

## Rule

- Planning-only actions must not mutate stock.
- Financial-only actions must not mutate stock.
- Inventory movement writes must use one of the allowed movement events below.
- Movement storage must support at least 100,000 lifetime physical movement writes without requiring every historical line to remain in the hottest runtime query path.
- A movement should be treated as a batch of inline line metadata that can later be exported, archived, compressed, and reread locally.

## Allowed Movement Events

- `stock_in` - inventory physically received into a bucket.
- `stock_out` - inventory physically removed from a bucket.
- `transfer` - inventory physically moved between buckets/locations.
- `event_load_out` - inventory physically moved to event floor.
- `event_return` - inventory physically returned from event to storage.
- `stitcher_handoff` - inventory physically handed to stitch workflow.
- `stitcher_return` - inventory physically returned from stitch workflow.
- `square_sale` - inventory physically sold at Square point of sale.
- `woocommerce_fulfillment` - inventory physically fulfilled for WooCommerce order.
- `warehouse_pick` - inventory physically picked for fulfillment staging.
- `adjustment` - manual audited correction (damage/count correction).

## Non-Movement Events (must not change stock)

- Event demand intake and demand edits.
- Event bucket assignment/release planning actions.
- Public projection edits and publication status updates.
- Replay/reporting actions that do not represent physical movement.

## Enforcement

- Allowed movement event policy is defined in `AIMS_Inventory_Movement_Events`.
- Movement event and reference type pairings are enforced by a policy matrix in `AIMS_Inventory_Movement_Events`.
- `AIMS_Inventory_Service::apply_movement()` rejects non-allowed movement types.
- `AIMS_Bucket_Movement_Service::record_movement()` rejects non-allowed movement types.
- Hot line writes should belong to a movement batch and carry inline line metadata for archive/export lifecycle support.
- Historical access should prefer movement-batch and archive-manifest reads once lines age out of the hottest operational path.

## Reference-Type Matrix

- `stock_in` -> `inbound_receipt`, `manual_adjustment`, `physical_count`
- `stock_out` -> `manual_adjustment`, `physical_count`, `shrinkage`
- `transfer` -> `bucket_transfer`, `location_transfer`, `custody_transfer`, `custody_receipt`, `custody_return_dispatch`, `custody_return_receipt`
- `event_load_out` -> `vendor_event_checkin`, `event_execution`
- `event_return` -> `vendor_event_return`, `event_execution`
- `stitcher_handoff` -> `stitch_job_handoff`
- `stitcher_return` -> `stitch_job_return`
- `square_sale` -> `square_sale_line`, `square_order`
- `woocommerce_fulfillment` -> `woo_fulfillment_line`, `woo_order_fulfillment`
- `warehouse_pick` -> `woo_order_fulfillment`, `warehouse_pick_ticket`
- `adjustment` -> `manual_adjustment`, `physical_count`, `reconciliation`

