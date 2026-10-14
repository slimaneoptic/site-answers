#!/usr/bin/env bash
# Lint every PHP file of the plugin on PHP 7.4 and 8.3 (PHP-WASM, no system PHP needed).
set -u
cd "$(dirname "$0")/.."
PHPBIN=tools/node_modules/.bin/php-wasm-cli
fail=0
for v in 7.4 8.3; do
  for f in $(find site-answers -name '*.php' | sort); do
    out=$(PHP=$v "$PHPBIN" -l "$f" 2>&1)
    if ! grep -q "No syntax errors" <<<"$out"; then echo "PHP $v FAIL $f: $out"; fail=1; fi
  done
  echo "PHP $v: $(find site-answers -name '*.php' | wc -l) files checked"
done
exit $fail
