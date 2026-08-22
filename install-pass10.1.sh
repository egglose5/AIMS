#!/bin/bash
set -euo pipefail

PASS_NAME="PASS 10.1"
DOCKER_BIN="${DOCKER_BIN:-/Applications/Docker.app/Contents/Resources/bin/docker}"
TARGET_ROOT="${AI_CONTROL_APP_TARGET:-/Users/jrmus/Downloads/AI Brain Files Lots so we have everything/AI-Pass-9-4}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PAYLOAD_ROOT="$SCRIPT_DIR/payload"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_ROOT="$TARGET_ROOT/.pass-backups/pass10.1-$STAMP"
RESTORE_NEEDED=0

FILES=(
  "Mustaine-AI/Program.cs"
  "Mustaine-AI/Components/Layout/NavMenu.razor"
  "Mustaine-AI/Components/Pages/Production.razor"
  "Mustaine-AI/Components/Pages/Scout.razor"
  "Mustaine-AI/Components/Pages/ScoutResearch.razor"
  "Mustaine-AI/Components/Pages/ShowOrders.razor"
  "Mustaine-AI/Components/Pages/Shows.razor"
  "Mustaine-AI/Components/Pages/VendorShows.razor"
  "Mustaine-AI/Services/FulfillmentWorkflowRules.cs"
  "Mustaine-AI/Services/OperationalBoundaryRules.cs"
  "Mustaine-AI/Services/ScoutResearchService.cs"
  "Mustaine-AI/Services/ShowFinderBackgroundService.cs"
  "Mustaine-AI/Services/ShowPlacementService.cs"
  "Mustaine-AI/Services/ShowWebResearchService.cs"
  ".env.ai-brain.example"
  "V10-PASS10.1-CHANGES.txt"
)

restore_files() {
  echo "[${PASS_NAME}] Restoring backed up files..."
  for rel in "${FILES[@]}"; do
    local backup="$BACKUP_ROOT/$rel"
    local target="$TARGET_ROOT/$rel"
    if [ -f "$backup" ]; then
      mkdir -p "$(dirname "$target")"
      cp -p "$backup" "$target"
    else
      rm -f "$target"
    fi
  done
}

cleanup_on_error() {
  if [ "$RESTORE_NEEDED" -eq 1 ]; then
    restore_files || true
    if [ -x "$DOCKER_BIN" ] && [ -f "$TARGET_ROOT/docker-compose.yml" ]; then
      echo "[${PASS_NAME}] Rebuilding/restoring running application after rollback..."
      (
        cd "$TARGET_ROOT"
        "$DOCKER_BIN" compose build web >/dev/null
        "$DOCKER_BIN" compose up -d web >/dev/null
      ) || true
    fi
  fi
}

trap cleanup_on_error ERR

if [ ! -x "$DOCKER_BIN" ]; then
  echo "Docker binary not found at: $DOCKER_BIN"
  exit 1
fi

if [ ! -d "$TARGET_ROOT" ]; then
  echo "Live source directory not found: $TARGET_ROOT"
  exit 1
fi

if [ ! -d "$PAYLOAD_ROOT" ]; then
  echo "Payload directory not found: $PAYLOAD_ROOT"
  exit 1
fi

if [ ! -f "$TARGET_ROOT/Mustaine-AI/Program.cs" ] || [ ! -f "$TARGET_ROOT/Mustaine-AI/Services/ScoutResearchService.cs" ]; then
  echo "Target does not look like the expected live Control App source."
  exit 1
fi

if ! grep -q "SCOUT_S2_RESEARCH" "$TARGET_ROOT/Mustaine-AI/Program.cs"; then
  echo "Target Program.cs does not contain the expected Scout S2.3 marker."
  exit 1
fi

if ! grep -q "SCOUT:S1.22" "$TARGET_ROOT/Mustaine-AI/Services/ScoutDiscoveryService.cs"; then
  echo "Target ScoutDiscoveryService.cs does not contain the expected Scout S1.27 lineage markers."
  exit 1
fi

mkdir -p "$BACKUP_ROOT"

echo "[${PASS_NAME}] Backing up live files to $BACKUP_ROOT"
for rel in "${FILES[@]}"; do
  src="$TARGET_ROOT/$rel"
  backup="$BACKUP_ROOT/$rel"
  mkdir -p "$(dirname "$backup")"
  if [ -f "$src" ]; then
    cp -p "$src" "$backup"
  fi
done

echo "[${PASS_NAME}] Overlaying approved source files"
for rel in "${FILES[@]}"; do
  src="$PAYLOAD_ROOT/$rel"
  dst="$TARGET_ROOT/$rel"
  if [ ! -f "$src" ]; then
    echo "Missing payload file: $rel"
    exit 1
  fi
  mkdir -p "$(dirname "$dst")"
  cp -p "$src" "$dst"
done
RESTORE_NEEDED=1

echo "[${PASS_NAME}] Building updated web image before restart"
(
  cd "$TARGET_ROOT"
  "$DOCKER_BIN" compose build web
)

echo "[${PASS_NAME}] Restarting services with the validated build"
(
  cd "$TARGET_ROOT"
  "$DOCKER_BIN" compose up -d postgres
  "$DOCKER_BIN" compose up -d web
)

echo "[${PASS_NAME}] Waiting for application readiness"
READY=0
for _ in {1..30}; do
  if curl -fsS "http://127.0.0.1:8080/Account/Login" >/dev/null 2>&1; then
    READY=1
    break
  fi
  sleep 2
done

if [ "$READY" -ne 1 ]; then
  echo "[${PASS_NAME}] Application did not become ready on http://127.0.0.1:8080/Account/Login"
  (
    cd "$TARGET_ROOT"
    "$DOCKER_BIN" compose ps || true
    "$DOCKER_BIN" compose logs --tail=80 web || true
  )
  exit 1
fi

RESTORE_NEEDED=0

echo "[${PASS_NAME}] Post-install diagnostics"
(
  cd "$TARGET_ROOT"
  "$DOCKER_BIN" compose ps
)
"$DOCKER_BIN" exec controlapp-postgres psql -U postgres -d MustaineAI -c 'SELECT COUNT(*) AS identity_users FROM "AspNetUsers"; SELECT COUNT(*) AS mapped_show_admins FROM "ShowVendorProfiles" WHERE "IsActive" = TRUE AND "IsShowAdmin" = TRUE AND "ApplicationUserId" IS NOT NULL; SELECT COUNT(*) AS unmapped_show_admins FROM "ShowVendorProfiles" WHERE "IsActive" = TRUE AND "IsShowAdmin" = TRUE AND "ApplicationUserId" IS NULL; SELECT COUNT(*) AS fulfillment_rows FROM "FulfillmentOrderLines";'

echo "[${PASS_NAME}] Installed successfully."
echo "[${PASS_NAME}] Live source: $TARGET_ROOT"
echo "[${PASS_NAME}] Backup: $BACKUP_ROOT"
