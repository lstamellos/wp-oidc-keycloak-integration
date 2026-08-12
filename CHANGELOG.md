# Changelog

## 0.6.32 — 2026-08-13

- Re-audit the complete `0.6.29` → generic namespace migration as a compatibility migration rather than a textual rename.
- Add generic-first fallback for the five pre-generic authentication feature constants and the two updater configuration constants.
- Preserve existing `_omniatv_keycloak_*` user/order state through generic-first legacy-fallback reads, synchronized migration-window writes and dual-key deletion.
- Accept pre-generic account-action and contribution/account-link query parameters so links generated before the upgrade remain valid.
- Invoke the legacy checkout-registration filter before the canonical generic filter.
- Add a delayed `OmniaTV_Keycloak_Integration` class alias, legacy WP-CLI updater alias, old cron/lock handling, preserved machine-readable WP error codes, dual theme CSS classes, legacy DOM IDs and a legacy style-handle alias.
- Make the bootstrap installer fail closed on dependency/configuration problems, back up the full relevant MU-plugin state, switch legacy → generic files under maintenance mode, verify the live runtime and roll back on failure.
- Make the updater enforce `release.json` WordPress/PHP/OIDC requirements before file replacement and fix rollback for files newly created during a failed update.
- Make release checksum files portable by recording the release asset basename instead of the builder's absolute filesystem path.
- Strengthen validation so deployment-specific hosts/paths remain forbidden while only an explicit whitelist of migration aliases is allowed.

## 0.6.31 — 2026-08-12

- Rename runtime files, classes, hooks, options, CSS identifiers and WP-CLI command to the generic `wp-oidc-keycloak` namespace.
- Remove deployment-specific hostnames, identity-provider URIs, Unix home paths, users and filesystem ownership assumptions.
- Replace the hard-coded provisioner configuration path with `WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH` (constant or environment variable).
- Generalize user-facing account and authentication wording while retaining **OmniaTV** only as the project author attribution.
- Declare `daggerhart-openid-connect-generic` with the WordPress `Requires Plugins` header.
- Add a runtime dependency guard for **OpenID Connect Generic Client >= 3.11.3**.
- Make the bootstrap installer verify that the OIDC dependency is installed, active and sufficiently recent.
- Add validation that rejects deployment-specific references from runtime/source files.
- Retain the dedicated GitHub Release MU updater with SHA-256 verification, atomic deployment and rollback.

## 0.6.29 — 2026-08-12

- Source release used as the verified account-authority baseline before repository generalization.
