#!/bin/bash
set -e
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
cd "$(dirname "$0")"

echo "Stopping prior Mustaine-AI containers..."
docker rm -f controlapp-web controlapp-postgres 2>/dev/null || true

echo "Building Mustaine-AI v10 Pass 7 Simplified Fulfillment..."
docker compose up -d --build

echo "Waiting for PostgreSQL..."
until docker exec controlapp-postgres pg_isready -U postgres -d MustaineAI >/dev/null 2>&1; do sleep 1; done

echo "Verifying shared fulfillment table..."
docker exec -i controlapp-postgres psql -v ON_ERROR_STOP=1 -U postgres -d MustaineAI < V10-PASS5-FULFILLMENT-SCHEMA.sql >/dev/null

# Pass 6 was a workflow prototype. Reset unshipped Show Orders to the production gate so
# they cannot remain visible merely because prototype buttons were clicked during testing.
docker exec -i controlapp-postgres psql -v ON_ERROR_STOP=1 -U postgres -d MustaineAI <<'SQL' >/dev/null
UPDATE "FulfillmentOrderLines"
SET "ProductionStatus" = 'NEEDS_PRODUCTION',
    "FulfillmentStatus" = 'OPEN',
    "UpdatedAt" = NOW()
WHERE "SourceChannel" = 'SQUARE_SHOW_ORDER'
  AND COALESCE("TrackingNumber", '') = ''
  AND "ShippedAt" IS NULL;

UPDATE "ShowOrderFulfillments"
SET "Status" = 'NEEDS_PRODUCTION',
    "UpdatedAt" = NOW()
WHERE "Status" IN ('READY_TO_SHIP');
SQL

docker restart controlapp-web >/dev/null
sleep 4

echo "v10 Pass 7 is running at http://localhost:8080"
echo "Fulfillment is now scan-gated: pending production stays out; a matching finished scan puts the order into Fulfillment."
