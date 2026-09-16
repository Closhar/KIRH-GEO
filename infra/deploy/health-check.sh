#!/usr/bin/env bash
# Usage: bash infra/deploy/health-check.sh
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
preflight
for service in postgres redis api worker scheduler reverb proxy share; do
    container="$(dc ps -q "$service")"
    [[ -n "$container" ]] || { log_error "$service missing"; exit 1; }
    health="$(docker inspect --format '{{.State.Health.Status}}' "$container")"
    [[ "$health" == healthy ]] || { log_error "$service: $health"; exit 1; }
done
dc exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -c "SELECT PostGIS_Version();"'
dc exec -T proxy wget -q -O /dev/null http://127.0.0.1:8080/up
dc exec -T share wget -q -O /dev/null http://127.0.0.1:8080/
log_success 'All eight services present and healthy; PostGIS, API and share viewer respond.'
