<?php

namespace Lavka\ProductMediaUpload;

if (!defined('ABSPATH')) { exit; }

/** Server-owned snapshot and explicit operator decision for replacing a main image. */
final class MainImageReplacement
{
    public static function versioned_filename(string $filename, string $sha256): string
    {
        $extension = (string) pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = '-r' . substr(hash('sha256', $filename . '|' . $sha256), 0, 12) . '.' . $extension;
        // ALL_ARTC.S50 holds at most 50 CP1251 bytes; generated stems are ASCII.
        return rtrim(substr((string) pathinfo($filename, PATHINFO_FILENAME), 0, 50 - strlen($suffix)), '-_.') . $suffix;
    }

    public static function snapshot(array $row): array
    {
        $product = wc_get_product((int) $row['product_id']);
        if (!$product) {
            throw new \RuntimeException(__('The WooCommerce product disappeared before image assignment.', 'lavka-product-media-upload'));
        }
        // edit avoids treating an inherited variation image as its own attachment.
        $id = (int) $product->get_image_id('edit');
        $file = $id ? (string) get_post_meta($id, '_wp_attached_file', true) : '';
        return [
            'attachment_id' => $id,
            'file' => $file,
            'filename' => $file !== '' ? wp_basename($file) : '',
            'gallery_ids' => array_values(array_map('intval', $product->get_gallery_image_ids('edit'))),
            'required' => $id > 0 && $id !== (int) ($row['attachment_id'] ?? 0),
            'can_keep' => !$product->is_type('variation') && $file !== '',
        ];
    }

    public static function assert_current(array $row): void
    {
        $expected = $row['_main_snapshot'] ?? null;
        if (!is_array($expected)) {
            throw new \RuntimeException(__('Run the mandatory check before uploading.', 'lavka-product-media-upload'));
        }
        $current = self::snapshot($row);
        if ($current['attachment_id'] !== $expected['attachment_id']
            || $current['file'] !== $expected['file']
            || $current['can_keep'] !== $expected['can_keep']
            || $current['gallery_ids'] !== $expected['gallery_ids']) {
            throw new \RuntimeException(__('The product images changed after verification. Check the batch again and choose what to do with the current main image.', 'lavka-product-media-upload'));
        }
    }

    public static function choose(array $row, string $choice): array
    {
        $snapshot = $row['_main_snapshot'];
        if (!$snapshot['required']) { return $row; }
        if (!in_array($choice, ['gallery', 'remove'], true)
            || ($choice === 'gallery' && !$snapshot['can_keep'])) {
            throw new \RuntimeException(__('Choose whether to move the current main image to the gallery or remove it from this product.', 'lavka-product-media-upload'));
        }
        $row['previous_main_action'] = $choice;
        $row['previous_main_id'] = $snapshot['attachment_id'];
        $row['previous_main_file'] = $snapshot['filename'];
        return $row;
    }

    public static function gallery_after(array $row, array $gallery): array
    {
        $old = (int) ($row['previous_main_id'] ?? 0);
        if (!$old) { return $gallery; }
        $filename = (string) ($row['previous_main_file'] ?? '');
        $gallery = array_values(array_filter($gallery, static function ($id) use ($old, $filename): bool {
            if ((int) $id === $old) { return false; }
            $file = (string) get_post_meta((int) $id, '_wp_attached_file', true);
            return $filename === '' || wp_basename($file) !== $filename;
        }));
        if (($row['previous_main_action'] ?? '') === 'gallery') { $gallery[] = $old; }
        return $gallery;
    }
}
