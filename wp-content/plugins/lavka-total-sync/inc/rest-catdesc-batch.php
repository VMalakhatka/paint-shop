<?php
/**
 * Lavka Sync — массовое обновление описаний категорий.
 *
 * POST /wp-json/lavka/v1/catdesc/batch
 * Body: { "items": [ { "term_id":3942, "html":"<p>текст</p>" }, ... ] }
 */

add_action('rest_api_init', function () {
    register_rest_route('lavka/v1', '/catdesc/batch', [
        'methods'  => 'POST',
        'permission_callback' => function () {
            // тот же доступ, что и у sync
            return current_user_can('manage_lavka_sync');
        },
        'callback' => 'lavka_sync_catdesc_batch',
    ]);
});

function lavka_sync_catdesc_batch(WP_REST_Request $req) {
    $items = $req->get_param('items');
    if (!is_array($items)) {
        return new WP_Error('bad_request', 'items must be array', ['status' => 400]);
    }

    $opt = function_exists('lts_get_options') ? lts_get_options() : [];
    $prefix = isset($opt['cat_desc_prefix_html']) ? trim((string)$opt['cat_desc_prefix_html']) : '';
    $suffix = isset($opt['cat_desc_suffix_html']) ? trim((string)$opt['cat_desc_suffix_html']) : '';

    $updated = 0;
    $skipped = 0;
    $errors  = [];

    // временно отключаем очистку HTML
    remove_filter('pre_term_description', 'wp_filter_kses');
    remove_filter('term_description', 'wp_kses_data');

    foreach ($items as $row) {
        $term_id = isset($row['term_id']) ? intval($row['term_id']) : 0;
        $html    = isset($row['html']) ? trim((string)$row['html']) : '';

        if ($term_id <= 0 || $html === '') {
            $skipped++;
            continue;
        }

        $finalHtml = lavka_sync_prepare_catdesc($html, $prefix, $suffix);

        // читаем текущее описание
        $current = term_description($term_id, 'product_cat');
        if (is_string($current)) {
            $current = trim($current);
        }

        // если одинаково — пропускаем
        if ($current === $finalHtml) {
            $skipped++;
            continue;
        }

        // обновляем описание
        $res = wp_update_term($term_id, 'product_cat', ['description' => $finalHtml]);

        if (is_wp_error($res)) {
            $errors[] = [
                'term_id' => $term_id,
                'message' => $res->get_error_message(),
            ];
        } else {
            $updated++;
        }
    }

    // возвращаем фильтры на место
    add_filter('pre_term_description', 'wp_filter_kses');
    add_filter('term_description', 'wp_kses_data');

    return [
        'ok'       => true,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'prefix'   => $prefix,
        'suffix'   => $suffix,
    ];
}

/**
 * Склеивает HTML категории с префиксом/суффиксом.
 */
function lavka_sync_prepare_catdesc(string $html, string $prefix, string $suffix): string {
    $html = trim($html);
    if ($html === '') return '';

    $hasPrefix = ($prefix !== '' && str_starts_with($html, trim($prefix)));
    $hasSuffix = ($suffix !== '' && str_ends_with($html, trim($suffix)));

    return ($hasPrefix ? '' : $prefix) . $html . ($hasSuffix ? '' : $suffix);
}