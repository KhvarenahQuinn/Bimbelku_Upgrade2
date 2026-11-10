#!/usr/bin/env bash
# Pintasan agar proyek bisa dijalankan dengan: ./start.sh
set -euo pipefail
exec bash "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/scripts/run-local.sh" "$@"
