#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
TAG="${1:-}"
PLUGIN="$ROOT/mu-plugins/wp-oidc-keycloak-integration.php"
VERSION="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$PLUGIN" | head -n1)"

if [[ -n "$TAG" && "$TAG" != "v$VERSION" ]]; then
    echo "ERROR: tag $TAG does not match v$VERSION" >&2
    exit 1
fi

"$ROOT/scripts/validate.sh" "${TAG:-v$VERSION}"

DIST="$ROOT/dist"
STAGE="$DIST/package"
ASSET="$DIST/wp-oidc-keycloak-integration-v$VERSION.zip"
rm -rf "$DIST"
mkdir -p "$STAGE/wp-oidc-keycloak-templates/myaccount"

cp "$ROOT/mu-plugins/wp-oidc-keycloak-integration.php" "$STAGE/"
cp "$ROOT/mu-plugins/wp-oidc-keycloak-updater.php" "$STAGE/"
cp "$ROOT/mu-plugins/wp-oidc-keycloak-templates/myaccount/form-edit-account.php" \
   "$STAGE/wp-oidc-keycloak-templates/myaccount/"

python3 - "$STAGE" "$VERSION" <<'PY'
from pathlib import Path
import hashlib, json, sys
stage = Path(sys.argv[1])
version = sys.argv[2]
paths = [
    'wp-oidc-keycloak-integration.php',
    'wp-oidc-keycloak-updater.php',
    'wp-oidc-keycloak-templates/myaccount/form-edit-account.php',
]
files = {}
for rel in paths:
    files[rel] = hashlib.sha256((stage / rel).read_bytes()).hexdigest()
manifest = {
    'name': 'WP OIDC Keycloak Integration',
    'version': version,
    'requires': {
        'wordpress': '>=6.5',
        'php': '>=8.0',
        'plugins': {
            'daggerhart-openid-connect-generic': '>=3.11.3',
        },
    },
    'files': files,
}
(stage / 'release.json').write_text(
    json.dumps(manifest, indent=2, sort_keys=True) + '\n',
    encoding='utf-8',
)
PY

python3 - "$DIST" "$ASSET" <<'PY'
from pathlib import Path
import sys, zipfile
base = Path(sys.argv[1])
out = Path(sys.argv[2])
with zipfile.ZipFile(out, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
    for path in sorted((base / 'package').rglob('*')):
        if path.is_file():
            zf.write(path, path.relative_to(base).as_posix())
PY

(
    cd "$DIST"
    sha256sum "$(basename "$ASSET")" > "$(basename "$ASSET").sha256"
)
printf 'asset=%s\n' "$ASSET"
cat "$ASSET.sha256"
