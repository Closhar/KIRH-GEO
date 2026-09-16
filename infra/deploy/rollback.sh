#!/usr/bin/env bash
# Usage: API_IMAGE=registry/api:previous-sha PROXY_IMAGE=registry/proxy:previous-sha SHARE_IMAGE=registry/share:previous-sha bash infra/deploy/rollback.sh
# App-only rollback; only safe when new DB schema is backward compatible.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
: "${API_IMAGE:?Specify previous API image}"
: "${PROXY_IMAGE:?Specify previous proxy image}"
: "${SHARE_IMAGE:?Specify previous share image}"
export API_IMAGE PROXY_IMAGE SHARE_IMAGE
preflight
dc pull api worker scheduler reverb proxy share
dc up -d --no-deps --wait --wait-timeout 120 api worker scheduler reverb proxy share
bash "$PROJECT_ROOT/infra/deploy/health-check.sh"
log_success 'Application images rolled back; database unchanged.'
