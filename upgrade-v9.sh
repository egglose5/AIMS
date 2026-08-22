#!/bin/bash
set -euo pipefail
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
ROOT="$(cd "$(dirname "$0")" && pwd)"
STABLE="$HOME/.mustaine-ai"
mkdir -p "$STABLE/postgres-data" "$STABLE/dp-keys"

echo "Mustaine-AI v9 upgrade"
echo "Permanent database and login/settings remain in $STABLE."

docker rm -f controlapp-web controlapp-postgres >/dev/null 2>&1 || true
cd "$ROOT"
echo "Building and starting v9..."
docker compose up -d --build

echo "Waiting for PostgreSQL..."
for i in {1..30}; do
  if docker exec controlapp-postgres pg_isready -U postgres -d MustaineAI >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo
echo "v9 is running at http://localhost:8080"
echo "Production is now sales -> make list -> one finished scan."
