# AIMS Binary Stream Specification

## Purpose

This document defines the hot-path binary packet format for AIMS movement output. It is intended for small-business operations that need high throughput, low overhead, and zero-drift financial integrity.

The binary stream stores only the minimum facts needed for fast movement processing and reconciliation:

- SKU
- actual sale price snapshot in cents
- tax snapshot in cents
- timestamp
- event ID

Descriptions, category strings, image URLs, and other metadata do not belong in the binary stream.

## Product Rules

- `SKU` length is intentionally limited to `1-32 UTF-8 bytes`.
- Oversized SKUs must be rejected before packet creation.
- SKUs must never be truncated in the binary stream.
- Price and tax snapshots must be stored as unsigned integer cents.
- `PRICE_CENT_SNAPSHOT` must record the actual realized sale price at the moment of sale, including event-specific pricing adjustments, and must not fall back to the catalog/base price.
- Floating-point money values are not allowed on the hot path.
- Integer cents are preferred because they save CPU cycles and eliminate drift from repeated float conversion.

## Packet Layout

Each packet is exactly `64 bytes` and is fixed-width for cache-friendly processing.

| Offset | Field | Size | Type | Notes |
| --- | --- | --- | --- | --- |
| `0-31` | `SKU_HEADER` | `32 bytes` | UTF-8 string | Null-padded, validated, never truncated |
| `32-39` | `PRICE_CENT_SNAPSHOT` | `8 bytes` | Unsigned 64-bit integer | Actual realized sale price in cents at time of sale, after event-specific adjustments |
| `40-47` | `TAX_CENT_SNAPSHOT` | `8 bytes` | Unsigned 64-bit integer | Tax snapshot in cents |
| `48-55` | `TIMESTAMP` | `8 bytes` | Integer epoch | Movement-out time |
| `56-63` | `EVENT_ID` | `8 bytes` | Integer | Internal event mapping |

## Validation Rules

- Reject any SKU that exceeds `32 UTF-8 bytes`.
- Reject invalid UTF-8 input.
- Reject negative monetary values.
- Reject non-integer cents.
- Reject any packet build that tries to use a catalog/base price instead of the actual realized sale price.
- Reject missing event IDs.
- Reject timestamps that cannot be represented as integers.
- Reject any payload that includes descriptions, category names, image URLs, or other metadata as part of the packet.

## Encoding Rules

- `SKU_HEADER` is copied as raw UTF-8 bytes.
- Short SKUs are null-padded to 32 bytes.
- Integer fields are packed as unsigned 64-bit big-endian values.
- Price and tax are always written as integer cents.
- `PRICE_CENT_SNAPSHOT` is taken from the actual completed sale transaction for that event, not from the product catalog.
- The writer must not silently repair invalid business input.

## Streaming Strategy

- Packets should be assembled in memory first using `php://temp` or an equivalent RAM-backed buffer.
- Flush to physical storage in batches to reduce syscall overhead.
- Keep the write path append-only.
- Avoid partial-record writes and mixed-format files.
- The binary file is the hot-path stream, not the long-term human-readable archive.

Recommended starting thresholds:

- `1024` packets per flush, or
- `64 KB` buffered payload, whichever comes first

## Exception Lane

Rejected records must be routed to a separate exception lane.

Typical exception cases:

- SKU longer than 32 bytes
- invalid UTF-8
- missing event ID
- invalid or negative cents
- attempted catalog/base-price fallback instead of actual realized sale price
- missing required movement data

Exception records should preserve enough context for later correction and audit, but they must not be inserted into the binary packet stream.

## Rollout Plan

1. Add the binary stream in shadow mode.
2. Write binary packets alongside the current ledger path.
3. Reconcile packet counts and financial totals against the existing movement flow.
4. Confirm that no cent drift appears across repeated write/read cycles.
5. Promote the binary stream only after shadow output stays clean.

## Storage Guidance

- Keep the hot binary stream separate from Parquet archives and from general configuration data.
- Use a dedicated sink path for hot binary packets.
- Store exception logs separately from the packet stream.

## Operational Note

This format is intentionally optimized for small-business operations. AIMS treats short SKUs and integer-cent money snapshots as product rules, not implementation accidents.

Event-specific price changes are treated as normal industry-standard behavior. The binary stream must preserve the actual event sale price, not simply the catalog price.
