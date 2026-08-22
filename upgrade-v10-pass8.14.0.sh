#!/usr/bin/env bash
set -euo pipefail
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
cd "$(dirname "$0")"

echo "Stopping prior Control App containers (database files are preserved)..."
docker rm -f controlapp-web controlapp-postgres 2>/dev/null || true

echo "Building Ancient Innovations Control App v10 Pass 8.14.0..."
docker compose up --build -d

echo "Waiting for PostgreSQL..."
for i in {1..60}; do
  if docker inspect -f '{{.State.Health.Status}}' controlapp-postgres 2>/dev/null | grep -q healthy; then break; fi
  sleep 1
done

if [ -f V10-PASS8.13.1-ARTWORK-SUBCATEGORY-SCHEMA.sql ]; then
  docker exec -i controlapp-postgres psql -U postgres -d MustaineAI < V10-PASS8.13.1-ARTWORK-SUBCATEGORY-SCHEMA.sql
fi
if [ -f V10-PASS8.13.8-SQUARE-SKU-SCHEMA.sql ]; then
  docker exec -i controlapp-postgres psql -U postgres -d MustaineAI < V10-PASS8.13.8-SQUARE-SKU-SCHEMA.sql
fi
if [ -f V10-PASS8.13.9-HOLD-SCHEMA.sql ]; then
  docker exec -i controlapp-postgres psql -U postgres -d MustaineAI < V10-PASS8.13.9-HOLD-SCHEMA.sql
fi

docker restart controlapp-web >/dev/null
sleep 3

echo "v10 Pass 8.14.0 is running at http://localhost:8080"
echo "Fulfillment now groups customer orders, supports Pack -> Ready to Ship -> Shipped, opens Pirate Ship, and keeps shipped history."
