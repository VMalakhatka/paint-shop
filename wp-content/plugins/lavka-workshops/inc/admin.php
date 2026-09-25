<?php
namespace Lavka\Workshops;
defined('ABSPATH') || exit;

add_filter('use_block_editor_for_post_type', static fn($use, $type) => $type === 'lavka_workshop' ? false : $use, 10, 2);
add_filter('default_content', static function ($content, $post) {
    if ($post->post_type !== 'lavka_workshop' || $content) return $content;
    return '<h2>' . esc_html__('What we will create', 'lavka-workshops') . '</h2><p>' . esc_html__('Describe the artwork and the experience in a few sentences.', 'lavka-workshops') . '</p><h2>' . esc_html__('Who it is for', 'lavka-workshops') . '</h2><p>' . esc_html__('Tell guests about the age range and experience level.', 'lavka-workshops') . '</p><h2>' . esc_html__('What is included', 'lavka-workshops') . '</h2><ul><li>' . esc_html__('Materials and tools', 'lavka-workshops') . '</li><li>' . esc_html__('Guidance from the instructor', 'lavka-workshops') . '</li></ul><h2>' . esc_html__('Good to know', 'lavka-workshops') . '</h2><p>' . esc_html__('Explain what to bring and when guests can collect their artwork.', 'lavka-workshops') . '</p>';
}, 10, 2);
add_action('add_meta_boxes', static function () {
    add_meta_box('lw-schedule', __('Dates and booking', 'lavka-workshops'), __NAMESPACE__ . '\\schedule_box', 'lavka_workshop', 'normal', 'high');
    add_meta_box('lw-guide', __('Three simple steps', 'lavka-workshops'), static function () {
        echo '<p>' . esc_html__('1. Add a title and cover photo. 2. Write the article like a Word document; use Add Media for photos. 3. Fill in the dates below and publish.', 'lavka-workshops') . '</p><p>' . esc_html__('The excerpt is the short text on the schedule card. Preview lets you check the article before publishing.', 'lavka-workshops') . '</p><p><a href="' . esc_url(get_post_type_archive_link('lavka_workshop')) . '" target="_blank">' . esc_html__('View schedule', 'lavka-workshops') . '</a></p>';
    }, 'lavka_workshop', 'side', 'high');
});
function schedule_row(array $row, string $index): void {
    $prefix = 'lw_sessions[' . $index . ']';
    echo '<tr><td><input type="hidden" name="' . esc_attr($prefix . '[id]') . '" value="' . esc_attr($row['id'] ?? '') . '">';
    foreach (['date' => ['date', __('Date', 'lavka-workshops')], 'time' => ['time', __('Time', 'lavka-workshops')]] as $key => [$type, $label]) {
        echo '<label>' . esc_html($label) . '<input required type="' . $type . '" name="' . esc_attr($prefix . '[' . $key . ']') . '" value="' . esc_attr($row[$key] ?? '') . '"></label>';
    }
    echo '</td><td><label>' . esc_html__('City', 'lavka-workshops') . '<select name="' . esc_attr($prefix . '[city]') . '">';
    foreach (cities() as $key => $label) echo '<option value="' . esc_attr($key) . '" ' . selected($row['city'] ?? 'kyiv', $key, false) . '>' . esc_html($label) . '</option>';
    echo '</select></label><label>' . esc_html__('Studio address', 'lavka-workshops') . '<input required maxlength="240" name="' . esc_attr($prefix . '[address]') . '" value="' . esc_attr($row['address'] ?? '') . '"></label></td><td>';
    foreach (['price' => [__('Price, UAH', 'lavka-workshops'), '0', '999999', '0.01', ''], 'duration' => [__('Duration, minutes', 'lavka-workshops'), '15', '1440', '1', '120']] as $key => [$label, $min, $max, $step, $default]) {
        echo '<label>' . esc_html($label) . '<input required type="number" min="' . $min . '" max="' . $max . '" step="' . $step . '" name="' . esc_attr($prefix . '[' . $key . ']') . '" value="' . esc_attr($row[$key] ?? $default) . '"></label>';
    }
    echo '</td><td><label>' . esc_html__('Availability', 'lavka-workshops') . '<select name="' . esc_attr($prefix . '[state]') . '">';
    foreach (['open' => __('Booking open', 'lavka-workshops'), 'full' => __('Fully booked', 'lavka-workshops'), 'cancelled' => __('Cancelled', 'lavka-workshops')] as $key => $label) echo '<option value="' . $key . '" ' . selected($row['state'] ?? 'open', $key, false) . '>' . esc_html($label) . '</option>';
    echo '</select></label><button type="button" class="button lw-copy-row">' . esc_html__('Copy date', 'lavka-workshops') . '</button> <button type="button" class="button lw-remove-row">' . esc_html__('Remove', 'lavka-workshops') . '</button></td></tr>';
}
function schedule_box(\WP_Post $post): void {
    wp_nonce_field('lw_schedule', 'lw_nonce');
    echo '<p>' . esc_html__('Times are in Kyiv time. One article can have several dates. Copy a row to add the next class.', 'lavka-workshops') . '</p><p>' . esc_html__('Requests do not reserve seats automatically. Set Fully booked when the group is complete. If guests have already signed up, contact them before changing or cancelling a date.', 'lavka-workshops') . '</p>';
    echo '<input type="hidden" name="lw_schedule_present" value="1"><div class="lw-admin-scroll"><table class="widefat lw-dates"><tbody>';
    foreach (sessions($post->ID) as $index => $row) schedule_row($row, (string) $index);
    echo '</tbody></table></div><p><button type="button" class="button button-secondary" id="lw-add-date">' . esc_html__('Add date', 'lavka-workshops') . '</button></p><template id="lw-date-template">';
    schedule_row([], '__INDEX__');
    echo '</template>';
}
add_action('admin_enqueue_scripts', static function () {
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, ['lavka_workshop', 'lavka_mk_request'], true)) return;
    wp_enqueue_style('lw-admin', plugins_url('assets/admin.css', FILE), [], VERSION);
    wp_enqueue_script('lw-admin', plugins_url('assets/admin.js', FILE), [], VERSION, true);
    wp_localize_script('lw-admin', 'lwAdmin', ['remove' => __('Remove this date? Existing requests will remain in the request list.', 'lavka-workshops')]);
});
add_action('save_post_lavka_workshop', static function ($id) {
    if (wp_is_post_revision($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $id) ||
        !isset($_POST['lw_nonce'], $_POST['lw_schedule_present']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lw_nonce'])), 'lw_schedule')) return;
    $rows = validate_sessions((array) wp_unslash($_POST['lw_sessions'] ?? []));
    if (is_wp_error($rows)) {
        set_transient('lw_error_' . get_current_user_id(), $rows->get_error_message(), 120);
        return;
    }
    update_post_meta($id, '_lw_sessions', $rows);
});
add_action('admin_notices', static function () {
    $error = get_transient('lw_error_' . get_current_user_id());
    if (!$error) return;
    delete_transient('lw_error_' . get_current_user_id());
    echo '<div class="notice notice-error"><p>' . esc_html__('Schedule not saved. The previous dates have been kept.', 'lavka-workshops') . ' ' . esc_html($error) . '</p></div>';
});
add_filter('manage_lavka_workshop_posts_columns', static function ($columns) {
    return ['cb' => $columns['cb'], 'title' => $columns['title'], 'lw_dates' => __('Upcoming dates', 'lavka-workshops'), 'author' => __('Author', 'lavka-workshops'), 'date' => $columns['date']];
});
add_action('manage_lavka_workshop_posts_custom_column', static function ($column, $id) {
    if ($column !== 'lw_dates') return;
    foreach (sessions($id, true) as $row) echo esc_html(session_label($row) . ' · ' . price_label($row)) . '<br>';
}, 10, 2);
// Woo otherwise redirects custom editorial roles without edit_posts to My Account.
// Keep WordPress capability checks; do not grant access to posts or shop management.
add_filter('woocommerce_prevent_admin_access', static fn($prevent) => current_user_can('edit_lavka_workshops') ? false : $prevent);
add_filter('login_redirect', static function ($redirect, $requested, $user) {
    if ($user instanceof \WP_User && $user->has_cap('edit_lavka_workshops') && !$user->has_cap('manage_options') && !$requested) {
        return admin_url('edit.php?post_type=lavka_workshop');
    }
    return $redirect;
}, 20, 3);
add_action('admin_enqueue_scripts', static function () {
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, ['lavka_workshop', 'lavka_mk_request'], true) || current_user_can('manage_options')) return;
    // Rank Math enqueues this mount even when the instructor has no SEO metabox.
    wp_dequeue_script('rank-math-editor');
}, 100);

add_filter('admin_body_class', static function ($classes) {
    $screen = get_current_screen();
    if ($screen && in_array($screen->post_type, ['lavka_workshop', 'lavka_mk_request'], true) && !current_user_can('manage_options')) $classes .= ' lw-instructor';
    return $classes;
});
