# Changelog

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
