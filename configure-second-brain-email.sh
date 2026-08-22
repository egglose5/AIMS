#!/bin/bash
set -e
cd "$(dirname "$0")"
ENV=.env.ai-brain
[ -f "$ENV" ] || touch "$ENV"
echo "Ancient Innovations Email Hub — second inbox"
read -p "Second email address: " USER2
read -s -p "Google App Password for that inbox: " PASS2; echo
read -p "IMAP host [imap.gmail.com]: " HOST2
HOST2="${HOST2:-imap.gmail.com}"
read -p "IMAP port [993]: " PORT2
PORT2="${PORT2:-993}"
TMP=$(mktemp); grep -Ev '^BRAIN_EMAIL_2_(USERNAME|APP_PASSWORD|HOST|PORT)=' "$ENV" > "$TMP" || true
printf 'BRAIN_EMAIL_2_USERNAME=%s\nBRAIN_EMAIL_2_APP_PASSWORD=%s\nBRAIN_EMAIL_2_HOST=%s\nBRAIN_EMAIL_2_PORT=%s\n' "$USER2" "${PASS2// /}" "$HOST2" "$PORT2" >> "$TMP"; mv "$TMP" "$ENV"; chmod 600 "$ENV"
DOCKER="$(command -v docker || true)"; [ -x "$DOCKER" ] || DOCKER=/Applications/Docker.app/Contents/Resources/bin/docker
"$DOCKER" compose up -d --build
echo "Second inbox configured. Open http://localhost:8080/email-hub and click Check Business Email."
