#!/usr/bin/env bash
#
# Build a clean distribution ZIP for SSO for Microsoft Entra.
#
# Usage: bash .github/scripts/build-zip.sh [version]
#   version: optional, defaults to the version in the plugin header.
#
# The ZIP is written to the repo root as sso-for-microsoft-entra-{version}.zip
# with a single top-level directory sso-for-microsoft-entra/.
#
# File set matches the v2.6.1 manual release ZIP:
#   INCLUDED  : .wordpress-org/ assets/ includes/ languages/ templates/
#               readme.txt sso-for-microsoft-entra.php uninstall.php
#               CHANGELOG.md LICENSE README.md
#   EXCLUDED  : .git/ .github/ docs/ tests/ vendor/
#               composer.json composer.lock phpcs.xml.dist phpunit.xml.dist
#               .gitignore and other dotfiles
#

set -euo pipefail

PLUGIN_SLUG="sso-for-microsoft-entra"
ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
VERSION="${1:-$(grep -oP "Version:\s*\K[\d.]+" "$ROOT_DIR/sso-for-microsoft-entra.php" | head -1)}"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
BUILD_DIR="/tmp/${PLUGIN_SLUG}-build-$$"

echo "==> Building ${PLUGIN_SLUG} v${VERSION}..."

# Clean up any previous build artifacts
rm -f "$ROOT_DIR/${ZIP_NAME}"

# Create a clean copy excluding dev files (matches v2.6.1 manual ZIP)
mkdir -p "$BUILD_DIR"

rsync -a --delete \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.gitignore' \
  --exclude='docs/' \
  --exclude='tests/' \
  --exclude='vendor/' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='phpcs.xml.dist' \
  --exclude='phpunit.xml.dist' \
  --exclude='*.zip' \
  "$ROOT_DIR/" "${BUILD_DIR}/${PLUGIN_SLUG}/" > /dev/null

# Verify the main plugin file exists
if [[ ! -f "${BUILD_DIR}/${PLUGIN_SLUG}/sso-for-microsoft-entra.php" ]]; then
  echo "ERROR: Main plugin file missing from build!"
  rm -rf "$BUILD_DIR"
  exit 1
fi

# Create the ZIP from the build directory
cd "$BUILD_DIR"
zip -r "${ROOT_DIR}/${ZIP_NAME}" "${PLUGIN_SLUG}/" > /dev/null
cd "$ROOT_DIR"

# Clean up
rm -rf "$BUILD_DIR"

echo "==> Done: ${ZIP_NAME} ($(du -h "${ROOT_DIR}/${ZIP_NAME}" | cut -f1))"