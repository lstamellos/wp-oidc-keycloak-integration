#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
TAG="${1:-}"
PLUGIN="$ROOT/mu-plugins/wp-oidc-keycloak-integration.php"
UPDATER="$ROOT/mu-plugins/wp-oidc-keycloak-updater.php"
TEMPLATE="$ROOT/mu-plugins/wp-oidc-keycloak-templates/myaccount/form-edit-account.php"

for file in "$PLUGIN" "$UPDATER" "$TEMPLATE"; do
    php -l "$file"
done

VERSION="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$PLUGIN" | head -n1)"
UPDATER_VERSION="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$UPDATER" | head -n1)"

if [[ -z "$VERSION" || "$VERSION" != "$UPDATER_VERSION" ]]; then
    echo "ERROR: integration/updater version mismatch: ${VERSION:-missing} / ${UPDATER_VERSION:-missing}" >&2
    exit 1
fi

if [[ -n "$TAG" && "$TAG" != "v$VERSION" ]]; then
    echo "ERROR: tag $TAG does not match plugin version v$VERSION" >&2
    exit 1
fi

for file in "$PLUGIN" "$UPDATER"; do
    if ! grep -Fq 'Author: OmniaTV' "$file"; then
        echo "ERROR: Author header is not OmniaTV in $file" >&2
        exit 1
    fi
done

if ! grep -Fq 'Requires Plugins: daggerhart-openid-connect-generic' "$PLUGIN"; then
    echo 'ERROR: required OIDC plugin dependency header is missing.' >&2
    exit 1
fi

if ! grep -Fq "private const OIDC_PLUGIN_MIN_VERSION = '3.11.3';" "$PLUGIN"; then
    echo 'ERROR: OIDC minimum-version runtime guard is missing.' >&2
    exit 1
fi

if ! grep -Fq "'WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH'" "$PLUGIN"; then
    echo 'ERROR: generic provisioner config-path setting is missing.' >&2
    exit 1
fi

python3 - "$ROOT" <<'PY'
from pathlib import Path
import sys
root = Path(sys.argv[1])
forbidden = (
    '/home/',
    'web.cremedia.studio',
    'auth.omniatv.com',
    'OMNIATV_KEYCLOAK_',
    'OmniaTV_Keycloak_',
    'omniatv_keycloak_',
    'omniatv-keycloak-',
)
paths = list((root / 'mu-plugins').rglob('*')) + [
    root / 'scripts/install.sh',
    root / 'scripts/build-release.sh',
    root / '.github/workflows/release.yml',
    root / 'README.md',
]
violations = []
for path in paths:
    if not path.is_file():
        continue
    text = path.read_text(encoding='utf-8')
    for lineno, line in enumerate(text.splitlines(), 1):
        if any(token in line for token in forbidden):
            violations.append((path, lineno, line))
        if 'get_current_blog_id() === ' in line or 'get_current_blog_id()===' in line:
            violations.append((path, lineno, line))
        if 'OmniaTV' in line or 'omniatv.com' in line:
            allowed = (
                line.startswith(' * Author: OmniaTV') or
                line.startswith(' * Author URI: https://omniatv.com/') or
                line.strip() == '**Author:** OmniaTV'
            )
            if not allowed:
                violations.append((path, lineno, line))
if violations:
    for path, lineno, line in violations:
        print(f'ERROR: deployment-specific reference: {path.relative_to(root)}:{lineno}: {line}', file=sys.stderr)
    raise SystemExit(1)
PY
printf 'version=%s\n' "$VERSION"
sha256sum "$PLUGIN" "$UPDATER" "$TEMPLATE"
