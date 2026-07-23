<?php
/**
 * Plugin Name: PC Folio Customer Map
 * Description: Adds Folio customer mapping fields to WordPress user profiles.
 * Author: Volodymyr
 * Version: 0.1.0
 */

defined('ABSPATH') || exit;

const PC_FOLIO_PARTNER_META_ID         = '_folio_partner_id';
const PC_FOLIO_PARTNER_META_SHORT_NAME = '_folio_partner_short_name';
const PC_FOLIO_PARTNER_META_NAME       = '_folio_partner_name';
const PC_FOLIO_PARTNER_META_TYPE       = '_folio_partner_type';

function pc_folio_customer_map_can_manage(): bool {
    return current_user_can('edit_users') || current_user_can('manage_woocommerce');
}

function pc_folio_customer_map_target_user_id(): int {
    if (isset($_GET['user_id'])) {
        return max(0, (int) $_GET['user_id']);
    }
    return get_current_user_id();
}

function pc_folio_customer_map_get_value(int $user_id): array {
    return [
        'id'        => (string) get_user_meta($user_id, PC_FOLIO_PARTNER_META_ID, true),
        'shortName' => (string) get_user_meta($user_id, PC_FOLIO_PARTNER_META_SHORT_NAME, true),
        'name'      => (string) get_user_meta($user_id, PC_FOLIO_PARTNER_META_NAME, true),
        'type'      => (string) get_user_meta($user_id, PC_FOLIO_PARTNER_META_TYPE, true),
    ];
}

function pc_folio_customer_map_type_label(string $type): string {
    $labels = [
        'Я' => 'Own organization',
        'П' => 'Partner',
        'Д' => 'Dealer',
        'К' => 'Customer',
        'Т' => 'Supplier',
        'I' => 'Foreign supplier',
    ];
    return $labels[$type] ?? $type;
}

function pc_folio_customer_map_admin_footer(): void {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $screen_id = $screen ? (string) $screen->id : '';

    if (!in_array($screen_id, ['profile', 'user-edit'], true)) {
        return;
    }

    if (!pc_folio_customer_map_can_manage()) {
        return;
    }

    $user_id = pc_folio_customer_map_target_user_id();
    if ($user_id <= 0 || !current_user_can('edit_user', $user_id)) {
        return;
    }

    $value = pc_folio_customer_map_get_value($user_id);
    $nonce = wp_create_nonce('pc_folio_partner_search');
    ?>
    <script>
    (function(){
        var current = <?php echo wp_json_encode($value); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        var timer = null;

        function esc(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
            });
        }

        function labelFor(item) {
            var parts = [];
            if (item.shortName) parts.push(item.shortName);
            if (item.name && item.name !== item.shortName) parts.push(item.name);
            if (item.typeLabel || item.type) parts.push('[' + (item.typeLabel || item.type) + ']');
            return parts.join(' - ');
        }

        function setPartner(item) {
            document.getElementById('pc_folio_partner_id').value = item.id || '';
            document.getElementById('pc_folio_partner_short_name').value = item.shortName || '';
            document.getElementById('pc_folio_partner_name').value = item.name || '';
            document.getElementById('pc_folio_partner_type').value = item.type || '';
            document.getElementById('pc-folio-partner-current').innerHTML = item.id
                ? esc(labelFor(item))
                : '<em><?php echo esc_js(__('Not selected', 'pc-folio-customer-map')); ?></em>';
            document.getElementById('pc-folio-partner-results').innerHTML = '';
        }

        function renderResults(items) {
            var box = document.getElementById('pc-folio-partner-results');
            if (!items || !items.length) {
                box.innerHTML = '<p class="description"><?php echo esc_js(__('No Folio clients found.', 'pc-folio-customer-map')); ?></p>';
                return;
            }
            box.innerHTML = items.map(function(item, index) {
                return '<button type="button" class="button pc-folio-partner-pick" data-index="'+index+'">'
                    + esc(labelFor(item))
                    + '</button>';
            }).join(' ');
            box.querySelectorAll('.pc-folio-partner-pick').forEach(function(btn){
                btn.addEventListener('click', function(){
                    setPartner(items[parseInt(btn.getAttribute('data-index'), 10)] || {});
                });
            });
        }

        function searchPartners(query) {
            var box = document.getElementById('pc-folio-partner-results');
            if (!query || query.length < 2) {
                box.innerHTML = '';
                return;
            }
            box.innerHTML = '<p class="description"><?php echo esc_js(__('Searching...', 'pc-folio-customer-map')); ?></p>';

            var body = new URLSearchParams();
            body.set('action', 'pc_folio_partner_search');
            body.set('_ajax_nonce', nonce);
            body.set('q', query);
            body.set('types', 'П,Д,К');
            body.set('limit', '20');

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            })
            .then(function(resp){ return resp.json(); })
            .then(function(resp){
                if (!resp || !resp.success) {
                    var msg = resp && resp.data && resp.data.message ? resp.data.message : '<?php echo esc_js(__('Folio client search failed.', 'pc-folio-customer-map')); ?>';
                    box.innerHTML = '<p class="description" style="color:#b32d2e">'+esc(msg)+'</p>';
                    return;
                }
                renderResults(resp.data.items || []);
            })
            .catch(function(){
                box.innerHTML = '<p class="description" style="color:#b32d2e"><?php echo esc_js(__('Connection error.', 'pc-folio-customer-map')); ?></p>';
            });
        }

        function insertRow() {
            var anchor = document.querySelector('tr.user-user-login-wrap') || (document.getElementById('user_login') ? document.getElementById('user_login').closest('tr') : null);
            if (!anchor || document.getElementById('pc-folio-partner-row')) {
                return;
            }

            var row = document.createElement('tr');
            row.id = 'pc-folio-partner-row';
            row.innerHTML =
                '<th><label for="pc-folio-partner-search"><?php echo esc_js(__('Folio client', 'pc-folio-customer-map')); ?></label></th>' +
                '<td>' +
                    '<input type="hidden" name="pc_folio_partner_nonce" value="<?php echo esc_attr(wp_create_nonce('pc_folio_partner_save')); ?>">' +
                    '<input type="hidden" id="pc_folio_partner_id" name="pc_folio_partner_id">' +
                    '<input type="hidden" id="pc_folio_partner_short_name" name="pc_folio_partner_short_name">' +
                    '<input type="hidden" id="pc_folio_partner_name" name="pc_folio_partner_name">' +
                    '<input type="hidden" id="pc_folio_partner_type" name="pc_folio_partner_type">' +
                    '<p id="pc-folio-partner-current" style="margin:0 0 8px"></p>' +
                    '<input type="search" id="pc-folio-partner-search" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr__('Search Folio client...', 'pc-folio-customer-map'); ?>">' +
                    ' <button type="button" class="button" id="pc-folio-partner-clear"><?php echo esc_js(__('Clear', 'pc-folio-customer-map')); ?></button>' +
                    '<div id="pc-folio-partner-results" style="margin-top:8px"></div>' +
                    '<p class="description"><?php echo esc_js(__('Searches Folio partners, dealers, and customers. The selected client will be used for Folio account preview.', 'pc-folio-customer-map')); ?></p>' +
                '</td>';

            anchor.parentNode.insertBefore(row, anchor.nextSibling);
            setPartner(current);

            document.getElementById('pc-folio-partner-search').addEventListener('input', function(){
                var query = this.value.trim();
                clearTimeout(timer);
                timer = setTimeout(function(){ searchPartners(query); }, 300);
            });
            document.getElementById('pc-folio-partner-clear').addEventListener('click', function(){
                setPartner({});
                document.getElementById('pc-folio-partner-search').value = '';
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', insertRow);
        } else {
            insertRow();
        }
    })();
    </script>
    <style>
      #pc-folio-partner-results .button { margin: 0 4px 4px 0; text-align: left; white-space: normal; }
    </style>
    <?php
}
add_action('admin_footer', 'pc_folio_customer_map_admin_footer');

function pc_folio_customer_map_save(int $user_id): void {
    if (!pc_folio_customer_map_can_manage() || !current_user_can('edit_user', $user_id)) {
        return;
    }

    $nonce = isset($_POST['pc_folio_partner_nonce']) ? (string) $_POST['pc_folio_partner_nonce'] : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'pc_folio_partner_save')) {
        return;
    }

    $id = isset($_POST['pc_folio_partner_id']) ? sanitize_text_field(wp_unslash($_POST['pc_folio_partner_id'])) : '';
    $short_name = isset($_POST['pc_folio_partner_short_name']) ? sanitize_text_field(wp_unslash($_POST['pc_folio_partner_short_name'])) : '';
    $name = isset($_POST['pc_folio_partner_name']) ? sanitize_text_field(wp_unslash($_POST['pc_folio_partner_name'])) : '';
    $type = isset($_POST['pc_folio_partner_type']) ? sanitize_text_field(wp_unslash($_POST['pc_folio_partner_type'])) : '';

    if ($id === '') {
        delete_user_meta($user_id, PC_FOLIO_PARTNER_META_ID);
        delete_user_meta($user_id, PC_FOLIO_PARTNER_META_SHORT_NAME);
        delete_user_meta($user_id, PC_FOLIO_PARTNER_META_NAME);
        delete_user_meta($user_id, PC_FOLIO_PARTNER_META_TYPE);
        return;
    }

    update_user_meta($user_id, PC_FOLIO_PARTNER_META_ID, $id);
    update_user_meta($user_id, PC_FOLIO_PARTNER_META_SHORT_NAME, $short_name);
    update_user_meta($user_id, PC_FOLIO_PARTNER_META_NAME, $name);
    update_user_meta($user_id, PC_FOLIO_PARTNER_META_TYPE, $type);
}
add_action('personal_options_update', 'pc_folio_customer_map_save');
add_action('edit_user_profile_update', 'pc_folio_customer_map_save');

function pc_folio_customer_map_ajax_search(): void {
    if (!pc_folio_customer_map_can_manage()) {
        wp_send_json_error(['message' => __('Forbidden.', 'pc-folio-customer-map')], 403);
    }

    check_ajax_referer('pc_folio_partner_search');

    if (!function_exists('lps_java_get')) {
        wp_send_json_error(['message' => __('Java connection helper is unavailable.', 'pc-folio-customer-map')], 500);
    }

    $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    $types = isset($_POST['types']) ? sanitize_text_field(wp_unslash($_POST['types'])) : 'П,Д,К';
    $limit = isset($_POST['limit']) ? max(1, min(20, (int) $_POST['limit'])) : 20;

    $path = add_query_arg([
        'q'      => $q,
        'types'  => $types,
        'limit'  => $limit,
        'offset' => 0,
    ], '/admin/folio/partners');

    $resp = lps_java_get($path);
    if (is_wp_error($resp)) {
        wp_send_json_error(['message' => $resp->get_error_message()], 500);
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw = (string) wp_remote_retrieve_body($resp);
    $data = json_decode($raw, true);

    if ($code < 200 || $code >= 300) {
        wp_send_json_error(['message' => 'Folio partners HTTP ' . $code], $code);
    }

    if (!is_array($data)) {
        wp_send_json_error(['message' => __('Invalid Folio partners response.', 'pc-folio-customer-map')], 500);
    }

    $items = [];
    foreach ((array) ($data['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = (string) ($item['type'] ?? '');
        $items[] = [
            'id'        => (string) ($item['id'] ?? ''),
            'shortName' => (string) ($item['shortName'] ?? ''),
            'name'      => (string) ($item['name'] ?? ''),
            'type'      => $type,
            'typeLabel' => (string) ($item['typeLabel'] ?? pc_folio_customer_map_type_label($type)),
        ];
    }

    wp_send_json_success([
        'items'  => $items,
        'total'  => (int) ($data['total'] ?? count($items)),
        'limit'  => (int) ($data['limit'] ?? $limit),
        'offset' => (int) ($data['offset'] ?? 0),
    ]);
}
add_action('wp_ajax_pc_folio_partner_search', 'pc_folio_customer_map_ajax_search');
