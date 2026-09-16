#!/usr/bin/env bash
# Sourced by operations scripts. No sourcing of untrusted .env as shell code.
set -euo pipefail
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="${KIRH_COMPOSE_ENV:-${PROJECT_ROOT}/infra/compose/.env}"
dc() { docker compose --env-file "$ENV_FILE" -f "$PROJECT_ROOT/infra/compose/compose.yml" -f "$PROJECT_ROOT/infra/compose/compose.production.yml" "$@"; }
log_info() { printf '\033[34m[INFO]\033[0m %s\n' "$*"; }
log_success() { printf '\033[32m[OK]\033[0m %s\n' "$*"; }
log_error() { printf '\033[31m[ERROR]\033[0m %s\n' "$*" >&2; }
preflight() {
    command -v docker >/dev/null
    test -f "$ENV_FILE"
    docker compose version >/dev/null
    docker info >/dev/null
    dc config --quiet
}
