#!/bin/bash
set -e
cd "$(dirname "$0")"
echo "Stopping prior Control App containers (database, maps and vendor files are preserved)..."
docker compose down || true
echo "Applying Pass 9.6 database additions..."
docker compose up -d postgres
until docker exec controlapp-postgres pg_isready -U postgres -d MustaineAI >/dev/null 2>&1; do sleep 1; done
docker exec -i controlapp-postgres psql -U postgres -d MustaineAI < V10-PASS9.6-BRAIN-INTAKE.sql
echo "Building Ancient Innovations Control App v10 Pass 9.6..."
docker compose up -d --build
echo
echo "v10 Pass 9.6 is running at http://localhost:8080"
echo "Next: configure AI Brain Gmail once with ./configure-ai-brain-email.sh, then open Show Inbox and click Check AI Brain Gmail."
