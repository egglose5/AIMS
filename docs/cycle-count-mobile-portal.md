# Mobile Cycle Count Portal

This portal is the warehouse-side mobile surface for cycle counts and initial inventory deployment.

It is intended for phone use on the warehouse floor:

- scan the bucket barcode or bucket code
- scan SKUs that are already in the bucket or are being placed into the bucket
- adjust quantities inline when a scan count needs correction
- submit one audited count batch that updates bucket positions and records count deltas

## Exposure

- Shortcode: `aims_cycle_count_portal`
- REST namespace: `aims/v1`
- Bucket lookup route: `GET /wp-json/aims/v1/cycle-count/bucket?barcode=...`
- Count submit route: `POST /wp-json/aims/v1/cycle-count/submit`

## Access control

- User must be logged in.
- User must have `manage_aims_inventory` or `manage_aims`.
- The controller returns a login prompt or permission message instead of the portal when access fails.

## Operator flow

1. Open a page that contains the shortcode.
2. On supported mobile browsers over HTTPS, the portal auto-prompts for rear-camera scanning. If camera scan is unavailable, use a handheld barcode scanner (USB/Bluetooth keyboard wedge) or manual entry.
3. Scan the bucket barcode. Manual bucket-code entry is also supported.
4. Review the current bucket contents returned by AIMS.
5. Scan item SKUs. Each scan increments that SKU count in the working set.
6. Edit quantities manually if the scan count is not the intended final count.
7. Add optional notes and submit.

## Runtime behavior

- Uses the browser `BarcodeDetector` API when available.
- Uses rear-camera preference through `getUserMedia(... facingMode: environment ...)`.
- Auto-starts camera scanning on mobile browsers when `BarcodeDetector` + secure context are available.
- Includes an in-portal scan mode selector: `Auto (mobile camera first)`, `Camera preferred`, or `Hardware scanner preferred`.
- Supports hardware barcode scanners as keyboard input fallback (Enter/Tab scan suffixes are accepted).
- Provides manual text-entry fallback for both bucket and SKU scans.
- Existing bucket positions are preloaded into the working set so the operator is editing final truth, not building a blind delta.

## Persistence model

- Final submitted quantity is stored in `aims_bucket_inventory_positions` as the current truth for that bucket and product.
- Each changed line also writes an audited row to `aims_bucket_inventory_movements` with `movement_type = cycle_count`.
- Movement metadata includes the SKU, old quantity, new quantity, and count source so warehouse audit trails can explain why the position changed.

## Deployment notes

- Put the shortcode on a frontend page that is reachable by authenticated warehouse staff.
- Prefer HTTPS so camera access works reliably on mobile devices.
- Test with at least one device that supports `BarcodeDetector` and one fallback/manual-entry path.
- If site caching is aggressive, exclude the portal page so REST nonce handling stays clean.

## Recommended rollout check

1. Scan a known bucket and confirm current contents load.
2. Scan one known SKU twice and verify the working quantity increments.
3. Manually reduce the quantity before submit and confirm the final absolute count is respected.
4. Verify the bucket position row updates and the movement audit row shows the expected delta.