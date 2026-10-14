#!/usr/bin/env bash
# Start a local WordPress (Playground CLI, PHP-WASM + SQLite) with the plugin folder mounted.
# Usage: tests/serve.sh PORT PHP_VERSION [extra CLI args...]   (run in the background; prints the server log)
cd "$(dirname "$0")/.."
PORT=${1:-9400}; PHPV=${2:-8.3}; shift 2 || true
exec tools/node_modules/.bin/wp-playground-cli server \
  --port="$PORT" --php="$PHPV" --wp=latest \
  --mount="$PWD/site-answers:/wordpress/wp-content/plugins/site-answers" \
  --mount="$PWD/tests/mu/sa-test-helper.php:/wordpress/wp-content/mu-plugins/sa-test-helper.php" \
  --blueprint="$PWD/tests/blueprint-local-$PHPV.json" \
  --define-bool WP_DEBUG true --define-bool WP_DEBUG_LOG true \
  --login "$@"
