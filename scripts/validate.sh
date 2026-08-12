#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
TAG="${1:-}"
PLUGIN="$ROOT/mu-plugins/wp-oidc-keycloak-integration.php"
UPDATER="$ROOT/mu-plugins/wp-oidc-keycloak-updater.php"
TEMPLATE="$ROOT/mu-plugins/wp-oidc-keycloak-templates/myaccount/form-edit-account.php"
INSTALLER="$ROOT/scripts/install.sh"
BUILD="$ROOT/scripts/build-release.sh"

for file in "$PLUGIN" "$UPDATER" "$TEMPLATE"; do
    php -l "$file"
done
bash -n "$INSTALLER" "$BUILD"

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
    grep -Fq 'Author: OmniaTV' "$file" || {
        echo "ERROR: Author header is not OmniaTV in $file" >&2
        exit 1
    }
done

grep -Fq 'Requires Plugins: daggerhart-openid-connect-generic' "$PLUGIN" || {
    echo 'ERROR: required OIDC plugin dependency header is missing.' >&2
    exit 1
}
grep -Fq "private const OIDC_PLUGIN_MIN_VERSION = '3.11.3';" "$PLUGIN" || {
    echo 'ERROR: OIDC minimum-version runtime guard is missing.' >&2
    exit 1
}
grep -Fq "'WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH'" "$PLUGIN" || {
    echo 'ERROR: generic provisioner config-path setting is missing.' >&2
    exit 1
}

python3 - "$ROOT" <<'PY'
from pathlib import Path
import re
import sys

root = Path(sys.argv[1])
plugin = (root / 'mu-plugins/wp-oidc-keycloak-integration.php').read_text(encoding='utf-8')
updater = (root / 'mu-plugins/wp-oidc-keycloak-updater.php').read_text(encoding='utf-8')
installer = (root / 'scripts/install.sh').read_text(encoding='utf-8')
readme = (root / 'README.md').read_text(encoding='utf-8')
migration = (root / 'MIGRATION.md').read_text(encoding='utf-8')
build = (root / 'scripts/build-release.sh').read_text(encoding='utf-8')

# Deployment-specific values must never return to executable/project source.
scan_paths = [
    p for p in root.rglob('*')
    if p.is_file() and '.git' not in p.parts and 'dist' not in p.parts
    and p.name != 'validate.sh'
]
deployment_forbidden = (
    '/home/omniatv',
    'web.cremedia.studio',
    'auth.omniatv.com',
    'cloud.omniatv.com',
    'video.omniatv.com',
)
violations = []
for path in scan_paths:
    try:
        text = path.read_text(encoding='utf-8')
    except UnicodeDecodeError:
        continue
    for lineno, line in enumerate(text.splitlines(), 1):
        for token in deployment_forbidden:
            if token in line:
                violations.append((path, lineno, f'deployment value {token!r}', line))
        if 'get_current_blog_id() === ' in line or 'get_current_blog_id()===' in line:
            violations.append((path, lineno, 'hard-coded blog-id language contract', line))

# Legacy names are allowed only as explicit migration compatibility contracts.
allowed_flags = {
    'OMNIATV_KEYCLOAK_FILTER_LOGIN_URLS',
    'OMNIATV_KEYCLOAK_REDIRECT_DIRECT_LOGIN',
    'OMNIATV_KEYCLOAK_BLOCK_NATIVE_AUTHENTICATION',
    'OMNIATV_KEYCLOAK_SYNC_WORDPRESS_ATTRIBUTES',
    'OMNIATV_KEYCLOAK_LOGIN_AUDIT',
    'OMNIATV_KEYCLOAK_AUTO_UPDATE_ENABLED',
    'OMNIATV_KEYCLOAK_GITHUB_REPOSITORY',
}
for m in re.finditer(r'OMNIATV_KEYCLOAK_[A-Z0-9_]+', plugin + '\n' + updater + '\n' + migration):
    if m.group(0) not in allowed_flags:
        raise SystemExit(f'ERROR: unexpected legacy constant: {m.group(0)}')

required_legacy_runtime = [
    'OmniaTV_Keycloak_Integration',
    '_omniatv_keycloak_',
    'omniatv_update_email',
    'omniatv_update_password',
    'omniatv_account_link',
    'omniatv_claim_contribution',
    'omniatv_claim_sig',
    'omniatv_keycloak_checkout_registration_request',
    'omniatv_keycloak_native_login_disabled',
    'omniatv_keycloak_account_identity_unavailable',
    'omniatv_keycloak_email_change_disabled',
    'omniatv_keycloak_password_change_disabled',
    'omniatv-keycloak-login-title',
]
for token in required_legacy_runtime:
    if token not in plugin:
        raise SystemExit(f'ERROR: required migration compatibility token missing: {token}')

required_legacy_updater = [
    'omniatv_keycloak_check_for_updates',
    'omniatv_keycloak_update_lock',
    'omniatv-keycloak update',
]
for token in required_legacy_updater:
    if token not in updater:
        raise SystemExit(f'ERROR: required updater compatibility token missing: {token}')

# Core upstream OIDC/Keycloak contracts must remain unchanged by namespace work.
required_upstream = [
    'OpenID_Connect_Generic',
    'daggerhart-openid-connect-generic',
    'openid-connect-generic-settings',
    'openid-connect-generic-user-create',
    'openid-connect-generic-user-logged-in',
    'openid-connect-generic-subject-identity',
    'KEYCLOAK_BASE_URL',
    'KEYCLOAK_ADMIN_BASE_URL',
    'KEYCLOAK_REALM',
    'KEYCLOAK_PROVISIONER_CLIENT_ID',
    'KEYCLOAK_PROVISIONER_SECRET_FILE',
    'VERIFY_EMAIL',
    'UPDATE_PASSWORD',
    'wordpress_user_id',
    'wordpress_login',
    'wordpress_display_name',
    '/wp-login.php',
    '/openid-connect-authorize',
]
for token in required_upstream:
    if token not in plugin:
        raise SystemExit(f'ERROR: upstream/runtime contract missing after migration: {token}')

# Every custom persisted meta field must have a canonical generic key and use
# generic-first/legacy-fallback access helpers.
meta_suffixes = [
    'provisioning_status', 'provisioning_source', 'provisioning_decision',
    'guest_auto_link_user_id', 'guest_auto_link_method',
    'guest_auto_link_email_sha256', 'guest_auto_linked_at_utc',
    'guest_claim_user_id', 'guest_claim_subject', 'guest_claimed_at_utc',
    'activation_email_status', 'activation_email_actions',
    'activation_email_error', 'activation_email_error_code',
    'activation_email_error_detail', 'activation_email_error_sha256',
    'activation_email_failed_at_utc', 'activation_email_last_attempt_at_utc',
    'activation_email_sent_at_utc',
]
for suffix in meta_suffixes:
    token = f'_wp_oidc_keycloak_{suffix}'
    if token not in plugin:
        raise SystemExit(f'ERROR: canonical persisted meta key missing: {token}')
for helper in (
    'get_compatible_user_meta', 'update_compatible_user_meta',
    'delete_compatible_user_meta', 'get_compatible_order_meta',
    'update_compatible_order_meta', 'query_parameter',
):
    if f'function {helper}' not in plugin:
        raise SystemExit(f'ERROR: compatibility helper missing: {helper}')

# Generic identifiers must be the emitted/canonical identifiers; legacy
# identifiers are accepted as fallbacks during migration.
for token in (
    'wp_oidc_keycloak_update_email', 'wp_oidc_keycloak_update_password',
    'wp_oidc_keycloak_account_link', 'wp_oidc_keycloak_claim_contribution',
    'wp_oidc_keycloak_claim_sig', 'wp_oidc_keycloak_checkout_registration_request',
):
    if token not in plugin:
        raise SystemExit(f'ERROR: canonical generic contract missing: {token}')

# Feature flag generic-first fallback and class alias are required.
for generic, legacy in {
    'WP_OIDC_KEYCLOAK_FILTER_LOGIN_URLS': 'OMNIATV_KEYCLOAK_FILTER_LOGIN_URLS',
    'WP_OIDC_KEYCLOAK_REDIRECT_DIRECT_LOGIN': 'OMNIATV_KEYCLOAK_REDIRECT_DIRECT_LOGIN',
    'WP_OIDC_KEYCLOAK_BLOCK_NATIVE_AUTHENTICATION': 'OMNIATV_KEYCLOAK_BLOCK_NATIVE_AUTHENTICATION',
    'WP_OIDC_KEYCLOAK_SYNC_WORDPRESS_ATTRIBUTES': 'OMNIATV_KEYCLOAK_SYNC_WORDPRESS_ATTRIBUTES',
    'WP_OIDC_KEYCLOAK_LOGIN_AUDIT': 'OMNIATV_KEYCLOAK_LOGIN_AUDIT',
}.items():
    if generic not in plugin or legacy not in plugin:
        raise SystemExit(f'ERROR: feature-flag migration mapping missing: {legacy} -> {generic}')
if "class_alias(" not in plugin or "self::class" not in plugin or "OmniaTV_Keycloak_Integration" not in plugin:
    raise SystemExit('ERROR: legacy PHP class alias is missing.')
if 'unsafe dual-load state' not in plugin or "class_exists('OmniaTV_Keycloak_Integration', false)" not in plugin:
    raise SystemExit('ERROR: runtime dual-load guard is missing.')

# UI/style contracts get dual classes/handle so existing theme customizations
# do not break solely because of the namespace migration.
for legacy_css in (
    'omniatv-keycloak-login', 'omniatv-keycloak-checkout-login',
    'omniatv-keycloak-panel', 'omniatv-keycloak-registration-panel',
    'omniatv-keycloak-register-button', 'omniatv-keycloak-authority',
    'omniatv-keycloak-contribution-account',
):
    if legacy_css not in plugin and legacy_css not in (root / 'mu-plugins/wp-oidc-keycloak-templates/myaccount/form-edit-account.php').read_text(encoding='utf-8'):
        raise SystemExit(f'ERROR: legacy CSS compatibility alias missing: {legacy_css}')
if "'omniatv-keycloak-integration'" not in plugin:
    raise SystemExit('ERROR: legacy WordPress style-handle alias is missing.')
if 'id="omniatv_account_email"' not in (root / 'mu-plugins/wp-oidc-keycloak-templates/myaccount/form-edit-account.php').read_text(encoding='utf-8'):
    raise SystemExit('ERROR: legacy account-email DOM id compatibility is missing.')

# Installer must be migration-atomic and fail closed before replacing files.
installer_required = [
    'WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH',
    'daggerhart-openid-connect-generic',
    'maintenance-mode activate',
    'omniatv-keycloak-integration.php',
    'omniatv-keycloak-updater.php',
    'omniatv-keycloak-templates',
    'runtime-before.tar',
    'restore_runtime_state',
    '.new-',
    'legacy_artifacts_removed=yes',
]
for token in installer_required:
    if token not in installer:
        raise SystemExit(f'ERROR: installer migration safety contract missing: {token}')

# Updater must enforce release requirements before mutation and be able to
# remove a newly created file during rollback.
for token in (
    'validate_runtime_requirements', 'assert_minimum_constraint',
    'installed_plugin_version', "'had_original'", 'LEGACY_CRON_HOOK',
    'LEGACY_LOCK_OPTION', 'LEGACY_AUTO_UPDATE_FLAG', 'LEGACY_REPOSITORY_FLAG',
):
    if token not in updater:
        raise SystemExit(f'ERROR: updater safety/compatibility contract missing: {token}')

# Build must write a portable checksum line containing only the asset basename.
if 'basename "$ASSET"' not in build:
    raise SystemExit('ERROR: release checksum generation is not basename-portable.')

# OmniaTV attribution is allowed; runtime-facing OmniaTV text must be limited to
# explicit legacy compatibility identifiers rather than deployment URLs/paths.
for path in (root / 'mu-plugins').rglob('*'):
    if not path.is_file():
        continue
    text = path.read_text(encoding='utf-8')
    for lineno, line in enumerate(text.splitlines(), 1):
        if 'omniatv.com' in line and 'Author URI: https://omniatv.com/' not in line:
            violations.append((path, lineno, 'unexpected OmniaTV URI', line))
        if 'OmniaTV' in line:
            allowed = (
                'Author: OmniaTV' in line
                or 'OmniaTV_Keycloak_Integration' in line
                or 'pre-generic' in line.lower()
            )
            if not allowed:
                violations.append((path, lineno, 'unexpected OmniaTV runtime branding', line))

if violations:
    for path, lineno, reason, line in violations:
        print(f'ERROR: {reason}: {path.relative_to(root)}:{lineno}: {line}', file=sys.stderr)
    raise SystemExit(1)
PY

printf 'version=%s\n' "$VERSION"
sha256sum "$PLUGIN" "$UPDATER" "$TEMPLATE"
