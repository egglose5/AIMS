# Customer Inventory Integration

This guide is for customers and partner systems that need to automatically send current inventory counts to AIMS.

## What this integration does

- Lets your system tell AIMS how much inventory you currently have.
- Lets your system read back AIMS updates so both systems can stay aligned.

## Authentication

Use the shared integration token in request headers:

- `X-Ames-Token: <your integration token>`
- `Content-Type: application/json` for POST requests

If your token is missing or invalid, the API returns `401`.

## Send your latest inventory counts

Route:

- `POST /wp-json/aims/v1/integrations/inventory`

Example request body:

```json
{
  "updates": [
    {
      "sku": "SKU-123",
      "available_quantity": 28,
      "total_quantity": 30,
      "reserved_quantity": 2,
      "source_reference": "partner-sync-2026-04-12T14:00:00Z"
    }
  ]
}
```

Recommended fields per update item:

- `sku`: product SKU in your system
- `available_quantity`: currently available quantity
- `total_quantity`: total quantity before reservations
- `reserved_quantity`: currently reserved quantity
- `source_reference`: stable unique reference from your sync job

Tip: keep `source_reference` stable per outbound event so duplicate sends can be safely ignored.

Example cURL:

```bash
curl -X POST "https://your-store.example.com/wp-json/aims/v1/integrations/inventory" \
  -H "X-Ames-Token: <your integration token>" \
  -H "Content-Type: application/json" \
  -d '{
    "updates": [
      {
        "sku": "SKU-123",
        "available_quantity": 28,
        "total_quantity": 30,
        "reserved_quantity": 2,
        "source_reference": "partner-sync-2026-04-12T14:00:00Z"
      }
    ]
  }'
```

## Read AIMS updates feed

Route:

- `GET /wp-json/aims/v1/integrations/updates`

Optional query params:

- `since`: only return updates newer than this timestamp
- `limit`: max rows to return (default 50, max 500)

Example cURL:

```bash
curl -X GET "https://your-store.example.com/wp-json/aims/v1/integrations/updates?limit=50" \
  -H "X-Ames-Token: <your integration token>"
```

Response includes:

- `updates`: normalized update rows
- `latest_cursor`: newest update timestamp for pagination
- `low_stock_summary`: current low-stock rollup
- `low_stock_alerts`: low-stock item list

## Sync recommendations

- Send updates whenever your inventory changes.
- Store and reuse `latest_cursor` for incremental feed reads.
- Retry failed requests with backoff.
- Monitor `401` responses and rotate token safely if needed.
