#!/bin/bash
set -e
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
cd "$(dirname "$0")"

echo "Stopping prior Mustaine-AI containers..."
docker rm -f controlapp-web controlapp-postgres 2>/dev/null || true

echo "Building Mustaine-AI v10 Pass 6 Operational Fulfillment Stages..."
docker compose up -d --build

echo "Waiting for PostgreSQL..."
until docker exec controlapp-postgres pg_isready -U postgres -d MustaineAI >/dev/null 2>&1; do sleep 1; done

# Preserve the known-good Pass 5.1 schema verification. This is idempotent.
echo "Verifying shared fulfillment table..."
docker exec -i controlapp-postgres psql -v ON_ERROR_STOP=1 -U postgres -d MustaineAI < V10-PASS5-FULFILLMENT-SCHEMA.sql >/dev/null

docker restart controlapp-web >/dev/null
sleep 4

echo "v10 Pass 6 is running at http://localhost:8080"
echo "Test Fulfillment: Production Complete -> Ready to Pick -> Picked -> Ready to Pack -> Ready to Ship -> Shipped -> Complete."
