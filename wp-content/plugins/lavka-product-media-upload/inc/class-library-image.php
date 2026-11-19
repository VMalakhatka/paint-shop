<?php

namespace Lavka\ProductMediaUpload;

if (!defined('ABSPATH')) { exit; }

final class LibraryImage
{
    public static function describe(int $id): ?array
    {
        if (!$id || !wp_attachment_is_image($id) || get_post_status($id) === 'trash' || !current_user_can('read_post', $id)) {
            return null;
        }
        $file = (string) get_post_meta($id, '_wp_attached_file', true);
        $url = esc_url_raw((string) wp_get_attachment_url($id), ['http', 'https']);
        if ($file === '' || !$url) {
            return null;
        }
        return ['id' => $id, 'name' => wp_basename($file), 'url' => $url];
    }

    /** These are suggestions for the manager, never an automatic match. */
    public static function candidates(array $row): array
    {
        global $wpdb;
        $product = wc_get_product($row['product_id']);
        $ids = $product ? array_merge([(int) $product->get_image_id('edit')], $product->get_gallery_image_ids('edit')) : [];
        $names = array_unique(array_filter([$row['suggested_file'] ?? '', $row['canonical_file'] ?? '']));
        foreach ($names as $filename) {
            $suffix = '%/' . $wpdb->esc_like($filename);
            $stem = pathinfo($filename, PATHINFO_FILENAME);
            $scaled_suffix = '%/' . $wpdb->esc_like($stem . '-scaled.' . pathinfo($filename, PATHINFO_EXTENSION));
            // WordPress can store a scaled file while retaining the original name on the attachment.
            $matches = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_wp_attached_file'
                 WHERE p.post_type='attachment' AND p.post_mime_type LIKE 'image/%%' AND (LOWER(m.meta_value)=LOWER(%s)
                   OR LOWER(m.meta_value) LIKE LOWER(%s) OR LOWER(p.guid) LIKE LOWER(%s)
                   OR LOWER(m.meta_value) LIKE LOWER(%s) OR p.post_name=%s) ORDER BY p.ID LIMIT 20",
                $filename, $suffix, $suffix, $scaled_suffix, sanitize_title($stem)
            ));
            $ids = array_merge($matches, $ids);
        }
        $ids[] = $row['conflict_attachment_id'] ?? 0;
        $ids[] = $row['attachment_id'] ?? 0;
        return array_values(array_filter(array_map([self::class, 'describe'], array_unique(array_map('intval', $ids)))));
    }
}
