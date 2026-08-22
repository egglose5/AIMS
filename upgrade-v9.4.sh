#!/bin/bash
set -e
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"
echo "Installing Mustaine-AI v9.4..."
docker compose down --remove-orphans || true
docker compose up -d --build
printf 'Waiting for PostgreSQL...\n'
for i in {1..60}; do
  if docker inspect --format='{{.State.Health.Status}}' controlapp-postgres 2>/dev/null | grep -q healthy; then break; fi
  sleep 1
done
echo "v9.4 is running at http://localhost:8080"
echo "Barcode readiness patch installed. Unnamed historical sales no longer enter Production."
