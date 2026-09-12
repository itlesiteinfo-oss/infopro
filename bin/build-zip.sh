#!/bin/bash
# Builds dist/horizon-press-news-bar.zip from the plugin source folder (production files only).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/horizon-press-news-bar"
DIST="$ROOT/dist"
ZIP="$DIST/horizon-press-news-bar.zip"
mkdir -p "$DIST"
rm -f "$ZIP"
cd "$ROOT"
zip -r -X -q "$ZIP" horizon-press-news-bar \
  -x 'horizon-press-news-bar/.*' \
  -x '*/.DS_Store' -x '*/Thumbs.db' -x '*.log' -x '*.bak' -x '*.tmp' -x '*.swp' -x '*~' \
  -x 'horizon-press-news-bar/node_modules/*' -x 'horizon-press-news-bar/vendor/*' -x 'horizon-press-news-bar/tests/*'
echo "Built $ZIP ($(wc -c < "$ZIP") bytes)"
unzip -l "$ZIP" | tail -1
