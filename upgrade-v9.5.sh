#!/bin/bash
set -e
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"
echo "Installing Mustaine-AI v9.5 — Visual Artwork Identification..."
# Fixed container names are shared across versions. Remove containers only;
# permanent PostgreSQL data remains safely bind-mounted in ~/.mustaine-ai.
docker rm -f controlapp-web controlapp-postgres 2>/dev/null || true
docker compose down --remove-orphans 2>/dev/null || true
docker compose up -d --build
printf 'Waiting for PostgreSQL...\n'
for i in {1..60}; do
  if docker inspect --format='{{.State.Health.Status}}' controlapp-postgres 2>/dev/null | grep -q healthy; then break; fi
  sleep 1
done
echo "v9.5 is running at http://localhost:8080"
echo "Visual artwork cards installed for Initial Inventory and Production."
