<?php
namespace Lavka\Workshops;
defined('ABSPATH') || exit;

function booking_form(int $id): void {
    $rows = sessions($id, true);
    $selected = input_text($_GET, 'session');
    if (!$rows) {
        echo '<p>' . esc_html__('New dates will appear here soon. Please check the schedule later.', 'lavka-workshops') . '</p>';
        return;
    }
    if (isset($_GET['request_received'])) echo '<p class="lw-success" role="status">' . esc_html__('Request received. The instructor will contact you to confirm your place.', 'lavka-workshops') . '</p>';
    echo '<form class="lw-request" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="lw_request"><input type="hidden" name="workshop" value="' . $id . '">';
    wp_nonce_field('lw_request_' . $id, 'nonce', false);
    echo '<fieldset class="lw-session-choices"><legend>' . esc_html__('Choose your date', 'lavka-workshops') . '</legend>';
    $open = array_values(array_filter($rows, static fn($row) => $row['state'] === 'open'));
    if (!in_array($selected, array_column($open, 'id'), true)) $selected = $open[0]['id'] ?? '';
    foreach ($rows as $row) {
        echo '<label class="lw-session"><input type="radio" required name="session" value="' . esc_attr($row['id']) . '" ' . checked($selected, $row['id'], false) . ' ' . disabled($row['state'], 'full', false) . '><span><strong>' . esc_html(session_label($row)) . '</strong><small>' . esc_html($row['address']) . '</small><small>' . esc_html(sprintf(__('%s min', 'lavka-workshops'), $row['duration']) . ' · ' . price_label($row)) . '</small>' . ($row['state'] === 'full' ? '<small>' . esc_html__('Fully booked', 'lavka-workshops') . '</small>' : '') . '</span></label>';
    }
    echo '</fieldset>';
    if (!$open) {
        echo '<p>' . esc_html__('All current dates are fully booked. Please check back for new dates.', 'lavka-workshops') . '</p></form>';
        return;
    }
    echo '<label for="lw-name">' . esc_html__('Your name', 'lavka-workshops') . '</label><input id="lw-name" name="guest_name" autocomplete="given-name" maxlength="100" required><label for="lw-phone">' . esc_html__('Phone number', 'lavka-workshops') . '</label><input id="lw-phone" name="phone" type="tel" autocomplete="tel" maxlength="30" required placeholder="+380"><div class="lw-honey" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div><label class="lw-consent"><input type="checkbox" name="consent" value="1" required><span>' . esc_html__('I agree to be contacted about this workshop using the details I provided.', 'lavka-workshops');
    if (get_privacy_policy_url()) echo ' <a href="' . esc_url(get_privacy_policy_url()) . '" target="_blank" rel="noopener">' . esc_html__('Privacy policy', 'lavka-workshops') . '</a>';
    echo '</span></label><button class="lw-button" type="submit">' . esc_html__('Request a place', 'lavka-workshops') . ' <span aria-hidden="true">↗︎</span></button><p class="lw-form-note">' . esc_html__('No payment now. Your place is confirmed personally by the instructor.', 'lavka-workshops') . '</p><div class="lw-form-result" role="status" aria-live="polite" tabindex="-1"></div></form>';
}
function request_reply(bool $success, string $message, int $id, int $status = 200): void {
    if (wp_doing_ajax()) {
        if ($success) wp_send_json_success(['message' => $message], $status);
        wp_send_json_error(['message' => $message], $status);
    }
    if ($success) {
        wp_safe_redirect(add_query_arg('request_received', '1', get_permalink($id)) . '#lw-booking');
        exit;
    }
    wp_die(esc_html($message), esc_html__('Booking request', 'lavka-workshops'), ['response' => $status, 'back_link' => true]);
}
function request_nonce(): void {
    $id = absint(input_text($_GET, 'workshop'));
    if (get_post_type($id) !== 'lavka_workshop' || get_post_status($id) !== 'publish') wp_send_json_error([], 404);
    nocache_headers();
    wp_send_json_success(['nonce' => wp_create_nonce('lw_request_' . $id)]);
}
add_action('wp_ajax_lw_nonce', __NAMESPACE__ . '\\request_nonce');
add_action('wp_ajax_nopriv_lw_nonce', __NAMESPACE__ . '\\request_nonce');

function receive_request(): void {
    $id = absint(input_text($_POST, 'workshop'));
    $failure = __('Please check your name, phone number and consent, then try again.', 'lavka-workshops');
    if (get_post_type($id) !== 'lavka_workshop' || get_post_status($id) !== 'publish' ||
        !wp_verify_nonce(input_text($_POST, 'nonce'), 'lw_request_' . $id)) {
        request_reply(false, __('This form has expired. Reload the page and try again.', 'lavka-workshops'), $id, 403);
    }
    $name = input_text($_POST, 'guest_name');
    $phone = preg_replace('/[^0-9+]/', '', input_text($_POST, 'phone'));
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 10 && str_starts_with($digits, '0')) $digits = '38' . $digits;
    if (!$name || mb_strlen($name) > 100 || strlen($digits) < 9 || strlen($digits) > 15 || ($_POST['consent'] ?? '') !== '1' || !empty($_POST['website'])) request_reply(false, $failure, $id, 422);
    $session_id = input_text($_POST, 'session');
    $row = null;
    foreach (sessions($id, true) as $candidate) if ($candidate['id'] === $session_id && $candidate['state'] === 'open') $row = $candidate;
    if (!$row) request_reply(false, __('This date is no longer available. Please reload and choose another date.', 'lavka-workshops'), $id, 409);
    $hash = hash_hmac('sha256', $id . '|' . $session_id . '|' . $digits, wp_salt());
    $success = __('Request received. The instructor will contact you to confirm your place.', 'lavka-workshops');
    $existing = static fn() => get_posts(['post_type' => 'lavka_mk_request', 'post_status' => ['private', 'trash'], 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_lw_hash', 'meta_value' => $hash]);
    if ($existing()) request_reply(true, $success, $id);
    // Atomic option claim serializes duplicate requests, including two concurrent tabs.
    $lock = '_lw_lock_' . $hash;
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $lock, time() - 60));
    wp_cache_delete($lock, 'options');
    if (!add_option($lock, time(), '', false)) request_reply(false, __('Your request is being processed. Please wait a moment and try again.', 'lavka-workshops'), $id, 409);
    if ($existing()) { delete_option($lock); request_reply(true, $success, $id); }
    $rate_key = 'lw_rate_' . hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', wp_salt());
    $rate = (int) get_transient($rate_key);
    if ($rate >= 10) { delete_option($lock); request_reply(false, __('Too many requests. Please try again in a few minutes.', 'lavka-workshops'), $id, 429); }
    set_transient($rate_key, $rate + 1, 10 * MINUTE_IN_SECONDS);
    $request = wp_insert_post(['post_type' => 'lavka_mk_request', 'post_status' => 'private', 'post_title' => get_the_title($id),
        'meta_input' => ['_lw_hash' => $hash, '_lw_workshop' => $id, '_lw_session' => $row, '_lw_name' => $name,
            '_lw_phone' => '+' . $digits, '_lw_status' => 'new', '_lw_consent' => 'contact-workshop-v1']], true);
    delete_option($lock);
    if (is_wp_error($request) || !$request) request_reply(false, __('The request could not be saved. Please try again.', 'lavka-workshops'), $id, 500);
    request_reply(true, $success, $id);
}
foreach (['wp_ajax_lw_request', 'wp_ajax_nopriv_lw_request', 'admin_post_lw_request', 'admin_post_nopriv_lw_request'] as $hook) add_action($hook, __NAMESPACE__ . '\\receive_request');

function request_states(): array { return ['new' => __('New request', 'lavka-workshops'), 'confirmed' => __('Confirmed', 'lavka-workshops'), 'cancelled' => __('Cancelled', 'lavka-workshops')]; }
add_filter('manage_lavka_mk_request_posts_columns', static function ($columns) {
    return ['cb' => $columns['cb'], 'title' => __('Workshop', 'lavka-workshops'), 'lw_guest' => __('Guest', 'lavka-workshops'), 'lw_session' => __('Date', 'lavka-workshops'), 'lw_status' => __('Status', 'lavka-workshops'), 'date' => __('Received', 'lavka-workshops')];
});
add_action('manage_lavka_mk_request_posts_custom_column', static function ($column, $id) {
    if ($column === 'lw_guest') echo esc_html(get_post_meta($id, '_lw_name', true)) . '<br>' . esc_html(get_post_meta($id, '_lw_phone', true));
    if ($column === 'lw_session') { $row = get_post_meta($id, '_lw_session', true); if (is_array($row)) echo esc_html(session_label($row) . ' · ' . $row['address']); }
    if ($column === 'lw_status') echo esc_html(request_states()[get_post_meta($id, '_lw_status', true)] ?? '');
}, 10, 2);
add_action('add_meta_boxes_lavka_mk_request', static function () {
    remove_meta_box('submitdiv', 'lavka_mk_request', 'side');
    add_meta_box('lw-request-details', __('Booking request', 'lavka-workshops'), static function ($post) {
        $row = get_post_meta($post->ID, '_lw_session', true);
        echo '<h3>' . esc_html(get_the_title($post)) . '</h3><input type="hidden" name="post_title" value="' . esc_attr(get_the_title($post)) . '">';
        echo '<p><strong>' . esc_html(get_post_meta($post->ID, '_lw_name', true)) . '</strong> · ' . esc_html(get_post_meta($post->ID, '_lw_phone', true)) . '</p>';
        if (is_array($row)) echo '<p>' . esc_html(session_label($row) . ' · ' . $row['address'] . ' · ' . price_label($row)) . '</p>';
        echo '<p>' . esc_html__('These are the details at the time of the request. Check the current schedule before confirming with the guest.', 'lavka-workshops') . '</p>';
        $workshop = (int) get_post_meta($post->ID, '_lw_workshop', true);
        if (get_edit_post_link($workshop)) echo '<p><a href="' . esc_url(get_edit_post_link($workshop)) . '">' . esc_html__('Edit workshop', 'lavka-workshops') . '</a></p>';
        wp_nonce_field('lw_request_status', 'lw_status_nonce');
        echo '<label>' . esc_html__('Status', 'lavka-workshops') . ' <select name="lw_request_status">';
        foreach (request_states() as $key => $label) echo '<option value="' . $key . '" ' . selected(get_post_meta($post->ID, '_lw_status', true), $key, false) . '>' . esc_html($label) . '</option>';
        echo '</select></label><p>' . esc_html__('Contact the guest yourself. Changing the status does not send a message or change availability.', 'lavka-workshops') . '</p>';
        submit_button(__('Save status', 'lavka-workshops'));
        if (current_user_can('delete_post', $post->ID)) echo '<a href="' . esc_url(get_delete_post_link($post->ID)) . '">' . esc_html__('Move to trash', 'lavka-workshops') . '</a>';
    }, 'lavka_mk_request', 'normal', 'high');
});
add_action('save_post_lavka_mk_request', static function ($id) {
    if (!current_user_can('edit_post', $id) || !isset($_POST['lw_status_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lw_status_nonce'])), 'lw_request_status')) return;
    $status = sanitize_key($_POST['lw_request_status'] ?? '');
    if (isset(request_states()[$status])) update_post_meta($id, '_lw_status', $status);
});
// Requests must never become public, even through a crafted editor request.
add_filter('wp_insert_post_data', static function ($data) {
    if ($data['post_type'] === 'lavka_mk_request' && $data['post_status'] !== 'trash') $data['post_status'] = 'private';
    return $data;
});
add_filter('post_row_actions', static function ($actions, $post) {
    if ($post->post_type === 'lavka_mk_request') unset($actions['inline hide-if-no-js'], $actions['view']);
    return $actions;
}, 10, 2);
