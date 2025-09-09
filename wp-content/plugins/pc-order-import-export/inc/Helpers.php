<?php
namespace PaintCore\PCOE;

use WC_Product;

class Helpers {
    // Текстові мітки (1 місце для перекладів)
    public static function labels(): array {
        return [
            'btn_csv'       => 'Завантажити CSV',
            'btn_xlsx'      => 'Завантажити XLSX',
            'conf_toggle'   => 'Налаштувати експорт',
            'split_label'   => 'Формат позицій:',
            'split_agg'     => 'Загальна (у примітках «Списання: …»)',
            'split_per_loc' => 'Окремо по кожному складу',
            'col_sku'       => 'Артикул',
            'col_gtin'      => 'GTIN',
            'col_name'      => 'Назва',
            'col_qty'       => 'К-сть',
            'col_price'     => 'Ціна',
            'col_total'     => 'Сума',
            'col_note'      => 'Примітка',
            'filename_cart'  => 'cart',
            'filename_order' => 'order',
            'xls_missing'    => 'XLSX недоступний (PhpSpreadsheet не знайдено) — збережено як CSV.',
        ];
    }

    public static function columns(): array {
        $L = self::labels();
        $cols = [
            'sku'   => $L['col_sku'],
            'gtin'  => $L['col_gtin'],
            'name'  => $L['col_name'],
            'qty'   => $L['col_qty'],
            'price' => $L['col_price'],
            'total' => $L['col_total'],
            'note'  => $L['col_note'],
        ];
        return apply_filters('pcoe_columns', $cols);
    }

    public static function gtin_meta_keys(): array {
        $keys = ['_global_unique_id','_wpm_gtin_code','_alg_ean','_ean','_sku_gtin'];
        return apply_filters('pcoe_gtin_meta_keys', $keys);
    }

    public static function product_gtin(WC_Product $product): string {
        // якщо є глобальний хелпер core — віддай йому
        if (function_exists('pc_get_product_gtin')) {
            $v = (string) pc_get_product_gtin($product);
            if ($v !== '') return $v;
        }
        $read = function($id){
            foreach (self::gtin_meta_keys() as $k){
                $raw = get_post_meta($id, $k, true);
                if ($raw !== '' && $raw !== null) return (string)$raw;
            }
            return '';
        };
        $v = $read($product->get_id());
        if ($v !== '') return $v;
        if ($product->is_type('variation') && $product->get_parent_id()){
            $v = $read($product->get_parent_id());
        }
        return (string)$v;
    }

    public static function find_product_by_gtin(string $gtin): int {
        foreach (self::gtin_meta_keys() as $k) {
            $q = new \WP_Query([
                'post_type'      => ['product','product_variation'],
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => 1,
                'meta_query'     => [['key'=>$k,'value'=>$gtin,'compare'=>'=']],
            ]);
            if ($q->have_posts()) { $id = (int)$q->posts[0]; wp_reset_postdata(); return $id; }
            wp_reset_postdata();
        }
        return 0;
    }

    // нормалізатор колонки + мапінг заголовку
    public static function norm(string $s): string {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        $s = str_replace(['ё','й'], ['е','и'], $s);
        return preg_replace('~\s+~u',' ', $s);
    }
    public static function build_colmap(array $header): array {
        $syn = [
            'sku'   => ['sku','артикул'],
            'gtin'  => ['gtin','штрих код','штрих-код','штрихкод','ean','upc'],
            'qty'   => ['qty','к-сть','кількість','количество','quantity'],
            'price' => ['price','ціна','цена'],
        ];
        $map = ['sku'=>null,'gtin'=>null,'qty'=>null,'price'=>null];
        $h = array_map([__CLASS__,'norm'], $header);
        foreach ($map as $k => $_) {
            foreach ($syn[$k] as $want) {
                $pos = array_search($want, $h, true);
                if ($pos !== false) { $map[$k] = $pos; break; }
            }
        }
        if ($map['qty'] === null) $map['qty'] = 1;
        if ($map['sku'] === null && $map['gtin'] === null) $map['sku'] = 0;
        return apply_filters('pcoe_colmap', $map, $header);
    }

    // парсер кількості (., ,; пробіли-тисячні)
    public static function parse_qty($raw): float {
        $s = trim((string)$raw);
        if ($s === '') return 0.0;
        $s = str_replace(["\xC2\xA0",' '], '', $s);
        if (strpos($s, ',') !== false && strpos($s, '.') === false) $s = str_replace(',', '.', $s);
        return (float)$s;
    }

    // локація + “Списання: …” (через фільтри, щоб не тягнути core напряму)
    public static function primary_location_id(WC_Product $product): int {
        $tid = (int) apply_filters('pcoe_primary_location_id', 0, $product);
        if ($tid) return $tid;
        // дефолт: meta від Yoast Primary (як у твоїй реалізації)
        $pid = $product->get_id();
        $tid = (int) get_post_meta($pid, '_yoast_wpseo_primary_location', true);
        if (!$tid && $product->is_type('variation') && $product->get_parent_id()){
            $tid = (int) get_post_meta($product->get_parent_id(), '_yoast_wpseo_primary_location', true);
        }
        return $tid ?: 0;
    }
    public static function loc_name_by_id(int $tid): string {
        $name = apply_filters('pcoe_location_name', '', $tid);
        if ($name !== '') return $name;
        $t = get_term($tid, 'location');
        return ($t && !is_wp_error($t)) ? $t->name : '';
    }
    public static function note_from_plan(array $plan): string {
        error_log('NOTE_FROM_PLAN input: ' . print_r($plan, true));
        if (!$plan) return '';
        arsort($plan, SORT_NUMERIC);
        $parts = [];
        foreach ($plan as $tid=>$q) {
            $n = self::loc_name_by_id((int)$tid);
            if ($n && $q>0) $parts[] = $n.' × '.(int)$q;
        }
        return $parts ? ('Списання: '.implode(', ', $parts)) : '';
    }
}