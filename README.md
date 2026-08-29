# WP OIDC Keycloak Integration

A WordPress must-use integration that makes a Keycloak/OpenID Connect identity provider authoritative for interactive authentication and selected account-identity workflows in WordPress and WooCommerce.

**Author:** OmniaTV

## Required dependency

This integration requires **OpenID Connect Generic**:

- WordPress.org slug: `daggerhart-openid-connect-generic`
- supported minimum version: `3.11.3`
- required runtime class: `OpenID_Connect_Generic`

The main MU-plugin header declares:

```text
Requires Plugins: daggerhart-openid-connect-generic
```

Because WordPress's standard plugin-dependency lifecycle is designed around ordinary plugins rather than MU-plugin activation, the integration also performs its own runtime dependency check after regular plugins have loaded. If the dependency is missing, inactive, or older than `3.11.3`, the integration does not bootstrap and displays an administrative error notice. The bootstrap installer also refuses installation when the dependency is not active.

## Repository layout

- `mu-plugins/wp-oidc-keycloak-integration.php` — Keycloak/OIDC account-authority integration.
- `mu-plugins/wp-oidc-keycloak-updater.php` — stable GitHub Release auto-updater.
- `mu-plugins/wp-oidc-keycloak-templates/myaccount/form-edit-account.php` — Keycloak-authoritative WooCommerce account form.
- `scripts/validate.sh` — lint, version, dependency, metadata and deployment-specific-reference validation.
- `scripts/build-release.sh` — release package builder with file-level SHA-256 manifest.
- `scripts/install.sh` — environment-neutral bootstrap installer.
- `.github/workflows/release.yml` — creates a GitHub Release from a `vX.Y.Z` tag.

## Configuration

The repository contains no deployment-specific Keycloak URI, WordPress filesystem path, Unix account, hostname, realm name or secret path.

OIDC endpoint, issuer and client settings remain the responsibility of **OpenID Connect Generic Client**.

WooCommerce-to-Keycloak provisioning uses an external configuration file. Its path must be supplied server-side with either a WordPress constant or an environment variable:

```php
define(
    'WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH',
    '/etc/wp-oidc-keycloak/woocommerce-provisioner.conf'
);
```

The path must be absolute. The file should be outside the public document root and contain:

```text
KEYCLOAK_BASE_URL=https://id.example.org
KEYCLOAK_ADMIN_BASE_URL=http://127.0.0.1:8180
KEYCLOAK_REALM=example
KEYCLOAK_PROVISIONER_CLIENT_ID=wordpress-provisioner
KEYCLOAK_PROVISIONER_SECRET_FILE=/etc/wp-oidc-keycloak/provisioner.secret
```

The following feature flags are available as server-side WordPress constants:

```php
define('WP_OIDC_KEYCLOAK_FILTER_LOGIN_URLS', true);
define('WP_OIDC_KEYCLOAK_REDIRECT_DIRECT_LOGIN', true);
define('WP_OIDC_KEYCLOAK_BLOCK_NATIVE_AUTHENTICATION', true);
define('WP_OIDC_KEYCLOAK_BLOCK_XMLRPC_AUTHENTICATION', false);
define('WP_OIDC_KEYCLOAK_SYNC_WORDPRESS_ATTRIBUTES', true);
define('WP_OIDC_KEYCLOAK_LOGIN_AUDIT', false);
```


### Legacy feature-flag fallback during migration

For upgrades from pre-generic builds, the five `OMNIATV_KEYCLOAK_*` feature constants and the two updater configuration constants documented in `MIGRATION.md` remain supported as fallbacks. If a canonical `WP_OIDC_KEYCLOAK_*` constant is defined, it takes precedence. New deployments should use only the generic names.

## Auto-update model

WordPress does not use its normal plugin updater for must-use plugins, so this project ships a separate MU updater.

The updater:

1. runs only on the primary site when WordPress Multisite is enabled;
2. checks the latest stable GitHub Release twice daily via WP-Cron;
3. requires `vX.Y.Z` release tags;
4. accepts only `wp-oidc-keycloak-integration-vX.Y.Z.zip`;
5. verifies the SHA-256 digest reported by the GitHub Releases API;
6. verifies each deployable file against `release.json` inside the asset;
7. enforces the WordPress/PHP/OIDC requirements declared by the release before replacing files;
8. stages and atomically replaces all deployment files;
9. rolls back replaced files and removes newly created files if installation fails mid-update.

Auto-update is enabled by default and can be disabled with:

```php
define('WP_OIDC_KEYCLOAK_AUTO_UPDATE_ENABLED', false);
```

A fork can override the update source:

```php
define(
    'WP_OIDC_KEYCLOAK_GITHUB_REPOSITORY',
    'owner/wp-oidc-keycloak-integration'
);
```

The updater assumes a public GitHub repository and stores no GitHub credential in WordPress.

## Migration from pre-generic builds

Deployments upgrading from the pre-generic `0.6.29` namespace must follow [`MIGRATION.md`](MIGRATION.md). The completed compatibility review is documented in [`docs/migration-audit-0.6.32.md`](docs/migration-audit-0.6.32.md). Version `0.6.32` includes a migration compatibility layer for legacy feature constants, persisted metadata, previously generated account/claim links, the old extension filter, class name, WP-CLI command, updater schedule/lock and theme CSS/style identifiers. Generic names remain canonical for new configuration and new state.

## Bootstrap installation

The initial updater installation is manual because an MU plugin cannot update itself before an updater exists.

```bash
sudo bash scripts/install.sh --wp-path=/var/www/example/wordpress
```

Optional installer parameters:

```text
--backup-root=/secure/backups
--owner=www-data
--group=www-data
```

The same values can be supplied through `WP_PATH`, `BACKUP_ROOT`, `OWNER` and `GROUP` environment variables. If owner/group are omitted, the installer uses the current ownership of `WPMU_PLUGIN_DIR`.

Before changing any MU-plugin file, the installer requires `WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH` to resolve in the actual WordPress runtime and verifies the external provisioner configuration and secret file. During a pre-generic migration it creates a backup, enters maintenance mode, removes the old `omniatv-*` runtime files as part of the same switch, validates the resulting WordPress runtime, and restores the previous state automatically if the migration fails.

A forced update check can then be run with:

```bash
wp --path=/var/www/example/wordpress wp-oidc-keycloak update
```

## Creating a release

1. Update the `Version:` headers in both MU plugin files.
2. Run:

```bash
bash scripts/validate.sh vX.Y.Z
bash scripts/build-release.sh vX.Y.Z
```

3. Commit and push tag `vX.Y.Z`.
4. The GitHub Actions release workflow rebuilds and publishes the ZIP and SHA-256 file.

Draft and prerelease GitHub releases are ignored by the updater.

## Security notes

- When `WP_OIDC_KEYCLOAK_BLOCK_NATIVE_AUTHENTICATION` is enabled, ordinary credential-bearing `POST /wp-login.php` requests are rejected before WordPress enters the native password-authentication filter chain; the existing terminal `authenticate` blocker remains in place as defense in depth.
- `WP_OIDC_KEYCLOAK_BLOCK_XMLRPC_AUTHENTICATION` is opt-in and defaults to false. When enabled, authenticated XML-RPC methods are disabled through WordPress core's `xmlrpc_enabled` filter without removing the XML-RPC endpoint or unauthenticated methods.
- No Keycloak client secret is committed to Git.
- No deployment hostname, identity-provider URI or server home path is embedded in the runtime code.
- The OIDC dependency is checked before the integration boots.
- Release assets and individual deployment files are integrity-checked before activation.
- Update installation uses same-filesystem staging and rollback.
- The updater is separate from the authentication integration so update failures do not replace the update mechanism with application logic.
