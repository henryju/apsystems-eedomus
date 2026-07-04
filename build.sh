#!/usr/bin/env bash
# Builds dist/aps_eedomus_plugin.zip from the plugin sources.
# Requires the `zip` command (present on ubuntu-latest CI runners; on
# Windows, use build.ps1 instead).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

if ! command -v zip >/dev/null 2>&1; then
  echo "error: 'zip' command not found. Install it (e.g. 'apt install zip')," >&2
  echo "or use build.ps1 on Windows." >&2
  exit 1
fi

ZIP_NAME="aps_eedomus_plugin.zip"

rm -rf dist
mkdir -p dist

zip -r "dist/$ZIP_NAME" \
  eedomus_plugin.json \
  aps_solar.php \
  aps_discover.php \
  readme_en.md \
  readme_fr.md \
  img

echo "Built dist/$ZIP_NAME"
