#!/usr/bin/env bash
# Usage: bash infra/deploy/deploy.sh (initial installation only)
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
preflight
if [[ -n "$(dc ps -aq)" ]]; then log_error 'Project containers already exist; use update.sh.'; exit 1; fi
log_info 'Pulling the configured release images'
dc pull
dc up -d --wait --wait-timeout 120 postgres redis
# Role credentials are read from the DB container, never printed.
dc run --rm --no-deps -e DB_USERNAME=kirh_migrator \
    -e "DB_PASSWORD=$(dc exec -T postgres printenv POSTGRES_PASSWORD)" api php artisan migrate --force
dc up -d --wait --wait-timeout 120
bash "$PROJECT_ROOT/infra/deploy/health-check.sh"
log_success 'Release started. Configure a separate host TLS virtual host before public access.'
