#!/usr/bin/env bash
# Usage: bash infra/deploy/restore-drill.sh /absolute/path/archive.dump
# Creates a NEW uniquely named drill DB; never drops or overwrites an existing DB.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
preflight
archive="${1:?Supply archive path}"
test -f "$archive"
sha256sum --check "$archive.sha256"
drill="kirh_restore_$(date -u +%Y%m%d_%H%M%S)_${RANDOM}"
dc exec -T postgres createdb -U kirh_migrator "$drill"
dc exec -T postgres pg_restore -U kirh_migrator -d "$drill" --no-owner --no-acl --exit-on-error < "$archive"
dc exec -T postgres psql -U kirh_migrator -d "$drill" -v ON_ERROR_STOP=1 -c 'SELECT PostGIS_Version(); SELECT count(*) FROM migrations;'
log_success "Restore readable in $drill. Run application smoke tests against it; retain for review and remove explicitly afterwards."
