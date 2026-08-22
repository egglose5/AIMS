#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd -P)"
[ -d "$ROOT/Mustaine-AI" ] || { echo "ERROR: Could not find Mustaine-AI under $ROOT"; exit 1; }
cd "$ROOT"

echo "B5 live reasoning provider setup"
echo "The API key will be stored only in local .env.brain-b5 (mode 600)."
printf "OpenAI API key: "
IFS= read -r -s API_KEY
echo
[ -n "$API_KEY" ] || { echo "ERROR: API key cannot be empty."; exit 1; }
printf "Model [gpt-5.6-luna]: "
IFS= read -r MODEL
MODEL="${MODEL:-gpt-5.6-luna}"
printf "Reasoning effort [low]: "
IFS= read -r EFFORT
EFFORT="${EFFORT:-low}"
case "$EFFORT" in none|low|medium|high|xhigh|max) ;; *) echo "ERROR: effort must be none, low, medium, high, xhigh, or max."; exit 1;; esac

umask 077
cat > .env.brain-b5 <<ENV
BRAIN_REASONING_PROVIDER=OPENAI
BRAIN_REASONING_MODEL=$MODEL
BRAIN_REASONING_API_KEY=$API_KEY
BRAIN_REASONING_EFFORT=$EFFORT
ENV
chmod 600 .env.brain-b5

if [ -f docker-compose.override.yml ] && ! grep -q 'B5-BRAIN-REASONING' docker-compose.override.yml; then
  echo "ERROR: docker-compose.override.yml already exists and is not B5-owned."
  echo "Your key file was created, but B5 did not alter the existing override."
  echo "Send that override file back for a safe merge."
  exit 1
fi
cat > docker-compose.override.yml <<'YAML'
# B5-BRAIN-REASONING — local secret injection only; do not commit .env.brain-b5
services:
  web:
    env_file:
      - .env.brain-b5
YAML

touch .gitignore
for f in .env.brain-b5 docker-compose.override.yml; do grep -qxF "$f" .gitignore || echo "$f" >> .gitignore; done

DOCKER="/Applications/Docker.app/Contents/Resources/bin/docker"
[ -x "$DOCKER" ] || { echo "ERROR: Docker CLI not found at $DOCKER"; exit 1; }
"$DOCKER" compose up -d --build

echo
echo "B5 provider configured. Open http://localhost:8080/show-brain"
echo "Then click: Run B5 live reasoning self-test"
