#!/usr/bin/env bash
set -euo pipefail
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
cd "$(dirname "$0")"
docker compose down --remove-orphans || true
docker compose up --build -d
printf 'Waiting for PostgreSQL and app startup...\n'
for i in {1..60}; do
  if docker inspect -f '{{.State.Health.Status}}' controlapp-postgres 2>/dev/null | grep -q healthy; then break; fi
  sleep 1
done
printf 'v10 Pass 8 is running at http://localhost:8080\n'
printf 'Test Show Orders first, then Production, then Fulfillment. Existing stock should bypass customer-order production; unavailable stock should remain under Customer Orders until one finished scan.\n'
