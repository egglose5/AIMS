#!/bin/bash
set -euo pipefail

export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
ROOT="$(cd "$(dirname "$0")" && pwd)"
STABLE="$HOME/.mustaine-ai"
PG_DEST="$STABLE/postgres-data"
DP_DEST="$STABLE/dp-keys"

mkdir -p "$STABLE"

echo "Mustaine-AI v8 upgrade"
echo "This keeps the database and login/settings outside versioned Downloads folders."

PG_SRC="$(docker inspect controlapp-postgres --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
DP_SRC="$(docker inspect controlapp-web --format '{{range .Mounts}}{{if eq .Destination "/app/.aspnet/DataProtection-Keys"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"

if [ -n "$PG_SRC" ] && [ -d "$PG_SRC" ] && [ ! -f "$PG_DEST/PG_VERSION" ]; then
  echo "Stopping current app so PostgreSQL can be copied safely..."
  docker stop controlapp-web controlapp-postgres >/dev/null 2>&1 || true
  mkdir -p "$PG_DEST"
  echo "Copying existing PostgreSQL data to $PG_DEST ..."
  ditto "$PG_SRC" "$PG_DEST"
else
  docker stop controlapp-web controlapp-postgres >/dev/null 2>&1 || true
fi

if [ -n "$DP_SRC" ] && [ -d "$DP_SRC" ] && [ ! -d "$DP_DEST" ]; then
  mkdir -p "$DP_DEST"
  ditto "$DP_SRC" "$DP_DEST"
else
  mkdir -p "$DP_DEST"
fi

# Remove only the containers. Persistent database files are not removed.
docker rm -f controlapp-web controlapp-postgres >/dev/null 2>&1 || true

cd "$ROOT"
echo "Building and starting v8..."
docker compose up -d --build

echo "Waiting for the database..."
for i in {1..30}; do
  if docker exec controlapp-postgres pg_isready -U postgres -d MustaineAI >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo
echo "v8 is running at http://localhost:8080"
echo "Permanent app data now lives in: $STABLE"
echo "Future version folders can be moved or deleted without moving the database."
