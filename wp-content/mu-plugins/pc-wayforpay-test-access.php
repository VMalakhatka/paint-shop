<?php
/**
 * Plugin Name: PC WayForPay Test Access
 * Description: Restricts the WayForPay payment method to selected test users until it is opened to all customers.
 * Version: 1.0.0
 * Text Domain: pc-wayforpay-test-access
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

const PC_WAYFORPAY_ACCESS_MODE_OPTION = 'pc_wayforpay_access_mode';
const PC_WAYFORPAY_TEST_ACCESS_META = '_pc_wayforpay_test_access';
const PC_WAYFORPAY_GATEWAY_ID = 'wayforpay';

function pc_wayforpay_access_mode(): string
{
    return get_option(PC_WAYFORPAY_ACCESS_MODE_OPTION, 'allowlist') === 'all'
        ? 'all'
        : 'allowlist';
}

function pc_wayforpay_user_has_test_access(int $user_id): bool
{
    return $user_id > 0 && get_user_meta($user_id, PC_WAYFORPAY_TEST_ACCESS_META, true) === 'yes';
}

/**
 * Keep the gateway enabled in WooCommerce while controlling only its checkout visibility.
 * Guests never receive access in allow-list mode.
 *
 * @param array<string, WC_Payment_Gateway> $gateways
 * @return array<string, WC_Payment_Gateway>
 */
function pc_wayforpay_filter_available_gateways(array $gateways): array
{
    if (!isset($gateways[PC_WAYFORPAY_GATEWAY_ID]) || pc_wayforpay_access_mode() === 'all') {
        return $gateways;
    }

    if (is_admin() && !wp_doing_ajax()) {
        return $gateways;
    }

    $user_id = get_current_user_id();
    if (!pc_wayforpay_user_has_test_access($user_id)) {
        unset($gateways[PC_WAYFORPAY_GATEWAY_ID]);
    }

    return $gateways;
}
add_filter('woocommerce_available_payment_gateways', 'pc_wayforpay_filter_available_gateways', 999);

function pc_wayforpay_access_can_manage(): bool
{
    return current_user_can('manage_woocommerce');
}

function pc_wayforpay_register_access_setting(): void
{
    register_setting(
        'pc_wayforpay_test_access',
        PC_WAYFORPAY_ACCESS_MODE_OPTION,
        [
            'type'              => 'string',
            'default'           => 'allowlist',
            'sanitize_callback' => static function ($value): string {
                return $value === 'all' ? 'all' : 'allowlist';
            },
        ]
    );
}
add_action('admin_init', 'pc_wayforpay_register_access_setting');

function pc_wayforpay_add_access_page(): void
{
    add_submenu_page(
        'woocommerce',
        __('WayForPay access', 'pc-wayforpay-test-access'),
        __('WayForPay access', 'pc-wayforpay-test-access'),
        'manage_woocommerce',
        'pc-wayforpay-test-access',
        'pc_wayforpay_render_access_page'
    );
}
add_action('admin_menu', 'pc_wayforpay_add_access_page', 90);

function pc_wayforpay_render_access_page(): void
{
    if (!pc_wayforpay_access_can_manage()) {
        wp_die(esc_html__('You are not allowed to manage WayForPay access.', 'pc-wayforpay-test-access'));
    }

    $mode = pc_wayforpay_access_mode();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('WayForPay access', 'pc-wayforpay-test-access'); ?></h1>
        <p><?php echo esc_html__('Use restricted mode while the merchant is being tested. The payment method remains enabled in WooCommerce but is shown only to selected signed-in users.', 'pc-wayforpay-test-access'); ?></p>

        <form action="options.php" method="post">
            <?php settings_fields('pc_wayforpay_test_access'); ?>
            <fieldset>
                <legend class="screen-reader-text"><?php echo esc_html__('Checkout availability', 'pc-wayforpay-test-access'); ?></legend>
                <p>
                    <label>
                        <input type="radio" name="<?php echo esc_attr(PC_WAYFORPAY_ACCESS_MODE_OPTION); ?>" value="allowlist" <?php checked($mode, 'allowlist'); ?>>
                        <strong><?php echo esc_html__('Only selected test users', 'pc-wayforpay-test-access'); ?></strong>
                    </label><br>
                    <span class="description"><?php echo esc_html__('WayForPay is hidden from guests and all users who do not have test access.', 'pc-wayforpay-test-access'); ?></span>
                </p>
                <p>
                    <label>
                        <input type="radio" name="<?php echo esc_attr(PC_WAYFORPAY_ACCESS_MODE_OPTION); ?>" value="all" <?php checked($mode, 'all'); ?>>
                        <strong><?php echo esc_html__('All customers', 'pc-wayforpay-test-access'); ?></strong>
                    </label><br>
                    <span class="description"><?php echo esc_html__('WayForPay is available to everyone when the gateway itself is enabled and available.', 'pc-wayforpay-test-access'); ?></span>
                </p>
            </fieldset>
            <?php submit_button(__('Save access mode', 'pc-wayforpay-test-access')); ?>
        </form>

        <hr>
        <h2><?php echo esc_html__('Select test users', 'pc-wayforpay-test-access'); ?></h2>
        <p><?php echo esc_html__('Open a user profile and enable WayForPay test access in the dedicated section.', 'pc-wayforpay-test-access'); ?></p>
        <p><a class="button" href="<?php echo esc_url(admin_url('users.php')); ?>"><?php echo esc_html__('Open users', 'pc-wayforpay-test-access'); ?></a></p>
    </div>
    <?php
}

function pc_wayforpay_render_user_access_field(WP_User $user): void
{
    if (!pc_wayforpay_access_can_manage() || !current_user_can('edit_user', $user->ID)) {
        return;
    }

    $allowed = pc_wayforpay_user_has_test_access((int) $user->ID);
    ?>
    <h2><?php echo esc_html__('WayForPay test access', 'pc-wayforpay-test-access'); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php echo esc_html__('Card payment testing', 'pc-wayforpay-test-access'); ?></th>
            <td>
                <?php wp_nonce_field('pc_wayforpay_save_user_access', 'pc_wayforpay_access_nonce'); ?>
                <label for="pc_wayforpay_test_access">
                    <input type="checkbox" id="pc_wayforpay_test_access" name="pc_wayforpay_test_access" value="yes" <?php checked($allowed); ?>>
                    <?php echo esc_html__('Show WayForPay to this user in restricted mode', 'pc-wayforpay-test-access'); ?>
                </label>
                <p class="description"><?php echo esc_html__('The user must sign in before opening checkout. Guests cannot use the test payment method.', 'pc-wayforpay-test-access'); ?></p>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'pc_wayforpay_render_user_access_field');
add_action('edit_user_profile', 'pc_wayforpay_render_user_access_field');

function pc_wayforpay_save_user_access(int $user_id): void
{
    if (!pc_wayforpay_access_can_manage() || !current_user_can('edit_user', $user_id)) {
        return;
    }

    $nonce = isset($_POST['pc_wayforpay_access_nonce'])
        ? sanitize_text_field(wp_unslash($_POST['pc_wayforpay_access_nonce']))
        : '';

    if ($nonce === '' || !wp_verify_nonce($nonce, 'pc_wayforpay_save_user_access')) {
        return;
    }

    $enabled = isset($_POST['pc_wayforpay_test_access'])
        && sanitize_text_field(wp_unslash($_POST['pc_wayforpay_test_access'])) === 'yes';

    if ($enabled) {
        update_user_meta($user_id, PC_WAYFORPAY_TEST_ACCESS_META, 'yes');
        return;
    }

    delete_user_meta($user_id, PC_WAYFORPAY_TEST_ACCESS_META);
}
add_action('personal_options_update', 'pc_wayforpay_save_user_access');
add_action('edit_user_profile_update', 'pc_wayforpay_save_user_access');
