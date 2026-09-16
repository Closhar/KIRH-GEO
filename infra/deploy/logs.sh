#!/usr/bin/env bash
# Usage: bash infra/deploy/logs.sh [service]
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/common.sh"
preflight
dc logs --tail=100 --follow "$@"
