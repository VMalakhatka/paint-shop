<?php

namespace Paint\NovaPoshta\Admin;

use Paint\NovaPoshta\Domain\DeliveryPolicy;
use Paint\NovaPoshta\Infrastructure\ApiClient;
use Paint\NovaPoshta\Infrastructure\SenderDirectory;
use Paint\NovaPoshta\Infrastructure\WarehouseDirectory;

defined('ABSPATH') || exit;

final class SettingsPage
{
    public function __construct(
        private readonly ApiClient $api,
        private readonly SenderDirectory $senders,
        private readonly WarehouseDirectory $warehouses
    ) {
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_pnpm_save_settings', [$this, 'save']);
        add_action('wp_ajax_pnpm_test_api', [$this, 'testApi']);
        add_action('wp_ajax_pnpm_load_senders', [$this, 'loadSenders']);
        add_action('wp_ajax_pnpm_search_handover_points', [$this, 'searchHandoverPoints']);
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
            'chooseHandoverPoint' => __('Choose the Nova Poshta handover point', 'paint-nova-poshta-multishipping'),
            'handoverQueryRequired' => __('Enter a branch number, parcel locker number, street, or landmark.', 'paint-nova-poshta-multishipping'),
            'loadingHandoverPoints' => __('Searching Nova Poshta handover points...', 'paint-nova-poshta-multishipping'),
            'handoverPointsFailed' => __('Nova Poshta handover points could not be loaded.', 'paint-nova-poshta-multishipping'),
            'handoverPointsEmpty' => __('No available handover points were found.', 'paint-nova-poshta-multishipping'),
            'handoverNotRequired' => __('Not required: Nova Poshta courier collects parcels from the registered address.', 'paint-nova-poshta-multishipping'),
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
        $roles = $this->availableRoles();
        $delivery_policy = DeliveryPolicy::load(array_keys($roles));
        $checkout_settings = wp_parse_args((array) get_option('pnpm_settings', []), [
            'checkout_enabled' => 'yes',
            'weight_mode' => 'grams',
            'fallback_item_weight_kg' => 0.25,
            'minimum_declared_cost' => 500,
            'parcel_locker_surcharge' => 10,
        ]);
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
                    <p><?php esc_html_e('Map each WooCommerce Stock Location to a registered sender address. For self-drop-off, also choose the exact Nova Poshta branch or parcel locker where parcels will be handed over.', 'paint-nova-poshta-multishipping'); ?></p>
                    <div class="pnpm-table-wrap">
                        <table class="widefat striped pnpm-mappings">
                            <thead><tr>
                                <th><?php esc_html_e('Stock Location', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Enabled', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Sender type', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Business sender', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Registered sender address', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Nova Poshta handover point', 'paint-nova-poshta-multishipping'); ?></th>
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
                                $sender_type = in_array(($mapping['sender_type'] ?? ''), ['warehouse', 'doors'], true)
                                    ? $mapping['sender_type'] : 'warehouse';
                                $required = ['counterparty_ref', 'city_ref', 'sender_address_ref', 'contact_ref', 'phone', 'customer_label'];
                                if ($sender_type === 'warehouse') {
                                    $required[] = 'handover_warehouse_ref';
                                }
                                $enabled = ($mapping['enabled'] ?? 'no') === 'yes';
                                $handover_ref = trim((string) ($mapping['handover_warehouse_ref'] ?? ''));
                                $handover_label = trim((string) ($mapping['handover_warehouse_label'] ?? ''));
                                if ($handover_label === '') {
                                    $handover_label = __('Search and choose a handover point', 'paint-nova-poshta-multishipping');
                                }
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
                                        <select class="pnpm-sender-type" name="<?php echo esc_attr($prefix); ?>[sender_type]">
                                            <option value="warehouse" <?php selected($sender_type, 'warehouse'); ?>><?php esc_html_e('Drop off at a branch / parcel locker', 'paint-nova-poshta-multishipping'); ?></option>
                                            <option value="doors" <?php selected($sender_type, 'doors'); ?>><?php esc_html_e('Courier pickup from sender address', 'paint-nova-poshta-multishipping'); ?></option>
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
                                    <td class="pnpm-handover-cell">
                                        <div class="pnpm-handover-controls">
                                            <input type="search" class="pnpm-handover-query" placeholder="<?php echo esc_attr__('Branch number or address', 'paint-nova-poshta-multishipping'); ?>">
                                            <button type="button" class="button pnpm-search-handover" title="<?php echo esc_attr__('Search handover points', 'paint-nova-poshta-multishipping'); ?>">
                                                <?php esc_html_e('Search', 'paint-nova-poshta-multishipping'); ?>
                                            </button>
                                        </div>
                                        <select class="pnpm-handover-select" data-current="<?php echo esc_attr($handover_ref); ?>">
                                            <option value="<?php echo esc_attr($handover_ref); ?>">
                                                <?php echo esc_html($handover_label); ?>
                                            </option>
                                        </select>
                                        <small class="pnpm-handover-help"></small>
                                        <?php $this->technicalRefInput($prefix, 'handover_warehouse_ref', $mapping); ?>
                                        <input
                                            type="hidden"
                                            class="pnpm-handover-warehouse-label"
                                            name="<?php echo esc_attr($prefix); ?>[handover_warehouse_label]"
                                            value="<?php echo esc_attr((string) ($mapping['handover_warehouse_label'] ?? '')); ?>"
                                        >
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

                <?php $this->renderCheckoutSettings($checkout_settings); ?>
                <?php $this->renderDeliveryPolicy($delivery_policy, $roles); ?>

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
                'handover_warehouse_ref' => sanitize_text_field($mapping['handover_warehouse_ref'] ?? ''),
                'handover_warehouse_label' => sanitize_text_field($mapping['handover_warehouse_label'] ?? ''),
                'contact_ref' => sanitize_text_field($mapping['contact_ref'] ?? ''),
                'phone' => preg_replace('/[^0-9+]/', '', (string) ($mapping['phone'] ?? '')),
                'customer_label' => sanitize_text_field($mapping['customer_label'] ?? ''),
            ];
        }
        update_option('pnpm_location_mappings', $clean, false);

        $available_roles = array_keys($this->availableRoles());
        $raw_policy = isset($_POST['delivery_policy']) && is_array($_POST['delivery_policy'])
            ? wp_unslash($_POST['delivery_policy'])
            : [];
        update_option(
            DeliveryPolicy::OPTION_NAME,
            DeliveryPolicy::sanitize($raw_policy, $available_roles),
            false
        );

        $stored_settings = get_option('pnpm_settings', []);
        $stored_settings = is_array($stored_settings) ? $stored_settings : [];
        $raw_checkout = isset($_POST['checkout_settings']) && is_array($_POST['checkout_settings'])
            ? wp_unslash($_POST['checkout_settings'])
            : [];
        $stored_settings['checkout_enabled'] = ($raw_checkout['checkout_enabled'] ?? 'no') === 'yes' ? 'yes' : 'no';
        $stored_settings['weight_mode'] = ($raw_checkout['weight_mode'] ?? 'grams') === 'woocommerce' ? 'woocommerce' : 'grams';
        $stored_settings['fallback_item_weight_kg'] = max(0.01, (float) ($raw_checkout['fallback_item_weight_kg'] ?? 0.25));
        $stored_settings['minimum_declared_cost'] = max(1.0, (float) ($raw_checkout['minimum_declared_cost'] ?? 500));
        $stored_settings['parcel_locker_surcharge'] = max(0.0, (float) ($raw_checkout['parcel_locker_surcharge'] ?? 10));
        update_option('pnpm_settings', $stored_settings, false);

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

    public function searchHandoverPoints(): void
    {
        check_ajax_referer('pnpm_test_api', 'nonce');
        if (!current_user_can('manage_pnpm_shipments')) {
            wp_send_json_error(['message' => __('Permission denied.', 'paint-nova-poshta-multishipping')], 403);
        }

        $city_ref = sanitize_text_field(wp_unslash((string) ($_POST['cityRef'] ?? '')));
        $query = sanitize_text_field(wp_unslash((string) ($_POST['query'] ?? '')));
        $result = $this->warehouses->search($city_ref, $query);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success([
            'points' => $result,
            'message' => sprintf(
                /* translators: %d: handover point count. */
                _n('%d handover point found.', '%d handover points found.', count($result), 'paint-nova-poshta-multishipping'),
                count($result)
            ),
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

    /** @return array<string,string> */
    private function availableRoles(): array
    {
        $roles = [
            'guest' => __('Guest customer', 'paint-nova-poshta-multishipping'),
        ];
        $registered_roles = wp_roles()->roles;
        $customer_role_slugs = apply_filters('pnpm_delivery_customer_roles', [
            'customer',
            'subscriber',
            'internet_client',
            'partner',
            'opt',
            'opt_osn',
            'schule',
        ]);
        foreach ((array) $customer_role_slugs as $slug) {
            $slug = sanitize_key((string) $slug);
            if ($slug !== '' && isset($registered_roles[$slug])) {
                $roles[$slug] = translate_user_role((string) ($registered_roles[$slug]['name'] ?? $slug));
            }
        }

        return $roles;
    }

    /** @param array<string,mixed> $policy
     *  @param array<string,string> $roles
     */
    private function renderDeliveryPolicy(array $policy, array $roles): void
    {
        $segments = is_array($policy['role_segments'] ?? null) ? $policy['role_segments'] : [];
        $profiles = is_array($policy['profiles'] ?? null) ? $policy['profiles'] : [];
        ?>
        <section class="pnpm-section pnpm-delivery-policy">
            <h2><?php esc_html_e('Delivery payment and loyalty policy', 'paint-nova-poshta-multishipping'); ?></h2>
            <div class="notice notice-info inline"><p>
                <?php esc_html_e('These rules are applied when the customer selects Nova Poshta delivery at checkout.', 'paint-nova-poshta-multishipping'); ?>
            </p></div>

            <h3><?php esc_html_e('Customer groups', 'paint-nova-poshta-multishipping'); ?></h3>
            <p><?php esc_html_e('Assign storefront roles to the retail or partner policy. Unassigned roles use the retail policy.', 'paint-nova-poshta-multishipping'); ?></p>
            <div class="pnpm-table-wrap">
                <table class="widefat striped pnpm-role-policy">
                    <thead><tr>
                        <th><?php esc_html_e('WordPress role', 'paint-nova-poshta-multishipping'); ?></th>
                        <th><?php esc_html_e('Delivery policy', 'paint-nova-poshta-multishipping'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($roles as $role_slug => $role_name) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($role_name); ?></strong><br><code><?php echo esc_html($role_slug); ?></code></td>
                            <td>
                                <select name="delivery_policy[role_segments][<?php echo esc_attr($role_slug); ?>]">
                                    <option value="" <?php selected($segments[$role_slug] ?? '', ''); ?>><?php esc_html_e('Use retail fallback', 'paint-nova-poshta-multishipping'); ?></option>
                                    <option value="retail" <?php selected($segments[$role_slug] ?? '', 'retail'); ?>><?php esc_html_e('Retail customers', 'paint-nova-poshta-multishipping'); ?></option>
                                    <option value="partner" <?php selected($segments[$role_slug] ?? '', 'partner'); ?>><?php esc_html_e('Partners and wholesale customers', 'paint-nova-poshta-multishipping'); ?></option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php foreach (['retail', 'partner'] as $profile_key) :
                $profile = is_array($profiles[$profile_key] ?? null) ? $profiles[$profile_key] : [];
                $threshold = (float) ($profile['threshold'] ?? 0);
                $components = is_array($profile['components'] ?? null) ? $profile['components'] : [];
                $cod = is_array($profile['cod'] ?? null) ? $profile['cod'] : [];
                ?>
                <div class="pnpm-policy-profile">
                    <h3>
                        <?php echo esc_html($profile_key === 'retail'
                            ? __('Retail customer policy', 'paint-nova-poshta-multishipping')
                            : __('Partner and wholesale policy', 'paint-nova-poshta-multishipping')); ?>
                    </h3>

                    <div class="pnpm-threshold-field">
                        <label for="pnpm-<?php echo esc_attr($profile_key); ?>-threshold">
                            <strong><?php esc_html_e('Order threshold', 'paint-nova-poshta-multishipping'); ?></strong>
                        </label>
                        <input
                            id="pnpm-<?php echo esc_attr($profile_key); ?>-threshold"
                            type="number"
                            min="0"
                            step="0.01"
                            name="delivery_policy[profiles][<?php echo esc_attr($profile_key); ?>][threshold]"
                            value="<?php echo esc_attr((string) $threshold); ?>"
                        >
                        <span><?php echo esc_html(get_woocommerce_currency_symbol()); ?></span>
                        <p class="description"><?php esc_html_e('The order merchandise total after discounts is used; shipping and fees are excluded.', 'paint-nova-poshta-multishipping'); ?></p>
                    </div>

                    <h4><?php esc_html_e('Store delivery allowance', 'paint-nova-poshta-multishipping'); ?></h4>
                    <div class="pnpm-table-wrap">
                        <table class="widefat striped pnpm-policy-tiers">
                            <thead><tr>
                                <th><?php esc_html_e('Order range', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Allowance calculation', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Value', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Maximum store contribution', 'paint-nova-poshta-multishipping'); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php $this->renderPolicyTier($profile_key, 'below', $profile['below'] ?? [], __('Below the threshold', 'paint-nova-poshta-multishipping')); ?>
                                <?php $this->renderPolicyTier($profile_key, 'above', $profile['above'] ?? [], __('At or above the threshold', 'paint-nova-poshta-multishipping')); ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="description"><?php esc_html_e('A zero maximum means no additional cap. Percentage values are entered as a number, for example 3 for three percent.', 'paint-nova-poshta-multishipping'); ?></p>

                    <h4><?php esc_html_e('Who pays each delivery component', 'paint-nova-poshta-multishipping'); ?></h4>
                    <div class="pnpm-table-wrap">
                        <table class="widefat striped pnpm-policy-components">
                            <thead><tr>
                                <th><?php esc_html_e('Delivery component', 'paint-nova-poshta-multishipping'); ?></th>
                                <th><?php esc_html_e('Payment rule', 'paint-nova-poshta-multishipping'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach (DeliveryPolicy::componentLabels() as $component_key => $label) : ?>
                                <tr>
                                    <td><?php echo esc_html($label); ?></td>
                                    <td>
                                        <select name="delivery_policy[profiles][<?php echo esc_attr($profile_key); ?>][components][<?php echo esc_attr($component_key); ?>]">
                                            <option value="customer" <?php selected($components[$component_key] ?? 'customer', 'customer'); ?>><?php esc_html_e('Customer pays', 'paint-nova-poshta-multishipping'); ?></option>
                                            <option value="store" <?php selected($components[$component_key] ?? 'customer', 'store'); ?>><?php esc_html_e('Store pays in full', 'paint-nova-poshta-multishipping'); ?></option>
                                            <option value="budget" <?php selected($components[$component_key] ?? 'customer', 'budget'); ?>><?php esc_html_e('Use the store allowance, customer pays the remainder', 'paint-nova-poshta-multishipping'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h4><?php esc_html_e('Cash on delivery', 'paint-nova-poshta-multishipping'); ?></h4>
                    <div class="pnpm-cod-policy">
                        <label>
                            <input type="hidden" name="delivery_policy[profiles][<?php echo esc_attr($profile_key); ?>][cod][single_shipment]" value="no">
                            <input type="checkbox" name="delivery_policy[profiles][<?php echo esc_attr($profile_key); ?>][cod][single_shipment]" value="yes" <?php checked($cod['single_shipment'] ?? 'no', 'yes'); ?>>
                            <?php esc_html_e('Allow cash on delivery for a single shipment', 'paint-nova-poshta-multishipping'); ?>
                        </label>
                        <label>
                            <input type="hidden" name="delivery_policy[profiles][<?php echo esc_attr($profile_key); ?>][cod][multi_shipment]" value="no">
                            <input type="checkbox" name="delivery_policy[profiles][<?php echo esc_attr($profile_key); ?>][cod][multi_shipment]" value="yes" <?php checked($cod['multi_shipment'] ?? 'no', 'yes'); ?>>
                            <?php esc_html_e('Allow cash on delivery for orders from multiple warehouses', 'paint-nova-poshta-multishipping'); ?>
                        </label>
                        <label>
                            <span><?php esc_html_e('Cash on delivery commission', 'paint-nova-poshta-multishipping'); ?></span>
                            <select name="delivery_policy[profiles][<?php echo esc_attr($profile_key); ?>][cod][fee_payer]">
                                <option value="customer" <?php selected($cod['fee_payer'] ?? 'customer', 'customer'); ?>><?php esc_html_e('Customer pays', 'paint-nova-poshta-multishipping'); ?></option>
                                <option value="store" <?php selected($cod['fee_payer'] ?? 'customer', 'store'); ?>><?php esc_html_e('Store pays in full', 'paint-nova-poshta-multishipping'); ?></option>
                                <option value="budget" <?php selected($cod['fee_payer'] ?? 'customer', 'budget'); ?>><?php esc_html_e('Use the store allowance, customer pays the remainder', 'paint-nova-poshta-multishipping'); ?></option>
                            </select>
                        </label>
                    </div>
                    <p class="description"><?php esc_html_e('When multi-shipment cash on delivery is disabled, checkout must offer prepayment methods instead.', 'paint-nova-poshta-multishipping'); ?></p>
                </div>
            <?php endforeach; ?>
        </section>
        <?php
    }

    /** @param array<string,mixed> $settings */
    private function renderCheckoutSettings(array $settings): void
    {
        ?>
        <section class="pnpm-section pnpm-checkout-settings">
            <h2><?php esc_html_e('Checkout calculation', 'paint-nova-poshta-multishipping'); ?></h2>
            <p><?php esc_html_e('The checkout uses the official Nova Poshta tariff API in read-only mode. These settings do not create shipments or TTNs.', 'paint-nova-poshta-multishipping'); ?></p>
            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e('Customer checkout', 'paint-nova-poshta-multishipping'); ?></th>
                    <td>
                        <input type="hidden" name="checkout_settings[checkout_enabled]" value="no">
                        <label><input type="checkbox" name="checkout_settings[checkout_enabled]" value="yes" <?php checked($settings['checkout_enabled'] ?? 'yes', 'yes'); ?>>
                            <?php esc_html_e('Enable Nova Poshta fields and tariff calculation in classic checkout', 'paint-nova-poshta-multishipping'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="pnpm-weight-mode"><?php esc_html_e('Product weight storage', 'paint-nova-poshta-multishipping'); ?></label></th>
                    <td>
                        <select id="pnpm-weight-mode" name="checkout_settings[weight_mode]">
                            <option value="grams" <?php selected($settings['weight_mode'] ?? 'grams', 'grams'); ?>><?php esc_html_e('Existing product values are grams', 'paint-nova-poshta-multishipping'); ?></option>
                            <option value="woocommerce" <?php selected($settings['weight_mode'] ?? 'grams', 'woocommerce'); ?>><?php esc_html_e('Use the WooCommerce weight unit', 'paint-nova-poshta-multishipping'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('The current Lavka catalogue usually stores gram values in the product weight field.', 'paint-nova-poshta-multishipping'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="pnpm-fallback-weight"><?php esc_html_e('Fallback item weight', 'paint-nova-poshta-multishipping'); ?></label></th>
                    <td><input id="pnpm-fallback-weight" type="number" min="0.01" step="0.01" name="checkout_settings[fallback_item_weight_kg]" value="<?php echo esc_attr((string) ($settings['fallback_item_weight_kg'] ?? 0.25)); ?>"> kg</td>
                </tr>
                <tr>
                    <th><label for="pnpm-minimum-declared"><?php esc_html_e('Declared value comparison threshold', 'paint-nova-poshta-multishipping'); ?></label></th>
                    <td><input id="pnpm-minimum-declared" type="number" min="1" step="1" name="checkout_settings[minimum_declared_cost]" value="<?php echo esc_attr((string) ($settings['minimum_declared_cost'] ?? 500)); ?>"> <?php echo esc_html(get_woocommerce_currency_symbol()); ?></td>
                </tr>
                <tr>
                    <th><label for="pnpm-locker-surcharge"><?php esc_html_e('Parcel locker surcharge', 'paint-nova-poshta-multishipping'); ?></label></th>
                    <td><input id="pnpm-locker-surcharge" type="number" min="0" step="0.01" name="checkout_settings[parcel_locker_surcharge]" value="<?php echo esc_attr((string) ($settings['parcel_locker_surcharge'] ?? 10)); ?>"> <?php echo esc_html(get_woocommerce_currency_symbol()); ?></td>
                </tr>
            </table>
        </section>
        <?php
    }

    /** @param array<string,mixed> $tier */
    private function renderPolicyTier(string $profile_key, string $tier_key, array $tier, string $label): void
    {
        $prefix = 'delivery_policy[profiles][' . $profile_key . '][' . $tier_key . ']';
        ?>
        <tr>
            <td><strong><?php echo esc_html($label); ?></strong></td>
            <td>
                <select name="<?php echo esc_attr($prefix); ?>[mode]">
                    <option value="customer" <?php selected($tier['mode'] ?? 'customer', 'customer'); ?>><?php esc_html_e('No allowance; customer pays', 'paint-nova-poshta-multishipping'); ?></option>
                    <option value="store" <?php selected($tier['mode'] ?? 'customer', 'store'); ?>><?php esc_html_e('Store pays all selected components', 'paint-nova-poshta-multishipping'); ?></option>
                    <option value="fixed" <?php selected($tier['mode'] ?? 'customer', 'fixed'); ?>><?php esc_html_e('Fixed amount', 'paint-nova-poshta-multishipping'); ?></option>
                    <option value="order_percent" <?php selected($tier['mode'] ?? 'customer', 'order_percent'); ?>><?php esc_html_e('Percentage of merchandise total', 'paint-nova-poshta-multishipping'); ?></option>
                    <option value="delivery_percent" <?php selected($tier['mode'] ?? 'customer', 'delivery_percent'); ?>><?php esc_html_e('Percentage of selected delivery components', 'paint-nova-poshta-multishipping'); ?></option>
                </select>
            </td>
            <td><input type="number" min="0" step="0.01" name="<?php echo esc_attr($prefix); ?>[value]" value="<?php echo esc_attr((string) ($tier['value'] ?? 0)); ?>"></td>
            <td><input type="number" min="0" step="0.01" name="<?php echo esc_attr($prefix); ?>[cap]" value="<?php echo esc_attr((string) ($tier['cap'] ?? 0)); ?>"></td>
        </tr>
        <?php
    }
}
