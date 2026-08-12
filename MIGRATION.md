# Migration from pre-generic OmniaTV builds

Version `0.6.31` changes the runtime namespace from the deployment-specific OmniaTV names used by the `0.6.29` baseline to the generic `wp-oidc-keycloak` namespace.

This migration is intentionally explicit. Do not leave the old and new MU-plugin files active at the same time, because both would register authentication/account hooks.

## 1. Rename server-side feature constants

Update any existing WordPress configuration definitions as follows:

| Legacy constant | Generic constant |
| --- | --- |
| `OMNIATV_KEYCLOAK_FILTER_LOGIN_URLS` | `WP_OIDC_KEYCLOAK_FILTER_LOGIN_URLS` |
| `OMNIATV_KEYCLOAK_REDIRECT_DIRECT_LOGIN` | `WP_OIDC_KEYCLOAK_REDIRECT_DIRECT_LOGIN` |
| `OMNIATV_KEYCLOAK_BLOCK_NATIVE_AUTHENTICATION` | `WP_OIDC_KEYCLOAK_BLOCK_NATIVE_AUTHENTICATION` |
| `OMNIATV_KEYCLOAK_SYNC_WORDPRESS_ATTRIBUTES` | `WP_OIDC_KEYCLOAK_SYNC_WORDPRESS_ATTRIBUTES` |
| `OMNIATV_KEYCLOAK_LOGIN_AUDIT` | `WP_OIDC_KEYCLOAK_LOGIN_AUDIT` |

If auto-update settings from a repository-preparation build were used, rename these too:

| Legacy constant | Generic constant |
| --- | --- |
| `OMNIATV_KEYCLOAK_AUTO_UPDATE_ENABLED` | `WP_OIDC_KEYCLOAK_AUTO_UPDATE_ENABLED` |
| `OMNIATV_KEYCLOAK_GITHUB_REPOSITORY` | `WP_OIDC_KEYCLOAK_GITHUB_REPOSITORY` |

## 2. Define the provisioner config path explicitly

The old code embedded the deployment path. The generic build does not.

Define the absolute external path with:

```php
define(
    'WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH',
    '/absolute/path/outside/document-root/woocommerce-provisioner.conf'
);
```

The existing provisioner configuration file itself does not need to change merely because its path is now configured explicitly.

## 3. Verify the required OIDC plugin

`daggerhart-openid-connect-generic` must be active and version `3.11.3` or newer before the generic MU integration is installed.

## 4. Replace the MU-plugin files as one maintenance operation

Back up the existing files first. Install these new files:

```text
wp-oidc-keycloak-integration.php
wp-oidc-keycloak-updater.php
wp-oidc-keycloak-templates/myaccount/form-edit-account.php
```

Then remove the legacy runtime files/directories from `WPMU_PLUGIN_DIR`:

```text
omniatv-keycloak-integration.php
omniatv-keycloak-updater.php
omniatv-keycloak-templates/
```

Do not serve normal traffic between installing the generic files and removing the legacy files.

## 5. Validate after migration

Check at minimum:

```bash
wp --path=/path/to/wordpress plugin status daggerhart-openid-connect-generic
wp --path=/path/to/wordpress plugin list --status=must-use
wp --path=/path/to/wordpress wp-oidc-keycloak update
```

Then test login, logout, registration, password recovery/change, WooCommerce My Account and any checkout provisioning flow used by the deployment.
