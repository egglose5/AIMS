#!/bin/bash
set -e
BASE="$HOME/Downloads/AI-Pass-9-4"
[ -d "$BASE/Mustaine-AI" ] || { echo "Could not find $BASE"; exit 1; }
echo "Ancient Innovations Brain - IONOS live inbox"
echo "Historical mail is handled by the archive importer; this connection is for current/new mail."
read -p "IONOS mailbox [info@tsartisans.com]: " USER
USER="${USER:-info@tsartisans.com}"
read -s -p "IONOS mailbox password: " PASS
echo
[ -n "$PASS" ] || { echo "Password cannot be blank."; exit 1; }
python3 - "$BASE/Mustaine-AI/appsettings.json" "$USER" "$PASS" <<'PY'
import json,sys
p,user,pw=sys.argv[1:]
with open(p) as f: d=json.load(f)
d['BrainEmail2']={'Username':user,'AppPassword':pw,'Host':'imap.ionos.com','Port':'993'}
with open(p,'w') as f: json.dump(d,f,indent=2)
PY
cd "$BASE"
DOCKER="$(command -v docker || true)"; [ -x "$DOCKER" ] || DOCKER=/Applications/Docker.app/Contents/Resources/bin/docker
"$DOCKER" compose up -d --build web
echo "IONOS live inbox configured. Email Hub → Check Business Inboxes will now read both business accounts."
