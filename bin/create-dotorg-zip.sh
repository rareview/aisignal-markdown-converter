#!/bin/sh

set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname "$0")" && pwd -P)"
REPO_DIR="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd -P)"
DIST_DIR="${REPO_DIR}/dist"
ZIP_PATH="${DIST_DIR}/web-page-content-to-markdown-converter.zip"
DISTIGNORE_PATH="${REPO_DIR}/.distignore"
TEMP_DIR="$(mktemp -d)"
STAGE_DIR="${TEMP_DIR}/web-page-content-to-markdown-converter"

cleanup() {
	rm -rf "${TEMP_DIR}"
}

trap cleanup EXIT INT TERM

if [ ! -f "${REPO_DIR}/web-page-content-to-markdown-converter.php" ]; then
	echo "Main plugin file not found in ${REPO_DIR}" >&2
	exit 1
fi

if [ ! -f "${DISTIGNORE_PATH}" ]; then
	echo ".distignore not found in ${REPO_DIR}" >&2
	exit 1
fi

rm -rf "${ZIP_PATH}"
mkdir -p "${DIST_DIR}" "${STAGE_DIR}"

rsync -a --delete --delete-excluded \
	--exclude-from="${DISTIGNORE_PATH}" \
	"${REPO_DIR}/" "${STAGE_DIR}/"

(
	cd "${TEMP_DIR}"
	zip -rq "${ZIP_PATH}" "web-page-content-to-markdown-converter"
)

echo "Created ${ZIP_PATH}"
