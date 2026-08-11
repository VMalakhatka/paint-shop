<?php

namespace Lavka\ProductMediaUpload;

if (!defined('ABSPATH')) {
    exit;
}

final class ProductResolver
{
    public function resolve(string $sku, string $barcode, bool $allow_missing_barcode = false): array
    {
        $sku_id = $sku !== '' ? (int) wc_get_product_id_by_sku($sku) : 0;
        $barcode_ids = $barcode !== '' ? $this->find_by_barcode($barcode) : [];
        $warnings = [];

        if (count($barcode_ids) > 1) {
            return [
                'ok' => false,
                'status' => 'IDENTIFIER_MISMATCH',
                'message' => __('The barcode belongs to more than one WooCommerce product.', 'lavka-product-media-upload'),
                'technical' => implode(',', $barcode_ids),
            ];
        }

        $barcode_id = $barcode_ids[0] ?? 0;
        if ($sku !== '' && $sku_id < 1) {
            return [
                'ok' => false,
                'status' => 'PRODUCT_NOT_FOUND',
                'message' => __('No WooCommerce product was found for the SKU.', 'lavka-product-media-upload'),
                'technical' => $sku,
            ];
        }
        if ($barcode !== '' && $barcode_id < 1 && !($allow_missing_barcode && $sku_id > 0)) {
            return [
                'ok' => false,
                'status' => 'PRODUCT_NOT_FOUND',
                'message' => __('No WooCommerce product was found for the barcode.', 'lavka-product-media-upload'),
                'technical' => $barcode,
            ];
        }
        if ($barcode !== '' && $barcode_id < 1 && $allow_missing_barcode && $sku_id > 0) {
            $warnings[] = __('The barcode was not found in WooCommerce. The SKU match is used for this row.', 'lavka-product-media-upload');
        }
        if ($sku_id > 0 && $barcode_id > 0 && $sku_id !== $barcode_id) {
            return [
                'ok' => false,
                'status' => 'IDENTIFIER_MISMATCH',
                'message' => __('The SKU and barcode point to different WooCommerce products.', 'lavka-product-media-upload'),
                'technical' => 'sku_product=' . $sku_id . '; barcode_product=' . $barcode_id,
            ];
        }

        $product_id = $sku_id ?: $barcode_id;
        $product = $product_id ? wc_get_product($product_id) : false;
        if (!$product || !in_array($product->get_type(), ['simple', 'variable', 'variation'], true)) {
            return [
                'ok' => false,
                'status' => 'PRODUCT_NOT_FOUND',
                'message' => __('The matched object is not a supported WooCommerce product or variation.', 'lavka-product-media-upload'),
                'technical' => (string) $product_id,
            ];
        }

        return [
            'ok' => true,
            'product_id' => $product_id,
            'product' => $product,
            'product_sku' => (string) $product->get_sku(),
            'product_type' => $product->get_type(),
            'product_name' => $product->get_name(),
            'warnings' => $warnings,
        ];
    }

    private function find_by_barcode(string $barcode): array
    {
        global $wpdb;

        $keys = (array) apply_filters('lavka_product_media_upload_barcode_meta_keys', [
            '_wc_gtin_code',
            '_global_unique_id',
            '_wpm_gtin_code',
            '_alg_ean',
            '_ean',
            '_sku_gtin',
            '_gtin',
        ]);
        $keys = array_values(array_filter(array_map('sanitize_key', $keys)));
        if (!$keys) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $params = array_merge($keys, [$barcode]);
        $sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status <> 'trash'
              AND pm.meta_key IN ({$placeholders})
              AND BINARY pm.meta_value = BINARY %s
            LIMIT 3
        ";

        return array_map(
            'intval',
            $wpdb->get_col($wpdb->prepare($sql, $params)) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }
}
