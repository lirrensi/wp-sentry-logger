#!/usr/bin/env bash
# Build a distributable zip with vendor/ included. Run from the plugin dir OR its parent.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$here"
composer install --no-dev --quiet
rm -f error-logger-for-sentry-*.zip
dst="error-logger-for-sentry-${1:-local}.zip"
php bin/make-zip.php "$dst"
echo "built $dst"
