#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
find . -path './dist' -prune -o -path './tests' -prune -o -name '*.php' -type f -exec php -l {} \;
php tests/contract_test.php
php tests/route_scope_test.php
php tests/source_contract_test.php
php tests/api_schema_test.php
../../scripts/node_modules/.bin/tsx tests/server_normalizer_bridge.test.ts
php tests/console_test.php
php ../wordpress/portable-console/tests/core-fixtures.php ServiceConsole "SendRepute\\MailAdapter\\ServiceConsole"
php tests/package_test.php