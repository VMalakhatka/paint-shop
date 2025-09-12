<?php
namespace PaintCore\Orders;
defined('ABSPATH') || exit;

/** Primary term_id of location for product/variation. */
if (!function_exists(__NAMESPACE__.'\\pc_primary_location_term_id_for_product')) {
    function pc_primary_location_term_id_for_product(\WC_Product $product): int {
        $pid = $product->get_id();
        $tid = (int) get_post_meta($pid, '_yoast_wpseo_primary_location', true);
        if (!$tid && $product->is_type('variation')) {
            $parent = $product->get_parent_id();
            if ($parent) $tid = (int) get_post_meta($parent, '_yoast_wpseo_primary_location', true);
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

/** Human label for order item plan: "Київ — 2, Одеса — 1" (fallbacks: SLW → primary). */
if (!function_exists(__NAMESPACE__.'\\pc_order_item_location_label')) {
    function pc_order_item_location_label(\WC_Order_Item_Product $item): string {
        // 1) план списания
        $plan = \pc_get_order_item_plan($item);
        if (!empty($plan)) {
            $label = \pc_humanize_alloc_plan($plan);
            if ($label !== '') return $label;
        }

        // 2) SLW / машинные меты
        $loc_ids = $item->get_meta('_stock_locations', true);
        if (is_array($loc_ids) && $loc_ids) {
            $names = [];
            foreach ($loc_ids as $tid) {
                $t = get_term((int)$tid, 'location');
                if ($t && !is_wp_error($t)) $names[] = $t->name;
            }
            $names = array_values(array_unique(array_filter($names)));
            if ($names) return implode(', ', $names);
        }
        $id   = (int) $item->get_meta('_stock_location_id');
        $slug = (string) $item->get_meta('_stock_location_slug');
        if ($id)  { $t = get_term($id, 'location');              if ($t && !is_wp_error($t)) return $t->name; }
        if ($slug !== '') { $t = get_term_by('slug', $slug, 'location'); if ($t && !is_wp_error($t)) return $t->name; }

        // 3) primary у товара
        $product = $item->get_product();
        if ($product instanceof \WC_Product) {
            $tid = pc_primary_location_term_id_for_product($product);
            if ($tid) {
                $t = get_term($tid, 'location');
                if ($t && !is_wp_error($t)) return $t->name;
            }
        }

        // --- DEBUG fallback ---
        if (defined('PC_DEBUG_ALLOC') && PC_DEBUG_ALLOC) {
            return '[NO PLAN]'; // временно для отладки
        }

        
        return '';
    }
}