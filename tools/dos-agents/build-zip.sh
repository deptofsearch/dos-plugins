#!/usr/bin/env bash
# Builds the DoS - Agents release ZIP locally, the same way .github/workflows/release.yml does:
# lint, run every test, copy the plugin (minus tests/ and dist/), zip it with `dos-agents/` as the
# only top-level folder. Writes plugins/dos-agents/dist/dos-agents-<version>.zip.
#
#   tools/dos-agents/build-zip.sh                # uses `php` from PATH
#   PHP=/opt/homebrew/bin/php tools/dos-agents/build-zip.sh
#
# Nothing here tags, commits or uploads. Release from CI: `git tag dos-agents-v<version>`.
set -euo pipefail

PHP="${PHP:-php}"
SLUG="dos-agents"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

HEADER=$(grep -m1 -E '^\s*\*\s*Version:' "plugins/${SLUG}/${SLUG}.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
CONST=$(grep -m1 -E "define\([[:space:]]*'[A-Za-z0-9_]*_VERSION'[[:space:]]*," "plugins/${SLUG}/${SLUG}.php" | sed -E "s/.*,[[:space:]]*'([^']*)'.*/\1/")
if [ "$HEADER" != "$CONST" ]; then
  echo "Plugin header says ${HEADER}, the version constant says ${CONST}." >&2
  exit 1
fi

find "plugins/${SLUG}" -name '*.php' -print0 | xargs -0 -n1 "$PHP" -l >/dev/null
for t in tests/test-*.php; do
  "$PHP" "$t" >/dev/null || { echo "FAILED: $t" >&2; exit 1; }
done

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
cp -R "plugins/${SLUG}" "${WORK}/${SLUG}"
rm -rf "${WORK}/${SLUG}/tests" "${WORK}/${SLUG}/dist"
find "$WORK" -name '.DS_Store' -delete

OUT="plugins/${SLUG}/dist/${SLUG}-${HEADER}.zip"
mkdir -p "plugins/${SLUG}/dist"
rm -f "$OUT"
( cd "$WORK" && zip -rq "${ROOT}/${OUT}" "${SLUG}" )

unzip -l "$OUT"
echo "Built ${OUT}"
