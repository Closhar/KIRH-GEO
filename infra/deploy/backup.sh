#!/usr/bin/env bash
# Usage: KIRH_BACKUP_DIR=/absolute/private/path bash infra/deploy/backup.sh
# Archive must be encrypted/copied to independent storage by the operator.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
preflight
umask 077
backup_dir="${KIRH_BACKUP_DIR:-${PROJECT_ROOT}/backups}"
[[ "$backup_dir" = /* && "$backup_dir" != / ]] || { log_error 'Use an absolute backup directory'; exit 1; }
mkdir -p "$backup_dir"
archive="$(mktemp "$backup_dir/kirh-geo-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX.dump")"
dc exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom --no-owner --no-acl' > "$archive"
test -s "$archive"
dc exec -T postgres pg_restore --list < "$archive" >/dev/null
sha256sum "$archive" > "$archive.sha256"
log_success "Backup validated: $archive"
# Never automatically remove backups until remote upload and retention are configured.
