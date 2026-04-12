#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'HELP'
AIMS IONOS installer

Usage:
  scripts/install-ionos.sh \
    --wp-plugin-dir /path/to/wp-content/plugins \
    --web-root /path/to/public_html \
    [--plugin-slug ai-man-sys] \
    [--core-subdir ames-core] \
    [--base-domain example] \
    [--domain-suffix sec.com] \
    [--shared-secret <value>] \
    [--archive-secret <value>] \
    [--encryption-key <value>] \
    [--core-base-url https://example.com/ames-core] \
    [--auto-system-prereqs] \
    [--confirm-write] \
    [--dry-run]

What this script does:
  1) Validates PHP version and required extension checks.
  2) Deploys this repo to wp-content/plugins/<plugin-slug>.
  3) Deploys ames-core to <web-root>/<core-subdir>.
  4) Creates writable runtime directories (sink, vault, logs, config).
  5) Creates/updates ames-core/.env with required secrets.
  6) Optionally probes /status endpoint if --core-base-url is supplied.
  7) Can derive core URL as https://aims.<base-domain>.<domain-suffix>.

Notes:
  - Host-level extension installation is only attempted when --auto-system-prereqs is set.
  - On many shared hosts (including some IONOS plans), enabling extensions may require panel-level changes.
  - DNS/subdomain creation itself is not performed unless your environment exposes a DNS API workflow.
  - Non-dry-run execution requires --confirm-write.
HELP
}

log() {
  printf '[AIMS INSTALL] %s\n' "$*"
}

fail() {
  printf '[AIMS INSTALL][ERROR] %s\n' "$*" >&2
  exit 1
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"
}

generate_secret() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 32
  else
    php -r "echo bin2hex(random_bytes(32));"
  fi
}

php_bool_ext_loaded() {
  local ext="$1"
  php -r "exit(extension_loaded('$ext') ? 0 : 1);"
}

php_version_ok() {
  php -r "exit(version_compare(PHP_VERSION, '7.4.0', '>=') ? 0 : 1);"
}

try_enable_sqlite_prereq() {
  if php_bool_ext_loaded pdo_sqlite; then
    return 0
  fi

  if [[ "${AUTO_SYSTEM_PREREQS}" != "1" ]]; then
    return 1
  fi

  if command -v apt-get >/dev/null 2>&1 && command -v sudo >/dev/null 2>&1; then
    log "Attempting system prerequisite install: php-sqlite3"
    sudo apt-get update
    sudo apt-get install -y php-sqlite3 || true
  elif command -v yum >/dev/null 2>&1 && command -v sudo >/dev/null 2>&1; then
    log "Attempting system prerequisite install: php-pdo php-sqlite3"
    sudo yum install -y php-pdo php-sqlite3 || true
  else
    log "No supported system package manager detected for automatic extension install."
  fi

  php_bool_ext_loaded pdo_sqlite
}

upsert_env_value() {
  local file="$1"
  local key="$2"
  local value="$3"

  if grep -q "^${key}=" "$file"; then
    sed -i.bak "s#^${key}=.*#${key}=${value}#" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}

safe_copy_repo() {
  local src="$1"
  local dst="$2"

  mkdir -p "$dst"

  if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete \
      --exclude '.git' \
      --exclude '.github' \
      --exclude '.phpunit.cache' \
      --exclude 'pulls' \
      "$src/" "$dst/"
  else
    rm -rf "$dst"
    mkdir -p "$dst"
    cp -R "$src/." "$dst/"
    rm -rf "$dst/.git" "$dst/.github" "$dst/.phpunit.cache" "$dst/pulls"
  fi
}

backup_dir_if_exists() {
  local target="$1"
  if [[ -d "$target" ]]; then
    local stamp
    stamp="$(date +%Y%m%d-%H%M%S)"
    local backup="${target}.backup-${stamp}"
    log "Creating backup: $backup"
    cp -R "$target" "$backup"
  fi
}

probe_status_endpoint() {
  local base_url="$1"
  local token="$2"

  require_cmd curl

  local url_a="${base_url%/}/status"
  local url_b="${base_url%/}/index.php/status"

  log "Probing status endpoint: $url_a"
  if curl -fsS -H "X-Ames-Token: $token" "$url_a" >/dev/null; then
    log "Status probe succeeded via rewrite-friendly route."
    return 0
  fi

  log "Rewrite route probe failed. Probing fallback route: $url_b"
  if curl -fsS -H "X-Ames-Token: $token" "$url_b" >/dev/null; then
    log "Status probe succeeded via index.php fallback route."
    return 0
  fi

  fail "Unable to reach AIMS status endpoint at either route. Verify web routing and firewall rules."
}

WP_PLUGIN_DIR=''
WEB_ROOT=''
PLUGIN_SLUG='ai-man-sys'
CORE_SUBDIR='ames-core'
BASE_DOMAIN=''
DOMAIN_SUFFIX='sec.com'
SHARED_SECRET=''
ARCHIVE_SECRET=''
ENCRYPTION_KEY=''
CORE_BASE_URL=''
AUTO_SYSTEM_PREREQS='0'
CONFIRM_WRITE='0'
DRY_RUN='0'

while [[ $# -gt 0 ]]; do
  case "$1" in
    --wp-plugin-dir)
      WP_PLUGIN_DIR="${2:-}"
      shift 2
      ;;
    --web-root)
      WEB_ROOT="${2:-}"
      shift 2
      ;;
    --plugin-slug)
      PLUGIN_SLUG="${2:-}"
      shift 2
      ;;
    --core-subdir)
      CORE_SUBDIR="${2:-}"
      shift 2
      ;;
    --base-domain)
      BASE_DOMAIN="${2:-}"
      shift 2
      ;;
    --domain-suffix)
      DOMAIN_SUFFIX="${2:-}"
      shift 2
      ;;
    --shared-secret)
      SHARED_SECRET="${2:-}"
      shift 2
      ;;
    --archive-secret)
      ARCHIVE_SECRET="${2:-}"
      shift 2
      ;;
    --encryption-key)
      ENCRYPTION_KEY="${2:-}"
      shift 2
      ;;
    --core-base-url)
      CORE_BASE_URL="${2:-}"
      shift 2
      ;;
    --auto-system-prereqs)
      AUTO_SYSTEM_PREREQS='1'
      shift
      ;;
    --confirm-write)
      CONFIRM_WRITE='1'
      shift
      ;;
    --dry-run)
      DRY_RUN='1'
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      fail "Unknown argument: $1"
      ;;
  esac
done

[[ -n "$WP_PLUGIN_DIR" ]] || fail "Missing required argument: --wp-plugin-dir"
[[ -n "$WEB_ROOT" ]] || fail "Missing required argument: --web-root"

require_cmd php
require_cmd grep
require_cmd sed

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

PLUGIN_TARGET="${WP_PLUGIN_DIR%/}/${PLUGIN_SLUG}"
CORE_TARGET="${WEB_ROOT%/}/${CORE_SUBDIR}"

if [[ -z "$CORE_BASE_URL" && -n "$BASE_DOMAIN" ]]; then
  CORE_BASE_URL="https://aims.${BASE_DOMAIN}.${DOMAIN_SUFFIX}"
fi

log "Validating PHP runtime prerequisites"
if ! php_version_ok; then
  fail "PHP 7.4+ is required. Found: $(php -r 'echo PHP_VERSION;')"
fi

if ! php_bool_ext_loaded pdo; then
  fail "PHP extension 'pdo' is required."
fi

if ! php_bool_ext_loaded pdo_sqlite; then
  log "Missing PHP extension: pdo_sqlite"
  if ! try_enable_sqlite_prereq; then
    fail "Cannot continue: pdo_sqlite is not enabled. On IONOS shared hosting, enable it in PHP settings or contact support."
  fi
fi

if [[ -z "$SHARED_SECRET" ]]; then
  SHARED_SECRET="$(generate_secret)"
fi
if [[ -z "$ARCHIVE_SECRET" ]]; then
  ARCHIVE_SECRET="$SHARED_SECRET"
fi
if [[ -z "$ENCRYPTION_KEY" ]]; then
  ENCRYPTION_KEY="$(generate_secret)"
fi

log "Deployment targets"
log "  Plugin: $PLUGIN_TARGET"
log "  Core:   $CORE_TARGET"
if [[ -n "$CORE_BASE_URL" ]]; then
  log "  Core URL: $CORE_BASE_URL"
fi

if [[ "$DRY_RUN" == '1' ]]; then
  log "Dry run mode enabled. No file changes were made."
  exit 0
fi

if [[ "$CONFIRM_WRITE" != '1' ]]; then
  fail "Refusing to write without explicit acknowledgment. Re-run with --confirm-write (or use --dry-run first)."
fi

mkdir -p "$WP_PLUGIN_DIR"
mkdir -p "$WEB_ROOT"

backup_dir_if_exists "$PLUGIN_TARGET"
backup_dir_if_exists "$CORE_TARGET"

log "Deploying plugin files"
safe_copy_repo "$REPO_ROOT" "$PLUGIN_TARGET"

log "Deploying headless core files"
mkdir -p "$CORE_TARGET"
safe_copy_repo "$REPO_ROOT/ames-core" "$CORE_TARGET"

log "Ensuring writable runtime directories"
mkdir -p "$CORE_TARGET/sink" "$CORE_TARGET/vault" "$CORE_TARGET/logs" "$CORE_TARGET/config"
chmod -R u+rwX,go-rwx "$CORE_TARGET/sink" "$CORE_TARGET/vault" "$CORE_TARGET/logs" "$CORE_TARGET/config"

ENV_FILE="$CORE_TARGET/.env"
if [[ ! -f "$ENV_FILE" ]]; then
  cp "$CORE_TARGET/.env.example" "$ENV_FILE"
fi

log "Configuring required environment secrets"
upsert_env_value "$ENV_FILE" AIMS_SHARED_SECRET "$SHARED_SECRET"
upsert_env_value "$ENV_FILE" AIMS_ARCHIVE_SECRET "$ARCHIVE_SECRET"
upsert_env_value "$ENV_FILE" AIMS_ENCRYPTION_KEY "$ENCRYPTION_KEY"
upsert_env_value "$ENV_FILE" AIMS_BINARY_STREAM_MODE shadow
upsert_env_value "$ENV_FILE" AIMS_BINARY_PRIMARY_APPROVED 0

if [[ -n "$CORE_BASE_URL" ]]; then
  probe_status_endpoint "$CORE_BASE_URL" "$SHARED_SECRET"
fi

if [[ -n "$BASE_DOMAIN" ]]; then
  log "DNS reminder: ensure aims.${BASE_DOMAIN}.${DOMAIN_SUFFIX} points to this host before relying on subdomain probes."
fi

log "Install script completed successfully."
log "Next steps in WordPress admin:"
log "  1) Activate plugin: $PLUGIN_SLUG"
log "  2) Set AIMS API URL to the core route base"
log "  3) Set AIMS Token to AIMS_SHARED_SECRET from $ENV_FILE"
log "  4) Open AIMS Dashboard and verify Core Status"
