#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SLUG="mfr-keyword-limiter"
MAIN_FILE="${SLUG}.php"
ZIP_PATH=""
EXPECTED_HOST="https://updatemachine.com"
EXPECTED_UPDATE_URI="${EXPECTED_HOST}/${SLUG}/update.json"
EXPECTED_UPDATER_SHA256="441bea98932657fa87468cf7ad18c9d609b0e01c4fcc343f66a5f783e4b4ed97"

usage() {
	cat <<EOF
Usage: $(basename "$0") [--zip <path>]

Validates release metadata, Update Machine wiring, the committed updater,
PHP syntax, and optionally the contents of a final release ZIP.
EOF
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--zip)
			ZIP_PATH="${2:-}"
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			echo "Unknown argument: $1" >&2
			usage >&2
			exit 2
			;;
	esac
done

fail() {
	echo "::error::$*" >&2
	exit 1
}

header_value() {
	local file="$1"
	local header="$2"
	sed -n "s/^[[:space:]]*\* ${header}:[[:space:]]*//p" "$file" | head -n 1 | xargs
}

sha256_file() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{print $1}'
	else
		shasum -a 256 "$1" | awk '{print $1}'
	fi
}

[ -f "$MAIN_FILE" ] || fail "Main plugin file not found: ${MAIN_FILE}"
[ -s "includes/um-updater.php" ] || fail "includes/um-updater.php is missing or empty"
[ -f "readme.txt" ] || fail "readme.txt is missing"

PLUGIN_NAME="$(header_value "$MAIN_FILE" 'Plugin Name')"
PLUGIN_DESCRIPTION="$(header_value "$MAIN_FILE" 'Description')"
PLUGIN_VERSION="$(header_value "$MAIN_FILE" 'Version')"
REQUIRES="$(header_value "$MAIN_FILE" 'Requires at least')"
REQUIRES_PHP="$(header_value "$MAIN_FILE" 'Requires PHP')"
UPDATE_URI="$(header_value "$MAIN_FILE" 'Update URI')"
TEXT_DOMAIN="$(header_value "$MAIN_FILE" 'Text Domain')"
TESTED="$(sed -n 's/^Tested up to:[[:space:]]*//p' readme.txt | head -n 1 | xargs)"
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -n 1 | xargs)"
VERSION_CONSTANT="$(sed -n "s/^define( 'MFR_KEYWORD_LIMITER_VERSION', '\([^']*\)' );/\1/p" "$MAIN_FILE" | head -n 1)"

[ -n "$PLUGIN_NAME" ] || fail "Plugin Name header is missing or empty"
[ -n "$PLUGIN_DESCRIPTION" ] || fail "Description header is missing or empty"
[ -n "$PLUGIN_VERSION" ] || fail "Version header is missing or empty"
[ -n "$REQUIRES" ] || fail "Requires at least header is missing or empty"
[ -n "$REQUIRES_PHP" ] || fail "Requires PHP header is missing or empty"
[ -n "$TESTED" ] || fail "Tested up to is missing or empty in readme.txt"
[ "$STABLE_TAG" = "$PLUGIN_VERSION" ] || fail "readme.txt Stable tag (${STABLE_TAG:-missing}) does not match Version (${PLUGIN_VERSION})"
[ "$VERSION_CONSTANT" = "$PLUGIN_VERSION" ] || fail "MFR_KEYWORD_LIMITER_VERSION (${VERSION_CONSTANT:-missing}) does not match Version (${PLUGIN_VERSION})"
[ "$TEXT_DOMAIN" = "$SLUG" ] || fail "Text Domain must be ${SLUG}, found ${TEXT_DOMAIN:-missing}"
[ "$UPDATE_URI" = "$EXPECTED_UPDATE_URI" ] || fail "Update URI must be ${EXPECTED_UPDATE_URI}, found ${UPDATE_URI:-missing}"

grep -Fq "'slug'       => '${SLUG}'" "$MAIN_FILE" || fail "Updater registration slug is missing or incorrect"
grep -Fq "'update_url' => '${EXPECTED_UPDATE_URI}'" "$MAIN_FILE" || fail "Updater registration URL is missing or incorrect"
grep -Fq "'server'     => '${EXPECTED_HOST}'" "$MAIN_FILE" || fail "Updater server is missing or incorrect"

UPDATER_SHA256="$(sha256_file includes/um-updater.php)"
[ "$UPDATER_SHA256" = "$EXPECTED_UPDATER_SHA256" ] || fail "includes/um-updater.php SHA-256 mismatch: ${UPDATER_SHA256}"

if command -v php >/dev/null 2>&1; then
	for file in "$MAIN_FILE" includes/um-updater.php uninstall.php; do
		php -l "$file" >/dev/null || fail "PHP syntax check failed: ${file}"
	done
else
	echo "::warning::php is unavailable; PHP syntax checks were skipped"
fi

if [ -n "$ZIP_PATH" ]; then
	[ -f "$ZIP_PATH" ] || fail "Release ZIP not found: ${ZIP_PATH}"
	command -v unzip >/dev/null 2>&1 || fail "unzip is required to validate the release ZIP"

	ZIP_LIST="$(mktemp)"
	ZIP_FILE="$(mktemp)"
	trap 'rm -f "$ZIP_LIST" "$ZIP_FILE"' EXIT
	unzip -Z1 "$ZIP_PATH" > "$ZIP_LIST"

	while IFS= read -r entry; do
		case "$entry" in
			"${SLUG}"|"${SLUG}/"|"${SLUG}/"*) ;;
			*) fail "ZIP entry is outside the ${SLUG}/ top-level directory: ${entry}" ;;
		esac
	done < "$ZIP_LIST"

	for required_file in \
		"${SLUG}/${MAIN_FILE}" \
		"${SLUG}/includes/um-updater.php" \
		"${SLUG}/readme.txt" \
		"${SLUG}/uninstall.php"; do
		grep -qx "$required_file" "$ZIP_LIST" || fail "ZIP is missing ${required_file}"
	done

	while IFS= read -r entry; do
		case "$entry" in
			*/|"") ;;
			"${SLUG}/${MAIN_FILE}"|"${SLUG}/readme.txt"|"${SLUG}/uninstall.php"|"${SLUG}/includes/um-updater.php") ;;
			*) fail "ZIP contains an unexpected runtime file: ${entry}" ;;
		esac
	done < "$ZIP_LIST"

	FORBIDDEN='(^|/)(\.git|\.github|node_modules|vendor|tests|docs|scripts|release)(/|$)|(^|/)(\.distignore|\.gitignore|composer\..*|package.*\.json|phpunit\..*|.*\.map|.*\.zip)$'
	if grep -Eq "$FORBIDDEN" "$ZIP_LIST"; then
		echo "::error::ZIP contains repository-only files:" >&2
		grep -E "$FORBIDDEN" "$ZIP_LIST" >&2
		exit 1
	fi

	for source_file in "$MAIN_FILE" readme.txt uninstall.php includes/um-updater.php; do
		unzip -p "$ZIP_PATH" "${SLUG}/${source_file}" > "$ZIP_FILE"
		cmp -s "$source_file" "$ZIP_FILE" || fail "Final ZIP copy does not match source: ${source_file}"
	done

	unzip -p "$ZIP_PATH" "${SLUG}/includes/um-updater.php" > "$ZIP_FILE"
	ZIP_UPDATER_SHA256="$(sha256_file "$ZIP_FILE")"
	[ "$ZIP_UPDATER_SHA256" = "$EXPECTED_UPDATER_SHA256" ] || fail "Bundled updater SHA-256 mismatch in final ZIP: ${ZIP_UPDATER_SHA256}"
fi

echo "Validated ${PLUGIN_NAME} (${SLUG}) v${PLUGIN_VERSION}"
