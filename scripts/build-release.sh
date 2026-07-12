#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SLUG="mfr-keyword-limiter"
MAIN_FILE="${SLUG}.php"
RELEASE_DIR="${RELEASE_DIR:-release}"
VERSION="${PLUGIN_VERSION:-}"

if [ -z "$VERSION" ]; then
	VERSION="$(sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' "$MAIN_FILE" | head -n 1 | xargs)"
fi

if [ -z "$VERSION" ]; then
	echo "Unable to determine the plugin version from ${MAIN_FILE}." >&2
	exit 1
fi

DEST="${RELEASE_DIR}/${SLUG}"
ZIP="${RELEASE_DIR}/${SLUG}-${VERSION}.zip"

rm -rf "$RELEASE_DIR"
mkdir -p "$DEST"

rsync -a \
	--exclude-from='.distignore' \
	--exclude="${RELEASE_DIR}/" \
	./ "$DEST/"

find "$DEST" -type f \( -name '.DS_Store' -o -name 'Thumbs.db' \) -delete

(
	cd "$RELEASE_DIR"
	zip -qr "${SLUG}-${VERSION}.zip" "$SLUG"
)

echo "Built ${ZIP}"
