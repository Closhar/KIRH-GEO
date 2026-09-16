#!/usr/bin/env bash
# Usage: set immutable API_IMAGE, PROXY_IMAGE and SHARE_IMAGE in compose env, then run this.
# Requires backwards-compatible migrations; brief single-instance interruption.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
preflight
bash "$PROJECT_ROOT/infra/deploy/backup.sh"
dc pull
dc run --rm --no-deps -e DB_USERNAME=kirh_migrator \
    -e "DB_PASSWORD=$(dc exec -T postgres printenv POSTGRES_PASSWORD)" api php artisan migrate --force
dc up -d --no-deps --wait --wait-timeout 120 api worker scheduler reverb proxy share
bash "$PROJECT_ROOT/infra/deploy/health-check.sh"
log_success 'Release updated; verify authenticated GPS and payment smoke checks.'
