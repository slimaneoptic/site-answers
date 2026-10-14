#!/usr/bin/env bash
# PHPCS with the WordPress Coding Standards (phpcs.xml.dist), through PHP-WASM (no system PHP needed).
# Needs: PHPCS_PHAR=/path/to/phpcs.phar and PHPCS_STANDARDS=comma list of the WordPress-Coding-Standards,
# PHPCSExtra and PHPCSUtils folders. Prints the totals; exit 1 on any error or warning.
set -u
cd "$(dirname "$0")/.."
: "${PHPCS_PHAR:?set PHPCS_PHAR}" "${PHPCS_STANDARDS:?set PHPCS_STANDARDS}"
PHP=8.3 tools/node_modules/.bin/php-wasm-cli "$PHPCS_PHAR" --runtime-set installed_paths "$PHPCS_STANDARDS" \
  --standard=phpcs.xml.dist --report=json </dev/null 2>/dev/null | python3 -c '
import json, sys
raw = sys.stdin.read()
d = json.loads(raw[raw.find("{"):])
for f, v in d["files"].items():
    for m in v["messages"]:
        print(f.split("/site-answers/")[-1], m["line"], m["type"], m["source"])
t = d["totals"]
print("PHPCS (WordPress): %d files, %d errors, %d warnings" % (len(d["files"]), t["errors"], t["warnings"]))
sys.exit(1 if t["errors"] or t["warnings"] else 0)'
