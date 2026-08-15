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

if ! command -v iconv >/dev/null 2>&1; then
  echo "error: 'iconv' command not found. Needed to convert the readmes to" >&2
  echo "ISO-8859-1 for eedomus's doc viewer (see SESSION_NOTES.md Piege n10)." >&2
  exit 1
fi

ZIP_NAME="aps_eedomus_plugin.zip"

rm -rf dist
mkdir -p dist

# readme_en.md/readme_fr.md are kept in UTF-8 in the repo for easy editing,
# but eedomus's doc.php help viewer expects ISO-8859-1, like the .php
# scripts (see SESSION_NOTES.md Piege n5/n10) - convert only at build time,
# into the zip, never touching the UTF-8 sources.
iconv -f UTF-8 -t ISO-8859-1 readme_en.md > dist/readme_en.md
iconv -f UTF-8 -t ISO-8859-1 readme_fr.md > dist/readme_fr.md

zip -j "dist/$ZIP_NAME" \
  eedomus_plugin.json \
  aps_solar.php \
  dist/readme_en.md \
  dist/readme_fr.md

# Only the icon goes in the zip's img/ - the eedomus Store validates every
# file under img/ as an icon candidate (must be 128x128), so the doc
# screenshots (referenced via GitHub raw URLs in the readmes, not bundled)
# must NOT be included here.
mkdir -p dist/img
cp img/apsolar.png dist/img/apsolar.png
(cd dist && zip -r "$ZIP_NAME" img)
rm -rf dist/img

rm dist/readme_en.md dist/readme_fr.md

echo "Built dist/$ZIP_NAME"
