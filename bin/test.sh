#!/bin/sh
# Atajo opcional (Git Bash / Mac / Linux): corre las pruebas de una o varias versiones.
# Optional shortcut (Git Bash / Mac / Linux): runs the tests for one or several versions.
#   bin/test.sh          -> l13
#   bin/test.sh l10 l11  -> esas versiones / those versions
#   bin/test.sh all      -> l10 l11 l12 l13
set -u

cd "$(dirname "$0")/.."

targets="${*:-l13}"
[ "$targets" = "all" ] && targets="l10 l11 l12 l13"

failed=""
for target in $targets; do
  echo "=== $target"
  docker compose run --rm "$target" || failed="$failed $target"
done

if [ -n "$failed" ]; then
  echo "Fallaron / Failed:$failed"
  exit 1
fi
echo "Todo en verde / All green: $targets"
