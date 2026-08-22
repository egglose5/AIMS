#!/bin/bash
set -e
cd "$(dirname "$0")"
echo "Ancient Innovations AI Brain Gmail connection"
echo "This uses a Google App Password; your normal Google password is never stored."
read -p "Workspace mailbox [info@ancient-innovations.com]: " USER
USER=${USER:-info@ancient-innovations.com}
read -s -p "Google App Password (16 characters): " PASS
echo
PASS=$(echo "$PASS" | tr -d ' ')
if [ -z "$PASS" ]; then echo "No app password entered. Nothing changed."; exit 1; fi
cat > .env.ai-brain <<ENV
BRAIN_EMAIL_USERNAME=$USER
BRAIN_EMAIL_APP_PASSWORD=$PASS
BRAIN_EMAIL_INTAKE_ADDRESS=ai-brain@ancient-innovations.com
ENV
chmod 600 .env.ai-brain
echo "Saved locally. Restarting Control App..."
docker compose up -d --build
echo "AI Brain email connection configured. Open http://localhost:8080/show-inbox and click Check AI Brain Gmail."
