#!/usr/bin/env bash
# Builds the installable plugin zip. Usage, from the repo root: scripts/build-zip.sh <out-dir>
# WordPress 7.1.4+ unpacks into a randomly suffixed folder, so the zip holds one top-level folder.
set -euo pipefail

SLUG=authorize-net-terminal-for-woocommerce
MAIN="$SLUG.php"
OUT=${1:?usage: scripts/build-zip.sh <out-dir>}

VERSION=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$MAIN" | head -n1)
CONST=$(sed -n "s/^define('ANTWC_VERSION', '\([^']*\)');.*/\1/p" "$MAIN" | head -n1)
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-beta[0-9]+)?$ ]]; then
  echo "Version header '$VERSION' is neither X.Y.Z nor X.Y.Z-betaN" >&2; exit 1
fi
if [ "$VERSION" != "$CONST" ]; then
  echo "Version header '$VERSION' differs from ANTWC_VERSION '$CONST'" >&2; exit 1
fi

OUT=$(mkdir -p "$OUT" && cd "$OUT" && pwd)
STAGE="$OUT/stage"
ZIP="$OUT/$SLUG.zip"
rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE/$SLUG"

# Tracked files only, minus development material.
git ls-files -z | while IFS= read -r -d '' f; do
  case "$f" in
    .github/*|tests/*|scripts/*|.gitignore|.wp-env.json|phpunit*.xml.dist|composer.json|composer.lock|AGENTS.md) continue ;;
  esac
  mkdir -p "$STAGE/$SLUG/$(dirname "$f")"
  cp -p "$f" "$STAGE/$SLUG/$f"
done
(cd "$STAGE" && zip -qrX "$ZIP" "$SLUG")
LIST=$(unzip -Z1 "$ZIP")
TOP=$(cut -d/ -f1 <<<"$LIST" | sort -u)
[ "$TOP" = "$SLUG" ] || { echo "zip must hold only $SLUG/ at the top level, found: $TOP" >&2; exit 1; }
for want in "$SLUG/$MAIN" "$SLUG/includes/Server/Authorize_Net_Server_Provider.php"; do
  grep -qxF "$want" <<<"$LIST" || { echo "zip is missing $want" >&2; exit 1; }
done
! grep -qE '(^|/)(tests/|\.git)' <<<"$LIST" || { echo "zip contains a tests/ or .git path" >&2; exit 1; }
find "$STAGE/$SLUG" -name '*.php' -print0 | xargs -0 -n1 php -l
if command -v sha256sum >/dev/null; then SHA=$(sha256sum "$ZIP" | cut -d' ' -f1); else SHA=$(shasum -a 256 "$ZIP" | cut -d' ' -f1); fi
echo "version: $VERSION"
echo "sha256:  $SHA  $ZIP"
if [ -n "${GITHUB_OUTPUT:-}" ]; then echo "version=$VERSION" >> "$GITHUB_OUTPUT"; fi
