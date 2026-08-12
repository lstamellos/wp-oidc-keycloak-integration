#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
WP_PATH="${WP_PATH:-}"
BACKUP_ROOT="${BACKUP_ROOT:-}"
OWNER="${OWNER:-}"
GROUP="${GROUP:-}"

usage() {
    cat <<'USAGE'
Usage:
  scripts/install.sh --wp-path=/path/to/wordpress [--backup-root=/path] [--owner=user] [--group=group]

Environment equivalents:
  WP_PATH, BACKUP_ROOT, OWNER, GROUP
USAGE
}

for arg in "$@"; do
    case "$arg" in
        --wp-path=*) WP_PATH="${arg#*=}" ;;
        --backup-root=*) BACKUP_ROOT="${arg#*=}" ;;
        --owner=*) OWNER="${arg#*=}" ;;
        --group=*) GROUP="${arg#*=}" ;;
        -h|--help) usage; exit 0 ;;
        *) echo "ERROR: unknown argument: $arg" >&2; usage >&2; exit 2 ;;
    esac
done

if [[ -z "$WP_PATH" ]]; then
    echo 'ERROR: --wp-path or WP_PATH is required.' >&2
    usage >&2
    exit 2
fi

if ! command -v wp >/dev/null 2>&1; then
    echo 'ERROR: WP-CLI (wp) is required.' >&2
    exit 1
fi

if ! wp --path="$WP_PATH" core is-installed >/dev/null 2>&1; then
    echo "ERROR: WordPress is not installed at $WP_PATH" >&2
    exit 1
fi

"$ROOT/scripts/validate.sh"

OIDC_SLUG='daggerhart-openid-connect-generic'
if ! wp --path="$WP_PATH" plugin is-installed "$OIDC_SLUG" >/dev/null 2>&1; then
    echo "ERROR: required plugin $OIDC_SLUG is not installed." >&2
    exit 1
fi

OIDC_ACTIVE="$(wp --path="$WP_PATH" eval 'echo class_exists("OpenID_Connect_Generic") ? "yes" : "no";' 2>/dev/null || true)"
if [[ "$OIDC_ACTIVE" != 'yes' ]]; then
    echo "ERROR: required plugin $OIDC_SLUG is not active for this WordPress context." >&2
    exit 1
fi

OIDC_VERSION="$(wp --path="$WP_PATH" plugin get "$OIDC_SLUG" --field=version 2>/dev/null || true)"
if [[ -z "$OIDC_VERSION" ]]; then
    echo "ERROR: could not determine version of $OIDC_SLUG." >&2
    exit 1
fi

if ! php -r 'exit(version_compare($argv[1], $argv[2], ">=") ? 0 : 1);' "$OIDC_VERSION" '3.11.3'; then
    echo "ERROR: $OIDC_SLUG >= 3.11.3 is required; detected $OIDC_VERSION." >&2
    exit 1
fi

MU_DIR="$(wp --path="$WP_PATH" eval 'echo WPMU_PLUGIN_DIR;' 2>/dev/null)"
if [[ -z "$MU_DIR" ]]; then
    echo 'ERROR: could not determine WPMU_PLUGIN_DIR.' >&2
    exit 1
fi

mkdir -p "$MU_DIR"

if [[ -z "$OWNER" ]]; then
    OWNER="$(stat -c '%U' "$MU_DIR")"
fi
if [[ -z "$GROUP" ]]; then
    GROUP="$(stat -c '%G' "$MU_DIR")"
fi

if [[ -z "$BACKUP_ROOT" ]]; then
    BACKUP_ROOT="$(dirname -- "$WP_PATH")/wp-oidc-keycloak-backups"
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="$BACKUP_ROOT/bootstrap-$STAMP"
mkdir -p "$BACKUP" "$MU_DIR/wp-oidc-keycloak-templates/myaccount"

for rel in \
    wp-oidc-keycloak-integration.php \
    wp-oidc-keycloak-updater.php \
    wp-oidc-keycloak-templates/myaccount/form-edit-account.php; do
    src="$ROOT/mu-plugins/$rel"
    dst="$MU_DIR/$rel"
    mkdir -p "$(dirname -- "$dst")" "$(dirname -- "$BACKUP/$rel")"

    if [[ -f "$dst" ]]; then
        cp -a "$dst" "$BACKUP/$rel"
    fi

    install -o "$OWNER" -g "$GROUP" -m 0644 "$src" "$dst.new-$STAMP"
    mv -f "$dst.new-$STAMP" "$dst"
done

php -l "$MU_DIR/wp-oidc-keycloak-integration.php"
php -l "$MU_DIR/wp-oidc-keycloak-updater.php"
php -l "$MU_DIR/wp-oidc-keycloak-templates/myaccount/form-edit-account.php"

printf 'wp_path=%s\n' "$WP_PATH"
printf 'mu_plugin_dir=%s\n' "$MU_DIR"
printf 'dependency=%s@%s\n' "$OIDC_SLUG" "$OIDC_VERSION"
printf 'backup_dir=%s\n' "$BACKUP"
echo 'bootstrap_install=yes'
