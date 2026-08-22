#!/usr/bin/env bash
set -euo pipefail
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
cd "$(dirname "$0")"

echo "Stopping prior Control App containers (database files are preserved)..."
docker rm -f controlapp-web controlapp-postgres 2>/dev/null || true

echo "Building Ancient Innovations Control App v10 Pass 9.1 - Shows + Research..."
docker compose up --build -d

echo "Waiting for PostgreSQL..."
for i in {1..60}; do
  if docker inspect -f '{{.State.Health.Status}}' controlapp-postgres 2>/dev/null | grep -q healthy; then break; fi
  sleep 1
done

# Preserve prior self-healing schemas for installations upgrading from older passes.
for schema in V10-PASS8.13.1-ARTWORK-SUBCATEGORY-SCHEMA.sql V10-PASS8.13.8-SQUARE-SKU-SCHEMA.sql V10-PASS8.13.9-HOLD-SCHEMA.sql V10-PASS9-SHOW-ARM-SCHEMA.sql; do
  if [ -f "$schema" ]; then docker exec -i controlapp-postgres psql -U postgres -d MustaineAI < "$schema"; fi
done

docker restart controlapp-web >/dev/null
sleep 3

echo "v10 Pass 9.1 is running at http://localhost:8080"
echo "Open Show Arm in the left menu. Your existing vendor profiles and Show Arm data are preserved."
