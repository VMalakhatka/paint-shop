<?php
/** Resolve moved Media Library images without rewriting saved category content. */
defined('ABSPATH') || exit;

function psu_category_description_media(string $description, $term): string {
    if (is_admin() || !($term instanceof WP_Term) || $term->taxonomy !== 'product_cat'
        || !is_product_category() || !class_exists('WP_HTML_Tag_Processor')) return $description;

    $html = new WP_HTML_Tag_Processor($description);
    $registered_sizes = get_intermediate_image_sizes();
    $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    while ($html->next_tag('IMG')) {
        $classes = $html->get_attribute('class');
        $old_url = $html->get_attribute('src');
        if (!is_string($classes) || !is_string($old_url)) continue;
        preg_match_all('/(?:^|\s)wp-image-([1-9][0-9]*)(?=\s|$)/', $classes, $ids);
        preg_match_all('/(?:^|\s)size-([a-zA-Z0-9_-]+)(?=\s|$)/', $classes, $sizes);
        // Ambiguous/missing references and arbitrary external illustrations stay as authored.
        if (count($ids[1]) !== 1 || count($sizes[1]) !== 1) continue;
        $id = (int) $ids[1][0];
        $size = $sizes[1][0];
        $attachment = get_post($id);
        if (!$attachment || $attachment->post_type !== 'attachment'
            || $attachment->post_status === 'trash' || !wp_attachment_is_image($attachment)) continue;
        $metadata = wp_get_attachment_metadata($id);
        if ($size !== 'full' && !in_array($size, $registered_sizes, true)
            && (!is_array($metadata) || !isset($metadata['sizes'][$size]))) continue;
        $image = wp_get_attachment_image_src($id, $size);
        if (!is_array($image) || !is_string($image[0] ?? null)) continue;
        $new_url = $image[0];
        $old = wp_parse_url($old_url);
        $new = wp_parse_url($new_url);
        if (!is_array($old) || !is_array($new)
            || !in_array(strtolower($old['scheme'] ?? ''), ['http', 'https'], true)
            || !in_array(strtolower($new['scheme'] ?? ''), ['http', 'https'], true)
            || empty($old['host']) || empty($new['host'])
            || !in_array(strtolower($old['host']), [$site_host, strtolower($new['host'])], true)) continue;
        $old_file = rawurldecode(wp_basename($old['path'] ?? ''));
        $new_file = rawurldecode(wp_basename($new['path'] ?? ''));
        // A matching filename proves a moved path; an ID alone must not replace
        // another illustration. No guessed paths or network probes during rendering.
        if ($old_file === '' || $old_file !== $new_file) continue;

        $html->set_attribute('src', $new_url);
        if ($html->get_attribute('srcset') !== null) {
            $srcset = wp_get_attachment_image_srcset($id, $size, $metadata);
            if (is_string($srcset) && $srcset !== '') $html->set_attribute('srcset', $srcset);
            else $html->remove_attribute('srcset');
        }
    }
    return $html->get_updated_html();
}
add_filter('woocommerce_taxonomy_archive_description_raw', 'psu_category_description_media', 20, 2);
