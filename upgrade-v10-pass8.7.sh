#!/usr/bin/env bash
set -euo pipefail
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
cd "$(dirname "$0")"

echo "Stopping prior Control App containers (persistent database files are preserved)..."
docker rm -f controlapp-web controlapp-postgres 2>/dev/null || true

echo "Building Ancient Innovations Control App v10 Pass 8.7..."
docker compose up --build -d

echo "Waiting for PostgreSQL and app startup..."
for i in {1..60}; do
  if docker inspect -f '{{.State.Health.Status}}' controlapp-postgres 2>/dev/null | grep -q healthy; then break; fi
  sleep 1
done
sleep 2

echo "v10 Pass 8.7 is running at http://localhost:8080"
echo "Inventory now uses the live Square catalog as its master list. Search Inventory for Whispering Mill first."
