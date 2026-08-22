#!/usr/bin/env bash
set -euo pipefail
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
cd "$(dirname "$0")"

echo "Stopping prior Control App containers (database files and map archive are preserved)..."
docker rm -f controlapp-web controlapp-postgres 2>/dev/null || true
mkdir -p "${HOME}/.mustaine-ai/show-maps"

echo "Building Ancient Innovations Control App v10 Pass 9.2 - Show Research Model v1..."
docker compose up --build -d

echo "Waiting for PostgreSQL..."
for i in {1..60}; do
  if docker inspect -f '{{.State.Health.Status}}' controlapp-postgres 2>/dev/null | grep -q healthy; then break; fi
  sleep 1
done

for schema in V10-PASS8.13.1-ARTWORK-SUBCATEGORY-SCHEMA.sql V10-PASS8.13.8-SQUARE-SKU-SCHEMA.sql V10-PASS8.13.9-HOLD-SCHEMA.sql V10-PASS9-SHOW-ARM-SCHEMA.sql V10-PASS9.2-SHOW-RESEARCH-V1.sql; do
  if [ -f "$schema" ]; then docker exec -i controlapp-postgres psql -U postgres -d MustaineAI < "$schema"; fi
done

docker restart controlapp-web >/dev/null
sleep 3

echo "v10 Pass 9.2 is running at http://localhost:8080"
echo "Open Show Arm. Existing data is preserved; historical map files persist in ~/.mustaine-ai/show-maps."
