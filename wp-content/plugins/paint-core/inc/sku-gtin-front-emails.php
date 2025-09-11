<?php
namespace PaintCore\Orders;

defined('ABSPATH') || exit;

/**
 * Warehouse label for order item rows in emails.
 * Here there is NO CSV/GTIN — only the warehouse label definition.
 * CSV attachment and GTIN helpers live in order-attach-csv.php.
 */

/** ------------------------- Locations / warehouse ------------------------- */

/** Primary term_id of location for product/variation. */
if (!function_exists(__NAMESPACE__.'\\pc_primary_location_term_id_for_product')) {
    function pc_primary_location_term_id_for_product(\WC_Product $product): int {
        $pid = $product->get_id();
        $tid = (int) get_post_meta($pid, '_yoast_wpseo_primary_location', true);
        if (!$tid && $product->is_type('variation')) {
            $parent = $product->get_parent_id();
            if ($parent) {
                $tid = (int) get_post_meta($parent, '_yoast_wpseo_primary_location', true);
            }
        }
        return $tid ?: 0;
    }
}

/** Location name by term_id/slug (safe). */
if (!function_exists(__NAMESPACE__.'\\pc_location_name_by')) {
    function pc_location_name_by($term_id = 0, string $slug = ''): string {
        if ($term_id) {
            $t = get_term((int)$term_id, 'location');
            if ($t && !is_wp_error($t)) return $t->name;
        }
        if ($slug !== '') {
            $t = get_term_by('slug', $slug, 'location');
            if ($t && !is_wp_error($t)) return $t->name;
        }
        return '';
    }
}

/**
 * Unified read of allocation plan from order item.
 * Supports new key _pc_alloc_plan and legacy _pc_stock_breakdown.
 * Guard in case order-attach-csv.php is not yet loaded.
 */
if (!function_exists(__NAMESPACE__.'\\pc_get_order_item_plan')) {
    function pc_get_order_item_plan(\WC_Order_Item_Product $item): array {
        $plan = $item->get_meta('_pc_alloc_plan', true);
        if (!is_array($plan) || !$plan) {
            $plan = $item->get_meta('_pc_stock_breakdown', true);
            if (!is_array($plan)) {
                $try  = json_decode((string)$plan, true);
                $plan = is_array($try) ? $try : [];
            }
        }
        $out = [];
        foreach ((array)$plan as $tid => $q) {
            $tid = (int)$tid; $q = (int)$q;
            if ($tid > 0 && $q > 0) $out[$tid] = $q;
        }
        return $out;
    }
}

/**
 * Return human-readable warehouse breakdown for item:
 * e.g. "Kyiv — 2, Odesa — 1".
 */
if (!function_exists(__NAMESPACE__.'\\pc_order_item_location_label')) {
    function pc_order_item_location_label(\WC_Order_Item_Product $item): string {
        // 1) Already saved visible meta “Warehouse”
        $human = (string) $item->get_meta(__('Warehouse','paint-core'));
        if ($human !== '') return $human;

        // 2) Allocation plan (main source)
        $plan = pc_get_order_item_plan($item);
        if (!empty($plan)) {
            $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
            $dict  = [];
            if (!is_wp_error($terms)) {
                foreach ($terms as $t) $dict[(int)$t->term_id] = $t->name;
            }
            arsort($plan, SORT_NUMERIC);
            $parts = [];
            foreach ($plan as $tid => $q) {
                $name = $dict[(int)$tid] ?? ('#'.(int)$tid);
                $parts[] = $name . ' — ' . (int)$q;
            }
            if ($parts) return implode(', ', $parts);
        }

        // 3) Machine single metas
        $id   = (int) $item->get_meta('_stock_location_id');
        $slug = (string) $item->get_meta('_stock_location_slug');
        $name = pc_location_name_by($id, $slug);
        if ($name !== '') return $name;

        // 4) Fallback: SLW array
        $loc_ids = $item->get_meta('_stock_locations', true);
        if (is_array($loc_ids) && $loc_ids) {
            $names = [];
            foreach ($loc_ids as $tid) {
                $n = pc_location_name_by((int)$tid, '');
                if ($n !== '') $names[] = $n;
            }
            $names = array_values(array_unique(array_filter($names)));
            if ($names) return implode(', ', $names);
        }

        // 5) Final fallback — primary location
        $product = $item->get_product();
        if ($product instanceof \WC_Product) {
            $tid = pc_primary_location_term_id_for_product($product);
            if ($tid) {
                $n = pc_location_name_by($tid, '');
                if ($n !== '') return $n;
            }
        }
        return '';
    }
}