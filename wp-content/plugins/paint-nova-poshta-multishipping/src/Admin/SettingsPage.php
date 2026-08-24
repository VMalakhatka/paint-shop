<?php

namespace Paint\NovaPoshta\Admin;

use Paint\NovaPoshta\Infrastructure\ApiClient;

defined('ABSPATH') || exit;

final class SettingsPage
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_pnpm_save_settings', [$this, 'save']);
        add_action('wp_ajax_pnpm_test_api', [$this, 'testApi']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Nova Poshta multishipping', 'paint-nova-poshta-multishipping'),
            __('Nova Poshta shipments', 'paint-nova-poshta-multishipping'),
            'manage_pnpm_shipments',
            'pnpm-settings',
            [$this, 'render']
        );
    }

    public function assets(string $hook): void
    {
        if (!str_contains($hook, 'pnpm-settings')) {
            return;
        }
        wp_enqueue_style('pnpm-admin', PNPM_URL . 'assets/admin.css', [], PNPM_VERSION);
        wp_enqueue_script('pnpm-admin', PNPM_URL . 'assets/admin.js', ['jquery'], PNPM_VERSION, true);
        wp_localize_script('pnpm-admin', 'pnpmAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pnpm_test_api'),
            'testing' => __('Checking connection...', 'paint-nova-poshta-multishipping'),
            'failed' => __('Connection check failed.', 'paint-nova-poshta-multishipping'),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_pnpm_shipments')) {
            wp_die(esc_html__('You are not allowed to manage Nova Poshta shipments.', 'paint-nova-poshta-multishipping'));
        }

        $mappings = get_option('pnpm_location_mappings', []);
        $mappings = is_array($mappings) ? $mappings : [];
        $locations = get_terms(['taxonomy' => 'location', 'hide_empty' => false]);
        $locations = is_wp_error($locations) ? [] : $locations;
        ?>
        <div class="wrap pnpm-admin">
            <h1><?php esc_html_e('Nova Poshta multishipping', 'paint-nova-poshta-multishipping'); ?></h1>
            <?php if (isset($_GET['pnpm-updated']) && $_GET['pnpm-updated'] === '1') : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php esc_html_e('Nova Poshta settings saved.', 'paint-nova-poshta-multishipping'); ?>
                </p></div>
            <?php endif; ?>
            <div class="notice notice-warning inline"><p>
                <?php esc_html_e('Real shipment creation is not implemented and API write operations are locked.', 'paint-nova-poshta-multishipping'); ?>
            </p></div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pnpm_save_settings">
                <?php wp_nonce_field('pnpm_save_settings'); ?>

                <section class="pnpm-section">
                    <h2><?php esc_html_e('API connection', 'paint-nova-poshta-multishipping'); ?></h2>
                    <p>
                        <?php esc_html_e('The API key is read only from PNPM_NOVA_POSHTA_API_KEY in wp-config.php or the server environment.', 'paint-nova-poshta-multishipping'); ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><?php esc_html_e('API endpoint', 'paint-nova-poshta-multishipping'); ?></th>
                            <td><code>https://api.novaposhta.ua/v2.0/json/</code></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('API key', 'paint-nova-poshta-multishipping'); ?></th>
                            <td>
                                <?php if ($this->api->apiKeyConfigured()) : ?>
                                    <code><?php echo esc_html($this->api->maskedApiKey()); ?></code>
                                <?php else : ?>
                                    <strong class="pnpm-bad"><?php esc_html_e('Not configured', 'paint-nova-poshta-multishipping'); ?></strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Write mode', 'paint-nova-poshta-multishipping'); ?></th>
                            <td><strong><?php esc_html_e('Locked', 'paint-nova-poshta-multishipping'); ?></strong></td>
                        </tr>
                    </table>
                    <button type="button" class="button" id="pnpm-test-api">
                        <?php esc_html_e('Check read-only API connection', 'paint-nova-poshta-multishipping'); ?>
                    </button>
                    <span id="pnpm-test-api-result" aria-live="polite"></span>
                </section>

                <section class="pnpm-section">
                    <h2><?php esc_html_e('Warehouse senders', 'paint-nova-poshta-multishipping'); ?></h2>
                    <p><?php esc_html_e('Map each WooCommerce Stock Location to a sender branch or sender address from the same Nova Poshta business account.', 'paint-nova-poshta-multishipping'); ?></p>
                    <div class="pnpm-table-wrap">
                        <table class="widefat striped pnpm-mappings">
                            <thead><tr>
                                <th><?php esc_html_e('Stock Location', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Enabled', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Sender type', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('City Ref', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Sender address/branch Ref', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Contact Ref', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Phone', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Customer label', 'paint-nova-poshta-multishipping'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($locations as $location) :
                                $id = (int) $location->term_id;
                                $mapping = is_array($mappings[$id] ?? null) ? $mappings[$id] : [];
                                $prefix = 'mapping[' . $id . ']';
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($location->name); ?></strong><br><code><?php echo esc_html((string) $id); ?></code></td>
                                    <td><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[enabled]" value="yes" <?php checked($mapping['enabled'] ?? 'no', 'yes'); ?>></td>
                                    <td>
                                        <select name="<?php echo esc_attr($prefix); ?>[sender_type]">
                                            <option value="warehouse" <?php selected($mapping['sender_type'] ?? '', 'warehouse'); ?>><?php esc_html_e('Branch / parcel locker', 'paint-nova-poshta-multishipping'); ?></option>
                                            <option value="doors" <?php selected($mapping['sender_type'] ?? '', 'doors'); ?>><?php esc_html_e('Sender address', 'paint-nova-poshta-multishipping'); ?></option>
                                        </select>
                                    </td>
                                    <?php foreach (['city_ref', 'sender_address_ref', 'contact_ref', 'phone', 'customer_label'] as $field) : ?>
                                        <td><input type="text" name="<?php echo esc_attr($prefix . '[' . $field . ']'); ?>" value="<?php echo esc_attr((string) ($mapping[$field] ?? '')); ?>"></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php submit_button(__('Save Nova Poshta settings', 'paint-nova-poshta-multishipping')); ?>
            </form>
        </div>
        <?php
    }

    public function save(): void
    {
        if (!current_user_can('manage_pnpm_shipments')) {
            wp_die(esc_html__('Permission denied.', 'paint-nova-poshta-multishipping'));
        }
        check_admin_referer('pnpm_save_settings');

        $raw = isset($_POST['mapping']) && is_array($_POST['mapping'])
            ? wp_unslash($_POST['mapping'])
            : [];
        $clean = [];
        foreach ($raw as $location_id => $mapping) {
            $location_id = absint($location_id);
            if ($location_id < 1 || !is_array($mapping) || !term_exists($location_id, 'location')) {
                continue;
            }
            $clean[$location_id] = [
                'enabled' => ($mapping['enabled'] ?? 'no') === 'yes' ? 'yes' : 'no',
                'sender_type' => in_array(($mapping['sender_type'] ?? ''), ['warehouse', 'doors'], true)
                    ? $mapping['sender_type'] : 'warehouse',
                'city_ref' => sanitize_text_field($mapping['city_ref'] ?? ''),
                'sender_address_ref' => sanitize_text_field($mapping['sender_address_ref'] ?? ''),
                'contact_ref' => sanitize_text_field($mapping['contact_ref'] ?? ''),
                'phone' => preg_replace('/[^0-9+]/', '', (string) ($mapping['phone'] ?? '')),
                'customer_label' => sanitize_text_field($mapping['customer_label'] ?? ''),
            ];
        }
        update_option('pnpm_location_mappings', $clean, false);

        wp_safe_redirect(add_query_arg([
            'page' => 'pnpm-settings',
            'pnpm-updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function testApi(): void
    {
        check_ajax_referer('pnpm_test_api', 'nonce');
        if (!current_user_can('manage_pnpm_shipments')) {
            wp_send_json_error(['message' => __('Permission denied.', 'paint-nova-poshta-multishipping')], 403);
        }

        $result = $this->api->call('Address', 'getCities', ['Limit' => 1]);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }
        if (($result['success'] ?? false) !== true) {
            $messages = array_merge((array) ($result['errors'] ?? []), (array) ($result['warnings'] ?? []));
            wp_send_json_error([
                'message' => $messages ? implode('; ', array_map('sanitize_text_field', $messages)) : __('Nova Poshta rejected the request.', 'paint-nova-poshta-multishipping'),
            ], 400);
        }

        wp_send_json_success(['message' => __('Read-only API connection is working.', 'paint-nova-poshta-multishipping')]);
    }
}
