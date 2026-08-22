#!/bin/bash
set -e
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
cd "$(dirname "$0")"

echo "Stopping prior Mustaine-AI containers..."
docker rm -f controlapp-web controlapp-postgres 2>/dev/null || true

echo "Building Mustaine-AI v10 Pass 5 Shared Fulfillment Queue..."
docker compose up -d --build

echo "Waiting for PostgreSQL and app startup..."
sleep 5

echo "v10 Pass 5 is running at http://localhost:8080"
echo "Open Show Orders once, then open Fulfillment to verify the shared queue."
