#!/usr/bin/env bash
set -euo pipefail
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
cd "$(dirname "$0")"

echo "Stopping prior Control App containers (persistent database files are preserved)..."
docker rm -f controlapp-web controlapp-postgres 2>/dev/null || true

echo "Building Ancient Innovations Control App v10 Pass 8.4..."
docker compose up --build -d

echo "Waiting for PostgreSQL and app startup..."
for i in {1..60}; do
  if docker inspect -f '{{.State.Health.Status}}' controlapp-postgres 2>/dev/null | grep -q healthy; then break; fi
  sleep 1
done
sleep 2

echo "v10 Pass 8.4 is running at http://localhost:8080"
echo "Open Production directly. The pending Show Order should appear under Customer Orders without opening Show Orders first, and shipping charges should not appear in Inventory Replacement."
