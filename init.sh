#!/usr/bin/env bash
# CodeIgniter 4 BFF Starter — environment initializer.
# Run from the project root after cloning.
# Usage: ./init.sh [--skip-deps] [--skip-server]
#
# The BFF is stateless: this script does not create databases, run
# migrations, or sync permissions. It only installs PHP dependencies,
# writes .env, validates wiring, and optionally starts the dev server.

set -euo pipefail

# ---------------------------------------------------------------------------
# Output helpers (inlined — no scripts/setup.sh needed)
# ---------------------------------------------------------------------------

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

print_header() { printf "\n${BLUE}==> %s${NC}\n" "$1"; }
print_ok()     { printf "${GREEN}OK${NC} %s\n" "$1"; }
print_warn()   { printf "${YELLOW}WARN${NC} %s\n" "$1"; }
print_error()  { printf "${RED}ERROR${NC} %s\n" "$1"; }

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    print_error "Required command not found: $1"
    exit 1
  fi
}

trim() {
  local value="$1"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  printf "%s" "$value"
}

ask_with_default() {
  local prompt="$1" default="$2" answer
  read -r -p "$prompt [$default]: " answer
  answer="$(trim "$answer")"
  printf "%s" "${answer:-$default}"
}

# ---------------------------------------------------------------------------
# Flags
# ---------------------------------------------------------------------------

SKIP_DEPS=false
SKIP_SERVER=false

while [ $# -gt 0 ]; do
  case $1 in
    --skip-deps)   SKIP_DEPS=true; shift ;;
    --skip-server) SKIP_SERVER=true; shift ;;
    --help)
      printf "Usage: ./init.sh [OPTIONS]\n\n"
      printf "Options:\n"
      printf "  --skip-deps     Skip composer install\n"
      printf "  --skip-server   Do not offer to start the development server\n"
      printf "  --help          Show this help message\n"
      exit 0
      ;;
    *)
      print_error "Unknown option: $1"
      exit 1
      ;;
  esac
done

LOG_FILE="$(pwd)/init.log"
if [ "${CI4_FORCE_LOG_TO_FILE:-false}" = "true" ]; then
  exec >"$LOG_FILE" 2>&1
else
  exec > >(tee -a "$LOG_FILE") 2>&1
fi
printf "Init log: %s\n" "$LOG_FILE"

print_header "CI4 BFF Starter — Environment Setup"

# ---------------------------------------------------------------------------
# Requirements
# ---------------------------------------------------------------------------

print_header "Checking requirements"
require_cmd php
require_cmd composer

if ! php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; then
  print_error "PHP 8.2+ is required (found: $(php -r 'echo PHP_VERSION;'))."
  exit 1
fi
print_ok "Dependencies found (php, composer)"

# ---------------------------------------------------------------------------
# BFF coordinates
# ---------------------------------------------------------------------------

print_header "BFF coordinates"
printf "The BFF is a stateless gateway over a hub (ci4-api-starter) and an\n"
printf "optional domain app (ci4-domain-starter). You need the URLs of each\n"
printf "and the comma-separated list of client origins allowed by CORS.\n\n"

HUB_URL="$(ask_with_default 'Hub URL' "${BFF_HUB_URL:-http://localhost:8180}")"
DOMAIN_URL="$(ask_with_default 'Domain URL (blank = no domain)' "${BFF_DOMAIN_URL:-http://localhost:8190}")"
ALLOWED_ORIGINS="$(ask_with_default 'Allowed origins (CSV)' "${BFF_ALLOWED_ORIGINS:-http://localhost:3000,http://localhost:5173}")"

# Optional: hub appCode + apiKey for M2M service-token calls. Most BFFs do not
# need these (forward-only auth), so they default to empty.
HUB_APP_CODE="${BFF_APP_CODE:-}"
HUB_API_KEY="${BFF_API_KEY:-}"

# ---------------------------------------------------------------------------
# Dependencies
# ---------------------------------------------------------------------------

if [ "$SKIP_DEPS" = false ]; then
  print_header "Installing dependencies"
  composer install --no-interaction --no-progress
fi

# ---------------------------------------------------------------------------
# .env
# ---------------------------------------------------------------------------

print_header "Writing .env"
if [ -f ".env" ]; then
  print_warn ".env exists. Backing up to .env.bak.$(date +%s)"
  cp .env ".env.bak.$(date +%s)"
fi

cp .env.example .env
php scripts/bootstrap_env.php \
  --file .env \
  --set "bff.hubUrl=${HUB_URL}" \
  --set "bff.domainUrl=${DOMAIN_URL}" \
  --set "BFF_ALLOWED_ORIGINS=${ALLOWED_ORIGINS}" \
  --set "hub.url=${HUB_URL}" \
  --set "hub.appCode=${HUB_APP_CODE}" \
  --set "hub.apiKey=${HUB_API_KEY}"

# Generate a CI4 encryption key (idempotent — the spark command rotates the
# placeholder in .env to a fresh value).
php spark key:generate --force >/dev/null

print_ok ".env written"

# ---------------------------------------------------------------------------
# Wiring check
# ---------------------------------------------------------------------------

print_header "Validating ci4-api-core service wiring"
if php spark core:check 2>/dev/null; then
  print_ok "Service wiring OK"
else
  print_error "core:check failed — fix app/Config/Services.php before considering the BFF ready."
  exit 1
fi

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------

print_header "Done"
printf "BFF ready at: %s\n" "$(pwd)"
printf "Hub:    %s\n" "$HUB_URL"
printf "Domain: %s\n" "${DOMAIN_URL:-<none>}"
printf "CORS:   %s\n" "$ALLOWED_ORIGINS"

BFF_PORT="${BFF_PORT:-8188}"

if [ "$SKIP_SERVER" = false ]; then
  read -r -p "Start development server now? (y/N): " START_SERVER
  case "$(echo "$START_SERVER" | tr '[:upper:]' '[:lower:]')" in
    y|yes)
      print_header "Starting development server"
      printf "Server at http://localhost:%s — press Ctrl+C to stop.\n\n" "$BFF_PORT"
      php spark serve --port "$BFF_PORT"
      ;;
    *)
      printf "Start server: php spark serve --port %s\n" "$BFF_PORT"
      ;;
  esac
fi
