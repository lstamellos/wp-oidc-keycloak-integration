#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
WP_PATH="${WP_PATH:-}"
BACKUP_ROOT="${BACKUP_ROOT:-}"
OWNER="${OWNER:-}"
GROUP="${GROUP:-}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

ACTIVATION_STARTED=0
MAINTENANCE_ACTIVATED=0
PREEXISTING_MAINTENANCE=0
MU_DIR=''
BACKUP=''
STATE_TAR=''

usage() {
    cat <<'USAGE'
Usage:
  scripts/install.sh --wp-path=/path/to/wordpress [--backup-root=/path] [--owner=user] [--group=group]

Environment equivalents:
  WP_PATH, BACKUP_ROOT, OWNER, GROUP

The WordPress runtime must already define or export:
  WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH=/absolute/path/to/woocommerce-provisioner.conf
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

wp_cli() {
    if [[ "$(id -u)" -eq 0 ]]; then
        wp --allow-root --path="$WP_PATH" "$@"
    else
        wp --path="$WP_PATH" "$@"
    fi
}

cleanup_staged() {
    if [[ -z "$MU_DIR" ]]; then
        return
    fi

    find "$MU_DIR" -maxdepth 1 -type f \
        \( -name 'wp-oidc-keycloak-integration.php.new-*' \
        -o -name 'wp-oidc-keycloak-updater.php.new-*' \) \
        -delete 2>/dev/null || true

    if [[ -d "$MU_DIR/wp-oidc-keycloak-templates/myaccount" ]]; then
        find "$MU_DIR/wp-oidc-keycloak-templates/myaccount" \
            -maxdepth 1 -type f -name 'form-edit-account.php.new-*' \
            -delete 2>/dev/null || true
    fi
}

restore_runtime_state() {
    if [[ -z "$MU_DIR" || -z "$STATE_TAR" || ! -f "$STATE_TAR" ]]; then
        return
    fi

    rm -f \
        "$MU_DIR/wp-oidc-keycloak-integration.php" \
        "$MU_DIR/wp-oidc-keycloak-updater.php" \
        "$MU_DIR/omniatv-keycloak-integration.php" \
        "$MU_DIR/omniatv-keycloak-updater.php"
    rm -rf \
        "$MU_DIR/wp-oidc-keycloak-templates" \
        "$MU_DIR/omniatv-keycloak-templates"

    tar -xpf "$STATE_TAR" -C "$MU_DIR"
}

finish_maintenance() {
    if [[ "$MAINTENANCE_ACTIVATED" -eq 1 ]]; then
        wp_cli maintenance-mode deactivate >/dev/null 2>&1 || true
        MAINTENANCE_ACTIVATED=0
    fi
}

on_exit() {
    status=$?
    trap - EXIT

    if [[ "$status" -ne 0 ]]; then
        echo '=== INSTALL FAILURE ===' >&2

        if [[ "$ACTIVATION_STARTED" -eq 1 ]]; then
            echo 'Restoring pre-install MU-plugin state.' >&2
            restore_runtime_state || true
        fi

        cleanup_staged || true
        finish_maintenance || true
    fi

    exit "$status"
}
trap on_exit EXIT

if ! wp_cli core is-installed >/dev/null 2>&1; then
    echo "ERROR: WordPress is not installed at $WP_PATH" >&2
    exit 1
fi

"$ROOT/scripts/validate.sh"

OIDC_SLUG='daggerhart-openid-connect-generic'
if ! wp_cli plugin is-installed "$OIDC_SLUG" >/dev/null 2>&1; then
    echo "ERROR: required plugin $OIDC_SLUG is not installed." >&2
    exit 1
fi

OIDC_ACTIVE="$(wp_cli eval 'echo class_exists("OpenID_Connect_Generic") ? "yes" : "no";' 2>/dev/null || true)"
if [[ "$OIDC_ACTIVE" != 'yes' ]]; then
    echo "ERROR: required plugin $OIDC_SLUG is not active for this WordPress context." >&2
    exit 1
fi

OIDC_VERSION="$(wp_cli plugin get "$OIDC_SLUG" --field=version 2>/dev/null || true)"
if [[ -z "$OIDC_VERSION" ]]; then
    echo "ERROR: could not determine version of $OIDC_SLUG." >&2
    exit 1
fi

if ! php -r 'exit(version_compare($argv[1], $argv[2], ">=") ? 0 : 1);' "$OIDC_VERSION" '3.11.3'; then
    echo "ERROR: $OIDC_SLUG >= 3.11.3 is required; detected $OIDC_VERSION." >&2
    exit 1
fi

MU_DIR="$(wp_cli eval 'echo WPMU_PLUGIN_DIR;' 2>/dev/null)"
if [[ -z "$MU_DIR" ]]; then
    echo 'ERROR: could not determine WPMU_PLUGIN_DIR.' >&2
    exit 1
fi
mkdir -p "$MU_DIR"

PROVISIONER_CONFIG_PATH="$(wp_cli eval '
$setting = "WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH";
$value = "";
if (defined($setting) && is_string(constant($setting))) {
    $value = trim(constant($setting));
}
if ($value === "") {
    $env = getenv($setting);
    if (is_string($env)) {
        $value = trim($env);
    }
}
echo $value;
' 2>/dev/null || true)"

if [[ -z "$PROVISIONER_CONFIG_PATH" || "$PROVISIONER_CONFIG_PATH" != /* ]]; then
    echo 'ERROR: WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH must already resolve to an absolute path in the WordPress runtime.' >&2
    exit 1
fi

if [[ ! -r "$PROVISIONER_CONFIG_PATH" ]]; then
    echo "ERROR: provisioner config is not readable: $PROVISIONER_CONFIG_PATH" >&2
    exit 1
fi

php -r '
$path = $argv[1];
$c = parse_ini_file($path, false, INI_SCANNER_RAW);
$required = [
    "KEYCLOAK_BASE_URL",
    "KEYCLOAK_ADMIN_BASE_URL",
    "KEYCLOAK_REALM",
    "KEYCLOAK_PROVISIONER_CLIENT_ID",
    "KEYCLOAK_PROVISIONER_SECRET_FILE",
];
if (!is_array($c)) { fwrite(STDERR, "ERROR: provisioner config cannot be parsed.\n"); exit(1); }
foreach ($required as $key) {
    if (!isset($c[$key]) || !is_string($c[$key]) || trim($c[$key]) === "") {
        fwrite(STDERR, "ERROR: missing provisioner key: $key\n"); exit(1);
    }
}
foreach (["KEYCLOAK_BASE_URL", "KEYCLOAK_ADMIN_BASE_URL"] as $key) {
    if (filter_var(trim($c[$key]), FILTER_VALIDATE_URL) === false) {
        fwrite(STDERR, "ERROR: invalid provisioner URL: $key\n"); exit(1);
    }
}
$secret = trim($c["KEYCLOAK_PROVISIONER_SECRET_FILE"]);
if (!str_starts_with($secret, "/") || !is_readable($secret)) {
    fwrite(STDERR, "ERROR: provisioner secret file is not an absolute readable path.\n"); exit(1);
}
' "$PROVISIONER_CONFIG_PATH"

if [[ -z "$OWNER" ]]; then
    OWNER="$(stat -c '%U' "$MU_DIR")"
fi
if [[ -z "$GROUP" ]]; then
    GROUP="$(stat -c '%G' "$MU_DIR")"
fi

if [[ -z "$BACKUP_ROOT" ]]; then
    BACKUP_ROOT="$(dirname -- "$WP_PATH")/wp-oidc-keycloak-backups"
fi
BACKUP="$BACKUP_ROOT/bootstrap-$STAMP"
STATE_TAR="$BACKUP/runtime-before.tar"
mkdir -p "$BACKUP"

LEGACY_MAIN="$MU_DIR/omniatv-keycloak-integration.php"
LEGACY_UPDATER="$MU_DIR/omniatv-keycloak-updater.php"
LEGACY_TEMPLATE_DIR="$MU_DIR/omniatv-keycloak-templates"
GENERIC_MAIN="$MU_DIR/wp-oidc-keycloak-integration.php"
GENERIC_UPDATER="$MU_DIR/wp-oidc-keycloak-updater.php"
GENERIC_TEMPLATE_DIR="$MU_DIR/wp-oidc-keycloak-templates"
GENERIC_TEMPLATE="$GENERIC_TEMPLATE_DIR/myaccount/form-edit-account.php"

if [[ -e "$LEGACY_MAIN" && -e "$GENERIC_MAIN" ]]; then
    echo 'ERROR: legacy and generic integration files are both present; refusing to operate on an already unsafe dual-load state.' >&2
    exit 1
fi

STATE_ITEMS=()
for rel in \
    omniatv-keycloak-integration.php \
    omniatv-keycloak-updater.php \
    omniatv-keycloak-templates \
    wp-oidc-keycloak-integration.php \
    wp-oidc-keycloak-updater.php \
    wp-oidc-keycloak-templates; do
    if [[ -e "$MU_DIR/$rel" || -L "$MU_DIR/$rel" ]]; then
        STATE_ITEMS+=("$rel")
    fi
done

if [[ "${#STATE_ITEMS[@]}" -gt 0 ]]; then
    tar -cpf "$STATE_TAR" -C "$MU_DIR" "${STATE_ITEMS[@]}"
else
    tar -cpf "$STATE_TAR" --files-from /dev/null
fi

mkdir -p "$GENERIC_TEMPLATE_DIR/myaccount"
SRC_MAIN="$ROOT/mu-plugins/wp-oidc-keycloak-integration.php"
SRC_UPDATER="$ROOT/mu-plugins/wp-oidc-keycloak-updater.php"
SRC_TEMPLATE="$ROOT/mu-plugins/wp-oidc-keycloak-templates/myaccount/form-edit-account.php"
STAGE_MAIN="$GENERIC_MAIN.new-$STAMP"
STAGE_UPDATER="$GENERIC_UPDATER.new-$STAMP"
STAGE_TEMPLATE="$GENERIC_TEMPLATE.new-$STAMP"

install -o "$OWNER" -g "$GROUP" -m 0644 "$SRC_MAIN" "$STAGE_MAIN"
install -o "$OWNER" -g "$GROUP" -m 0644 "$SRC_UPDATER" "$STAGE_UPDATER"
install -o "$OWNER" -g "$GROUP" -m 0644 "$SRC_TEMPLATE" "$STAGE_TEMPLATE"
php -l "$STAGE_MAIN"
php -l "$STAGE_UPDATER"
php -l "$STAGE_TEMPLATE"

if wp_cli maintenance-mode is-active >/dev/null 2>&1; then
    PREEXISTING_MAINTENANCE=1
else
    wp_cli maintenance-mode activate >/dev/null
    MAINTENANCE_ACTIVATED=1
fi

# No WordPress/WP-CLI invocation is permitted between this point and the
# completion of the file switch: it could load both namespaces mid-migration.
ACTIVATION_STARTED=1
rm -f "$LEGACY_MAIN" "$LEGACY_UPDATER"
rm -rf "$LEGACY_TEMPLATE_DIR"
mv -f "$STAGE_MAIN" "$GENERIC_MAIN"
mv -f "$STAGE_UPDATER" "$GENERIC_UPDATER"
mv -f "$STAGE_TEMPLATE" "$GENERIC_TEMPLATE"

if [[ -e "$LEGACY_MAIN" || -e "$LEGACY_UPDATER" || -e "$LEGACY_TEMPLATE_DIR" ]]; then
    echo 'ERROR: a legacy MU-plugin artifact remains after activation.' >&2
    exit 1
fi

php -l "$GENERIC_MAIN"
php -l "$GENERIC_UPDATER"
php -l "$GENERIC_TEMPLATE"

RUNTIME_CHECK="$(wp_cli eval '
$checks = [];
$checks["generic_class"] = class_exists("WP_OIDC_Keycloak_Integration", false);
$checks["legacy_alias"] = class_exists("OmniaTV_Keycloak_Integration", false);
$checks["oidc_dependency"] = class_exists("OpenID_Connect_Generic");
$checks["template"] = class_exists("WP_OIDC_Keycloak_Integration", false)
    ? WP_OIDC_Keycloak_Integration::replace_my_account_login_template(
        "BASE",
        "myaccount/form-edit-account.php",
        [],
        "",
        ""
    )
    : "";
foreach ($checks as $key => $value) {
    echo $key . "=" . (is_bool($value) ? ($value ? "yes" : "no") : (string) $value) . PHP_EOL;
}
' 2>/dev/null)"
printf '%s\n' "$RUNTIME_CHECK"

if ! grep -Fxq 'generic_class=yes' <<<"$RUNTIME_CHECK"; then
    echo 'ERROR: generic integration class did not load.' >&2
    exit 1
fi
if ! grep -Fxq 'legacy_alias=yes' <<<"$RUNTIME_CHECK"; then
    echo 'ERROR: migration class alias did not load.' >&2
    exit 1
fi
if ! grep -Fxq 'oidc_dependency=yes' <<<"$RUNTIME_CHECK"; then
    echo 'ERROR: OIDC dependency is not active after migration.' >&2
    exit 1
fi
if ! grep -Fq "template=$GENERIC_TEMPLATE" <<<"$RUNTIME_CHECK"; then
    echo 'ERROR: WooCommerce account template resolves to an unexpected path.' >&2
    exit 1
fi

finish_maintenance
ACTIVATION_STARTED=0
cleanup_staged
trap - EXIT

printf 'wp_path=%s\n' "$WP_PATH"
printf 'mu_plugin_dir=%s\n' "$MU_DIR"
printf 'dependency=%s@%s\n' "$OIDC_SLUG" "$OIDC_VERSION"
printf 'provisioner_config_path=%s\n' "$PROVISIONER_CONFIG_PATH"
printf 'backup_dir=%s\n' "$BACKUP"
printf 'legacy_artifacts_removed=yes\n'
printf 'maintenance_preexisting=%s\n' "$PREEXISTING_MAINTENANCE"
printf 'version=0.6.33\n'
echo 'bootstrap_install=yes'
