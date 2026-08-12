<?php
/**
 * Keycloak-authoritative WooCommerce edit account form.
 *
 * Login email is immutable in self-service. Password changes are
 * performed only through Keycloak. WooCommerce retains non-identity fields.
 *
 * @package WP_OIDC_Keycloak_Integration
 * @version 11.0.0
 */

defined('ABSPATH') || exit;

if (!isset($user) || !$user instanceof WP_User) {
    $user = wp_get_current_user();
}

$isEnglish = !str_starts_with(
    strtolower(determine_locale()),
    'el'
);

$passwordActionUrl =
    class_exists('WP_OIDC_Keycloak_Integration')
        ? WP_OIDC_Keycloak_Integration::get_account_action_dispatch_url(
            'UPDATE_PASSWORD'
        )
        : '';

$emailLabel = $isEnglish
    ? 'Login email address'
    : 'Διεύθυνση email σύνδεσης';

$emailDescription = $isEnglish
    ? 'Your login email is fixed and cannot be changed from the account interface.'
    : 'Η διεύθυνση email σύνδεσης είναι σταθερή και δεν μπορεί να αλλάξει από τη διαχείριση λογαριασμού.';

$securityLegend = $isEnglish
    ? 'Account security'
    : 'Ασφάλεια λογαριασμού';

$passwordDescription = $isEnglish
    ? 'Your password is managed by the central identity service and is not stored or changed through WooCommerce.'
    : 'Ο κωδικός πρόσβασης διαχειρίζεται από το κεντρικό σύστημα ταυτότητας και δεν αποθηκεύεται ή αλλάζει μέσω WooCommerce.';

$passwordButton = $isEnglish
    ? 'Change password'
    : 'Αλλαγή κωδικού πρόσβασης';

$displayNameDescription = $isEnglish
    ? (
        wc_reviews_enabled()
            ? 'This will be how your name will be displayed in the account section and in reviews'
            : 'This will be how your name will be displayed in the account section'
    )
    : (
        wc_reviews_enabled()
            ? 'Έτσι θα εμφανίζεται το όνομά σας στην ενότητα λογαριασμού και στις αξιολογήσεις.'
            : 'Έτσι θα εμφανίζεται το όνομά σας στην ενότητα λογαριασμού.'
    );

$unavailable = $isEnglish
    ? 'The central identity service is temporarily unavailable.'
    : 'Η κεντρική υπηρεσία ταυτότητας είναι προσωρινά μη διαθέσιμη.';

do_action('woocommerce_before_edit_account_form');
?>

<form class="woocommerce-EditAccountForm edit-account" action="" method="post" <?php do_action('woocommerce_edit_account_form_tag'); ?>>

    <?php do_action('woocommerce_edit_account_form_start'); ?>

    <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
        <label for="account_first_name">
            <?php esc_html_e('First name', 'woocommerce'); ?>
            <span class="required" aria-hidden="true">*</span>
        </label>
        <input
            type="text"
            class="woocommerce-Input woocommerce-Input--text input-text"
            name="account_first_name"
            id="account_first_name"
            autocomplete="given-name"
            value="<?php echo esc_attr($user->first_name); ?>"
            aria-required="true"
        />
    </p>

    <p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
        <label for="account_last_name">
            <?php esc_html_e('Last name', 'woocommerce'); ?>
            <span class="required" aria-hidden="true">*</span>
        </label>
        <input
            type="text"
            class="woocommerce-Input woocommerce-Input--text input-text"
            name="account_last_name"
            id="account_last_name"
            autocomplete="family-name"
            value="<?php echo esc_attr($user->last_name); ?>"
            aria-required="true"
        />
    </p>

    <div class="clear"></div>

    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
        <label for="account_display_name">
            <?php esc_html_e('Display name', 'woocommerce'); ?>
            <span class="required" aria-hidden="true">*</span>
        </label>
        <input
            type="text"
            class="woocommerce-Input woocommerce-Input--text input-text"
            name="account_display_name"
            id="account_display_name"
            aria-describedby="account_display_name_description"
            value="<?php echo esc_attr($user->display_name); ?>"
            aria-required="true"
        />
        <span id="account_display_name_description">
            <em>
                <?php echo esc_html($displayNameDescription); ?>
            </em>
        </span>
    </p>

    <?php do_action('woocommerce_edit_account_form_fields'); ?>

    <fieldset class="wp-oidc-keycloak-authority omniatv-keycloak-authority">
        <legend><?php echo esc_html($securityLegend); ?></legend>

        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide wp-oidc-keycloak-authority__email omniatv-keycloak-authority__email">
            <label for="omniatv_account_email">
                <?php echo esc_html($emailLabel); ?>
            </label>
            <input
                type="email"
                class="woocommerce-Input woocommerce-Input--email input-text"
                id="omniatv_account_email"
                value="<?php echo esc_attr($user->user_email); ?>"
                readonly
                disabled
                autocomplete="email"
            />
        </p>

        <p><?php echo esc_html($emailDescription); ?></p>

        <hr />

        <p><?php echo esc_html($passwordDescription); ?></p>

        <?php if ($passwordActionUrl !== '') : ?>
            <p class="wp-oidc-keycloak-authority__actions omniatv-keycloak-authority__actions">
                <a class="button" href="<?php echo esc_url($passwordActionUrl); ?>">
                    <?php echo esc_html($passwordButton); ?>
                </a>
            </p>
        <?php else : ?>
            <p><?php echo esc_html($unavailable); ?></p>
        <?php endif; ?>
    </fieldset>

    <?php do_action('woocommerce_edit_account_form'); ?>

    <p>
        <?php wp_nonce_field('save_account_details', 'save-account-details-nonce'); ?>
        <button
            type="submit"
            class="woocommerce-Button button<?php echo esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>"
            name="save_account_details"
            value="<?php esc_attr_e('Save changes', 'woocommerce'); ?>"
        >
            <?php esc_html_e('Save changes', 'woocommerce'); ?>
        </button>
        <input type="hidden" name="action" value="save_account_details" />
    </p>

    <?php do_action('woocommerce_edit_account_form_end'); ?>

</form>

<?php do_action('woocommerce_after_edit_account_form'); ?>
