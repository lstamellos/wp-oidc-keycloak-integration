# Namespace migration correctness audit — 0.6.32

This audit compares the verified pre-generic `0.6.29` integration with the generic namespace and treats the change as a compatibility migration, not a textual rename.

## Scope

The audit covers:

- PHP classes and method surface;
- server-side feature constants;
- provisioner configuration/path resolution;
- WordPress/OIDC hooks and callback paths;
- Keycloak configuration keys, attributes, required actions and REST path fragments;
- WordPress user metadata and WooCommerce order metadata;
- account-action/query parameters and signed contribution links;
- WordPress error codes;
- MU-plugin/template filenames;
- WP-Cron hooks, updater lock/options and WP-CLI commands;
- CSS classes, DOM IDs and WordPress style handles;
- release manifest requirements, hashes, installer switching and updater rollback.

## Invariants retained exactly

The namespace migration does not rename upstream or identity-provider contracts. The following remain unchanged:

- OIDC runtime class `OpenID_Connect_Generic`;
- dependency slug `daggerhart-openid-connect-generic`;
- `openid-connect-generic-*` hooks/state/user-meta contracts used by the integration;
- OIDC callback path `/openid-connect-authorize`;
- WordPress `/wp-login.php` dispatch path;
- provisioner configuration keys `KEYCLOAK_BASE_URL`, `KEYCLOAK_ADMIN_BASE_URL`, `KEYCLOAK_REALM`, `KEYCLOAK_PROVISIONER_CLIENT_ID`, `KEYCLOAK_PROVISIONER_SECRET_FILE`;
- Keycloak attributes `wordpress_user_id`, `wordpress_login`, `wordpress_display_name`;
- Keycloak required actions such as `VERIFY_EMAIL` and `UPDATE_PASSWORD`;
- Keycloak Admin API/token/execute-actions path structure.

No identity-provider hostname, realm name, Unix account, server hostname or deployment home path is embedded in the generic runtime.

## Feature constants

The `WP_OIDC_KEYCLOAK_*` names are canonical. During migration, the old five authentication feature constants and two updater constants are accepted as fallbacks. Generic definitions take precedence when both exist, including an explicit generic `false` over legacy `true`.

## Persisted metadata

All 19 custom persisted fields have an exact suffix-preserving mapping from the pre-generic prefix to `_wp_oidc_keycloak_`:

- `provisioning_status`
- `provisioning_source`
- `provisioning_decision`
- `guest_auto_link_user_id`
- `guest_auto_link_method`
- `guest_auto_link_email_sha256`
- `guest_auto_linked_at_utc`
- `guest_claim_user_id`
- `guest_claim_subject`
- `guest_claimed_at_utc`
- `activation_email_status`
- `activation_email_actions`
- `activation_email_error`
- `activation_email_error_code`
- `activation_email_error_detail`
- `activation_email_error_sha256`
- `activation_email_failed_at_utc`
- `activation_email_last_attempt_at_utc`
- `activation_email_sent_at_utc`

The migration layer uses canonical-first reads, legacy fallback, canonical writes, synchronization of already-existing legacy keys, and dual deletion. Fresh generic installations do not create legacy metadata.

## Existing URLs/actions/extensions

Already generated pre-generic account/contribution links remain accepted. New links emit only canonical generic query/action names. Contribution signatures remain valid because the signature payload does not include the query parameter names.

The pre-generic checkout-registration filter is applied first and feeds the canonical generic filter, preserving existing extension behavior while making the generic hook authoritative for new code.

Machine-readable pre-generic `WP_Error` codes are retained during the migration window. They are not user-facing branding and changing them would provide no functional benefit while potentially breaking extension checks.

## PHP/UI/update compatibility

The migration window retains:

- the pre-generic integration class as a delayed alias;
- a runtime dual-load guard that refuses to bootstrap the generic integration if the complete legacy class is already loaded;
- the old WP-CLI updater command as an alias;
- old updater cron/lock/configuration fallback handling;
- dual legacy+generic CSS classes;
- legacy DOM IDs where HTML cannot carry two IDs;
- the legacy WordPress style handle as an alias of the generic handle.

## Filesystem/config migration

The runtime no longer embeds a provisioner configuration path. `WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH` must resolve to an absolute readable file before migration.

The installer performs the legacy-to-generic filename switch under maintenance mode. It validates/stages files before mutation, backs up the relevant MU-plugin state, removes the legacy files, activates all generic files without invoking WordPress mid-switch, checks the resulting live runtime, and restores the original state on any failure.

## Updater safety

The updater validates the release manifest and file hashes and now also enforces the release's WordPress, PHP and OIDC dependency constraints before replacement. Rollback covers both overwritten files and newly created files. Post-install version verification is inside the rollback scope.

The release checksum file records only the asset basename, so it does not leak or depend on the build runner's absolute path.

## Regression tests performed

The 0.6.32 validation suite and explicit harnesses cover:

- PHP lint of all runtime files;
- source/tag/version consistency;
- absence of deployment-specific hosts/paths and hard-coded language blog IDs;
- exact 19/19 metadata suffix mapping;
- legacy feature-flag fallback and generic precedence;
- legacy metadata read/sync/delete and query fallback;
- normal legacy class alias and unsafe dual-load rejection;
- updater WordPress/OIDC requirement enforcement;
- updater rollback after forced post-install verification failure;
- installer success migration from a simulated legacy MU-plugin tree;
- installer restoration of the complete legacy tree after a forced post-switch runtime failure;
- release ZIP/manifest/hash generation.

## Intentional non-compatible names

The installed canonical MU-plugin/template filenames and the provisioner-path setting are intentionally generic. These are migrated atomically by `scripts/install.sh`; the generic runtime does not keep a second full legacy plugin copy. User-facing project wording is also generic except for author attribution and explicitly documented migration aliases.
