<?php
/** Folio supplier -> the existing Woo product_brand taxonomy. */
defined('ABSPATH') || exit;

function lts_supplier_term_ids($name) {
    if (!is_string($name) || mb_strlen($name) > 200 || preg_match('/[\x00-\x1f\x7f<>]/u', $name)) {
        return new WP_Error('lts_supplier_invalid', 'FULL_SYNC_SUPPLIER_INVALID', ['status' => 400]);
    }
    if (!taxonomy_exists('product_brand') || !method_exists('WC_Product', 'set_brand_ids')) {
        return new WP_Error('lts_supplier_unavailable', 'FULL_SYNC_SUPPLIER_UNAVAILABLE', ['status' => 503]);
    }
    $name = trim($name);
    if ($name === '') return [];
    $term = get_term_by('name', $name, 'product_brand');
    if ($term) return [(int) $term->term_id];

    $created = wp_insert_term($name, 'product_brand');
    if (is_wp_error($created)) {
        // A concurrent writer may have created exactly this term, not a slug neighbour.
        if ($created->get_error_code() === 'term_exists') {
            $term = get_term_by('name', $name, 'product_brand');
            if ($term) return [(int) $term->term_id];
        }
        return new WP_Error('lts_supplier_save_failed', 'FULL_SYNC_SUPPLIER_SAVE_FAILED', ['status' => 500]);
    }
    return [(int) $created['term_id']];
}

function lts_verify_product_supplier(WC_Product $product, array $expected) {
    $actual = wp_get_object_terms($product->get_id(), 'product_brand', ['fields' => 'ids']);
    if (!is_wp_error($actual)) {
        $actual = array_map('intval', $actual);
        sort($actual);
        sort($expected);
        if ($actual === $expected) return true;
    }
    // Never acknowledge a source hash when its supplier assignment did not persist.
    $product->delete_meta_data('_ms_hash');
    $product->save_meta_data();
    return new WP_Error('lts_supplier_save_failed', 'FULL_SYNC_SUPPLIER_SAVE_FAILED', ['status' => 500]);
}

function lts_apply_product_supplier(int $product_id, $name) {
    $ids = lts_supplier_term_ids($name);
    if (is_wp_error($ids)) return $ids;
    $product = wc_get_product($product_id);
    if (!$product) return new WP_Error('lts_supplier_product_missing', 'FULL_SYNC_PRODUCT_MISSING', ['status' => 404]);
    $product->set_brand_ids($ids);
    $product->save();
    return lts_verify_product_supplier($product, $ids);
}

add_filter('woocommerce_rest_product_schema', static function (array $properties): array {
    if (taxonomy_exists('product_brand') && method_exists('WC_Product', 'set_brand_ids')) {
        $properties['lavka_supplier'] = [
            'description' => 'Folio supplier name; an empty string clears the supplier assignment.',
            'type' => 'string', 'context' => ['edit'], 'maxLength' => 200,
            'x-lavka-full-sync-supplier' => true,
        ];
    }
    return $properties;
});

add_filter('woocommerce_rest_pre_insert_product_object', static function ($product, $request) {
    if (is_wp_error($product) || !$request->has_param('lavka_supplier')) return $product;
    if ($request->has_param('brands')) {
        return new WP_Error('lts_supplier_conflict', 'FULL_SYNC_SUPPLIER_FIELDS_CONFLICT', ['status' => 400]);
    }
    $ids = lts_supplier_term_ids($request->get_param('lavka_supplier'));
    if (is_wp_error($ids)) return $ids;
    $product->set_brand_ids($ids);
    return $product;
}, 20, 2);

add_action('woocommerce_rest_insert_product_object', static function ($product, $request): void {
    if (!$request->has_param('lavka_supplier')) return;
    $result = lts_verify_product_supplier($product, $product->get_brand_ids('edit'));
    if (is_wp_error($result)) {
        throw new WC_REST_Exception($result->get_error_code(), $result->get_error_message(), 500);
    }
}, 100, 2);
