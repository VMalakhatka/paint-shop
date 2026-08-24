<?php

namespace Paint\NovaPoshta\Admin;

use Paint\NovaPoshta\Infrastructure\ApiClient;
use Paint\NovaPoshta\Infrastructure\SenderDirectory;

defined('ABSPATH') || exit;

final class SettingsPage
{
    public function __construct(
        private readonly ApiClient $api,
        private readonly SenderDirectory $senders
    ) {
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_pnpm_save_settings', [$this, 'save']);
        add_action('wp_ajax_pnpm_test_api', [$this, 'testApi']);
        add_action('wp_ajax_pnpm_load_senders', [$this, 'loadSenders']);
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
            'loadingSenders' => __('Loading sender profile...', 'paint-nova-poshta-multishipping'),
            'sendersFailed' => __('Sender profile could not be loaded.', 'paint-nova-poshta-multishipping'),
            'chooseSender' => __('Choose a business sender', 'paint-nova-poshta-multishipping'),
            'chooseAddress' => __('Choose a sender address', 'paint-nova-poshta-multishipping'),
            'chooseContact' => __('Choose a contact person', 'paint-nova-poshta-multishipping'),
            'mappingReady' => __('Ready', 'paint-nova-poshta-multishipping'),
            'mappingIncomplete' => __('Incomplete', 'paint-nova-poshta-multishipping'),
            'mappingDisabled' => __('Disabled', 'paint-nova-poshta-multishipping'),
            'savedRef' => __('Saved Ref', 'paint-nova-poshta-multishipping'),
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
                    <p>
                        <button type="button" class="button button-secondary" id="pnpm-load-senders">
                            <?php esc_html_e('Load senders from Nova Poshta', 'paint-nova-poshta-multishipping'); ?>
                        </button>
                        <span id="pnpm-load-senders-result" aria-live="polite"></span>
                    </p>
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
                                <th><?php esc_html_e('Business sender', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Sender address or branch', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Contact person', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Phone', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Customer label', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Status', 'paint-nova-poshta-multishipping'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($locations as $location) :
                                $id = (int) $location->term_id;
                                $mapping = is_array($mappings[$id] ?? null) ? $mappings[$id] : [];
                                $prefix = 'mapping[' . $id . ']';
                                $required = ['counterparty_ref', 'city_ref', 'sender_address_ref', 'contact_ref', 'phone', 'customer_label'];
                                $enabled = ($mapping['enabled'] ?? 'no') === 'yes';
                                $ready = false;
                                if ($enabled) {
                                    $ready = true;
                                    foreach ($required as $required_field) {
                                        if (trim((string) ($mapping[$required_field] ?? '')) === '') {
                                            $ready = false;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <tr class="pnpm-mapping-row" data-location-id="<?php echo esc_attr((string) $id); ?>">
                                    <td><strong><?php echo esc_html($location->name); ?></strong><br><code><?php echo esc_html((string) $id); ?></code></td>
                                    <td><input type="checkbox" class="pnpm-enabled" name="<?php echo esc_attr($prefix); ?>[enabled]" value="yes" <?php checked($mapping['enabled'] ?? 'no', 'yes'); ?>></td>
                                    <td>
                                        <select name="<?php echo esc_attr($prefix); ?>[sender_type]">
                                            <option value="warehouse" <?php selected($mapping['sender_type'] ?? '', 'warehouse'); ?>><?php esc_html_e('Branch / parcel locker', 'paint-nova-poshta-multishipping'); ?></option>
                                            <option value="doors" <?php selected($mapping['sender_type'] ?? '', 'doors'); ?>><?php esc_html_e('Sender address', 'paint-nova-poshta-multishipping'); ?></option>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="pnpm-counterparty-select" data-current="<?php echo esc_attr((string) ($mapping['counterparty_ref'] ?? '')); ?>">
                                            <option value=""><?php esc_html_e('Load senders first', 'paint-nova-poshta-multishipping'); ?></option>
                                        </select>
                                        <?php $this->technicalRefInput($prefix, 'counterparty_ref', $mapping); ?>
                                    </td>
                                    <td>
                                        <select class="pnpm-address-select" data-current="<?php echo esc_attr((string) ($mapping['sender_address_ref'] ?? '')); ?>">
                                            <option value=""><?php esc_html_e('Load senders first', 'paint-nova-poshta-multishipping'); ?></option>
                                        </select>
                                        <small class="pnpm-city-label"></small>
                                        <?php $this->technicalRefInput($prefix, 'sender_address_ref', $mapping); ?>
                                        <?php $this->technicalRefInput($prefix, 'city_ref', $mapping); ?>
                                    </td>
                                    <td>
                                        <select class="pnpm-contact-select" data-current="<?php echo esc_attr((string) ($mapping['contact_ref'] ?? '')); ?>">
                                            <option value=""><?php esc_html_e('Load senders first', 'paint-nova-poshta-multishipping'); ?></option>
                                        </select>
                                        <?php $this->technicalRefInput($prefix, 'contact_ref', $mapping); ?>
                                    </td>
                                    <td><input type="text" class="pnpm-phone" name="<?php echo esc_attr($prefix); ?>[phone]" value="<?php echo esc_attr((string) ($mapping['phone'] ?? '')); ?>"></td>
                                    <td><input type="text" class="pnpm-customer-label" name="<?php echo esc_attr($prefix); ?>[customer_label]" value="<?php echo esc_attr((string) ($mapping['customer_label'] ?? '')); ?>"></td>
                                    <td><strong class="pnpm-mapping-status <?php echo $enabled && !$ready ? 'pnpm-bad' : 'pnpm-good'; ?>"><?php echo esc_html(!$enabled ? __('Disabled', 'paint-nova-poshta-multishipping') : ($ready ? __('Ready', 'paint-nova-poshta-multishipping') : __('Incomplete', 'paint-nova-poshta-multishipping'))); ?></strong></td>
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
                'counterparty_ref' => sanitize_text_field($mapping['counterparty_ref'] ?? ''),
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

    public function loadSenders(): void
    {
        check_ajax_referer('pnpm_test_api', 'nonce');
        if (!current_user_can('manage_pnpm_shipments')) {
            wp_send_json_error(['message' => __('Permission denied.', 'paint-nova-poshta-multishipping')], 403);
        }

        $refresh = isset($_POST['refresh']) && $_POST['refresh'] === 'yes';
        $result = $this->senders->load($refresh);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: sender count. */
                _n('%d sender profile loaded.', '%d sender profiles loaded.', count($result['counterparties']), 'paint-nova-poshta-multishipping'),
                count($result['counterparties'])
            ),
            'directory' => $result,
        ]);
    }

    /** @param array<string,mixed> $mapping */
    private function technicalRefInput(string $prefix, string $field, array $mapping): void
    {
        ?>
        <details class="pnpm-technical-ref">
            <summary><?php esc_html_e('Technical Ref', 'paint-nova-poshta-multishipping'); ?></summary>
            <input
                type="text"
                class="pnpm-ref-input pnpm-<?php echo esc_attr(str_replace('_', '-', $field)); ?>"
                name="<?php echo esc_attr($prefix . '[' . $field . ']'); ?>"
                value="<?php echo esc_attr((string) ($mapping[$field] ?? '')); ?>"
            >
        </details>
        <?php
    }
}
