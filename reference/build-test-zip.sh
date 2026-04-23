#!/usr/bin/env bash
# Build a timestamped test release ZIP into reference/.
# Run from anywhere; always writes next to this script.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

VERSION=$(grep -oP "CLOUDPE_CMP_MODULE_VERSION'\s*,\s*'\K[^']+" \
    "$REPO_ROOT/modules/addons/cloudpe_cmp_admin/cloudpe_cmp_admin.php")
TS=$(date +%Y-%m-%d_%H-%M-%S)
OUT="$SCRIPT_DIR/cloudpe-cmp-whmcs-module-v${VERSION}-${TS}.zip"

cd "$REPO_ROOT"
zip -rq "$OUT" modules/
echo "Built: $OUT ($(du -h "$OUT" | cut -f1))"
