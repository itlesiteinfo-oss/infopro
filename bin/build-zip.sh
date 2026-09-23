#!/bin/bash
# Builds dist/horizon-press-news-bar-<version>.zip from the plugin source folder (production files
# only). The version comes from the plugin header, so the file name always says what it holds; the
# folder inside stays horizon-press-news-bar/, which is what WordPress installs.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/horizon-press-news-bar"
DIST="$ROOT/dist"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' "$SRC/horizon-press-news-bar.php" | head -1)"
[ -n "$VERSION" ] || { echo "Version header not found" >&2; exit 1; }
ZIP="$DIST/horizon-press-news-bar-$VERSION.zip"
mkdir -p "$DIST"
rm -f "$DIST"/horizon-press-news-bar*.zip
cd "$ROOT"
zip -r -X -q "$ZIP" horizon-press-news-bar \
  -x 'horizon-press-news-bar/.*' \
  -x '*/.DS_Store' -x '*/Thumbs.db' -x '*.log' -x '*.bak' -x '*.tmp' -x '*.swp' -x '*~' \
  -x 'horizon-press-news-bar/node_modules/*' -x 'horizon-press-news-bar/vendor/*' -x 'horizon-press-news-bar/tests/*'
echo "Built $ZIP ($(wc -c < "$ZIP") bytes)"
unzip -l "$ZIP" | tail -1
