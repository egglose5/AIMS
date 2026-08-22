#!/bin/bash
set -e
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
cd "$(dirname "$0")"

echo "Stopping prior Mustaine-AI containers..."
docker rm -f controlapp-web controlapp-postgres 2>/dev/null || true

echo "Building Mustaine-AI v9.9..."
docker compose up -d --build

echo "Waiting for PostgreSQL..."
sleep 3

echo "v9.9 is running at http://localhost:8080"
echo "Barcode intake + visual inventory installed. Pretty cards preserved."
