<?php
/** Run locally: wp eval-file <this-file> --skip-plugins --skip-themes */
namespace Lavka\Workshops;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new \RuntimeException('Local test only.');
require_once dirname(__DIR__) . '/lavka-workshops.php';
register();
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
$GLOBALS['lw_checks'] = 0; $created = []; $user = 0;
function check($condition, string $message): void {  if (!$condition) throw new \RuntimeException($message); $GLOBALS['lw_checks']++; }
function send(array $data): array {
    $_POST = $data;
    ob_start();
    try { receive_request(); } catch (\RuntimeException $e) { if ($e->getMessage() !== 'ajax-finished') throw $e; }
    return json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
}
add_filter('wp_die_ajax_handler', static fn() => static function () { throw new \RuntimeException('ajax-finished'); });
try {
    $base = ['date' => '2030-10-01', 'time' => '15:00', 'city' => 'kyiv', 'address' => 'TEST studio', 'duration' => '120', 'price' => '850,50', 'state' => 'open'];
    $valid = validate_sessions([$base]);
    check(!is_wp_error($valid) && $valid[0]['price'] === '850.50', 'Decimal comma normalized');
    check(wp_date('Y-m-d H:i', $valid[0]['timestamp'], timezone()) === '2030-10-01 15:00', 'Kyiv timezone round trip');
    foreach ([['date' => '2030-02-30'], ['time' => '25:00'], ['city' => 'elsewhere'], ['price' => '-1'], ['duration' => '0'], ['address' => ''], ['state' => 'invalid']] as $change) check(is_wp_error(validate_sessions([array_merge($base, $change)])), 'Reject malformed session');
    check(is_wp_error(validate_sessions([array_merge($base, ['date' => []])])), 'Reject nested input');
    check(is_wp_error(validate_sessions([$valid[0], $valid[0]])), 'Reject duplicate IDs');
    check(is_wp_error(validate_sessions(array_fill(0, 101, $base))), 'Reject oversized schedule');
    $id = wp_insert_post(['post_type' => 'lavka_workshop', 'post_status' => 'publish', 'post_title' => 'LW integration fixture']);
    $created[] = $id;
    update_post_meta($id, '_lw_sessions', $valid);
    check(count(sessions($id, true)) === 1, 'Upcoming date visible');
    $payload = ['workshop' => $id, 'session' => $valid[0]['id'], 'nonce' => wp_create_nonce('lw_request_' . $id), 'guest_name' => 'Synthetic Test', 'phone' => '+380000000001', 'consent' => '1'];
    $reply = send(array_merge($payload, ['nonce' => 'bad']));
    check(!$reply['success'], 'Reject invalid nonce');
    $reply = send(array_merge($payload, ['consent' => '']));
    check(!$reply['success'], 'Reject missing consent');
    check(!send(array_merge($payload, ['website' => 'spam']))['success'], 'Reject honeypot');
    check(!send(array_merge($payload, ['session' => wp_generate_uuid4()]))['success'], 'Reject unknown session');
    $lock = '_lw_lock_' . hash_hmac('sha256', $id . '|' . $valid[0]['id'] . '|380000000001', wp_salt());
    add_option($lock, time(), '', false);
    check(!send($payload)['success'], 'In-flight duplicate is serialized');
    delete_option($lock);
    check(send($payload)['success'], 'Save valid request');
    $requests = get_posts(['post_type' => 'lavka_mk_request', 'post_status' => 'private', 'numberposts' => -1, 'meta_key' => '_lw_workshop', 'meta_value' => $id, 'fields' => 'ids']);
    $created = array_merge($created, $requests);
    check(count($requests) === 1, 'Exactly one stored request');
    check(send(array_merge($payload, ['phone' => '000 000 00 01']))['success'], 'Normalize local phone and accept repeat');
    check(count(get_posts(['post_type' => 'lavka_mk_request', 'post_status' => 'private', 'numberposts' => -1, 'meta_key' => '_lw_workshop', 'meta_value' => $id])) === 1, 'Retry does not duplicate');
    check(get_post_meta($requests[0], '_lw_session', true)['price'] === '850.50', 'Request stores original price snapshot');
    wp_update_post(['ID' => $requests[0], 'post_status' => 'publish']);
    check(get_post_status($requests[0]) === 'private', 'Cannot publish private request');
    $closed = $valid; $closed[0]['state'] = 'full'; update_post_meta($id, '_lw_sessions', $closed);
    check(!send(array_merge($payload, ['phone' => '+380000000002']))['success'], 'Reject full session');
    $closed[0]['state'] = 'cancelled'; update_post_meta($id, '_lw_sessions', $closed);
    check(!sessions($id, true), 'Hide cancelled session');
    $closed[0]['state'] = 'open'; $closed[0]['timestamp'] = time() - 1; update_post_meta($id, '_lw_sessions', $closed);
    check(!send(array_merge($payload, ['phone' => '+380000000002']))['success'], 'Reject past session');
    update_post_meta($id, '_lw_sessions', $valid);
    wp_update_post(['ID' => $id, 'post_status' => 'draft']);
    check(!send($payload)['success'], 'Reject draft workshop');
    $user = wp_insert_user(['user_login' => 'lw-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(32), 'role' => 'lavka_instructor']);
    check(!is_wp_error($user), 'Create isolated instructor test account');
    wp_set_current_user($user);
    check(current_user_can('edit_post', $id) && current_user_can('edit_post', $requests[0]), 'Instructor edits workshops and requests');
    check(apply_filters('woocommerce_prevent_admin_access', true) === false, 'Woo allows instructor into custom admin');
    check(!current_user_can('manage_options') && !current_user_can('edit_users') && !current_user_can('edit_products'), 'Instructor cannot manage store or users');
    wp_set_current_user(0);
    check(apply_filters('woocommerce_prevent_admin_access', true) === true, 'Woo restriction remains for guests');
    check(!current_user_can('read_post', $requests[0]) && !current_user_can('edit_post', $id), 'Anonymous user cannot read request or edit workshop');
    $_POST = ['lw_nonce' => 'bad', 'lw_schedule_present' => '1', 'lw_sessions' => []];
    do_action('save_post_lavka_workshop', $id);
    check(count(sessions($id)) === 1, 'Unauthorized schedule save has no effect');
    echo 'PASS: ' . $GLOBALS['lw_checks'] . " integration checks\n";
} finally {
    $_POST = []; wp_set_current_user(0);
    foreach (array_reverse($created) as $post_id) wp_delete_post($post_id, true);
    if ($user && !is_wp_error($user)) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($user); }
    delete_transient('lw_rate_' . hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', wp_salt()));
}
