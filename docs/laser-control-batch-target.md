# Laser Control Docker Batch Target

AIMS now exposes an authenticated headless ingress endpoint for the laser control Docker software to push batch payloads into the AIMS sink.

## Target endpoint

You now have **two valid ingress targets** depending on where the Docker workflow is already connected.

### 1. WooCommerce / WordPress REST proxy

- **Method:** `POST`
- **Path:** `/wp-json/wc/v3/aims/laser-batches`
- **Auth:** your existing WooCommerce REST authentication flow
- **Content-Type:** `application/json`

Example:

```text
https://your-wordpress-host.example.com/wp-json/wc/v3/aims/laser-batches
```

Example `curl` using Woo REST credentials:

```bash
curl -X POST "https://your-wordpress-host.example.com/wp-json/wc/v3/aims/laser-batches" \
  -u "ck_xxxxx:cs_xxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "batch_id": "laser-run-20260411-001",
    "source": "laser_control_docker",
    "machine_id": "laser-01",
    "stitch_job_id": 991,
    "items": [
      { "sku": "PATCH-RED", "quantity": 12 }
    ]
  }'
```

This is the best target when your Docker software is already talking to the WooCommerce REST API.

To inspect the WordPress-side route, you can also `GET /wp-json/wc/v3/aims/laser-batches?limit=20` using the same WooCommerce REST credentials.

### 2. Direct headless AIMS ingress

- **Method:** `POST`
- **Path:** `/internal/laser/batches`
- **Auth header:** `X-Ames-Token: <AIMS shared token>`
- **Content-Type:** `application/json`

Example base URL:

```text
https://your-aims-core-host.example.com/internal/laser/batches
```

## Minimal payload

A push must include a batch identifier and at least one of these arrays:
- `items`
- `operations`
- `commands`

Example:

```json
{
  "batch_id": "laser-run-20260411-001",
  "source": "laser_control_docker",
  "machine_id": "laser-01",
  "stitch_job_id": 991,
  "event_id": 0,
  "items": [
    {
      "sku": "PATCH-RED",
      "quantity": 12,
      "material": "twill",
      "artwork_ref": "dragon-left-chest"
    },
    {
      "sku": "PATCH-BLK",
      "quantity": 8,
      "material": "twill",
      "artwork_ref": "dragon-back"
    }
  ]
}
```

## Accepted response

Successful pushes return `202 Accepted` with a summary of the stored batch record.

Example:

```json
{
  "ok": true,
  "batch": {
    "batch_id": "laser-run-20260411-001",
    "status": "accepted",
    "source": "laser_control_docker",
    "machine_id": "laser-01",
    "stitch_job_id": 991,
    "event_id": 0,
    "line_count": 2,
    "received_at": "2026-04-11T18:42:10+00:00",
    "target_path": "/path/to/ames-core/sink/laser-batches/20260411_184210-laser-run-20260411-001.json"
  },
  "message": "Laser batch accepted into the AIMS sink."
}
```

## Batch inbox location

Accepted payloads are written to:

```text
ames-core/sink/laser-batches/
```

This gives the laser flow a durable file-backed target without requiring WordPress to be the write hot path.

## Optional discovery / inspection

To inspect recently accepted laser batches:

- **Method:** `GET`
- **Path:** `/internal/laser/batches?limit=20`

This returns a recent summary list and confirms that the target endpoint is live.
