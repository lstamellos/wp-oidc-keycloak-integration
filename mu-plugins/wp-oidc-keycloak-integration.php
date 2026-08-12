<?php
/**
 * Plugin Name: WP OIDC Keycloak Integration
 * Description: Keycloak/OIDC account-authority integration for WordPress and WooCommerce.
 * Version: 0.6.31
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: daggerhart-openid-connect-generic
 * Update URI: https://github.com/lstamellos/wp-oidc-keycloak-integration
 * Author: OmniaTV
 * Author URI: https://omniatv.com/
 * Plugin URI: https://github.com/lstamellos/wp-oidc-keycloak-integration
 * Network: true
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WP_OIDC_Keycloak_Integration
{
    private static bool $checkoutProvisioningRunning = false;

    /**
     * Customer IDs completed by this bridge during the
     * current PHP request.
     *
     * @var array<int,true>
     */
    private static array $checkoutProvisionedCustomers = [];

    /**
     * Name of the server-side constant/environment variable that points
     * to the WooCommerce-to-Keycloak provisioner configuration file.
     *
     * The configured file should live outside the public document root.
     */
    private const PROVISIONER_CONFIG_PATH_SETTING =
        'WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH';

    /**
     * Required keys in the provisioner runtime config.
     *
     * @var list<string>
     */
    private const PROVISIONER_REQUIRED_CONFIG_KEYS = [
        'KEYCLOAK_BASE_URL',
        'KEYCLOAK_ADMIN_BASE_URL',
        'KEYCLOAK_REALM',
        'KEYCLOAK_PROVISIONER_CLIENT_ID',
        'KEYCLOAK_PROVISIONER_SECRET_FILE',
    ];

    private const OIDC_PLUGIN_CLASS = 'OpenID_Connect_Generic';
    private const OIDC_PLUGIN_SLUG =
        'daggerhart-openid-connect-generic';
    private const OIDC_PLUGIN_MIN_VERSION = '3.11.3';

    private static string $dependencyError = '';

    /**
     * WordPress login actions that must never be treated as
     * ordinary interactive username/password login requests.
     *
     * @var list<string>
     */
    private const NON_NATIVE_LOGIN_ACTIONS = [
        'logout',
        'postpass',
        'activitypub_authorize',
        'tds_validate',
    ];

    /**
     * Start the integration only after the required OIDC client plugin
     * has loaded and meets the minimum supported version.
     */
    public static function bootstrap_with_dependency_check(): void
    {
        $error = self::oidc_dependency_error();

        if ($error !== '') {
            self::$dependencyError = $error;
            add_action(
                'admin_notices',
                [self::class, 'render_dependency_notice']
            );
            add_action(
                'network_admin_notices',
                [self::class, 'render_dependency_notice']
            );
            error_log(
                'WP OIDC Keycloak dependency error: ' . $error
            );
            return;
        }

        self::bootstrap();
    }

    private static function oidc_dependency_error(): string
    {
        if (!class_exists(self::OIDC_PLUGIN_CLASS)) {
            return sprintf(
                'Required plugin %s is not active.',
                self::OIDC_PLUGIN_SLUG
            );
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!function_exists('get_plugins')) {
            return 'WordPress plugin metadata API is unavailable.';
        }

        $plugins = get_plugins();
        $detectedVersion = '';

        foreach ($plugins as $pluginFile => $metadata) {
            if (!is_array($metadata)) {
                continue;
            }

            $textDomain = isset($metadata['TextDomain'])
                ? (string) $metadata['TextDomain']
                : '';
            $directory = dirname((string) $pluginFile);

            if (
                $textDomain !== self::OIDC_PLUGIN_SLUG &&
                $directory !== self::OIDC_PLUGIN_SLUG
            ) {
                continue;
            }

            $detectedVersion = isset($metadata['Version'])
                ? trim((string) $metadata['Version'])
                : '';
            break;
        }

        if ($detectedVersion === '') {
            return sprintf(
                'Required plugin %s is active but its version cannot be verified.',
                self::OIDC_PLUGIN_SLUG
            );
        }

        if (
            version_compare(
                $detectedVersion,
                self::OIDC_PLUGIN_MIN_VERSION,
                '<'
            )
        ) {
            return sprintf(
                'Required plugin %s must be version %s or newer; detected %s.',
                self::OIDC_PLUGIN_SLUG,
                self::OIDC_PLUGIN_MIN_VERSION,
                $detectedVersion
            );
        }

        return '';
    }

    public static function render_dependency_notice(): void
    {
        if (
            self::$dependencyError === '' ||
            !current_user_can('activate_plugins')
        ) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__('WP OIDC Keycloak Integration:', 'wp-oidc-keycloak-integration'),
            esc_html(self::$dependencyError)
        );
    }

    public static function bootstrap(): void
    {
        add_action(
            'init',
            [self::class, 'audit_current_login_surface'],
            0
        );

        add_filter(
            'login_url',
            [self::class, 'filter_login_url'],
            100,
            3
        );


        /*
         * Keep WordPress-generated account recovery/registration URLs
         * as lazy local dispatchers. No OIDC state is created merely
         * by rendering a page containing one of these links.
         */
        add_filter(
            'lostpassword_url',
            [self::class, 'filter_lostpassword_url'],
            100,
            2
        );

        add_filter(
            'register_url',
            [self::class, 'filter_register_url'],
            100,
            1
        );

        /*
         * Native wp-login.php registration and password-management
         * actions are dispatch surfaces only. Keycloak remains the
         * credential authority.
         */
        add_action(
            'login_init',
            [self::class, 'redirect_native_account_actions'],
            0
        );


        /*
         * WordPress Multisite has a separate public registration
         * surface at wp-signup.php. Registrations are
         * Keycloak-authoritative, so that surface is only a dispatcher
         * to the central registration flow.
         */
        add_action(
            'before_signup_header',
            [self::class, 'redirect_multisite_signup_to_keycloak'],
            0
        );

        /*
         * Newspaper/tagDiv still registers legacy AJAX account
         * handlers even though the visible modal is replaced.
         * Reject direct calls to those native account endpoints.
         */
        add_action(
            'init',
            [self::class, 'block_legacy_tagdiv_account_ajax'],
            0
        );


        /*
         * Wordfence Login Security has independent password/passkey
         * authentication endpoints. Human authentication on this site
         * remains exclusively Keycloak-authoritative.
         */
        add_action(
            'init',
            [self::class, 'block_wordfence_native_auth_ajax'],
            0
        );

        add_filter(
            'rest_pre_dispatch',
            [self::class, 'block_wordfence_passkey_rest'],
            0,
            3
        );

        /*
         * Disable WooCommerce's native My Account login,
         * registration and password-reset processors while leaving
         * checkout/customer provisioning untouched.
         */
        add_action(
            'wp_loaded',
            [
                self::class,
                'disable_woocommerce_native_account_handlers',
            ],
            1
        );

        /*
         * A direct visit to the WooCommerce lost-password endpoint
         * must also enter the central Keycloak recovery flow.
         */
        add_action(
            'template_redirect',
            [
                self::class,
                'redirect_woocommerce_lost_password_surface',
            ],
            0
        );

        add_filter(
            'logout_redirect',
            [self::class, 'normalize_oidc_logout_redirect'],
            90,
            3
        );

        /*
         * OpenID Connect Generic checks state existence
         * through get_option() before using get_transient().
         * With a persistent external object cache, WordPress
         * stores the transient in the cache rather than the
         * options table. Expose only OIDC state transients to
         * that compatibility check.
         */
        add_filter(
            'pre_option',
            [
                self::class,
                'expose_cached_oidc_state_to_option_check',
            ],
            10,
            3
        );

        add_action(
            'login_init',
            [self::class, 'maybe_redirect_direct_login'],
            1
        );

        add_filter(
            'authenticate',
            [self::class, 'block_native_authentication'],
            1000,
            3
        );

        /*
         * td-woo replaces the standard WooCommerce checkout login
         * form with a link to My Account. Replace only that custom
         * callback after normal plugins have registered their hooks.
         */
        add_action(
            'wp_loaded',
            [
                self::class,
                'configure_woocommerce_checkout_login_panel',
            ],
            100
        );

        /*
         * Inject the checkout login panel at WordPress page-content
         * level. This supports both the classic WooCommerce shortcode
         * checkout and the Checkout Block without depending on either
         * checkout renderer's internal hooks.
         */
        add_filter(
            'the_content',
            [
                self::class,
                'prepend_woocommerce_checkout_login_panel',
            ],
            20
        );

        add_action(
            'woocommerce_before_customer_login_form',
            [self::class, 'render_woocommerce_login_panel'],
            1
        );

        add_action(
            'wp_enqueue_scripts',
            [self::class, 'enqueue_styles']
        );

        /*
         * Permit the upstream OIDC plugin to correlate a newly
         * encountered Keycloak subject with an existing WordPress
         * account by email.
         *
         * The actual correlation remains guarded by
         * authorize_safe_email_correlation().
         */
        add_filter(
            'openid-connect-generic-settings',
            [
                self::class,
                'configure_safe_email_correlation_settings',
            ],
            20,
            1
        );

        add_filter(
            'openid-connect-generic-user-login-test',
            [
                self::class,
                'authorize_safe_email_correlation',
            ],
            20,
            2
        );

        /*
         * Login email is immutable through the self-service SSO surface.
         * Do not copy a changed OIDC email claim into WordPress: a partial
         * propagation would desynchronize WordPress, Nextcloud and PeerTube.
         */

        add_action(
            'openid-connect-generic-user-create',
            [self::class, 'configure_new_oidc_user'],
            10,
            2
        );

        add_action(
            'openid-connect-generic-user-logged-in',
            [self::class, 'ensure_oidc_user_site_membership'],
            10,
            1
        );

        /*
         * Replace any stale guest WooCommerce customer snapshot after
         * OIDC authentication. Cart and other session data are retained.
         */
        add_action(
            'openid-connect-generic-user-logged-in',
            [
                self::class,
                'synchronize_woocommerce_customer_session',
            ],
            20,
            1
        );

        /*
         * Keep the reverse WordPress identity attributes in
         * Keycloak synchronized after a successful OIDC login.
         *
         * Production execution is disabled by default until the
         * dedicated canary has passed.
         */
        add_action(
            'openid-connect-generic-user-logged-in',
            [
                self::class,
                'synchronize_keycloak_wordpress_attributes',
            ],
            30,
            1
        );

        /*
         * A successful Keycloak/OIDC authentication is the
         * authoritative email-verification event for this site.
         *
         * Once the current OIDC subject and email have been
         * correlated safely with the WordPress account, mark
         * the email verified in WooCommerce. WooCommerce then
         * performs its normal past guest-order reconciliation.
         */
        add_action(
            'openid-connect-generic-user-logged-in',
            [
                self::class,
                'synchronize_woocommerce_verified_email',
            ],
            40,
            1
        );

        add_action(
            'woocommerce_before_customer_login_form',
            [self::class, 'render_registration_panel'],
            11
        );

        add_filter(
            'wc_get_template',
            [self::class, 'replace_my_account_login_template'],
            10,
            5
        );

        /*
         * Email and password are Keycloak-authoritative. The WooCommerce
         * account form may continue to save profile fields, but it must not
         * require or mutate WordPress identity credentials.
         */
        add_filter(
            'woocommerce_save_account_details_required_fields',
            [
                self::class,
                'filter_woocommerce_account_required_fields',
            ],
            100,
            1
        );

        add_action(
            'woocommerce_save_account_details_errors',
            [
                self::class,
                'block_woocommerce_identity_mutation',
            ],
            100,
            2
        );

        add_action(
            'woocommerce_created_customer',
            [self::class, 'provision_checkout_customer'],
            1,
            3
        );

        add_filter(
            'woocommerce_email_enabled_customer_new_account',
            [
                self::class,
                'filter_woocommerce_new_account_email',
            ],
            10,
            3
        );

        add_action(
            'woocommerce_payment_complete',
            [
                self::class,
                'maybe_auto_link_paid_guest_open_contribution',
            ],
            20,
            1
        );

        add_action(
            'woocommerce_order_status_processing',
            [
                self::class,
                'maybe_auto_link_paid_guest_open_contribution',
            ],
            20,
            1
        );

        add_action(
            'woocommerce_order_status_completed',
            [
                self::class,
                'maybe_auto_link_paid_guest_open_contribution',
            ],
            20,
            1
        );

        add_action(
            'woocommerce_thankyou',
            [
                self::class,
                'render_guest_open_contribution_account_cta',
            ],
            20,
            1
        );

        add_filter(
            'woocommerce_order_received_verify_known_shoppers',
            [
                self::class,
                'allow_auto_linked_contribution_order_received',
            ],
            20,
            1
        );

        add_filter(
            'woocommerce_order_email_verification_required',
            [
                self::class,
                'allow_auto_linked_contribution_email_verification',
            ],
            20,
            3
        );

        add_action(
            'template_redirect',
            [
                self::class,
                'maybe_claim_guest_open_contribution_order',
            ],
            20
        );
    }

    /**
     * Normalize the local WordPress logout destination before the
     * OpenID Connect Generic plugin wraps it in the Keycloak
     * end-session URL.
     *
     * WordPress may append locale-dependent parameters such as
     * wp_lang, which would otherwise require multiple registered
     * post-logout redirect URIs in Keycloak.
     *
     * @param string  $redirectTo          Final WordPress destination.
     * @param string  $requestedRedirectTo Originally requested destination.
     * @param WP_User $user                User being logged out.
     */
    public static function normalize_oidc_logout_redirect(
        string $redirectTo,
        string $requestedRedirectTo,
        WP_User $user
    ): string {
        unset($requestedRedirectTo, $user);

        $path = wp_parse_url($redirectTo, PHP_URL_PATH);
        $query = wp_parse_url($redirectTo, PHP_URL_QUERY);

        if (
            !is_string($path) ||
            basename($path) !== 'wp-login.php' ||
            !is_string($query)
        ) {
            return $redirectTo;
        }

        parse_str($query, $parameters);

        if (
            !isset($parameters['loggedout']) ||
            strtolower(
                trim((string) $parameters['loggedout'])
            ) !== 'true'
        ) {
            return $redirectTo;
        }

        return home_url('/');
    }

    /**
     * Report whether central OIDC login URL filtering has
     * been explicitly enabled by trusted server-side
     * configuration.
     */
    public static function login_url_filtering_enabled(): bool
    {
        return defined(
            'WP_OIDC_KEYCLOAK_FILTER_LOGIN_URLS'
        ) &&
            WP_OIDC_KEYCLOAK_FILTER_LOGIN_URLS === true;
    }

    /**
     * Replace WordPress-generated interactive login URLs
     * with an OIDC authorization URL.
     *
     * Disabled by default. The original URL is preserved
     * unless the server-side feature flag is explicitly
     * enabled and a valid OIDC URL can be generated.
     */
    public static function filter_login_url(
        string $loginUrl,
        string $redirectTo,
        bool $forceReauth
    ): string {
        if (!self::login_url_filtering_enabled()) {
            return $loginUrl;
        }


        /*
         * The OIDC plugin uses wp_login_url() while handling
         * callback failures. Redirecting that URL back to the
         * identity provider would hide the original error and
         * append login-error parameters to the authorization
         * URL. Keep callback and OIDC error handling local.
         */
        if (
            self::is_oidc_callback_request() ||
            self::has_oidc_login_error($loginUrl) ||
            self::is_oidc_post_logout_return_url($loginUrl) ||
            self::is_wordpress_logout_request()
        ) {
            return $loginUrl;
        }

        $redirectTo = trim($redirectTo);

        if ($redirectTo === '') {
            $redirectTo =
                self::default_login_redirect_for_request();
        }

        $authenticationUrl =
            self::authentication_url($redirectTo);

        if ($authenticationUrl === '') {
            return $loginUrl;
        }

        if ($forceReauth) {
            $authenticationUrl = add_query_arg(
                'prompt',
                'login',
                $authenticationUrl
            );
        }

        return $authenticationUrl;
    }

    /**
     * Redirect the WordPress Multisite public signup surface to
     * Keycloak registration.
     */
    public static function redirect_multisite_signup_to_keycloak(): void
    {
        if (!is_multisite()) {
            return;
        }

        /*
         * Keep wp-signup.php stateless. This surface may be cached
         * aggressively, so it must never emit a Keycloak authorization
         * URL containing a generated OIDC state value.
         *
         * wp-login.php?action=register is the local dispatcher and
         * generates a fresh Keycloak registration request per visit.
         */
        $url = network_site_url(
            'wp-login.php?action=register',
            'login'
        );

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        nocache_headers();

        wp_safe_redirect(
            $url,
            302,
            'WP OIDC Keycloak'
        );

        exit;
    }


    /**
     * Keep generated WordPress/WooCommerce lost-password links lazy.
     *
     * The local wp-login.php URL is only a dispatcher. Visiting it
     * is intercepted below and starts the central Keycloak flow.
     */
    public static function filter_lostpassword_url(
        string $lostPasswordUrl,
        string $redirectTo
    ): string {
        unset($lostPasswordUrl);

        $url = network_site_url(
            'wp-login.php?action=lostpassword',
            'login'
        );

        $redirectTo = trim($redirectTo);

        if ($redirectTo !== '') {
            $url = add_query_arg(
                'redirect_to',
                self::safe_local_redirect($redirectTo),
                $url
            );
        }

        return $url;
    }

    /**
     * Keep generated registration URLs as a lazy local dispatcher.
     */
    public static function filter_register_url(
        string $registerUrl
    ): string {
        unset($registerUrl);

        return network_site_url(
            'wp-login.php?action=register',
            'login'
        );
    }

    /**
     * Generate the central password-recovery entry point.
     *
     * Keycloak exposes Forgot Password from its login page. prompt=login
     * guarantees that this login surface is shown even when an SSO
     * session already exists in the browser.
     */
    public static function get_password_recovery_url(
        string $redirectTo = ''
    ): string {
        $redirectTo = trim($redirectTo);

        if ($redirectTo === '') {
            $redirectTo = self::current_account_url();
        }

        $url = self::authentication_url($redirectTo);

        if ($url === '') {
            return '';
        }

        return add_query_arg(
            'prompt',
            'login',
            $url
        );
    }

    /**
     * Convert native WordPress registration/password actions into
     * Keycloak flows before wp-login.php can render or process them.
     */
    public static function redirect_native_account_actions(): void
    {
        $rawAction = $_REQUEST['action'] ?? '';

        if (!is_string($rawAction)) {
            return;
        }

        $action = sanitize_key(
            (string) wp_unslash($rawAction)
        );

        $url = '';

        if ($action === 'register') {
            $url = self::get_registration_url();
        } elseif (
            in_array(
                $action,
                [
                    'lostpassword',
                    'retrievepassword',
                    'resetpass',
                    'rp',
                ],
                true
            )
        ) {
            $redirectTo = '';

            $rawRedirect = $_REQUEST['redirect_to'] ?? '';

            if (is_string($rawRedirect)) {
                $redirectTo = self::safe_local_redirect(
                    (string) wp_unslash($rawRedirect)
                );
            }

            $url = self::get_password_recovery_url(
                $redirectTo
            );
        } elseif ($action === 'wp_oidc_keycloak_update_email') {
            wp_die(
                esc_html__(
                    'Login email changes are not available for central accounts.',
                    'wp-oidc-keycloak-integration'
                ),
                esc_html__(
                    'central account',
                    'wp-oidc-keycloak-integration'
                ),
                ['response' => 403]
            );
        } elseif ($action === 'wp_oidc_keycloak_update_password') {
            $redirectTo = self::current_edit_account_url();
            $rawRedirect = $_REQUEST['redirect_to'] ?? '';

            if (is_string($rawRedirect) && trim($rawRedirect) !== '') {
                $redirectTo = self::safe_local_redirect(
                    (string) wp_unslash($rawRedirect)
                );
            }

            $url = self::get_account_action_url(
                'UPDATE_PASSWORD',
                $redirectTo
            );
        } else {
            return;
        }

        if ($url === '') {
            wp_die(
                esc_html__(
                    'The central identity service is temporarily unavailable.',
                    'wp-oidc-keycloak-integration'
                ),
                esc_html__(
                    'central account',
                    'wp-oidc-keycloak-integration'
                ),
                ['response' => 503]
            );
        }

        $method = strtoupper(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        );

        $status = in_array(
            $method,
            ['GET', 'HEAD'],
            true
        )
            ? 302
            : 303;

        wp_redirect(
            $url,
            $status,
            'WP OIDC Keycloak'
        );
        exit;
    }

    /**
     * Reject Wordfence alternate interactive authentication AJAX
     * endpoints.
     */
    public static function block_wordfence_native_auth_ajax(): void
    {
        if (!wp_doing_ajax()) {
            return;
        }

        $rawAction = $_REQUEST['action'] ?? '';

        if (!is_string($rawAction)) {
            return;
        }

        $action = sanitize_key(
            (string) wp_unslash($rawAction)
        );

        if (
            !in_array(
                $action,
                [
                    'wordfence_ls_authenticate',
                    'wordfence_ls_begin_passkey_login',
                    'wordfence_ls_finish_passkey_login',
                ],
                true
            )
        ) {
            return;
        }

        wp_send_json(
            [
                'error' =>
                    'native_authentication_disabled',
                'message' =>
                    'Use the central identity service.',
            ],
            403
        );
    }

    /**
     * Reject Wordfence headless passkey authentication.
     *
     * Firewall, scanning and the ordinary Wordfence REST API remain
     * untouched.
     */
    public static function block_wordfence_passkey_rest(
        $result,
        $server,
        $request
    ) {
        unset($server);

        if (
            !is_object($request) ||
            !method_exists($request, 'get_route')
        ) {
            return $result;
        }

        $route = (string) $request->get_route();

        if (
            !str_starts_with(
                $route,
                '/wordfence-login-security/v1/'
            ) ||
            !str_contains($route, 'passkey')
        ) {
            return $result;
        }

        return new WP_Error(
            'native_authentication_disabled',
            'Use the central identity service.',
            ['status' => 403]
        );
    }


    /**
     * Reject direct calls to Newspaper/tagDiv's legacy native account
     * AJAX handlers.
     */
    public static function block_legacy_tagdiv_account_ajax(): void
    {
        if (!wp_doing_ajax()) {
            return;
        }

        $rawAction = $_REQUEST['action'] ?? '';

        if (!is_string($rawAction)) {
            return;
        }

        $action = sanitize_key(
            (string) wp_unslash($rawAction)
        );

        $legacyAccountAction = in_array(
            $action,
            [
                'td_mod_login',
                'td_mod_register',
                'td_mod_remember_pass',

                /*
                 * tagDiv subscription/paywall account handlers are not
                 * used on this site. WordPress account creation, password
                 * reset and authentication remain Keycloak-authoritative.
                 */
                'td_mod_subscription_register',
                'td_mod_subscription_reset_pass',
                'td_resend_subscription_activation_link',

                /*
                 * Newspaper's legacy Facebook login is likewise not an
                 * central authentication authority.
                 */
                'td_ajax_fb_login_get_credentials',
                'td_ajax_fb_login_user',
            ],
            true
        );

        $legacyRecoveryAction =
            str_starts_with($action, 'td_mod_') &&
            str_contains($action, 'pass') &&
            (
                str_contains($action, 'lost') ||
                str_contains($action, 'forgot')
            );

        if (
            !$legacyAccountAction &&
            !$legacyRecoveryAction
        ) {
            return;
        }

        wp_send_json(
            [
                'error' =>
                    'native_authentication_disabled',
                'message' =>
                    'Use the central identity service.',
            ],
            403
        );
    }

    /**
     * Disable WooCommerce My Account native authentication,
     * registration and password-reset processors.
     *
     * Checkout processing is deliberately not modified.
     */
    public static function disable_woocommerce_native_account_handlers(): void
    {
        if (!class_exists('WC_Form_Handler')) {
            return;
        }

        remove_action(
            'wp_loaded',
            ['WC_Form_Handler', 'process_login'],
            20
        );

        remove_action(
            'wp_loaded',
            ['WC_Form_Handler', 'process_registration'],
            20
        );

        remove_action(
            'wp_loaded',
            ['WC_Form_Handler', 'process_lost_password'],
            20
        );

        remove_action(
            'wp_loaded',
            ['WC_Form_Handler', 'process_reset_password'],
            20
        );

        remove_action(
            'template_redirect',
            [
                'WC_Form_Handler',
                'redirect_reset_password_link',
            ],
            10
        );

        remove_action(
            'template_redirect',
            [
                'WC_Form_Handler',
                'resend_set_password',
            ],
            10
        );
    }

    /**
     * Redirect WooCommerce's /my-account/lost-password/ surface to
     * Keycloak. POSTs use 303 so any submitted native credentials are
     * discarded rather than replayed.
     */
    public static function redirect_woocommerce_lost_password_surface(): void
    {
        if (
            !function_exists('is_lost_password_page') ||
            !is_lost_password_page()
        ) {
            return;
        }

        $url = self::get_password_recovery_url(
            self::current_account_url()
        );

        if ($url === '') {
            wp_die(
                esc_html__(
                    'The central identity service is temporarily unavailable.',
                    'wp-oidc-keycloak-integration'
                ),
                esc_html__(
                    'central account',
                    'wp-oidc-keycloak-integration'
                ),
                ['response' => 503]
            );
        }

        $method = strtoupper(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
        );

        wp_redirect(
            $url,
            in_array($method, ['GET', 'HEAD'], true)
                ? 302
                : 303,
            'WP OIDC Keycloak'
        );
        exit;
    }


    /**
     * Resolve the implicit destination of a login request generated
     * without an explicit redirect_to value.
     *
     * WordPress normally sends a bare wp_login_url() login to the
     * administration area. On WooCommerce frontend screens that is
     * disruptive: authentication must return the customer to the
     * commerce flow that initiated it.
     *
     * Order-pay and order-received endpoints are deliberately excluded
     * because their current URLs may contain order-specific path/query
     * data. Those flows must continue to supply an explicit destination.
     */
    private static function default_login_redirect_for_request(): string
    {
        if (
            function_exists('is_cart') &&
            is_cart() &&
            function_exists('wc_get_cart_url')
        ) {
            return self::safe_local_redirect(
                wc_get_cart_url()
            );
        }

        if (
            function_exists('is_checkout') &&
            is_checkout() &&
            !(
                function_exists('is_checkout_pay_page') &&
                is_checkout_pay_page()
            ) &&
            !(
                function_exists('is_order_received_page') &&
                is_order_received_page()
            ) &&
            function_exists('wc_get_checkout_url')
        ) {
            return self::safe_local_redirect(
                wc_get_checkout_url()
            );
        }

        if (
            function_exists('is_account_page') &&
            is_account_page() &&
            function_exists('wc_get_page_permalink')
        ) {
            $accountUrl = wc_get_page_permalink(
                'myaccount'
            );

            if (
                is_string($accountUrl) &&
                $accountUrl !== ''
            ) {
                return self::safe_local_redirect(
                    $accountUrl
                );
            }
        }

        return admin_url();
    }

    /**
     * Report whether WordPress is currently processing its native
     * logout endpoint.
     *
     * During this request WordPress calls wp_login_url() before it
     * appends the loggedout marker. That intermediate URL must remain
     * local so that the OIDC plugin can construct a valid
     * post_logout_redirect_uri.
     */
    private static function is_wordpress_logout_request(): bool
    {
        if (!isset($_GET['action'])) {
            return false;
        }

        if (
            sanitize_key((string) $_GET['action']) !== 'logout'
        ) {
            return false;
        }

        $requestPath = wp_parse_url(
            (string) ($_SERVER['REQUEST_URI'] ?? ''),
            PHP_URL_PATH
        );

        return is_string($requestPath) &&
            basename($requestPath) === 'wp-login.php';
    }

    /**
     * Preserve the local WordPress return URL generated by the
     * OpenID Connect Generic plugin during logout.
     *
     * Rewriting this URL through the central login_url filter would
     * incorrectly use a Keycloak authorization request as the
     * post_logout_redirect_uri.
     */
    private static function is_oidc_post_logout_return_url(
        string $loginUrl
    ): bool {
        $path = wp_parse_url($loginUrl, PHP_URL_PATH);

        if (
            !is_string($path) ||
            basename($path) !== 'wp-login.php'
        ) {
            return false;
        }

        $query = wp_parse_url($loginUrl, PHP_URL_QUERY);

        if (!is_string($query) || $query === '') {
            return false;
        }

        parse_str($query, $parameters);

        if (!array_key_exists('loggedout', $parameters)) {
            return false;
        }

        return strtolower(
            trim((string) $parameters['loggedout'])
        ) === 'true';
    }

    /**
     * Bridge the OIDC plugin's direct option existence check
     * to WordPress transients stored in an external object
     * cache.
     *
     * The compatibility layer is deliberately restricted to
     * 32-character hexadecimal OIDC state keys. It does not
     * alter any other option or transient.
     *
     * @param mixed  $preOption   Existing short-circuit value.
     * @param string $option      Requested option name.
     * @param mixed  $defaultValue Requested default value.
     *
     * @return mixed
     */
    public static function expose_cached_oidc_state_to_option_check(
        mixed $preOption,
        string $option,
        mixed $defaultValue
    ): mixed {
        unset($defaultValue);

        if (
            $preOption !== false ||
            !wp_using_ext_object_cache()
        ) {
            return $preOption;
        }

        $optionPrefix =
            '_transient_' .
            'openid-connect-generic-state--';

        if (!str_starts_with($option, $optionPrefix)) {
            return $preOption;
        }

        $state = substr(
            $option,
            strlen($optionPrefix)
        );

        if (
            !is_string($state) ||
            preg_match(
                '/^[a-f0-9]{32}$/D',
                $state
            ) !== 1
        ) {
            return $preOption;
        }

        $value = get_transient(
            'openid-connect-generic-state--' .
            $state
        );

        return $value !== false
            ? $value
            : $preOption;
    }

    /**
     * Report whether the current request is an OIDC callback.
     */
    private static function is_oidc_callback_request(): bool
    {
        $action = isset($_REQUEST['action'])
            ? sanitize_key(
                (string) wp_unslash(
                    $_REQUEST['action']
                )
            )
            : '';

        if ($action === 'openid-connect-authorize') {
            return true;
        }

        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_unslash(
                $_SERVER['REQUEST_URI']
            )
            : '';

        $path = wp_parse_url(
            $requestUri,
            PHP_URL_PATH
        );

        return $path === '/openid-connect-authorize' ||
            $path === '/openid-connect-authorize/';
    }

    /**
     * Report whether a WordPress login URL is carrying an
     * OIDC callback error.
     */
    private static function has_oidc_login_error(
        string $loginUrl
    ): bool {
        $query = wp_parse_url(
            $loginUrl,
            PHP_URL_QUERY
        );

        if (!is_string($query) || $query === '') {
            return false;
        }

        parse_str($query, $parameters);

        return isset($parameters['login-error']);
    }

    /**
     * Report whether direct interactive wp-login.php
     * requests should be redirected to the OIDC provider.
     */
    public static function direct_login_redirect_enabled(): bool
    {
        return defined(
            'WP_OIDC_KEYCLOAK_REDIRECT_DIRECT_LOGIN'
        ) &&
            WP_OIDC_KEYCLOAK_REDIRECT_DIRECT_LOGIN === true;
    }

    /**
     * Decide whether one classified login request is
     * eligible for direct OIDC redirection.
     *
     * This method is pure and causes no redirect.
     */
    public static function should_redirect_login_surface(
        string $surface,
        string $method
    ): bool {
        if (!self::direct_login_redirect_enabled()) {
            return false;
        }

        $method = strtoupper(
            trim($method)
        );

        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        return $surface === 'wp_login_form';
    }

    /**
     * Redirect only an ordinary interactive wp-login.php
     * form request to Keycloak.
     *
     * Password recovery, registration, logout, postpass,
     * ActivityPub and theme-specific activation actions
     * remain untouched.
     */
    public static function maybe_redirect_direct_login(): void
    {
        /*
         * OpenID Connect Generic redirects callback failures to
         * wp-login.php?login-error=...&message=... so that its
         * login form can render the original OIDC error.
         *
         * Keep that request local. Starting a fresh authentication
         * request here would discard the failed flow's destination
         * and hide the original callback error.
         */
        if (isset($_GET['login-error'])) {
            return;
        }

        $context =
            self::current_login_surface_context();

        $surface =
            self::classify_login_surface($context);

        $method = (string) (
            $context['method'] ?? 'GET'
        );

        if (
            !self::should_redirect_login_surface(
                $surface,
                $method
            )
        ) {
            return;
        }

        $redirectTo = isset($_REQUEST['redirect_to'])
            ? self::safe_local_redirect(
                (string) wp_unslash(
                    $_REQUEST['redirect_to']
                )
            )
            : admin_url();

        $authenticationUrl =
            self::authentication_url($redirectTo);

        if ($authenticationUrl === '') {
            return;
        }

        $forceReauth =
            isset($_REQUEST['reauth']) &&
            (string) $_REQUEST['reauth'] !== '' &&
            (string) $_REQUEST['reauth'] !== '0';

        if ($forceReauth) {
            $authenticationUrl = add_query_arg(
                'prompt',
                'login',
                $authenticationUrl
            );
        }

        wp_safe_redirect($authenticationUrl);
        exit;
    }

    /**
     * Report whether native username/password authentication
     * should be rejected.
     */
    public static function native_authentication_blocking_enabled(): bool
    {
        return defined(
            'WP_OIDC_KEYCLOAK_BLOCK_NATIVE_AUTHENTICATION'
        ) &&
            WP_OIDC_KEYCLOAK_BLOCK_NATIVE_AUTHENTICATION === true;
    }

    /**
     * Decide whether a classified authentication request is a
     * native interactive password-login surface.
     *
     * The method is pure and does not authenticate or mutate state.
     */
    public static function should_block_native_authentication(
        string $surface
    ): bool {
        if (
            !self::native_authentication_blocking_enabled()
        ) {
            return false;
        }

        return in_array(
            $surface,
            [
                'wp_login_native_credentials',
                'frontend_native_credentials',
                'woocommerce_native_credentials',
                'newspaper_ajax_native_credentials',
            ],
            true
        );
    }

    /**
     * Reject native interactive password authentication while
     * preserving OIDC callbacks, REST, XML-RPC, checkout account
     * creation and special login actions.
     *
     * Runs after all ordinary authentication callbacks and acts as
     * terminal enforcement for selected interactive password surfaces.
     *
     * @param null|WP_User|WP_Error $user
     * @return null|WP_User|WP_Error
     */
    public static function block_native_authentication(
        $user,
        string $username,
        string $password
    ) {
        $context =
            self::current_login_surface_context();

        $surface =
            self::classify_login_surface($context);

        if (
            !self::should_block_native_authentication(
                $surface
            )
        ) {
            return $user;
        }

        return new WP_Error(
            'wp_oidc_keycloak_native_login_disabled',
            __(
                'Password login is disabled. Please sign in with OpenID Connect.',
                'wp-oidc-keycloak-integration'
            )
        );
    }

    /**
     * Generate an OIDC URL through the installed OIDC plugin.
     *
     * The plugin creates and stores the state transient and associates
     * the requested return URL with that state.
     */
    public static function authentication_url(string $redirect_to): string
    {
        $redirect_to = self::safe_local_redirect($redirect_to);

        if (
            !class_exists(self::OIDC_PLUGIN_CLASS) ||
            !method_exists(
                self::OIDC_PLUGIN_CLASS,
                'instance'
            )
        ) {
            return '';
        }

        $plugin = OpenID_Connect_Generic::instance();

        if (
            !isset($plugin->client_wrapper) ||
            !is_object($plugin->client_wrapper) ||
            !method_exists(
                $plugin->client_wrapper,
                'get_authentication_url'
            )
        ) {
            return '';
        }

        $url = $plugin->client_wrapper->get_authentication_url(
            [
                'redirect_to' => $redirect_to,
            ]
        );

        return is_string($url)
            ? esc_url_raw($url)
            : '';
    }

    /**
     * Build an OIDC Application Initiated Action URL for the self-service
     * password change. Login email is intentionally immutable.
     */
    public static function get_account_action_url(
        string $action,
        string $redirectTo = ''
    ): string {
        $allowedActions = [
            'UPDATE_PASSWORD',
        ];

        if (!in_array($action, $allowedActions, true)) {
            return '';
        }

        if ($redirectTo === '') {
            $redirectTo = self::current_edit_account_url();
        }

        $url = self::authentication_url($redirectTo);

        if ($url === '') {
            return '';
        }

        return esc_url_raw(
            add_query_arg(
                'kc_action',
                $action,
                $url
            )
        );
    }

    /**
     * Return a local, stateless dispatcher URL for a Keycloak account
     * action. OIDC state is generated only after the user clicks it.
     */
    public static function get_account_action_dispatch_url(
        string $action,
        string $redirectTo = ''
    ): string {
        $dispatchActions = [
            'UPDATE_PASSWORD' => 'wp_oidc_keycloak_update_password',
        ];

        if (!isset($dispatchActions[$action])) {
            return '';
        }

        if ($redirectTo === '') {
            $redirectTo = self::current_edit_account_url();
        }

        $url = network_site_url(
            'wp-login.php?action=' . rawurlencode(
                $dispatchActions[$action]
            ),
            'login'
        );

        return add_query_arg(
            'redirect_to',
            self::safe_local_redirect($redirectTo),
            $url
        );
    }

    /**
     * Remove checkout login renderers tied to the classic td-woo
     * template and initialize the WooCommerce checkout singleton.
     *
     * The OpenID Connect login panel is rendered independently through the
     * WordPress the_content filter, supporting both classic checkout
     * and Checkout Block pages.
     */
    public static function configure_woocommerce_checkout_login_panel(): void
    {
        if (!function_exists('WC')) {
            return;
        }

        remove_action(
            'woocommerce_checkout_before_order_review_heading',
            'woocommerce_checkout_custom_login',
            10
        );

        /*
         * Remove any earlier OpenID Connect registration on the classic-only
         * hook. This prevents duplicate output during upgrades from
         * previous development versions.
         */
        remove_action(
            'woocommerce_checkout_before_order_review_heading',
            [
                self::class,
                'render_woocommerce_checkout_login_panel',
            ],
            10
        );

        /*
         * WooCommerce registers its current billing renderer from the
         * WC_Checkout singleton constructor. td-woo invokes the billing
         * hook directly, so ensure that the checkout singleton has been
         * initialized before the classic checkout template is rendered.
         */
        if (
            class_exists('WC_Checkout') &&
            method_exists(
                'WC_Checkout',
                'instance'
            )
        ) {
            WC_Checkout::instance();
        }
    }

    /**
     * Prepend the Keycloak login panel to the main WooCommerce checkout
     * page content.
     *
     * This operates at WordPress page-content level and therefore does
     * not depend on classic checkout hooks, Checkout Block Slot/Fills,
     * theme markup, or td-woo template internals.
     */
    public static function prepend_woocommerce_checkout_login_panel(
        string $content
    ): string {
        /*
         * Key the request-level duplicate guard by site and checkout
         * page. This remains correct during multisite switch_to_blog()
         * operations and secondary content processing in one request.
         *
         * @var array<string, bool> $rendered_checkout_pages
         */
        static $rendered_checkout_pages = [];

        if (
            is_user_logged_in() ||
            is_admin() ||
            is_feed() ||
            !in_the_loop() ||
            !is_main_query() ||
            !function_exists('wc_get_page_id')
        ) {
            return $content;
        }

        if (
            defined('REST_REQUEST') &&
            REST_REQUEST
        ) {
            return $content;
        }

        $checkout_page_id = (int) wc_get_page_id(
            'checkout'
        );

        if (
            $checkout_page_id <= 0 ||
            (int) get_queried_object_id() !== $checkout_page_id ||
            (int) get_the_ID() !== $checkout_page_id
        ) {
            return $content;
        }

        $render_key = sprintf(
            '%d:%d',
            get_current_blog_id(),
            $checkout_page_id
        );

        if (
            isset(
                $rendered_checkout_pages[$render_key]
            )
        ) {
            return $content;
        }

        /*
         * Do not prepend login UI to payment or order-confirmation
         * endpoints that use the checkout page as their URL base.
         */
        if (
            (
                function_exists('is_checkout_pay_page') &&
                is_checkout_pay_page()
            ) ||
            (
                function_exists('is_order_received_page') &&
                is_order_received_page()
            )
        ) {
            return $content;
        }

        if (
            get_option(
                'woocommerce_enable_checkout_login_reminder'
            ) !== 'yes'
        ) {
            return $content;
        }

        ob_start();

        self::render_woocommerce_checkout_login_panel();

        $panel = (string) ob_get_clean();

        if ($panel === '') {
            return $content;
        }

        $rendered_checkout_pages[$render_key] = true;

        return $panel . $content;
    }

    /**
     * Display the Keycloak login action for the active checkout page.
     *
     * The OIDC flow returns to the current site's WooCommerce checkout
     * URL, allowing the cart and checkout session to continue.
     */
    public static function render_woocommerce_checkout_login_panel(): void
    {
        if (
            is_user_logged_in() ||
            get_option(
                'woocommerce_enable_checkout_login_reminder'
            ) !== 'yes'
        ) {
            return;
        }

        $checkout_url = function_exists('wc_get_checkout_url')
            ? wc_get_checkout_url()
            : home_url('/');

        $authentication_url = self::authentication_url(
            $checkout_url
        );

        if ($authentication_url === '') {
            return;
        }

        $locale = strtolower(determine_locale());
        $is_english = !str_starts_with(
            $locale,
            'el'
        );

        $heading = $is_english
            ? 'Already a customer?'
            : 'Είστε ήδη πελάτης;';

        $description = $is_english
            ? 'Sign in to continue your order with your saved account details.'
            : 'Συνδεθείτε για να συνεχίσετε την παραγγελία με τα αποθηκευμένα στοιχεία του λογαριασμού σας.';

        $login_label = $is_english
            ? 'Sign in with OpenID Connect'
            : 'Σύνδεση με OpenID Connect';

        printf(
            '<div class="td-woo-coupon-wrap wp-oidc-keycloak-checkout-login">
                <svg width="24" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
                    <path d="M16 15.65c3.472 0 6.286-2.814 6.287-6.287-0.001-3.473-2.815-6.287-6.287-6.288-3.474 0.001-6.287 2.815-6.288 6.289 0.001 3.473 2.815 6.287 6.288 6.287zM16 5.574c2.091 0.004 3.784 1.695 3.786 3.788-0.003 2.091-1.695 3.783-3.786 3.787-2.092-0.004-3.784-1.696-3.788-3.787 0.004-2.093 1.697-3.784 3.788-3.788zM16 18.182c-6.536 0.003-11.991 4.6-13.318 10.743h2.575c1.273-4.742 5.597-8.244 10.744-8.243 5.146-0.002 9.469 3.5 10.742 8.243h2.576c-1.329-6.143-6.782-10.74-13.318-10.743z"></path>
                </svg>
                <div class="wp-oidc-keycloak-checkout-login__content">
                    <p>
                        <strong>%1$s</strong>
                    </p>
                    <p>%2$s</p>
                    <p class="wp-oidc-keycloak-checkout-login__actions">
                        <a class="button alt wp-oidc-keycloak-checkout-login__button" href="%3$s">%4$s</a>
                    </p>
                </div>
            </div>',
            esc_html($heading),
            esc_html($description),
            esc_url($authentication_url),
            esc_html($login_label)
        );
    }

    public static function render_woocommerce_login_panel(): void
    {
        if (is_user_logged_in()) {
            return;
        }

        $redirect_to = self::current_account_url();
        $authentication_url = self::authentication_url(
            $redirect_to
        );

        if ($authentication_url === '') {
            return;
        }

        $language = determine_locale();
        $is_english = !str_starts_with(
            strtolower($language),
            'el'
        );

        $heading = $is_english
            ? 'Sign in'
            : 'Σύνδεση';

        $description = $is_english
            ? 'Use your central account.'
            : 'Χρησιμοποιήστε τον κεντρικό λογαριασμό σας.';

        $button_text = $is_english
            ? 'Continue with OpenID Connect'
            : 'Σύνδεση με OpenID Connect';

        printf(
            '<section class="wp-oidc-keycloak-login" aria-labelledby="wp-oidc-keycloak-login-title">
                <h2 id="wp-oidc-keycloak-login-title">%1$s</h2>
                <p>%2$s</p>
                <p>
                    <a class="button alt wp-oidc-keycloak-login__button" href="%3$s">%4$s</a>
                </p>
            </section>',
            esc_html($heading),
            esc_html($description),
            esc_url($authentication_url),
            esc_html($button_text)
        );
    }

    /**
     * Enable upstream existing-user linking at runtime.
     *
     * Correlation remains email-based because
     * identify_with_username is explicitly false.
     *
     * The saved OIDC plugin option is not modified.
     *
     * @param mixed $settings
     * @return mixed
     */
    public static function configure_safe_email_correlation_settings(
        mixed $settings
    ): mixed {
        if (!is_object($settings)) {
            return $settings;
        }

        $settings->identify_with_username = false;
        $settings->link_existing_users = 1;

        return $settings;
    }

    /**
     * Guard automatic Keycloak -> WordPress email correlation.
     *
     * Existing subject mappings are allowed normally.
     *
     * A newly encountered subject may claim an existing WP account
     * only when:
     *
     * - its email is verified;
     * - exactly one WP user owns that email;
     * - any different subject stored on that WP user is no longer
     *   present in Keycloak.
     *
     * @param mixed $allowed
     * @param mixed $userClaim
     */
    public static function authorize_safe_email_correlation(
        mixed $allowed,
        mixed $userClaim
    ): bool {
        if (!$allowed || !is_array($userClaim)) {
            return false;
        }

        $subject = strtolower(
            trim(
                (string) (
                    $userClaim['sub'] ?? ''
                )
            )
        );

        if (!self::is_keycloak_subject_uuid($subject)) {
            return false;
        }

        /*
         * An already mapped subject is an ordinary login,
         * not an email-correlation attempt.
         */
        $subjectMatches =
            self::find_wordpress_user_ids_by_oidc_subject(
                $subject
            );

        if (count($subjectMatches) > 1) {
            self::log_email_correlation_block(
                'subject_maps_to_multiple_wp_users',
                0,
                $subject
            );

            return false;
        }

        if (count($subjectMatches) === 1) {
            return true;
        }

        $email = strtolower(
            trim(
                (string) (
                    $userClaim['email'] ?? ''
                )
            )
        );

        /*
         * If there is no usable email, there is no existing-account
         * correlation for this guard to authorize. Preserve normal
         * OIDC handling for genuinely new identities.
         */
        if ($email === '' || !is_email($email)) {
            return true;
        }

        $emailMatches =
            self::find_wordpress_user_ids_by_exact_email(
                $email
            );

        if ($emailMatches === []) {
            return true;
        }

        if (count($emailMatches) !== 1) {
            self::log_email_correlation_block(
                'wordpress_email_not_unique',
                0,
                $subject
            );

            return false;
        }

        $wordpressUserId = $emailMatches[0];

        if (
            !self::oidc_claim_email_is_verified(
                $userClaim
            )
        ) {
            self::log_email_correlation_block(
                'keycloak_email_not_verified',
                $wordpressUserId,
                $subject
            );

            return false;
        }

        $existingSubjects =
            self::get_wordpress_oidc_subjects(
                $wordpressUserId
            );

        foreach ($existingSubjects as $existingSubject) {
            $existingSubject = strtolower(
                trim($existingSubject)
            );

            if (
                hash_equals(
                    $subject,
                    $existingSubject
                )
            ) {
                continue;
            }

            if (
                !self::is_keycloak_subject_uuid(
                    $existingSubject
                )
            ) {
                self::log_email_correlation_block(
                    'invalid_existing_wp_subject',
                    $wordpressUserId,
                    $subject
                );

                return false;
            }

            try {
                $isLive =
                    self::keycloak_subject_exists(
                        $existingSubject
                    );
            } catch (Throwable $exception) {
                self::log_email_correlation_block(
                    'cannot_verify_existing_subject',
                    $wordpressUserId,
                    $subject
                );

                return false;
            }

            if ($isLive) {
                self::log_email_correlation_block(
                    'live_subject_conflict',
                    $wordpressUserId,
                    $subject
                );

                return false;
            }
        }

        return true;
    }

    private static function is_keycloak_subject_uuid(
        string $subject
    ): bool {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-'
            . '[1-5][0-9a-f]{3}-'
            . '[89ab][0-9a-f]{3}-'
            . '[0-9a-f]{12}$/',
            strtolower(trim($subject))
        ) === 1;
    }

    /**
     * @param array<string,mixed> $userClaim
     */
    private static function oidc_claim_email_is_verified(
        array $userClaim
    ): bool {
        $value =
            $userClaim['email_verified']
            ?? false;

        if (
            $value === true ||
            $value === 1 ||
            $value === '1'
        ) {
            return true;
        }

        return
            is_string($value) &&
            strtolower(trim($value)) === 'true';
    }

    /**
     * Synchronize the verified Keycloak email into WordPress.
     *
     * The OIDC subject remains the stable correlation key. Email is updated
     * only after the current validated claim proves the same mapped subject,
     * carries a valid verified address and does not collide with another
     * WordPress network account.
     *
     * @param array<string,mixed> $userClaim
     */
    public static function synchronize_wordpress_email_from_oidc_claim(
        WP_User $user,
        array $userClaim
    ): void {
        $userId = (int) $user->ID;

        if ($userId < 1) {
            return;
        }

        $subject = strtolower(
            trim((string) ($userClaim['sub'] ?? ''))
        );

        $email = strtolower(
            trim((string) ($userClaim['email'] ?? ''))
        );

        if (
            !self::is_keycloak_subject_uuid($subject) ||
            !is_email($email) ||
            !self::oidc_claim_email_is_verified($userClaim)
        ) {
            return;
        }

        $subjects = self::get_wordpress_oidc_subjects($userId);

        if (count($subjects) !== 1) {
            error_log(
                sprintf(
                    'WP OIDC Keycloak email sync blocked for WordPress user %d: expected exactly one OIDC subject; found %d.',
                    $userId,
                    count($subjects)
                )
            );

            return;
        }

        $mappedSubject = strtolower(trim($subjects[0]));

        if (
            !self::is_keycloak_subject_uuid($mappedSubject) ||
            !hash_equals($mappedSubject, $subject)
        ) {
            error_log(
                sprintf(
                    'WP OIDC Keycloak email sync blocked for WordPress user %d: OIDC subject mismatch.',
                    $userId
                )
            );

            return;
        }

        $currentEmail = strtolower(
            trim((string) $user->user_email)
        );

        if ($currentEmail !== '' && hash_equals($currentEmail, $email)) {
            return;
        }

        $emailMatches =
            self::find_wordpress_user_ids_by_exact_email($email);

        foreach ($emailMatches as $matchingUserId) {
            if ((int) $matchingUserId !== $userId) {
                error_log(
                    sprintf(
                        'WP OIDC Keycloak email sync blocked for WordPress user %d: destination email belongs to another WordPress account.',
                        $userId
                    )
                );

                return;
            }
        }

        $result = wp_update_user(
            [
                'ID' => $userId,
                'user_email' => $email,
            ]
        );

        if (is_wp_error($result)) {
            error_log(
                sprintf(
                    'WP OIDC Keycloak email sync failed for WordPress user %d: %s',
                    $userId,
                    $result->get_error_code()
                )
            );

            return;
        }

        $updatedUser = get_user_by('id', $userId);

        if (
            !$updatedUser instanceof WP_User ||
            !hash_equals(
                $email,
                strtolower(trim((string) $updatedUser->user_email))
            )
        ) {
            error_log(
                sprintf(
                    'WP OIDC Keycloak email sync verification failed for WordPress user %d.',
                    $userId
                )
            );

            return;
        }

        /*
         * Keep the object passed by the OIDC plugin current for callbacks
         * later in the same request. billing_email is deliberately untouched.
         */
        $user->user_email = $updatedUser->user_email;
    }

    /**
     * @return list<int>
     */
    private static function find_wordpress_user_ids_by_exact_email(
        string $email
    ): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID
                 FROM {$wpdb->users}
                 WHERE LOWER(TRIM(user_email)) = LOWER(%s)
                 ORDER BY ID",
                trim($email)
            )
        );

        return array_values(
            array_map(
                'intval',
                is_array($ids) ? $ids : []
            )
        );
    }

    /**
     * @return list<int>
     */
    private static function find_wordpress_user_ids_by_oidc_subject(
        string $subject
    ): array {
        global $wpdb;

        $canonical =
            'openid-connect-generic-subject-identity';

        $prefixedLike =
            '%' .
            $wpdb->esc_like(
                '_' . $canonical
            );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT user_id
                 FROM {$wpdb->usermeta}
                 WHERE (
                     meta_key = %s
                     OR meta_key LIKE %s
                 )
                   AND LOWER(TRIM(meta_value)) = LOWER(%s)
                 ORDER BY user_id",
                $canonical,
                $prefixedLike,
                trim($subject)
            )
        );

        return array_values(
            array_map(
                'intval',
                is_array($ids) ? $ids : []
            )
        );
    }

    /**
     * @return list<string>
     */
    private static function get_wordpress_oidc_subjects(
        int $wordpressUserId
    ): array {
        global $wpdb;

        $canonical =
            'openid-connect-generic-subject-identity';

        $prefixedLike =
            '%' .
            $wpdb->esc_like(
                '_' . $canonical
            );

        $values = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_value
                 FROM {$wpdb->usermeta}
                 WHERE user_id = %d
                   AND (
                       meta_key = %s
                       OR meta_key LIKE %s
                   )
                 ORDER BY umeta_id",
                $wordpressUserId,
                $canonical,
                $prefixedLike
            )
        );

        $subjects = [];

        foreach (
            is_array($values) ? $values : []
            as $value
        ) {
            $value = strtolower(
                trim((string) $value)
            );

            if ($value !== '') {
                $subjects[$value] = true;
            }
        }

        return array_keys($subjects);
    }

    /**
     * Read-only existence check through Keycloak Admin REST.
     */
    private static function keycloak_subject_exists(
        string $subject
    ): bool {
        $subject = strtolower(
            trim($subject)
        );

        if (!self::is_keycloak_subject_uuid($subject)) {
            throw new RuntimeException(
                'Invalid Keycloak subject UUID.'
            );
        }

        $config =
            self::load_provisioner_config();

        $accessToken =
            self::request_provisioner_access_token();

        $url = sprintf(
            '%s/admin/realms/%s/users/%s',
            rtrim(
                $config['KEYCLOAK_ADMIN_BASE_URL'],
                '/'
            ),
            rawurlencode(
                $config['KEYCLOAK_REALM']
            ),
            rawurlencode($subject)
        );

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 20,
                'redirection' => 0,
                'headers' => [
                    'Authorization' =>
                        'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
            ]
        );

        unset($accessToken);

        if (is_wp_error($response)) {
            throw new RuntimeException(
                'Keycloak subject lookup failed.'
            );
        }

        $status =
            wp_remote_retrieve_response_code(
                $response
            );

        if ($status === 200) {
            return true;
        }

        if ($status === 404) {
            return false;
        }

        throw new RuntimeException(
            sprintf(
                'Keycloak subject lookup returned HTTP %d.',
                $status
            )
        );
    }

    private static function log_email_correlation_block(
        string $reason,
        int $wordpressUserId,
        string $subject
    ): void {
        error_log(
            sprintf(
                'WP OIDC Keycloak email correlation blocked: '
                . 'reason=%s wordpress_user_id=%d subject=%s',
                $reason,
                $wordpressUserId,
                $subject
            )
        );
    }

    /**
     * Add a newly-created OIDC user only to the site whose callback
     * created the account.
     *
     * WordPress multisite users are network-global, while roles and
     * membership are site-specific.
     *
     * @param WP_User $user       Newly-created WordPress user.
     * @param array   $user_claim Claims returned by the identity provider.
     */
    public static function configure_new_oidc_user(
        WP_User $user,
        array $user_claim
    ): void {
        unset($user_claim);

        if (!is_multisite()) {
            $user->set_role(
                get_option('default_role', 'subscriber')
            );

            return;
        }

        $blog_id = get_current_blog_id();

        if ($blog_id <= 0) {
            return;
        }

        $role = get_option(
            'default_role',
            'subscriber'
        );

        if (
            !is_string($role) ||
            $role === '' ||
            !get_role($role)
        ) {
            $role = 'subscriber';
        }

        if (!is_user_member_of_blog($user->ID, $blog_id)) {
            add_user_to_blog(
                $blog_id,
                $user->ID,
                $role
            );

            return;
        }

        /*
         * wp_insert_user() may already have added a role in the
         * current site context. Normalize only empty memberships.
         */
        $site_user = new WP_User(
            $user->ID,
            '',
            $blog_id
        );

        if (empty($site_user->roles)) {
            $site_user->set_role($role);
        }
    }

    /**
     * Synchronize the WooCommerce session customer after OIDC login.
     *
     * A checkout session created while logged out may retain an empty
     * guest customer snapshot after WordPress authentication completes.
     * The Checkout Block reads that snapshot through the Store API,
     * even though the database-backed WC_Customer contains the user's
     * saved billing details.
     *
     * Clear only the WooCommerce customer snapshot, then rebuild and
     * save the session-backed customer from the authenticated account.
     * Cart contents, coupons and all unrelated session data are retained.
     */
    public static function synchronize_woocommerce_customer_session(
        WP_User $user
    ): void {
        if (
            $user->ID <= 0 ||
            !function_exists('WC') ||
            !class_exists('WC_Customer') ||
            !class_exists('WC_Data_Store')
        ) {
            return;
        }

        $woocommerce = WC();

        if (!is_object($woocommerce)) {
            return;
        }

        /*
         * The OIDC callback may run before WooCommerce has initialized
         * its frontend session objects.
         */
        if (
            method_exists(
                $woocommerce,
                'initialize_session'
            )
        ) {
            $woocommerce->initialize_session();
        }

        if (
            !isset($woocommerce->session) ||
            !$woocommerce->session
        ) {
            return;
        }

        try {
            /*
             * Load the authenticated customer strictly from the
             * database. Session mode must remain false here, otherwise
             * the stale guest checkout snapshot may overwrite the saved
             * billing and shipping data.
             */
            $customer = new WC_Customer(
                $user->ID,
                false
            );
        } catch (Throwable $throwable) {
            unset($throwable);

            return;
        }

        if (
            (int) $customer->get_id() !==
            (int) $user->ID
        ) {
            return;
        }

        try {
            /*
             * Explicitly replace only the customer snapshot stored in
             * the existing WooCommerce session. The session data store
             * does not persist changes to the WordPress user database.
             */
            $session_store = WC_Data_Store::load(
                'customer-session'
            );

            $session_store->update(
                $customer
            );
        } catch (Throwable $throwable) {
            unset($throwable);

            return;
        }

        $woocommerce->customer = $customer;

        /*
         * Persist the updated customer snapshot immediately because the
         * OIDC callback redirects before the normal checkout lifecycle
         * completes. Cart, coupons and unrelated session values remain.
         */
        if (
            method_exists(
                $woocommerce->session,
                'save_data'
            )
        ) {
            $woocommerce->session->save_data();
        }
    }

    /**
     * Synchronize canonical WordPress identity attributes into the
     * already-correlated Keycloak user.
     *
     * Ordinary hook execution is controlled by
     * WP_OIDC_KEYCLOAK_SYNC_WORDPRESS_ATTRIBUTES.
     *
     * $force=true exists only for a controlled administrative
     * canary and is never supplied by the OIDC hook.
     */
    public static function synchronize_keycloak_wordpress_attributes(
        WP_User $user,
        bool $force = false
    ): bool {
        if (
            !$force &&
            !self::keycloak_wordpress_attribute_sync_enabled()
        ) {
            return true;
        }

        try {
            self::synchronize_keycloak_wordpress_attributes_or_throw(
                $user
            );

            return true;
        } catch (Throwable $exception) {
            error_log(
                sprintf(
                    'WP OIDC Keycloak WordPress attribute sync '
                    . 'failed for WordPress user %d: %s',
                    (int) $user->ID,
                    $exception->getMessage()
                )
            );

            return false;
        }
    }

    private static function keycloak_wordpress_attribute_sync_enabled(
    ): bool {
        return
            defined(
                'WP_OIDC_KEYCLOAK_SYNC_WORDPRESS_ATTRIBUTES'
            ) &&
            constant(
                'WP_OIDC_KEYCLOAK_SYNC_WORDPRESS_ATTRIBUTES'
            ) === true;
    }

    /**
     * Perform one verified full-UserRepresentation update.
     *
     * Only the three wordpress_* attributes may change.
     *
     * @throws RuntimeException
     */
    private static function synchronize_keycloak_wordpress_attributes_or_throw(
        WP_User $user
    ): void {
        if ($user->ID <= 0) {
            throw new RuntimeException(
                'Invalid WordPress user ID.'
            );
        }

        $subjects =
            self::get_wordpress_oidc_subjects(
                (int) $user->ID
            );

        if (count($subjects) !== 1) {
            throw new RuntimeException(
                sprintf(
                    'Expected exactly one OIDC subject; found %d.',
                    count($subjects)
                )
            );
        }

        $subject = strtolower(
            trim($subjects[0])
        );

        if (!self::is_keycloak_subject_uuid($subject)) {
            throw new RuntimeException(
                'Mapped Keycloak subject is invalid.'
            );
        }

        /*
         * This is the complete representation returned by the
         * Keycloak Admin API. Do not construct a sparse PUT.
         */
        $before =
            self::get_user_by_subject(
                $subject
            );

        $beforeId = strtolower(
            trim(
                (string) (
                    $before['id'] ?? ''
                )
            )
        );

        if (
            $beforeId === '' ||
            !hash_equals(
                $subject,
                $beforeId
            )
        ) {
            throw new RuntimeException(
                'Keycloak representation ID mismatch.'
            );
        }

        $attributes = (
            isset($before['attributes']) &&
            is_array($before['attributes'])
        )
            ? $before['attributes']
            : [];

        $desired = [
            'wordpress_user_id' => [
                (string) $user->ID,
            ],
            'wordpress_login' => [
                (string) $user->user_login,
            ],
            'wordpress_display_name' => [
                (string) $user->display_name,
            ],
        ];

        $needsUpdate = false;

        foreach ($desired as $name => $values) {
            $existing =
                self::normalize_keycloak_attribute_values(
                    $attributes[$name] ?? []
                );

            if ($existing !== $values) {
                $needsUpdate = true;
                break;
            }
        }

        /*
         * Avoid a PUT on ordinary logins when the reverse mapping
         * is already correct.
         */
        if (!$needsUpdate) {
            return;
        }

        $updated = $before;

        foreach ($desired as $name => $values) {
            $attributes[$name] = $values;
        }

        $updated['attributes'] = $attributes;

        /*
         * Full representation PUT. All unrelated top-level fields
         * and all unrelated attributes originate from the GET above.
         */
        self::admin_mutation_request(
            'PUT',
            'users/' . rawurlencode($subject),
            $updated,
            [204]
        );

        $after =
            self::get_user_by_subject(
                $subject
            );

        $afterAttributes = (
            isset($after['attributes']) &&
            is_array($after['attributes'])
        )
            ? $after['attributes']
            : [];

        foreach ($desired as $name => $values) {
            $actual =
                self::normalize_keycloak_attribute_values(
                    $afterAttributes[$name] ?? []
                );

            if ($actual !== $values) {
                throw new RuntimeException(
                    sprintf(
                        'Keycloak attribute verification '
                        . 'failed for %s.',
                        $name
                    )
                );
            }
        }

        /*
         * Detect any unrelated representation drift caused by the
         * update. The three expected attributes are removed before
         * canonical comparison.
         */
        $beforeComparable =
            self::keycloak_representation_without_wordpress_attributes(
                $before
            );

        $afterComparable =
            self::keycloak_representation_without_wordpress_attributes(
                $after
            );

        if (
            !hash_equals(
                self::canonical_json_sha256(
                    $beforeComparable
                ),
                self::canonical_json_sha256(
                    $afterComparable
                )
            )
        ) {
            throw new RuntimeException(
                'Unexpected unrelated Keycloak '
                . 'UserRepresentation change detected.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function normalize_keycloak_attribute_values(
        mixed $value
    ): array {
        if (is_scalar($value)) {
            return [
                (string) $value,
            ];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(
            array_map(
                'strval',
                $value
            )
        );
    }

    /**
     * Remove only the fields that this synchronizer intentionally
     * owns, then normalize the remaining representation.
     *
     * @param array<string,mixed> $representation
     * @return array<string,mixed>
     */
    private static function keycloak_representation_without_wordpress_attributes(
        array $representation
    ): array {
        $attributes = (
            isset($representation['attributes']) &&
            is_array($representation['attributes'])
        )
            ? $representation['attributes']
            : [];

        unset(
            $attributes['wordpress_user_id'],
            $attributes['wordpress_login'],
            $attributes['wordpress_display_name']
        );

        if ($attributes === []) {
            unset(
                $representation['attributes']
            );
        } else {
            $representation['attributes'] =
                $attributes;
        }

        $normalized =
            self::canonicalize_keycloak_value(
                $representation
            );

        return is_array($normalized)
            ? $normalized
            : [];
    }

    private static function canonicalize_keycloak_value(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $normalized = array_map(
                [
                    self::class,
                    'canonicalize_keycloak_value',
                ],
                $value
            );

            /*
             * Keycloak representation lists used here are
             * semantically unordered for verification purposes.
             */
            usort(
                $normalized,
                static function (
                    mixed $left,
                    mixed $right
                ): int {
                    return strcmp(
                        wp_json_encode(
                            $left,
                            JSON_UNESCAPED_SLASHES |
                            JSON_UNESCAPED_UNICODE
                        ) ?: '',
                        wp_json_encode(
                            $right,
                            JSON_UNESCAPED_SLASHES |
                            JSON_UNESCAPED_UNICODE
                        ) ?: ''
                    );
                }
            );

            return $normalized;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] =
                self::canonicalize_keycloak_value(
                    $item
                );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $value
     */
    private static function canonical_json_sha256(
        array $value
    ): string {
        $json = wp_json_encode(
            $value,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        );

        return hash(
            'sha256',
            $json
        );
    }

    /**
     * Ensure that an OIDC-authenticated network user belongs to the
     * site whose OIDC callback completed the login.
     *
     * Existing memberships and roles are never modified.
     */
    public static function ensure_oidc_user_site_membership(
        WP_User $user
    ): void {
        if (!is_multisite()) {
            return;
        }

        $blog_id = get_current_blog_id();

        if (
            $blog_id <= 0 ||
            $user->ID <= 0 ||
            is_user_member_of_blog($user->ID, $blog_id)
        ) {
            return;
        }

        $role = (string) get_option(
            'default_role',
            'subscriber'
        );

        if (
            $role === '' ||
            !get_role($role)
        ) {
            $role = 'subscriber';
        }

        $result = add_user_to_blog(
            $blog_id,
            $user->ID,
            $role
        );

        if (is_wp_error($result)) {
            error_log(
                sprintf(
                    'WP OIDC Keycloak: failed adding OIDC user %d to blog %d: %s',
                    $user->ID,
                    $blog_id,
                    $result->get_error_message()
                )
            );
        }
    }

    /**
     * Treat a successful Keycloak/OIDC login as authoritative
     * verification of the authenticated account email and let
     * WooCommerce reconcile guest orders for that email.
     *
     * Safety requirements:
     * - valid mapped Keycloak subject;
     * - current OIDC claim subject equals the mapped subject;
     * - current OIDC claim email equals WordPress user_email;
     * - exactly one WordPress account owns that email.
     *
     * WooCommerce's own verification service remains responsible
     * for persisting its verification state and firing the native
     * customer-email-verified event.
     */
    public static function synchronize_woocommerce_verified_email(
        WP_User $user
    ): void {
        $userId = (int) $user->ID;

        if (
            $userId < 1 ||
            !function_exists('wc_get_container')
        ) {
            return;
        }

        $accountEmail = strtolower(
            trim(
                (string) $user->user_email
            )
        );

        if (!is_email($accountEmail)) {
            return;
        }

        $subject = strtolower(
            trim(
                (string) get_user_meta(
                    $userId,
                    'openid-connect-generic-subject-identity',
                    true
                )
            )
        );

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-'
                . '[1-5][0-9a-f]{3}-'
                . '[89ab][0-9a-f]{3}-'
                . '[0-9a-f]{12}$/',
                $subject
            ) !== 1
        ) {
            return;
        }

        /*
         * OpenID Connect Generic stores the validated current
         * user claim as a user option before completing login.
         * get_user_option() also handles multisite prefixes.
         */
        $claim = get_user_option(
            'openid-connect-generic-last-user-claim',
            $userId
        );

        if (!is_array($claim)) {
            return;
        }

        $claimSubject = strtolower(
            trim(
                (string) (
                    $claim['sub'] ?? ''
                )
            )
        );

        $claimEmail = strtolower(
            trim(
                (string) (
                    $claim['email'] ?? ''
                )
            )
        );

        if (
            $claimSubject === '' ||
            $claimEmail === '' ||
            !hash_equals(
                $subject,
                $claimSubject
            ) ||
            !hash_equals(
                $accountEmail,
                $claimEmail
            )
        ) {
            return;
        }

        /*
         * One email -> one central account.
         *
         * Refuse reconciliation if legacy duplicate data ever
         * violates that invariant.
         */
        $matchingUsers =
            self::find_exact_wordpress_users_by_email(
                $accountEmail
            );

        if (
            count($matchingUsers) !== 1 ||
            (int) $matchingUsers[0] !== $userId
        ) {
            return;
        }

        $serviceClass =
            'Automattic\\WooCommerce\\Internal\\'
            . 'CustomerEmailVerification\\'
            . 'EmailVerificationService';

        if (!class_exists($serviceClass)) {
            return;
        }

        try {
            $service =
                wc_get_container()->get(
                    $serviceClass
                );

            if (
                !is_object($service) ||
                !method_exists(
                    $service,
                    'mark_verified'
                )
            ) {
                return;
            }

            /*
             * Native WooCommerce operation:
             * persist verification state and fire the standard
             * verified event, whose core listener reconciles
             * past guest orders for this customer email.
             */
            $service->mark_verified(
                $userId
            );
        } catch (Throwable $exception) {
            /*
             * Email/order reconciliation must never turn a
             * successful central authentication into a failed
             * login.
             */
            error_log(
                sprintf(
                    'WP OIDC Keycloak: WooCommerce email '
                    . 'verification sync failed for user %d (%s).',
                    $userId,
                    get_class($exception)
                )
            );
        }
    }

    /**
     * Replace the WooCommerce My Account authentication/identity templates
     * controlled by the central Keycloak integration.
     *
     * @param string $located       Resolved template path.
     * @param string $template_name WooCommerce template name.
     * @param array  $args          Template arguments.
     * @param string $template_path Custom template path.
     * @param string $default_path  WooCommerce default path.
     */
    public static function replace_my_account_login_template(
        string $located,
        string $template_name,
        array $args,
        string $template_path,
        string $default_path
    ): string {
        unset(
            $args,
            $template_path,
            $default_path
        );

        $managedTemplates = [
            'myaccount/form-login.php',
            'myaccount/form-edit-account.php',
        ];

        if (!in_array($template_name, $managedTemplates, true)) {
            return $located;
        }

        $customTemplate =
            WPMU_PLUGIN_DIR .
            '/wp-oidc-keycloak-templates/' .
            $template_name;

        if (!is_readable($customTemplate)) {
            return $located;
        }

        return $customTemplate;
    }

    /**
     * The WooCommerce profile form remains responsible for first name,
     * last name and display name. Login email is not a WooCommerce-required
     * field because it is managed exclusively by Keycloak.
     *
     * @param array<string,string> $requiredFields
     * @return array<string,string>
     */
    public static function filter_woocommerce_account_required_fields(
        array $requiredFields
    ): array {
        unset($requiredFields['account_email']);

        return $requiredFields;
    }

    /**
     * Final server-side guard against identity mutation through the native
     * WooCommerce edit-account handler.
     *
     * WooCommerce constructs the pending user object before firing this
     * action. Remove identity properties unconditionally, then reject any
     * request that attempted to change the immutable login email or a local
     * WordPress password.
     */
    public static function block_woocommerce_identity_mutation(
        WP_Error $errors,
        object $user
    ): void {
        if (property_exists($user, 'user_email')) {
            unset($user->user_email);
        }

        if (property_exists($user, 'user_pass')) {
            unset($user->user_pass);
        }

        $currentUser = get_user_by(
            'id',
            get_current_user_id()
        );

        if (!$currentUser instanceof WP_User) {
            $errors->add(
                'wp_oidc_keycloak_account_identity_unavailable',
                __('The central account could not be verified.', 'wp-oidc-keycloak-integration')
            );

            return;
        }

        if (array_key_exists('account_email', $_POST)) {
            $rawEmail = wp_unslash($_POST['account_email']);

            $postedEmail = is_string($rawEmail)
                ? strtolower(trim(sanitize_email($rawEmail)))
                : '';

            $currentEmail = strtolower(
                trim((string) $currentUser->user_email)
            );

            if (
                $postedEmail === '' ||
                !hash_equals($currentEmail, $postedEmail)
            ) {
                $errors->add(
                    'wp_oidc_keycloak_email_change_disabled',
                    __('The login email cannot be changed from the account interface.', 'wp-oidc-keycloak-integration')
                );
            }
        }

        foreach (
            ['password_current', 'password_1', 'password_2']
            as $passwordField
        ) {
            if (!array_key_exists($passwordField, $_POST)) {
                continue;
            }

            $value = wp_unslash($_POST[$passwordField]);

            if (is_string($value) && $value !== '') {
                $errors->add(
                    'wp_oidc_keycloak_password_change_disabled',
                    __('Password changes are managed through your central account.', 'wp-oidc-keycloak-integration')
                );

                break;
            }
        }
    }

    /**
     * Build a Keycloak registration URL using the OIDC plugin state
     * and callback machinery.
     */
    public static function get_registration_url(): string
    {
        if (
            !class_exists('OpenID_Connect_Generic') ||
            !method_exists(
                OpenID_Connect_Generic::class,
                'instance'
            )
        ) {
            return '';
        }

        $redirect_to = function_exists(
            'wc_get_page_permalink'
        )
            ? wc_get_page_permalink('myaccount')
            : home_url('/');

        $url = OpenID_Connect_Generic::instance()
            ->client_wrapper
            ->get_authentication_url(
                [
                    'redirect_to' => $redirect_to,
                ]
            );

        if (
            !is_string($url) ||
            $url === ''
        ) {
            return '';
        }

        return add_query_arg(
            'prompt',
            'create',
            $url
        );
    }

    /**
     * Display a direct Keycloak registration action above the native
     * WooCommerce customer login and registration forms.
     */
    public static function render_registration_panel(): void
    {
        if (is_user_logged_in()) {
            return;
        }

        $registration_url =
            self::get_registration_url();

        if ($registration_url === '') {
            return;
        }

        $is_english = !str_starts_with(
            strtolower(determine_locale()),
            'el'
        );

        $heading = $is_english
            ? 'Create a central account'
            : 'Δημιουργία λογαριασμού';

        $description = $is_english
            ? 'Create one account for the connected services.'
            : 'Δημιουργήστε έναν λογαριασμό για τις συνδεδεμένες υπηρεσίες.';

        $button_label = $is_english
            ? 'Register with OpenID Connect'
            : 'Εγγραφή με OpenID Connect';

        echo '<section class="wp-oidc-keycloak-panel wp-oidc-keycloak-registration-panel">';

        echo '<h2>' .
            esc_html($heading) .
            '</h2>';

        echo '<p>' .
            esc_html($description) .
            '</p>';

        echo '<p>';

        echo '<a class="button alt wp-oidc-keycloak-register-button" href="' .
            esc_url($registration_url) .
            '">';

        echo esc_html($button_label);

        echo '</a>';

        echo '</p>';

        echo '</section>';
    }

    public static function enqueue_styles(): void
    {
        if (
            !function_exists('is_account_page') ||
            !is_account_page()
        ) {
            return;
        }

        wp_register_style(
            'wp-oidc-keycloak-integration',
            false,
            [],
            '0.1.0'
        );

        wp_enqueue_style(
            'wp-oidc-keycloak-integration'
        );

        wp_add_inline_style(
            'wp-oidc-keycloak-integration',
            '
            .wp-oidc-keycloak-login {
                box-sizing: border-box;
                margin: 0 0 2rem;
                padding: 1.5rem;
                border: 1px solid rgba(0, 0, 0, 0.15);
                border-radius: 4px;
                text-align: center;
            }

            .wp-oidc-keycloak-login h2 {
                margin-top: 0;
            }

            .wp-oidc-keycloak-login__button {
                display: inline-block;
                min-width: 15rem;
                text-align: center;
            }

            .wp-oidc-keycloak-authority {
                box-sizing: border-box;
                margin: 1.5rem 0;
                padding: 1.25rem;
                border: 1px solid rgba(0, 0, 0, 0.15);
                border-radius: 4px;
            }

            .wp-oidc-keycloak-authority legend {
                padding: 0 0.35rem;
                font-weight: 600;
            }

            .wp-oidc-keycloak-authority__actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
                margin: 0.75rem 0 0;
            }

            .wp-oidc-keycloak-authority__email {
                margin-bottom: 0.75rem;
            }
            '
        );
    }

    private static function current_edit_account_url(): string
    {
        if (
            function_exists('wc_get_endpoint_url') &&
            function_exists('wc_get_page_permalink')
        ) {
            $url = wc_get_endpoint_url(
                'edit-account',
                '',
                wc_get_page_permalink('myaccount')
            );

            if (is_string($url) && $url !== '') {
                return self::safe_local_redirect($url);
            }
        }

        return self::current_account_url();
    }

    private static function current_account_url(): string
    {
        if (function_exists('wc_get_page_permalink')) {
            $url = wc_get_page_permalink('myaccount');

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return home_url('/');
    }

    private static function safe_local_redirect(string $url): string
    {
        $fallback = home_url('/');

        return wp_validate_redirect(
            $url,
            $fallback
        );
    }

    /**
     * Classify a request according to its authentication
     * surface without changing request execution.
     *
     * This method is deliberately pure so the classifier
     * can be verified through WP-CLI without issuing HTTP
     * requests.
     *
     * @param array{
     *     pagenow?:string,
     *     request_uri?:string,
     *     action?:string,
     *     wc_ajax?:string,
     *     method?:string,
     *     is_ajax?:bool,
     *     is_rest?:bool,
     *     is_xmlrpc?:bool
     * } $context
     *
     * @return non-empty-string
     */
    public static function classify_login_surface(
        array $context
    ): string {
        $pagenow = sanitize_key(
            (string) (
                $context['pagenow'] ?? ''
            )
        );

        $requestUri = (string) (
            $context['request_uri'] ?? ''
        );

        $path = wp_parse_url(
            $requestUri,
            PHP_URL_PATH
        );

        $path = is_string($path)
            ? '/' . ltrim($path, '/')
            : '';

        $action = sanitize_key(
            (string) (
                $context['action'] ?? ''
            )
        );

        $wcAjax = sanitize_key(
            (string) (
                $context['wc_ajax'] ?? ''
            )
        );

        $method = strtoupper(
            trim(
                (string) (
                    $context['method'] ?? 'GET'
                )
            )
        );

        $isAjax = !empty(
            $context['is_ajax']
        );

        $isRest = !empty(
            $context['is_rest']
        );

        $isXmlrpc = !empty(
            $context['is_xmlrpc']
        );

        if (
            $isAjax &&
            $action === 'openid-connect-authorize'
        ) {
            return 'oidc_callback_admin_ajax';
        }

        if (
            $path === '/openid-connect-authorize' ||
            $path === '/openid-connect-authorize/'
        ) {
            return 'oidc_callback_rewrite_route';
        }

        if ($isXmlrpc || $path === '/xmlrpc.php') {
            return 'xmlrpc_authentication';
        }

        if ($isRest) {
            return 'rest_api';
        }

        if (
            $isAjax &&
            $wcAjax === 'checkout'
        ) {
            return 'woocommerce_checkout_ajax';
        }

        if (
            $isAjax &&
            $action === 'td_mod_login'
        ) {
            return 'newspaper_ajax_native_credentials';
        }

        if (
            $isAjax &&
            $action !== ''
        ) {
            return 'admin_ajax_action';
        }

        if (
            $method === 'POST' &&
            !empty($context['has_login_submit_field']) &&
            !empty($context['has_username_field']) &&
            !empty($context['has_password_field'])
        ) {
            return 'woocommerce_native_credentials';
        }

        $isWpLogin =
            $pagenow === 'wp-login-php' ||
            $path === '/wp-login.php';

        if ($isWpLogin) {
            if (
                in_array(
                    $action,
                    self::NON_NATIVE_LOGIN_ACTIONS,
                    true
                )
            ) {
                return 'wp_login_special_action';
            }

            if (
                in_array(
                    $action,
                    [
                        'lostpassword',
                        'retrievepassword',
                        'rp',
                        'resetpass',
                    ],
                    true
                )
            ) {
                return 'wp_login_password_recovery';
            }

            if ($action === 'register') {
                return 'wp_login_registration';
            }

            if (
                $action === '' ||
                $action === 'login'
            ) {
                return $method === 'POST'
                    ? 'wp_login_native_credentials'
                    : 'wp_login_form';
            }

            return 'wp_login_unknown_action';
        }

        if (
            $method === 'POST' &&
            (
                isset($context['has_log_field']) &&
                $context['has_log_field']
            ) &&
            (
                isset($context['has_pwd_field']) &&
                $context['has_pwd_field']
            )
        ) {
            return 'frontend_native_credentials';
        }

        return 'non_login_request';
    }

    /**
     * Build the classifier context for the current request.
     *
     * @return array<string,mixed>
     */
    private static function current_login_surface_context(): array
    {
        global $pagenow;

        return [
            'pagenow' => is_string($pagenow)
                ? $pagenow
                : '',

            'request_uri' => (string) (
                $_SERVER['REQUEST_URI'] ?? ''
            ),

            'action' => (string) (
                $_REQUEST['action'] ?? ''
            ),

            'wc_ajax' => (string) (
                $_REQUEST['wc-ajax'] ?? ''
            ),

            'method' => (string) (
                $_SERVER['REQUEST_METHOD'] ?? 'GET'
            ),

            'is_ajax' => wp_doing_ajax(),

            'is_rest' =>
                defined('REST_REQUEST') &&
                REST_REQUEST,

            'is_xmlrpc' =>
                defined('XMLRPC_REQUEST') &&
                XMLRPC_REQUEST,

            'has_log_field' =>
                array_key_exists('log', $_POST),

            'has_pwd_field' =>
                array_key_exists('pwd', $_POST),

            'has_username_field' =>
                array_key_exists('username', $_POST),

            'has_password_field' =>
                array_key_exists('password', $_POST),

            'has_login_submit_field' =>
                array_key_exists('login', $_POST),
        ];
    }

    /**
     * Optionally record the request classification.
     *
     * Disabled unless WP_OIDC_KEYCLOAK_LOGIN_AUDIT is
     * explicitly defined as true by trusted server-side
     * configuration.
     */
    public static function audit_current_login_surface(): void
    {
        if (
            !defined(
                'WP_OIDC_KEYCLOAK_LOGIN_AUDIT'
            ) ||
            WP_OIDC_KEYCLOAK_LOGIN_AUDIT !== true
        ) {
            return;
        }

        $context =
            self::current_login_surface_context();

        $surface =
            self::classify_login_surface($context);

        $requestUri = (string) (
            $context['request_uri'] ?? ''
        );

        $path = wp_parse_url(
            $requestUri,
            PHP_URL_PATH
        );

        $path = is_string($path)
            ? '/' . ltrim($path, '/')
            : '';

        $method = strtoupper(
            (string) (
                $context['method'] ?? 'GET'
            )
        );

        $action = sanitize_key(
            (string) (
                $context['action'] ?? ''
            )
        );

        error_log(
            sprintf(
                'WP OIDC Keycloak login surface: '
                . 'surface=%s method=%s path=%s action=%s',
                sanitize_key($surface),
                sanitize_key($method),
                $path !== '' ? $path : '/',
                $action !== '' ? $action : '-'
            )
        );
    }

    /**
     * Load and validate the Keycloak provisioner config.
     *
     * This method does not read the client secret and
     * performs no HTTP request.
     *
     * @return array<string,string>
     *
     * @throws RuntimeException
     */
    private static function provisioner_config_path(): string
    {
        $setting = self::PROVISIONER_CONFIG_PATH_SETTING;
        $value = '';

        if (defined($setting)) {
            $configured = constant($setting);

            if (is_string($configured)) {
                $value = trim($configured);
            }
        }

        if ($value === '') {
            $environmentValue = getenv($setting);

            if (is_string($environmentValue)) {
                $value = trim($environmentValue);
            }
        }

        if ($value === '' || !str_starts_with($value, '/')) {
            throw new RuntimeException(
                'Define WP_OIDC_KEYCLOAK_PROVISIONER_CONFIG_PATH '
                . 'as an absolute path outside the public document root.'
            );
        }

        return $value;
    }

    private static function load_provisioner_config(): array
    {
        $configPath = self::provisioner_config_path();

        if (
            !is_file($configPath) ||
            !is_readable($configPath)
        ) {
            throw new RuntimeException(
                'Keycloak provisioner configuration '
                . 'is not readable.'
            );
        }

        $config = parse_ini_file(
            $configPath,
            false,
            INI_SCANNER_RAW
        );

        if (!is_array($config)) {
            throw new RuntimeException(
                'Keycloak provisioner configuration '
                . 'cannot be parsed.'
            );
        }

        $normalized = [];

        foreach (
            self::PROVISIONER_REQUIRED_CONFIG_KEYS
            as $key
        ) {
            $value = $config[$key] ?? null;

            if (!is_string($value)) {
                throw new RuntimeException(
                    sprintf(
                        'Missing Keycloak provisioner '
                        . 'configuration key: %s',
                        $key
                    )
                );
            }

            $value = trim($value);

            if ($value === '') {
                throw new RuntimeException(
                    sprintf(
                        'Empty Keycloak provisioner '
                        . 'configuration key: %s',
                        $key
                    )
                );
            }

            $normalized[$key] = $value;
        }

        $adminBaseUrl = filter_var(
            $normalized['KEYCLOAK_ADMIN_BASE_URL'],
            FILTER_VALIDATE_URL
        );

        $publicBaseUrl = filter_var(
            $normalized['KEYCLOAK_BASE_URL'],
            FILTER_VALIDATE_URL
        );

        if (
            $adminBaseUrl === false ||
            $publicBaseUrl === false
        ) {
            throw new RuntimeException(
                'Keycloak provisioner base URL is invalid.'
            );
        }

        $secretFile = $normalized[
            'KEYCLOAK_PROVISIONER_SECRET_FILE'
        ];

        if (
            !str_starts_with($secretFile, '/') ||
            !is_file($secretFile) ||
            !is_readable($secretFile)
        ) {
            throw new RuntimeException(
                'Keycloak provisioner secret file '
                . 'is not readable.'
            );
        }

        return $normalized;
    }

    /**
     * Read the Keycloak provisioner client secret.
     *
     * The secret must never be logged, persisted in
     * WordPress, or included in exception messages.
     *
     * @return non-empty-string
     *
     * @throws RuntimeException
     */
    private static function read_provisioner_secret(): string
    {
        $config = self::load_provisioner_config();

        $secretFile = $config[
            'KEYCLOAK_PROVISIONER_SECRET_FILE'
        ];

        $secret = file_get_contents($secretFile);

        if ($secret === false) {
            throw new RuntimeException(
                'Keycloak provisioner secret '
                . 'cannot be read.'
            );
        }

        $secret = trim($secret);

        if ($secret === '') {
            throw new RuntimeException(
                'Keycloak provisioner secret is empty.'
            );
        }

        return $secret;
    }

    /**
     * Request a service-account access token.
     *
     * The token and client secret must never be logged
     * or persisted by this method.
     *
     * @return non-empty-string
     *
     * @throws RuntimeException
     */
    private static function request_provisioner_access_token(): string
    {
        $config = self::load_provisioner_config();
        $secret = self::read_provisioner_secret();

        $tokenUrl = sprintf(
            '%s/realms/%s/protocol/openid-connect/token',
            rtrim(
                $config['KEYCLOAK_ADMIN_BASE_URL'],
                '/'
            ),
            rawurlencode(
                $config['KEYCLOAK_REALM']
            )
        );

        $response = wp_remote_post(
            $tokenUrl,
            [
                'timeout' => 20,
                'redirection' => 0,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' =>
                        'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' =>
                        'client_credentials',

                    'client_id' =>
                        $config[
                            'KEYCLOAK_PROVISIONER_CLIENT_ID'
                        ],

                    'client_secret' => $secret,
                ],
            ]
        );

        unset($secret);

        if (is_wp_error($response)) {
            throw new RuntimeException(
                'Keycloak token request failed.'
            );
        }

        $status = wp_remote_retrieve_response_code(
            $response
        );

        $body = wp_remote_retrieve_body(
            $response
        );

        if (
            $status !== 200 ||
            !json_validate($body)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Keycloak token endpoint returned '
                    . 'an invalid response with HTTP %d.',
                    $status
                )
            );
        }

        $data = json_decode(
            $body,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $accessToken = is_array($data)
            ? trim(
                (string) (
                    $data['access_token'] ?? ''
                )
            )
            : '';

        unset($data, $body, $response);

        if ($accessToken === '') {
            throw new RuntimeException(
                'Keycloak token response contains '
                . 'no access token.'
            );
        }

        return $accessToken;
    }

    /**
     * Execute a read-only Keycloak Admin API request.
     *
     * The path must be relative to the realm Admin API.
     * This method supports GET only and performs no
     * mutation.
     *
     * @param non-empty-string $relativePath
     *
     * @return array<mixed>
     *
     * @throws RuntimeException
     */
    private static function admin_get_json(
        string $relativePath
    ): array {
        $relativePath = trim($relativePath);

        if (
            $relativePath === '' ||
            str_starts_with($relativePath, '/') ||
            str_contains($relativePath, '..') ||
            !preg_match(
                '#^[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]+$#',
                $relativePath
            )
        ) {
            throw new RuntimeException(
                'Invalid Keycloak Admin API path.'
            );
        }

        $config = self::load_provisioner_config();
        $accessToken =
            self::request_provisioner_access_token();

        $url = sprintf(
            '%s/admin/realms/%s/%s',
            rtrim(
                $config['KEYCLOAK_ADMIN_BASE_URL'],
                '/'
            ),
            rawurlencode(
                $config['KEYCLOAK_REALM']
            ),
            $relativePath
        );

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 20,
                'redirection' => 0,
                'headers' => [
                    'Authorization' =>
                        'Bearer ' . $accessToken,

                    'Accept' => 'application/json',
                ],
            ]
        );

        unset($accessToken);

        if (is_wp_error($response)) {
            throw new RuntimeException(
                'Keycloak Admin API request failed.'
            );
        }

        $status = wp_remote_retrieve_response_code(
            $response
        );

        $body = wp_remote_retrieve_body(
            $response
        );

        if (
            $status !== 200 ||
            !json_validate($body)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Keycloak Admin API returned '
                    . 'an invalid response with HTTP %d.',
                    $status
                )
            );
        }

        $data = json_decode(
            $body,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        unset($body, $response);

        if (!is_array($data)) {
            throw new RuntimeException(
                'Keycloak Admin API JSON root '
                . 'is not an array or object.'
            );
        }

        return $data;
    }

    /**
     * Find Keycloak users by an exact email or username.
     *
     * Keycloak is asked for exact matching and the
     * returned representation is filtered again locally.
     *
     * @param 'email'|'username' $field
     * @param non-empty-string   $value
     *
     * @return list<array<string,mixed>>
     *
     * @throws RuntimeException
     */
    private static function find_users_exact(
        string $field,
        string $value
    ): array {
        if (
            $field !== 'email' &&
            $field !== 'username'
        ) {
            throw new RuntimeException(
                'Unsupported Keycloak user lookup field.'
            );
        }

        $value = strtolower(
            trim($value)
        );

        if ($value === '') {
            throw new RuntimeException(
                'Keycloak user lookup value is empty.'
            );
        }

        $relativePath = add_query_arg(
            [
                $field => $value,
                'exact' => 'true',
                'max' => 10,
            ],
            'users'
        );

        $users = self::admin_get_json(
            $relativePath
        );

        if (!array_is_list($users)) {
            throw new RuntimeException(
                'Keycloak user lookup response '
                . 'is not a list.'
            );
        }

        $matches = [];

        foreach ($users as $user) {
            if (!is_array($user)) {
                throw new RuntimeException(
                    'Keycloak user lookup contains '
                    . 'an invalid entry.'
                );
            }

            $actual = strtolower(
                trim(
                    (string) (
                        $user[$field] ?? ''
                    )
                )
            );

            if ($actual === $value) {
                $matches[] = $user;
            }
        }

        return array_values($matches);
    }

    /**
     * Fetch a Keycloak user through its canonical subject.
     *
     * @param non-empty-string $subject
     *
     * @return array<string,mixed>
     *
     * @throws RuntimeException
     */
    private static function get_user_by_subject(
        string $subject
    ): array {
        $subject = strtolower(
            trim($subject)
        );

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-'
                . '[1-5][0-9a-f]{3}-'
                . '[89ab][0-9a-f]{3}-'
                . '[0-9a-f]{12}$/',
                $subject
            ) !== 1
        ) {
            throw new RuntimeException(
                'Invalid Keycloak subject UUID.'
            );
        }

        $user = self::admin_get_json(
            'users/' . rawurlencode($subject)
        );

        $resolvedSubject = strtolower(
            trim(
                (string) (
                    $user['id'] ?? ''
                )
            )
        );

        if (
            $resolvedSubject === '' ||
            !hash_equals(
                $subject,
                $resolvedSubject
            )
        ) {
            throw new RuntimeException(
                'Keycloak subject response '
                . 'does not match the requested UUID.'
            );
        }

        return $user;
    }

    /**
     * Decide how a WordPress identity should be handled.
     *
     * This method is pure: it performs no I/O and causes
     * no WordPress or Keycloak mutation.
     *
     * @param array{
     *     wordpress_user_id:int,
     *     username:string,
     *     email:string,
     *     subject:string
     * } $identity
     * @param array<string,mixed>|null $subjectUser
     * @param list<array<string,mixed>> $emailMatches
     * @param list<array<string,mixed>> $usernameMatches
     *
     * @return non-empty-string
     */
    private static function provisioning_decision(
        array $identity,
        ?array $subjectUser,
        array $emailMatches,
        array $usernameMatches
    ): string {
        $subject = strtolower(
            trim(
                (string) (
                    $identity['subject'] ?? ''
                )
            )
        );

        $username = strtolower(
            trim(
                (string) (
                    $identity['username'] ?? ''
                )
            )
        );

        $email = strtolower(
            trim(
                (string) (
                    $identity['email'] ?? ''
                )
            )
        );

        if (
            (int) (
                $identity['wordpress_user_id'] ?? 0
            ) <= 0 ||
            $username === '' ||
            $email === ''
        ) {
            return 'invalid_local_identity';
        }

        if ($subject !== '') {
            if (!is_array($subjectUser)) {
                return 'subject_mapping_target_missing';
            }

            $resolvedSubject = strtolower(
                trim(
                    (string) (
                        $subjectUser['id'] ?? ''
                    )
                )
            );

            $resolvedUsername = strtolower(
                trim(
                    (string) (
                        $subjectUser['username'] ?? ''
                    )
                )
            );

            $resolvedEmail = strtolower(
                trim(
                    (string) (
                        $subjectUser['email'] ?? ''
                    )
                )
            );

            if (
                $resolvedSubject !== '' &&
                hash_equals(
                    $subject,
                    $resolvedSubject
                ) &&
                $resolvedUsername === $username &&
                $resolvedEmail === $email
            ) {
                return 'reuse_existing_subject_mapping';
            }

            return 'subject_mapping_conflict';
        }

        $emailMatches = array_values(
            array_filter(
                $emailMatches,
                static fn(array $user): bool =>
                    strtolower(
                        trim(
                            (string) (
                                $user['email'] ?? ''
                            )
                        )
                    ) === $email
            )
        );

        $usernameMatches = array_values(
            array_filter(
                $usernameMatches,
                static fn(array $user): bool =>
                    strtolower(
                        trim(
                            (string) (
                                $user['username'] ?? ''
                            )
                        )
                    ) === $username
            )
        );

        if (
            count($emailMatches) > 1 ||
            count($usernameMatches) > 1
        ) {
            return 'non_unique_keycloak_identity';
        }

        if (
            count($emailMatches) === 0 &&
            count($usernameMatches) === 0
        ) {
            return 'create_new_keycloak_user';
        }

        if (
            count($emailMatches) === 1 &&
            count($usernameMatches) === 1
        ) {
            $emailSubject = (string) (
                $emailMatches[0]['id'] ?? ''
            );

            $usernameSubject = (string) (
                $usernameMatches[0]['id'] ?? ''
            );

            if (
                $emailSubject !== '' &&
                hash_equals(
                    $emailSubject,
                    $usernameSubject
                )
            ) {
                return 'reuse_existing_unmapped_identity';
            }

            return 'email_username_cross_conflict';
        }

        if (count($emailMatches) === 1) {
            return 'email_conflict';
        }

        return 'username_conflict';
    }

    /**
     * Build the canonical local identity used by the
     * provisioning decision layer.
     *
     * This method is pure and performs no WordPress
     * lookup or mutation.
     *
     * @return array{
     *     wordpress_user_id:int,
     *     username:string,
     *     email:string,
     *     subject:string
     * }
     *
     * @throws RuntimeException
     */
    private static function build_local_identity(
        WP_User $user,
        string $subject
    ): array {
        $userId = (int) $user->ID;

        $username = strtolower(
            trim(
                (string) $user->user_login
            )
        );

        $email = strtolower(
            trim(
                (string) $user->user_email
            )
        );

        $subject = strtolower(
            trim($subject)
        );

        if ($userId <= 0) {
            throw new RuntimeException(
                'Invalid WordPress user ID.'
            );
        }

        if (
            $username === '' ||
            strlen($username) < 3 ||
            strlen($username) > 255 ||
            preg_match('/\s/u', $username) ||
            preg_match('/[^\x20-\x7E]/', $username) ||
            preg_match('~[<>/\\\\]~', $username)
        ) {
            throw new RuntimeException(
                'WordPress username is not valid '
                . 'for Keycloak provisioning.'
            );
        }

        if (!is_email($email)) {
            throw new RuntimeException(
                'WordPress user email is invalid.'
            );
        }

        if (
            $subject !== '' &&
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-'
                . '[1-5][0-9a-f]{3}-'
                . '[89ab][0-9a-f]{3}-'
                . '[0-9a-f]{12}$/',
                $subject
            ) !== 1
        ) {
            throw new RuntimeException(
                'Existing Keycloak subject is invalid.'
            );
        }

        return [
            'wordpress_user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'subject' => $subject,
        ];
    }


    /**
     * Build the Keycloak UserRepresentation used for
     * newly created WooCommerce customers.
     *
     * No WordPress password is included.
     *
     * @return array<string,mixed>
     */
    private static function build_keycloak_user_payload(
        WP_User $user
    ): array {
        $firstName = trim(
            (string) get_user_meta(
                $user->ID,
                'first_name',
                true
            )
        );

        $lastName = trim(
            (string) get_user_meta(
                $user->ID,
                'last_name',
                true
            )
        );

        $displayName = trim(
            (string) $user->display_name
        );

        return [
            'username' => strtolower(
                trim((string) $user->user_login)
            ),
            'email' => strtolower(
                trim((string) $user->user_email)
            ),
            'emailVerified' => false,
            'enabled' => true,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'attributes' => [
                'wordpress_user_id' => [
                    (string) $user->ID,
                ],
                'wordpress_login' => [
                    (string) $user->user_login,
                ],
                'wordpress_display_name' => [
                    $displayName,
                ],
            ],
            'requiredActions' => [
                'VERIFY_EMAIL',
                'UPDATE_PASSWORD',
            ],
        ];
    }

    /**
     * Execute a controlled mutation through the
     * Keycloak Admin API.
     *
     * Only POST and DELETE are accepted.
     *
     * @param 'POST'|'DELETE'      $method
     * @param non-empty-string     $relativePath
     * @param array<string,mixed>|null $payload
     * @param list<int>            $expectedStatuses
     *
     * @return array{
     *     status:int,
     *     location:string,
     *     body:string
     * }
     *
     * @throws RuntimeException
     */
    private static function admin_mutation_request(
        string $method,
        string $relativePath,
        ?array $payload,
        array $expectedStatuses
    ): array {
        if (
            $method !== 'POST' &&
            $method !== 'PUT' &&
            $method !== 'DELETE'
        ) {
            throw new RuntimeException(
                'Unsupported Keycloak mutation method.'
            );
        }

        $relativePath = trim($relativePath);

        if (
            $relativePath === '' ||
            str_starts_with($relativePath, '/') ||
            str_contains($relativePath, '..') ||
            !preg_match(
                '#^[A-Za-z0-9._~!$&\'()*+,;=:@%/?-]+$#',
                $relativePath
            )
        ) {
            throw new RuntimeException(
                'Invalid Keycloak mutation path.'
            );
        }

        if (
            $expectedStatuses === [] ||
            array_filter(
                $expectedStatuses,
                static fn(mixed $status): bool =>
                    !is_int($status) ||
                    $status < 100 ||
                    $status > 599
            ) !== []
        ) {
            throw new RuntimeException(
                'Invalid expected HTTP status list.'
            );
        }

        if (
            (
                $method === 'POST' ||
                $method === 'PUT'
            ) &&
            $payload === null
        ) {
            throw new RuntimeException(
                sprintf(
                    '%s mutation requires a payload.',
                    $method
                )
            );
        }

        if (
            $method === 'DELETE' &&
            $payload !== null
        ) {
            throw new RuntimeException(
                'DELETE mutation cannot have a payload.'
            );
        }

        $config = self::load_provisioner_config();
        $accessToken =
            self::request_provisioner_access_token();

        $url = sprintf(
            '%s/admin/realms/%s/%s',
            rtrim(
                $config['KEYCLOAK_ADMIN_BASE_URL'],
                '/'
            ),
            rawurlencode(
                $config['KEYCLOAK_REALM']
            ),
            $relativePath
        );

        $arguments = [
            'method' => $method,
            'timeout' => 20,
            'redirection' => 0,
            'headers' => [
                'Authorization' =>
                    'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
        ];

        if (
            $method === 'POST' ||
            $method === 'PUT'
        ) {
            $arguments['headers']['Content-Type'] =
                'application/json';

            $arguments['body'] = wp_json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE |
                JSON_THROW_ON_ERROR
            );

            $arguments['data_format'] = 'body';
        }

        $response = wp_remote_request(
            $url,
            $arguments
        );

        unset($accessToken);

        if (is_wp_error($response)) {
            throw new RuntimeException(
                'Keycloak Admin mutation failed.'
            );
        }

        $status = wp_remote_retrieve_response_code(
            $response
        );

        $location = wp_remote_retrieve_header(
            $response,
            'location'
        );

        $body = wp_remote_retrieve_body(
            $response
        );

        if (
            !in_array(
                $status,
                $expectedStatuses,
                true
            )
        ) {
            throw new RuntimeException(
                sprintf(
                    'Keycloak Admin mutation returned '
                    . 'unexpected HTTP %d.',
                    $status
                )
            );
        }

        return [
            'status' => $status,
            'location' => is_string($location)
                ? $location
                : '',
            'body' => $body,
        ];
    }

    /**
     * Extract and validate a Keycloak UUID from a
     * create-user Location response header.
     *
     * @return non-empty-string
     */
    private static function extract_user_uuid_from_location(
        string $location
    ): string {
        $path = parse_url(
            trim($location),
            PHP_URL_PATH
        );

        if (!is_string($path)) {
            throw new RuntimeException(
                'Invalid Keycloak Location header.'
            );
        }

        $subject = strtolower(
            basename(
                rtrim($path, '/')
            )
        );

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-'
                . '[1-5][0-9a-f]{3}-'
                . '[89ab][0-9a-f]{3}-'
                . '[0-9a-f]{12}$/',
                $subject
            ) !== 1
        ) {
            throw new RuntimeException(
                'Keycloak Location header contains '
                . 'no valid user UUID.'
            );
        }

        return $subject;
    }

    /**
     * Verify that a newly created Keycloak user exactly
     * represents the expected WordPress customer.
     */
    private static function verify_created_keycloak_user(
        WP_User $wordpressUser,
        string $subject
    ): void {
        $keycloakUser = self::get_user_by_subject(
            $subject
        );

        $payload = self::build_keycloak_user_payload(
            $wordpressUser
        );

        $attributes = is_array(
            $keycloakUser['attributes'] ?? null
        )
            ? $keycloakUser['attributes']
            : [];

        $getFirst = static function (
            array $values,
            string $key
        ): string {
            $value = $values[$key] ?? '';

            return is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) $value;
        };

        $requiredActions = array_values(
            array_map(
                'strval',
                is_array(
                    $keycloakUser[
                        'requiredActions'
                    ] ?? null
                )
                    ? $keycloakUser[
                        'requiredActions'
                    ]
                    : []
            )
        );

        $verified =
            strtolower(
                (string) (
                    $keycloakUser['username'] ?? ''
                )
            ) === $payload['username'] &&
            strtolower(
                (string) (
                    $keycloakUser['email'] ?? ''
                )
            ) === $payload['email'] &&
            !empty($keycloakUser['enabled']) &&
            empty($keycloakUser['emailVerified']) &&
            $getFirst(
                $attributes,
                'wordpress_user_id'
            ) === (string) $wordpressUser->ID &&
            $getFirst(
                $attributes,
                'wordpress_login'
            ) ===
                (string) $wordpressUser->user_login &&
            $getFirst(
                $attributes,
                'wordpress_display_name'
            ) ===
                (string) $wordpressUser->display_name &&
            in_array(
                'VERIFY_EMAIL',
                $requiredActions,
                true
            ) &&
            in_array(
                'UPDATE_PASSWORD',
                $requiredActions,
                true
            );

        if (!$verified) {
            throw new RuntimeException(
                'Created Keycloak user failed '
                . 'identity verification.'
            );
        }
    }

    /**
     * Create and verify one Keycloak user.
     *
     * No activation email is sent here.
     *
     * @return non-empty-string Canonical Keycloak UUID.
     */
    private static function create_keycloak_user(
        WP_User $user
    ): string {
        $response = self::admin_mutation_request(
            'POST',
            'users',
            self::build_keycloak_user_payload($user),
            [201]
        );

        $subject =
            self::extract_user_uuid_from_location(
                $response['location']
            );

        try {
            self::verify_created_keycloak_user(
                $user,
                $subject
            );
        } catch (Throwable $exception) {
            try {
                self::delete_keycloak_user(
                    $subject
                );
            } catch (Throwable $rollbackException) {
                throw new RuntimeException(
                    'Created Keycloak user verification '
                    . 'failed and automatic rollback '
                    . 'also failed.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }

        return $subject;
    }

    /**
     * Delete a Keycloak user during controlled rollback.
     */
    private static function delete_keycloak_user(
        string $subject
    ): void {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-'
                . '[1-5][0-9a-f]{3}-'
                . '[89ab][0-9a-f]{3}-'
                . '[0-9a-f]{12}$/',
                strtolower(trim($subject))
            ) !== 1
        ) {
            throw new RuntimeException(
                'Invalid Keycloak rollback subject.'
            );
        }

        self::admin_mutation_request(
            'DELETE',
            'users/' . rawurlencode($subject),
            null,
            [204]
        );
    }

    /**
     * Persist the canonical OIDC subject mapping.
     */
    private static function store_subject_mapping(
        int $wordpressUserId,
        string $subject
    ): void {
        $existing = trim(
            (string) get_user_meta(
                $wordpressUserId,
                'openid-connect-generic-subject-identity',
                true
            )
        );

        if ($existing !== '') {
            if (
                hash_equals(
                    strtolower($existing),
                    strtolower($subject)
                )
            ) {
                return;
            }

            throw new RuntimeException(
                'WordPress user already has a '
                . 'different OIDC subject mapping.'
            );
        }

        $stored = update_user_meta(
            $wordpressUserId,
            'openid-connect-generic-subject-identity',
            strtolower($subject)
        );

        if ($stored === false) {
            throw new RuntimeException(
                'Cannot store WordPress OIDC '
                . 'subject mapping.'
            );
        }

        $verified = trim(
            (string) get_user_meta(
                $wordpressUserId,
                'openid-connect-generic-subject-identity',
                true
            )
        );

        if (
            !hash_equals(
                strtolower($subject),
                strtolower($verified)
            )
        ) {
            throw new RuntimeException(
                'Stored WordPress OIDC subject '
                . 'mapping could not be verified.'
            );
        }
    }

    /**
     * Ensure that one WordPress customer has a canonical
     * Keycloak identity.
     *
     * This method is intentionally not connected to any
     * WordPress or WooCommerce hook yet.
     *
     * @return array{
     *     decision:string,
     *     subject:string,
     *     created:bool,
     *     mapping_written:bool
     * }
     */
    private static function ensure_keycloak_identity(
        int $wordpressUserId
    ): array {
        $user = get_user_by(
            'id',
            $wordpressUserId
        );

        if (!$user instanceof WP_User) {
            throw new RuntimeException(
                'WordPress customer was not found.'
            );
        }

        $subject = trim(
            (string) get_user_meta(
                $wordpressUserId,
                'openid-connect-generic-subject-identity',
                true
            )
        );

        $identity = self::build_local_identity(
            $user,
            $subject
        );

        $subjectUser = null;
        $emailMatches = [];
        $usernameMatches = [];

        if ($identity['subject'] !== '') {
            $subjectUser = self::get_user_by_subject(
                $identity['subject']
            );
        } else {
            $emailMatches = self::find_users_exact(
                'email',
                $identity['email']
            );

            $usernameMatches = self::find_users_exact(
                'username',
                $identity['username']
            );
        }

        $decision = self::provisioning_decision(
            $identity,
            $subjectUser,
            $emailMatches,
            $usernameMatches
        );

        if (
            $decision ===
            'reuse_existing_subject_mapping'
        ) {
            return [
                'decision' => $decision,
                'subject' =>
                    $identity['subject'],
                'created' => false,
                'mapping_written' => false,
            ];
        }

        if (
            $decision ===
            'reuse_existing_unmapped_identity'
        ) {
            $resolvedSubject = strtolower(
                (string) (
                    $emailMatches[0]['id'] ?? ''
                )
            );

            self::store_subject_mapping(
                $wordpressUserId,
                $resolvedSubject
            );

            return [
                'decision' => $decision,
                'subject' => $resolvedSubject,
                'created' => false,
                'mapping_written' => true,
            ];
        }

        if (
            $decision !==
            'create_new_keycloak_user'
        ) {
            throw new RuntimeException(
                sprintf(
                    'Keycloak provisioning stopped '
                    . 'with decision: %s',
                    $decision
                )
            );
        }

        $createdSubject =
            self::create_keycloak_user($user);

        try {
            self::store_subject_mapping(
                $wordpressUserId,
                $createdSubject
            );
        } catch (Throwable $exception) {
            try {
                self::delete_keycloak_user(
                    $createdSubject
                );
            } catch (Throwable $rollbackException) {
                throw new RuntimeException(
                    'WordPress mapping failed and '
                    . 'Keycloak rollback also failed.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }

        return [
            'decision' =>
                'create_new_keycloak_user',
            'subject' => $createdSubject,
            'created' => true,
            'mapping_written' => true,
        ];
    }


    /**
     * Provision a newly created checkout customer in
     * Keycloak before WooCommerce continues checkout.
     *
     * Non-checkout customer creation is ignored.
     *
     * @param int                 $customerId
     * @param array<string,mixed> $customerData
     * @param bool|string         $passwordGenerated
     */
    public static function provision_checkout_customer(
        int $customerId,
        array $customerData,
        bool|string $passwordGenerated
    ): void {
        unset($passwordGenerated);

        if (
            !self::is_checkout_registration_request(
                $customerData
            )
        ) {
            return;
        }

        if (
            $customerId <= 0 ||
            isset(
                self::$checkoutProvisionedCustomers[
                    $customerId
                ]
            )
        ) {
            return;
        }

        if (self::$checkoutProvisioningRunning) {
            throw new RuntimeException(
                self::checkout_provisioning_error_message()
            );
        }

        self::$checkoutProvisioningRunning = true;

        $expectedLogin = strtolower(
            trim(
                (string) (
                    $customerData['user_login'] ?? ''
                )
            )
        );

        $expectedEmail = strtolower(
            trim(
                (string) (
                    $customerData['user_email'] ?? ''
                )
            )
        );

        try {
            $user = get_user_by(
                'id',
                $customerId
            );

            if (!$user instanceof WP_User) {
                throw new RuntimeException(
                    'Created checkout customer was not found.'
                );
            }

            if (
                strtolower(
                    trim((string) $user->user_login)
                ) !== $expectedLogin ||
                strtolower(
                    trim((string) $user->user_email)
                ) !== $expectedEmail ||
                $expectedLogin === '' ||
                !is_email($expectedEmail)
            ) {
                throw new RuntimeException(
                    'Created checkout customer identity mismatch.'
                );
            }

            $result = self::ensure_keycloak_identity(
                $customerId
            );

            $subject = strtolower(
                trim(
                    (string) (
                        $result['subject'] ?? ''
                    )
                )
            );

            if (
                preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-'
                    . '[1-5][0-9a-f]{3}-'
                    . '[89ab][0-9a-f]{3}-'
                    . '[0-9a-f]{12}$/',
                    $subject
                ) !== 1
            ) {
                throw new RuntimeException(
                    'Checkout provisioning returned '
                    . 'an invalid Keycloak subject.'
                );
            }

            $storedSubject = strtolower(
                trim(
                    (string) get_user_meta(
                        $customerId,
                        'openid-connect-generic-subject-identity',
                        true
                    )
                )
            );

            if (
                $storedSubject === '' ||
                !hash_equals(
                    $subject,
                    $storedSubject
                )
            ) {
                throw new RuntimeException(
                    'Checkout subject mapping verification failed.'
                );
            }

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_provisioning_status',
                'complete'
            );

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_provisioning_source',
                'woocommerce_checkout'
            );

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_provisioning_decision',
                sanitize_key(
                    (string) (
                        $result['decision'] ?? ''
                    )
                )
            );

            /*
             * Identity provisioning is the transactional
             * checkout requirement. Email dispatch is a
             * non-transactional follow-up: a transport
             * failure must not remove a valid identity or
             * abort an otherwise valid checkout.
             */
            self::send_checkout_activation_email(
                $customerId,
                $subject
            );

            self::$checkoutProvisionedCustomers[
                $customerId
            ] = true;
        } catch (Throwable $exception) {
            $rollbackSucceeded = false;

            try {
                self::rollback_new_checkout_customer(
                    $customerId,
                    $expectedLogin,
                    $expectedEmail
                );

                $rollbackSucceeded = true;
            } catch (Throwable $rollbackException) {
                error_log(
                    sprintf(
                        'WP OIDC Keycloak checkout rollback '
                        . 'failed for customer %d (%s).',
                        $customerId,
                        get_class($rollbackException)
                    )
                );
            }

            error_log(
                sprintf(
                    'WP OIDC Keycloak checkout provisioning '
                    . 'failed for customer %d (%s); '
                    . 'rollback=%s.',
                    $customerId,
                    get_class($exception),
                    $rollbackSucceeded
                        ? 'complete'
                        : 'failed'
                )
            );

            throw new RuntimeException(
                self::checkout_provisioning_error_message(),
                0,
                $exception
            );
        } finally {
            self::$checkoutProvisioningRunning = false;
        }
    }

    /**
     * Detect only customer creation performed by the
     * WooCommerce classic or Store API checkout.
     *
     * Trusted WordPress code may override detection for
     * controlled integration tests.
     *
     * @param array<string,mixed> $customerData
     */
    private static function is_checkout_registration_request(
        array $customerData
    ): bool {
        $requestUri = (string) (
            $_SERVER['REQUEST_URI'] ?? ''
        );

        $storeApiCheckout =
            defined('REST_REQUEST') &&
            REST_REQUEST &&
            preg_match(
                '#/(?:wp-json/)?wc/store/v[0-9]+/'
                . 'checkout(?:/|\?|$)#',
                $requestUri
            ) === 1;

        $wcAjax = sanitize_key(
            (string) (
                $_REQUEST['wc-ajax'] ?? ''
            )
        );

        $classicCheckout =
            wp_doing_ajax() &&
            $wcAjax === 'checkout';

        $detected =
            $storeApiCheckout ||
            $classicCheckout;

        /**
         * Internal integration-test seam.
         *
         * Production behavior is the detected request
         * context unless trusted WordPress code changes it.
         */
        return (bool) apply_filters(
            'wp_oidc_keycloak_checkout_registration_request',
            $detected,
            $customerData
        );
    }

    /**
     * Build an OIDC login or registration URL that returns to a
     * signed guest-order claim URL.
     */
    private static function build_open_contribution_oidc_url(
        string $redirectTo,
        string $prompt
    ): string {
        $redirectTo = trim($redirectTo);

        if (
            $redirectTo === '' ||
            !in_array(
                $prompt,
                ['create', 'login'],
                true
            ) ||
            !class_exists('OpenID_Connect_Generic') ||
            !method_exists(
                OpenID_Connect_Generic::class,
                'instance'
            )
        ) {
            return '';
        }

        $url = OpenID_Connect_Generic::instance()
            ->client_wrapper
            ->get_authentication_url(
                [
                    'redirect_to' => $redirectTo,
                ]
            );

        if (
            !is_string($url) ||
            $url === ''
        ) {
            return '';
        }

        return add_query_arg(
            'prompt',
            $prompt,
            $url
        );
    }

    /**
     * Detect the standalone Name Your Price contribution product.
     *
     * Crowdfunding products use a different meta namespace and are
     * intentionally excluded.
     */
    private static function is_open_contribution_order(
        WC_Order $order
    ): bool {
        foreach (
            $order->get_items('line_item')
            as $item
        ) {
            if (
                !$item instanceof WC_Order_Item_Product
            ) {
                continue;
            }

            $productId =
                (int) $item->get_product_id();

            if ($productId < 1) {
                continue;
            }

            if (
                (string) get_post_meta(
                    $productId,
                    '_alg_wc_product_open_pricing_enabled',
                    true
                ) === 'yes'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sign one guest-order claim against site, order key and
     * billing email.
     */
    private static function open_contribution_claim_signature(
        WC_Order $order
    ): string {
        $payload = implode(
            "\n",
            [
                (string) get_current_blog_id(),
                (string) $order->get_id(),
                (string) $order->get_order_key(),
                strtolower(
                    trim(
                        (string) $order->get_billing_email()
                    )
                ),
            ]
        );

        return hash_hmac(
            'sha256',
            $payload,
            wp_salt('auth')
        );
    }

    /**
     * Build the local return URL used after Keycloak authentication.
     */
    private static function open_contribution_claim_url(
        WC_Order $order
    ): string {
        return add_query_arg(
            [
                'wp_oidc_keycloak_claim_contribution' =>
                    (string) $order->get_id(),
                'wp_oidc_keycloak_claim_sig' =>
                    self::open_contribution_claim_signature(
                        $order
                    ),
            ],
            $order->get_checkout_order_received_url()
        );
    }

    /**
     * Find up to two WordPress accounts with an exact email match.
     *
     * Two results are sufficient to distinguish a safe unique match
     * from ambiguous duplicate-email data.
     *
     * @return array<int,int>
     */
    private static function find_exact_wordpress_users_by_email(
        string $email
    ): array {
        global $wpdb;

        $email = strtolower(
            trim($email)
        );

        if (
            !is_email($email) ||
            !isset($wpdb->users)
        ) {
            return [];
        }

        $query = $wpdb->prepare(
            "SELECT ID
               FROM {$wpdb->users}
              WHERE LOWER(user_email) = LOWER(%s)
              ORDER BY ID ASC
              LIMIT 2",
            $email
        );

        if (!is_string($query)) {
            return [];
        }

        $ids = $wpdb->get_col($query);

        if (!is_array($ids)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    'absint',
                    $ids
                )
            )
        );
    }

    /**
     * Automatically associate a paid guest open-pricing contribution
     * with an existing WordPress customer when the billing email has
     * exactly one existing account match.
     *
     * @return int Linked customer ID, or zero when no safe link exists.
     */
    private static function auto_link_paid_guest_open_contribution(
        WC_Order $order
    ): int {
        if (
            !$order->is_paid() ||
            !self::is_open_contribution_order($order)
        ) {
            return 0;
        }

        $existingCustomerId =
            (int) $order->get_customer_id();

        if ($existingCustomerId > 0) {
            return $existingCustomerId;
        }

        $billingEmail = strtolower(
            trim(
                (string) $order->get_billing_email()
            )
        );

        if (!is_email($billingEmail)) {
            return 0;
        }

        $matchingUsers =
            self::find_exact_wordpress_users_by_email(
                $billingEmail
            );

        /*
         * Never choose arbitrarily between duplicate-email accounts.
         */
        if (count($matchingUsers) !== 1) {
            return 0;
        }

        $userId = (int) $matchingUsers[0];

        $user = get_user_by(
            'id',
            $userId
        );

        if (!$user instanceof WP_User) {
            return 0;
        }

        $accountEmail = strtolower(
            trim(
                (string) $user->user_email
            )
        );

        if (
            !is_email($accountEmail) ||
            !hash_equals(
                $billingEmail,
                $accountEmail
            )
        ) {
            return 0;
        }

        $order->set_customer_id(
            $userId
        );

        $order->update_meta_data(
            '_wp_oidc_keycloak_guest_auto_link_user_id',
            (string) $userId
        );

        $order->update_meta_data(
            '_wp_oidc_keycloak_guest_auto_link_method',
            'exact_billing_email'
        );

        $order->update_meta_data(
            '_wp_oidc_keycloak_guest_auto_link_email_sha256',
            hash(
                'sha256',
                $billingEmail
            )
        );

        $order->update_meta_data(
            '_wp_oidc_keycloak_guest_auto_linked_at_utc',
            gmdate('c')
        );

        $order->save();

        $order->add_order_note(
            sprintf(
                'WP OIDC Keycloak: paid guest open-pricing contribution automatically linked to existing customer #%d by unique exact billing-email match.',
                $userId
            )
        );

        return $userId;
    }

    /**
     * Payment/status hook wrapper for automatic guest contribution
     * association.
     */
    public static function maybe_auto_link_paid_guest_open_contribution(
        mixed $orderId
    ): void {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order(
            absint($orderId)
        );

        if (!$order instanceof WC_Order) {
            return;
        }

        self::auto_link_paid_guest_open_contribution(
            $order
        );
    }

    /**
     * Determine whether the current order-received request is the
     * short-lived receipt for a contribution that was automatically
     * associated with an existing account.
     */
    private static function is_auto_linked_contribution_receipt(
        WC_Order $order
    ): bool {
        if (
            !$order->is_paid() ||
            !self::is_open_contribution_order($order)
        ) {
            return false;
        }

        $autoLinkedUserId = absint(
            $order->get_meta(
                '_wp_oidc_keycloak_guest_auto_link_user_id',
                true
            )
        );

        if (
            $autoLinkedUserId < 1 ||
            (int) $order->get_customer_id() !==
                $autoLinkedUserId
        ) {
            return false;
        }

        if (
            (string) $order->get_meta(
                '_wp_oidc_keycloak_guest_auto_link_method',
                true
            ) !== 'exact_billing_email'
        ) {
            return false;
        }

        $linkedAt = trim(
            (string) $order->get_meta(
                '_wp_oidc_keycloak_guest_auto_linked_at_utc',
                true
            )
        );

        $linkedTimestamp = strtotime($linkedAt);

        /*
         * This exception exists only for the immediate post-payment
         * receipt. Old order-received URLs retain WooCommerce's normal
         * authenticated-customer protection.
         */
        if (
            $linkedTimestamp === false ||
            abs(time() - $linkedTimestamp) >
                (2 * HOUR_IN_SECONDS)
        ) {
            return false;
        }

        $orderKey = isset($_GET['key'])
            ? wc_clean(
                wp_unslash($_GET['key'])
            )
            : '';

        if (
            $orderKey === '' ||
            !hash_equals(
                (string) $order->get_order_key(),
                (string) $orderKey
            )
        ) {
            return false;
        }

        $user = get_user_by(
            'id',
            $autoLinkedUserId
        );

        if (!$user instanceof WP_User) {
            return false;
        }

        $billingEmail = strtolower(
            trim(
                (string) $order->get_billing_email()
            )
        );

        $accountEmail = strtolower(
            trim(
                (string) $user->user_email
            )
        );

        return
            is_email($billingEmail) &&
            is_email($accountEmail) &&
            hash_equals(
                $billingEmail,
                $accountEmail
            );
    }

    /**
     * Allow the immediate order-received page to render after we have
     * associated a guest wallet payment with its existing customer.
     *
     * WooCommerce has already validated the order ID/order key before
     * applying this filter; we deliberately validate the key again.
     */
    public static function allow_auto_linked_contribution_order_received(
        mixed $verifyKnownShoppers
    ): bool {
        if (
            !(bool) $verifyKnownShoppers ||
            is_user_logged_in() ||
            !function_exists('wc_get_order')
        ) {
            return (bool) $verifyKnownShoppers;
        }

        global $wp;

        $orderId = absint(
            $wp->query_vars['order-received'] ?? 0
        );

        if ($orderId < 1) {
            return (bool) $verifyKnownShoppers;
        }

        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return (bool) $verifyKnownShoppers;
        }

        if (
            self::is_auto_linked_contribution_receipt(
                $order
            )
        ) {
            return false;
        }

        return (bool) $verifyKnownShoppers;
    }

    /**
     * The same narrowly scoped receipt exception also bypasses the
     * subsequent WooCommerce email-verification prompt.
     */
    public static function allow_auto_linked_contribution_email_verification(
        mixed $verificationRequired,
        mixed $order,
        mixed $context
    ): bool {
        if (
            !(bool) $verificationRequired ||
            $context !== 'order-received' ||
            !$order instanceof WC_Order
        ) {
            return (bool) $verificationRequired;
        }

        if (
            self::is_auto_linked_contribution_receipt(
                $order
            )
        ) {
            return false;
        }

        return (bool) $verificationRequired;
    }

    /**
     * Display an optional account action after a paid guest
     * Name Your Price contribution.
     */
    public static function render_guest_open_contribution_account_cta(
        mixed $orderId
    ): void {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order(
            absint($orderId)
        );

        if (
            !$order instanceof WC_Order ||
            !$order->is_paid() ||
            !self::is_open_contribution_order($order)
        ) {
            return;
        }

        /*
         * Recovery path for payments completed before this hook ran,
         * including an order whose thank-you page is being refreshed.
         */
        if ((int) $order->get_customer_id() === 0) {
            self::auto_link_paid_guest_open_contribution(
                $order
            );
        }

        $isEnglish = !str_starts_with(
            strtolower(determine_locale()),
            'el'
        );

        $autoLinkedUserId = absint(
            $order->get_meta(
                '_wp_oidc_keycloak_guest_auto_link_user_id',
                true
            )
        );

        if (
            $autoLinkedUserId > 0 &&
            (int) $order->get_customer_id() ===
                $autoLinkedUserId
        ) {
            $heading = $isEnglish
                ? 'Contribution linked to your central account'
                : 'Η συνεισφορά συνδέθηκε με τον λογαριασμό σου';

            $description = $isEnglish
                ? 'The email used for the payment already belongs to a central account, so this contribution was linked automatically.'
                : 'Το email που χρησιμοποιήθηκε στην πληρωμή αντιστοιχεί ήδη σε κεντρικό λογαριασμό, οπότε η συνεισφορά συνδέθηκε αυτόματα.';

            echo '<section class="woocommerce-order-details wp-oidc-keycloak-contribution-account">';

            echo '<h2 class="woocommerce-order-details__title">' .
                esc_html($heading) .
                '</h2>';

            echo '<p>' .
                esc_html($description) .
                '</p>';

            echo '</section>';

            return;
        }

        $status = sanitize_key(
            (string) (
                $_GET['wp_oidc_keycloak_account_link'] ?? ''
            )
        );

        if (
            $status === 'linked' &&
            is_user_logged_in() &&
            (int) $order->get_customer_id() ===
                get_current_user_id()
        ) {
            if (function_exists('wc_print_notice')) {
                wc_print_notice(
                    $isEnglish
                        ? 'Your contribution has been linked to your central account.'
                        : 'Η συνεισφορά συνδέθηκε με τον λογαριασμό σου.',
                    'success'
                );
            }

            return;
        }

        if (
            $status === 'email_mismatch' ||
            $status === 'identity_missing'
        ) {
            if (function_exists('wc_print_notice')) {
                wc_print_notice(
                    $status === 'email_mismatch'
                        ? (
                            $isEnglish
                                ? 'The contribution was not linked because the account email does not match the email used for the payment.'
                                : 'Η συνεισφορά δεν συνδέθηκε, επειδή το email του λογαριασμού δεν είναι ίδιο με το email που χρησιμοποιήθηκε στην πληρωμή.'
                        )
                        : (
                            $isEnglish
                                ? 'The contribution could not be linked because a verified OIDC identity was not available.'
                                : 'Η συνεισφορά δεν μπόρεσε να συνδεθεί επειδή δεν βρέθηκε έγκυρη ταυτότητα OIDC.'
                        ),
                    'error'
                );
            }

            return;
        }

        if (
            is_user_logged_in() ||
            (int) $order->get_customer_id() !== 0
        ) {
            return;
        }

        $billingEmail = strtolower(
            trim(
                (string) $order->get_billing_email()
            )
        );

        if (!is_email($billingEmail)) {
            return;
        }

        $claimUrl =
            self::open_contribution_claim_url(
                $order
            );

        $registrationUrl =
            self::build_open_contribution_oidc_url(
                $claimUrl,
                'create'
            );

        $loginUrl =
            self::build_open_contribution_oidc_url(
                $claimUrl,
                'login'
            );

        if (
            $registrationUrl === '' &&
            $loginUrl === ''
        ) {
            return;
        }

        $heading = $isEnglish
            ? 'Would you like a central account?'
            : 'Θέλεις να δημιουργήσεις κεντρικό λογαριασμό;';

        $description = $isEnglish
            ? 'This is optional. Use the same email as the payment and this contribution will be linked to your account after authentication.'
            : 'Είναι προαιρετικό. Χρησιμοποίησε το ίδιο email με την πληρωμή και η συγκεκριμένη συνεισφορά θα συνδεθεί με τον λογαριασμό σου μετά την ταυτοποίηση.';

        echo '<section class="woocommerce-order-details wp-oidc-keycloak-contribution-account">';

        echo '<h2 class="woocommerce-order-details__title">' .
            esc_html($heading) .
            '</h2>';

        echo '<p>' .
            esc_html($description) .
            '</p>';

        echo '<p>';

        if ($registrationUrl !== '') {
            echo '<a class="button alt" href="' .
                esc_url($registrationUrl) .
                '">' .
                esc_html(
                    $isEnglish
                        ? 'Create central account'
                        : 'Δημιουργία λογαριασμού'
                ) .
                '</a> ';
        }

        if ($loginUrl !== '') {
            echo '<a class="button" href="' .
                esc_url($loginUrl) .
                '">' .
                esc_html(
                    $isEnglish
                        ? 'I already have an account'
                        : 'Έχω ήδη λογαριασμό'
                ) .
                '</a>';
        }

        echo '</p>';
        echo '</section>';
    }

    /**
     * Redirect back to the clean order-received URL with a
     * non-sensitive account-link result marker.
     */
    private static function redirect_open_contribution_claim(
        WC_Order $order,
        string $status
    ): void {
        $url = add_query_arg(
            'wp_oidc_keycloak_account_link',
            sanitize_key($status),
            $order->get_checkout_order_received_url()
        );

        wp_safe_redirect($url);
        exit;
    }

    /**
     * Claim exactly one paid guest contribution after successful
     * Keycloak/OIDC authentication.
     */
    public static function maybe_claim_guest_open_contribution_order(): void
    {
        if (
            !isset(
                $_GET['wp_oidc_keycloak_claim_contribution'],
                $_GET['wp_oidc_keycloak_claim_sig']
            ) ||
            !is_user_logged_in() ||
            !function_exists('wc_get_order')
        ) {
            return;
        }

        $orderId = absint(
            wp_unslash(
                $_GET['wp_oidc_keycloak_claim_contribution']
            )
        );

        $signature = sanitize_text_field(
            wp_unslash(
                $_GET['wp_oidc_keycloak_claim_sig']
            )
        );

        if (
            $orderId < 1 ||
            $signature === ''
        ) {
            return;
        }

        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $orderKey = isset($_GET['key'])
            ? wc_clean(
                wp_unslash($_GET['key'])
            )
            : '';

        if (
            $orderKey === '' ||
            !hash_equals(
                (string) $order->get_order_key(),
                (string) $orderKey
            ) ||
            !hash_equals(
                self::open_contribution_claim_signature(
                    $order
                ),
                $signature
            ) ||
            !$order->is_paid() ||
            !self::is_open_contribution_order($order)
        ) {
            return;
        }

        $user = wp_get_current_user();

        if (!$user instanceof WP_User) {
            return;
        }

        $subject = strtolower(
            trim(
                (string) get_user_meta(
                    $user->ID,
                    'openid-connect-generic-subject-identity',
                    true
                )
            )
        );

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-'
                . '[1-5][0-9a-f]{3}-'
                . '[89ab][0-9a-f]{3}-'
                . '[0-9a-f]{12}$/',
                $subject
            ) !== 1
        ) {
            self::redirect_open_contribution_claim(
                $order,
                'identity_missing'
            );
        }

        $accountEmail = strtolower(
            trim(
                (string) $user->user_email
            )
        );

        $billingEmail = strtolower(
            trim(
                (string) $order->get_billing_email()
            )
        );

        if (
            !is_email($accountEmail) ||
            !is_email($billingEmail) ||
            !hash_equals(
                $billingEmail,
                $accountEmail
            )
        ) {
            self::redirect_open_contribution_claim(
                $order,
                'email_mismatch'
            );
        }

        $existingCustomerId =
            (int) $order->get_customer_id();

        if (
            $existingCustomerId > 0 &&
            $existingCustomerId !==
                (int) $user->ID
        ) {
            return;
        }

        if ($existingCustomerId === 0) {
            $order->set_customer_id(
                (int) $user->ID
            );

            $order->update_meta_data(
                '_wp_oidc_keycloak_guest_claim_user_id',
                (string) $user->ID
            );

            $order->update_meta_data(
                '_wp_oidc_keycloak_guest_claim_subject',
                $subject
            );

            $order->update_meta_data(
                '_wp_oidc_keycloak_guest_claimed_at_utc',
                gmdate('c')
            );

            $order->save();

            $order->add_order_note(
                sprintf(
                    'WP OIDC Keycloak: guest open-pricing contribution linked to customer #%d after Keycloak/OIDC authentication.',
                    (int) $user->ID
                )
            );
        }

        self::redirect_open_contribution_claim(
            $order,
            'linked'
        );
    }

    /**
     * Suppress WooCommerce's local-password account email
     * only when the checkout-created customer has completed
     * Keycloak provisioning and the Keycloak action email
     * was accepted or was not required.
     *
     * Status "pending" deliberately preserves WooCommerce's
     * current email as a temporary fallback.
     */
    public static function filter_woocommerce_new_account_email(
        mixed $enabled,
        mixed $object,
        mixed $email
    ): bool {
        if (!(bool) $enabled) {
            return false;
        }

        $userId = 0;

        if ($object instanceof WP_User) {
            $userId = (int) $object->ID;
        } elseif (is_numeric($object)) {
            $userId = absint($object);
        } elseif (
            is_object($object) &&
            isset($object->ID)
        ) {
            $userId = absint($object->ID);
        }

        if ($userId < 1) {
            return true;
        }

        $provisioningStatus = (string) get_user_meta(
            $userId,
            '_wp_oidc_keycloak_provisioning_status',
            true
        );

        $provisioningSource = (string) get_user_meta(
            $userId,
            '_wp_oidc_keycloak_provisioning_source',
            true
        );

        $activationEmailStatus = (string) get_user_meta(
            $userId,
            '_wp_oidc_keycloak_activation_email_status',
            true
        );

        if (
            $provisioningStatus !== 'complete' ||
            $provisioningSource !== 'woocommerce_checkout'
        ) {
            return true;
        }

        return !in_array(
            $activationEmailStatus,
            [
                'sent',
                'not_required',
            ],
            true
        );
    }

    /**
     * Remove all diagnostic fields left by an earlier failed
     * activation-email attempt.
     */
    private static function clear_activation_email_error_metadata(
        int $customerId
    ): void {
        foreach (
            [
                '_wp_oidc_keycloak_activation_email_error',
                '_wp_oidc_keycloak_activation_email_error_code',
                '_wp_oidc_keycloak_activation_email_error_detail',
                '_wp_oidc_keycloak_activation_email_error_sha256',
                '_wp_oidc_keycloak_activation_email_failed_at_utc',
            ] as $metaKey
        ) {
            delete_user_meta(
                $customerId,
                $metaKey
            );
        }
    }

    /**
     * Request the Keycloak action email for any supported
     * required actions still pending on this identity.
     *
     * Transport failure is recorded as pending and never
     * invalidates the already verified identity.
     */
    private static function send_checkout_activation_email(
        int $customerId,
        string $subject
    ): void {
        $attemptedAt = gmdate('Y-m-d\TH:i:s\Z');

        update_user_meta(
            $customerId,
            '_wp_oidc_keycloak_activation_email_last_attempt_at_utc',
            $attemptedAt
        );

        try {
            $keycloakUser = self::get_user_by_subject(
                $subject
            );

            if (!is_array($keycloakUser)) {
                throw new RuntimeException(
                    'Keycloak identity is unavailable '
                    . 'for activation email.'
                );
            }

            $requiredActions = is_array(
                $keycloakUser['requiredActions'] ?? null
            )
                ? array_values(
                    array_unique(
                        array_map(
                            'strval',
                            $keycloakUser['requiredActions']
                        )
                    )
                )
                : [];

            $supportedActions = [];

            foreach (
                [
                    'VERIFY_EMAIL',
                    'UPDATE_PASSWORD',
                ] as $action
            ) {
                if (
                    in_array(
                        $action,
                        $requiredActions,
                        true
                    )
                ) {
                    $supportedActions[] = $action;
                }
            }

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_activation_email_actions',
                implode(',', $supportedActions)
            );

            if ($supportedActions === []) {
                update_user_meta(
                    $customerId,
                    '_wp_oidc_keycloak_activation_email_status',
                    'not_required'
                );

                self::clear_activation_email_error_metadata(
                    $customerId
                );

                return;
            }

            self::admin_mutation_request(
                'PUT',
                'users/'
                    . rawurlencode($subject)
                    . '/execute-actions-email',
                $supportedActions,
                [204]
            );

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_activation_email_status',
                'sent'
            );

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_activation_email_sent_at_utc',
                gmdate('Y-m-d\TH:i:s\Z')
            );

            self::clear_activation_email_error_metadata(
                $customerId
            );
        } catch (Throwable $exception) {
            $exceptionClass = (
                new ReflectionClass($exception)
            )->getShortName();

            $exceptionCode = (int) $exception->getCode();

            /*
             * Store a bounded diagnostic message. Strip
             * control characters and redact bearer tokens
             * or long opaque credential-like values.
             */
            $diagnosticMessage = preg_replace(
                '/[\x00-\x1F\x7F]+/u',
                ' ',
                (string) $exception->getMessage()
            );

            if (!is_string($diagnosticMessage)) {
                $diagnosticMessage =
                    'Unable to normalize exception message.';
            }

            $diagnosticMessage = preg_replace(
                '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i',
                'Bearer [REDACTED]',
                $diagnosticMessage
            );

            if (!is_string($diagnosticMessage)) {
                $diagnosticMessage =
                    'Unable to redact exception message.';
            }

            $diagnosticMessage = trim(
                preg_replace(
                    '/\s+/u',
                    ' ',
                    $diagnosticMessage
                ) ?? ''
            );

            if (function_exists('mb_substr')) {
                $diagnosticMessage = mb_substr(
                    $diagnosticMessage,
                    0,
                    1000,
                    'UTF-8'
                );
            } else {
                $diagnosticMessage = substr(
                    $diagnosticMessage,
                    0,
                    1000
                );
            }

            $diagnosticHash = hash(
                'sha256',
                $diagnosticMessage
            );

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_activation_email_status',
                'pending'
            );

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_activation_email_error',
                sanitize_key($exceptionClass)
            );

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_activation_email_error_code',
                (string) $exceptionCode
            );

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_activation_email_error_detail',
                $diagnosticMessage
            );

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_activation_email_error_sha256',
                $diagnosticHash
            );

            update_user_meta(
                $customerId,
                '_wp_oidc_keycloak_activation_email_failed_at_utc',
                gmdate('Y-m-d\TH:i:s\Z')
            );

            error_log(
                sprintf(
                    'WP OIDC Keycloak activation email '
                    . 'dispatch failed for customer %d; '
                    . 'exception=%s code=%d '
                    . 'detail_sha256=%s detail=%s; '
                    . 'identity retained and retry required.',
                    $customerId,
                    $exceptionClass,
                    $exceptionCode,
                    $diagnosticHash,
                    $diagnosticMessage
                )
            );
        }
    }

    /**
     * Remove only the exact network user created by the
     * failed checkout attempt.
     */
    private static function rollback_new_checkout_customer(
        int $customerId,
        string $expectedLogin,
        string $expectedEmail
    ): void {
        $userById = get_user_by(
            'id',
            $customerId
        );

        $userByLogin = get_user_by(
            'login',
            $expectedLogin
        );

        $userByEmail = get_user_by(
            'email',
            $expectedEmail
        );

        $exactIdentity =
            $customerId > 0 &&
            $expectedLogin !== '' &&
            is_email($expectedEmail) &&
            $userById instanceof WP_User &&
            $userByLogin instanceof WP_User &&
            $userByEmail instanceof WP_User &&
            (int) $userById->ID === $customerId &&
            (int) $userByLogin->ID === $customerId &&
            (int) $userByEmail->ID === $customerId &&
            strtolower(
                (string) $userById->user_login
            ) === $expectedLogin &&
            strtolower(
                (string) $userById->user_email
            ) === $expectedEmail;

        if (!$exactIdentity) {
            throw new RuntimeException(
                'Checkout rollback identity guard failed.'
            );
        }

        if (!is_multisite()) {
            throw new RuntimeException(
                'Checkout rollback requires multisite.'
            );
        }

        if (!function_exists('wpmu_delete_user')) {
            require_once ABSPATH
                . 'wp-admin/includes/ms.php';
        }

        if (!function_exists('wpmu_delete_user')) {
            throw new RuntimeException(
                'Network user deletion is unavailable.'
            );
        }

        if (wpmu_delete_user($customerId) !== true) {
            throw new RuntimeException(
                'Network user deletion failed.'
            );
        }

        if (
            get_user_by('id', $customerId)
                instanceof WP_User ||
            get_user_by('login', $expectedLogin)
                instanceof WP_User ||
            get_user_by('email', $expectedEmail)
                instanceof WP_User
        ) {
            throw new RuntimeException(
                'Checkout customer remains after rollback.'
            );
        }
    }

    private static function checkout_provisioning_error_message(): string
    {
        $locale = strtolower(
            determine_locale()
        );

        if (!str_starts_with($locale, 'el')) {
            return 'Your central account could not '
                . 'be created. No order was placed. '
                . 'Please try again.';
        }

        return 'Δεν ήταν δυνατή η δημιουργία του '
            . 'κεντρικού λογαριασμού. '
            . 'Δεν καταχωρίστηκε παραγγελία. '
            . 'Παρακαλούμε δοκιμάστε ξανά.';
    }

}

add_action(
    'plugins_loaded',
    [WP_OIDC_Keycloak_Integration::class, 'bootstrap_with_dependency_check'],
    20
);
