#!/bin/bash
set -e
cd "$(dirname "$0")"
ENV=.env.ai-brain
[ -f "$ENV" ] || touch "$ENV"
echo "Ancient Innovations Email Hub — second inbox"
read -p "Second email address: " USER2
read -s -p "Google App Password for that inbox: " PASS2; echo
TMP=$(mktemp); grep -v '^BRAIN_EMAIL2_' "$ENV" > "$TMP" || true
printf 'BRAIN_EMAIL2_USERNAME=%s\nBRAIN_EMAIL2_APP_PASSWORD=%s\n' "$USER2" "${PASS2// /}" >> "$TMP"; mv "$TMP" "$ENV"; chmod 600 "$ENV"
DOCKER="$(command -v docker || true)"; [ -x "$DOCKER" ] || DOCKER=/Applications/Docker.app/Contents/Resources/bin/docker
"$DOCKER" compose up -d --build
echo "Second inbox configured. Open http://localhost:8080/email-hub and click Check Business Email."
