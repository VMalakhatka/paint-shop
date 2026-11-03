<?php
/**
 * Scoped operator tool, invoked through `wp eval-file`, never a web endpoint.
 * Arguments: preview|prepare|verify|publish|update, absolute bundle directory,
 * expected existing content SHA256 (required for update).
 * prepare creates only this new draft and its six editorial attachments.
 * Unowned content is never overwritten; published updates require a hash and backup.
 */
if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}
$mode = $args[0] ?? 'preview';
$dir = realpath($args[1] ?? '');
$marker = 'makers-landing-20261103';
$slug = 'dlia-maistriv';
if (!in_array($mode, ['preview', 'prepare', 'verify', 'publish', 'update'], true) || !$dir) {
    WP_CLI::error('Expected preview|prepare|verify|publish|update and an existing bundle directory.');
}
if (parse_url(home_url('/'), PHP_URL_HOST) !== 'kreul.com.ua') {
    WP_CLI::error('This release is scoped to kreul.com.ua.');
}
if (!current_user_can('edit_pages') || !current_user_can('upload_files') || !current_user_can('unfiltered_html')) {
    WP_CLI::error('An editor with upload_files and unfiltered_html is required.');
}
$meta = json_decode(file_get_contents($dir . '/page-meta.json'), true);
$content = file_get_contents($dir . '/wordpress-content.html');
if (!$meta || $meta['slug'] !== $slug || strpos($content, 'class="lavka-makers"') === false) {
    WP_CLI::error('Unexpected content bundle.');
}
$alts = [
    'polymer-miniatures' => 'Мініатюрний столик із виліпленими ягодами, випічкою та посудом',
    'cernit-finishes' => 'Деталі мініатюри та матеріали Cernit для фінішного оздоблення',
    'glass-and-contours' => 'Розписана чашка, фарби Pébéo та контури',
    'textile-painting' => 'Колібрі на темній тканині та фарби Pébéo Setacolor',
    'decorative-painting' => 'Декоративна підставка із зайцем і фарби KREUL',
    'ceramic-painting' => 'Керамічна пташка з квітковим розписом і фарби Pébéo та KREUL',
];
$page = get_page_by_path($slug, OBJECT, 'page');
if ($page && get_post_meta($page->ID, '_lavka_makers_release', true) !== $marker) {
    WP_CLI::error('Slug is already owned by other content; refusing overwrite.');
}
$attachments = [];
foreach ($alts as $key => $alt) {
    $path = $dir . '/assets/' . $key . '.jpg';
    if (!is_file($path) || !getimagesize($path)) {
        WP_CLI::error('Missing image: ' . $key);
    }
    $ids = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 2,
        'meta_query' => [['key' => '_lavka_makers_release', 'value' => $marker], ['key' => '_lavka_makers_asset', 'value' => $key]]]);
    if (count($ids) > 1) {
        WP_CLI::error('Duplicate release attachments: ' . $key);
    }
    if ($ids && get_post_meta($ids[0], '_lavka_makers_sha256', true) !== hash_file('sha256', $path)) {
        WP_CLI::error('Existing image differs: ' . $key);
    }
    $attachments[$key] = $ids ? (int)$ids[0] : null;
}
if ($mode === 'preview') {
    WP_CLI::line(wp_json_encode(['mode' => $mode, 'page' => $page ? ['id' => $page->ID, 'status' => $page->post_status] : null,
        'slug' => $slug, 'email' => $meta['email'], 'attachments' => $attachments,
        'action' => 'Create a new draft and six editorial media assets; no catalog, orders, roles, menu or global settings writes.'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return;
}
if ($mode === 'prepare') {
    if ($page && $page->post_status !== 'draft') {
        WP_CLI::error('Only the owned draft may be prepared.');
    }
    if (!$page) {
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => $meta['title'],
            'post_name' => $slug, 'post_author' => get_current_user_id(), 'comment_status' => 'closed', 'ping_status' => 'closed',
            'meta_input' => ['_lavka_makers_release' => $marker]], true);
        if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); }
        $page = get_post($id);
        WP_CLI::line('Created draft: ' . $id);
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    foreach ($alts as $key => $alt) {
        if ($attachments[$key]) { continue; }
        $source = $dir . '/assets/' . $key . '.jpg';
        $tmp = wp_tempnam('lavka-makers-' . $key . '.jpg');
        if (!$tmp || !copy($source, $tmp)) { WP_CLI::error('Cannot stage: ' . $key); }
        $id = media_handle_sideload(['name' => 'lavka-makers-20261103-' . $key . '.jpg', 'tmp_name' => $tmp], $page->ID, $alt);
        if (is_wp_error($id)) { @unlink($tmp); WP_CLI::error($id->get_error_message()); }
        update_post_meta($id, '_lavka_makers_release', $marker);
        update_post_meta($id, '_lavka_makers_asset', $key);
        update_post_meta($id, '_lavka_makers_sha256', hash_file('sha256', $source));
        update_post_meta($id, '_wp_attachment_image_alt', $alt);
        $attachments[$key] = (int)$id;
        WP_CLI::line('Imported ' . $key . ': ' . $id);
    }
}
if (!$page || in_array(null, $attachments, true)) {
    WP_CLI::error('Release is incomplete; inspect current state before continuing.');
}
$urls = [];
foreach ($attachments as $key => $id) {
    $url = wp_get_attachment_url($id);
    if (!$url || parse_url($url, PHP_URL_SCHEME) !== 'https') { WP_CLI::error('Expected HTTPS media URL: ' . $key); }
    $urls[$key] = $url;
    $content = str_replace('assets/' . $key . '.jpg', esc_url($url), $content);
}
if (strpos($content, 'src="assets/') !== false || strpos($content, '{{') !== false) {
    WP_CLI::error('Unresolved asset placeholder.');
}
if ($mode === 'update') {
    $expected = $args[2] ?? '';
    if (!$page || $page->post_status !== 'publish' || !preg_match('/^[a-f0-9]{64}$/', $expected)
        || !hash_equals($expected, hash('sha256', $page->post_content))) {
        WP_CLI::error('Update requires the owned published page and its exact current content SHA256.');
    }
    $backup = $dir . '/page-backup-' . $page->ID . '-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false) . '.json';
    $payload = wp_json_encode(['post' => $page->to_array(), 'meta' => get_post_meta($page->ID)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $handle = fopen($backup, 'x');
    if (!$handle || !chmod($backup, 0600) || fwrite($handle, $payload) !== strlen($payload)) {
        WP_CLI::error('Cannot save private page backup; update stopped.');
    }
    fclose($handle);
    WP_CLI::line('Page backup: ' . basename($backup));
}
if (in_array($mode, ['prepare', 'update'], true)) {
    $result = wp_update_post(wp_slash(['ID' => $page->ID, 'post_content' => $content, 'post_excerpt' => $meta['seo_description']]), true);
    if (is_wp_error($result)) { WP_CLI::error($result->get_error_message()); }
    update_post_meta($page->ID, 'rank_math_title', $meta['seo_title']);
    update_post_meta($page->ID, 'rank_math_description', $meta['seo_description']);
    update_post_meta($page->ID, 'rank_math_robots', ['index', 'follow']);
    update_post_meta($page->ID, '_lavka_makers_content_sha256', hash('sha256', $content));
    // Use social-image metadata; a featured image would be duplicated by the theme.
    update_post_meta($page->ID, 'rank_math_facebook_image', $urls['polymer-miniatures']);
    update_post_meta($page->ID, 'rank_math_facebook_image_id', $attachments['polymer-miniatures']);
    update_post_meta($page->ID, 'rank_math_twitter_use_facebook', 'on');
}
$page = get_post($page->ID);
if ($page->post_content !== $content) { WP_CLI::error('Stored page content differs from the verified bundle.'); }
if ($mode === 'publish') {
    if (!current_user_can('publish_pages')) { WP_CLI::error('publish_pages is required.'); }
    if (!in_array($page->post_status, ['draft', 'publish'], true)) { WP_CLI::error('Unexpected page status.'); }
    if ($page->post_status === 'draft') {
        $result = wp_update_post(['ID' => $page->ID, 'post_status' => 'publish'], true);
        if (is_wp_error($result)) { WP_CLI::error($result->get_error_message()); }
    }
    $page = get_post($page->ID);
}
WP_CLI::line(wp_json_encode(['mode' => $mode, 'id' => $page->ID, 'status' => $page->post_status,
    'url' => get_permalink($page->ID), 'content_sha256' => hash('sha256', $page->post_content),
    'attachment_ids' => $attachments, 'asset_urls' => $urls], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
