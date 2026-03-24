#!/usr/bin/env bash
set -euo pipefail

echo "Setting up local dev environment for AIMS (Phase 0)"
composer install --no-interaction --prefer-dist
echo "Running phpunit (if available)..."
if [ -f ./vendor/bin/phpunit ]; then
  ./vendor/bin/phpunit --configuration phpunit.xml.dist --colors=always || true
else
  echo "phpunit not installed via composer; skipping."
fi

echo "Done. Use ./vendor/bin/phpunit to run tests locally."
