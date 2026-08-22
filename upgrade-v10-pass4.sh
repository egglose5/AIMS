#!/bin/bash
set -e
export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
cd "$(dirname "$0")"

echo "Stopping prior Mustaine-AI containers..."
docker rm -f controlapp-web controlapp-postgres 2>/dev/null || true

echo "Building Mustaine-AI v10 Pass 4 Shared Fulfillment Backbone..."
docker compose up -d --build

echo "Waiting for PostgreSQL and app startup..."
sleep 5

echo "v10 is running at http://localhost:8080"
echo "Shared fulfillment backbone installed; existing Show Orders workflow remains intact."
