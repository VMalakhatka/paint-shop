<?php
if (!defined('ABSPATH')) exit;

/** Универсальная проверка доступа к нашим REST-роутам
 *  1) Basic Auth через Application Passwords (user должен иметь manage_lavka_sync)
 *  2) ИЛИ заголовок X-Auth-Token, совпадающий с опцией api_token
 */
function lavka_rest_auth( WP_REST_Request $req ) {
    $u = wp_get_current_user(); // будет установлен при Basic Auth (App Password)
    if ( $u && $u->ID && user_can( $u, 'manage_lavka_sync' ) ) {
        return true;
    }
    // 1) Application Password / Basic Auth
    if (is_user_logged_in()) {
        $u = wp_get_current_user();
        if ($u && user_can($u, 'manage_lavka_sync')) return true;
    }

    // 2) Fallback-токен
    $opts = lavka_sync_get_options();
    $hdr  = $req->get_header('x-auth-token') ?: '';
    if (!empty($opts['api_token']) && hash_equals($opts['api_token'], $hdr)) {
        return true;
    }

    return new WP_Error('forbidden', 'Unauthorized', ['status'=>401]);
}

/** Справочник складов (таксономия location) */
add_action('rest_api_init', function () {
    $ns = 'lavka/v1';

    register_rest_route($ns, '/locations', [
        'methods'  => 'GET',
        'permission_callback' => 'lavka_rest_auth',
        'callback' => function( WP_REST_Request $req ) {
            $args = [
                'taxonomy'   => 'location',
                'hide_empty' => (int)($req->get_param('hide_empty') ?? 0) === 1,
                'search'     => sanitize_text_field($req->get_param('search') ?? ''),
                'number'     => max(1, min(200, (int)($req->get_param('per_page') ?? 100))),
                'offset'     => max(0, (int)($req->get_param('offset') ?? 0)),
            ];
            $terms = get_terms($args);
            if (is_wp_error($terms)) {
                return new WP_REST_Response(['ok'=>false,'error'=>$terms->get_error_message()], 500);
            }

            // total без пагинации
            $total = (int) wp_count_terms([
                'taxonomy' => 'location',
                'hide_empty' => $args['hide_empty'],
                'search' => $args['search'],
            ]);

            $items = [];
            foreach ($terms as $t) {
                $items[] = [
                    'id'     => (int)$t->term_id,
                    'slug'   => (string)$t->slug,
                    'name'   => (string)$t->name,
                    'parent' => (int)$t->parent,
                    'count'  => (int)$t->count,
                ];
            }
            return new WP_REST_Response([
                'page'       => (int) floor($args['offset'] / $args['number']) + 1,
                'per_page'   => (int) $args['number'],
                'total'      => $total,
                'totalPages' => (int) ceil($total / $args['number']),
                'items'      => $items,
            ], 200);
        },
    ]);

     // /health — быстрый тест доступности и прав
    register_rest_route($ns, '/health', [
        'methods'  => 'GET',
        'permission_callback' => 'lavka_rest_auth',
        'callback' => function(\WP_REST_Request $r) {
            return new WP_REST_Response([
                'ok'    => true,
                'time'  => current_time('mysql'),
                'user'  => is_user_logged_in() ? wp_get_current_user()->user_login : null,
                'site'  => get_bloginfo('name'),
                'ver'   => LAVKA_SYNC_VER,
            ], 200);
        },
    ]);

});

add_action('rest_api_init', function () {
    $ns = 'lavka/v1';

    register_rest_route($ns, '/stock/bulk', [
        'methods'  => WP_REST_Server::CREATABLE, // POST
        'permission_callback' => 'lavka_rest_auth',
        'callback' => function( WP_REST_Request $req ) {

            // --- ВАЛИДАЦИЯ ТЕЛА JSON ---
            $json = $req->get_json_params() ?: [];
            if (!is_array($json)) {
                return new WP_REST_Response(['ok'=>false,'error'=>'Invalid JSON body'], 400);
            }

            $items = $json['items'] ?? null;
            if (!is_array($items) || empty($items)) {
                return new WP_REST_Response([
                    'ok'    => false,
                    'error' => "Field 'items' must be a non-empty array"
                ], 422);
            }

            // булевы флаги из тела
            $flags = ['set_manage','upd_status','attach_terms','set_primary','duplicate_slug_meta','dry'];
            $opt = [];
            foreach ($flags as $k) {
                $opt[$k] = filter_var($json[$k] ?? false, FILTER_VALIDATE_BOOL);
            }

            // проверяем каждый item: ТОЛЬКО stocks
            $errors = [];
            foreach ($items as $idx => $it) {
                if (!is_array($it)) { $errors[] = "items[$idx] must be an object"; continue; }

                $sku = isset($it['sku']) ? trim((string)$it['sku']) : '';
                if ($sku === '') $errors[] = "items[$idx].sku is required";

                if (!isset($it['stocks']) || !is_array($it['stocks']) || empty($it['stocks'])) {
                    $errors[] = "items[$idx].stocks must be a non-empty array";
                    continue;
                }
                foreach ($it['stocks'] as $j => $row) {
                    if (!is_array($row)) { $errors[] = "items[$idx].stocks[$j] must be an object"; continue; }
                    $slug = isset($row['location_slug']) ? sanitize_title($row['location_slug']) : '';
                    if ($slug === '') $errors[] = "items[$idx].stocks[$j].location_slug is required";
                    if (!array_key_exists('qty', $row) || !is_numeric($row['qty'])) {
                        $errors[] = "items[$idx].stocks[$j].qty must be a number";
                    }
                }
            }

            if ($errors) {
                return new WP_REST_Response([
                    'ok'     => false,
                    'error'  => 'Validation failed',
                    'errors' => $errors
                ], 422);
            }
            // --- /валидация ---

            // JSON уже в $json, items валидны
            $items = $json['items'];

            // дефолты из опций (если какие-то ключи не заведены — будут false)
            $o = lavka_sync_get_options();
            $defaults = [
                'dry'                 => false,
                'set_manage'          => !empty($o['set_manage']),
                'upd_status'          => !empty($o['upd_status']),
                'attach_terms'        => !empty($o['attach_terms']),
                'set_primary'         => !empty($o['set_primary']),
                'duplicate_slug_meta' => !empty($o['duplicate_slug_meta']),
            ];

            // что пришло в JSON по флагам (мы их уже собрали в $opt выше)
            $opts = array_merge($defaults, $opt);

            // ограничение на размер пачки
            $max = 500;
            if (count($items) > $max) {
                return new WP_REST_Response(['ok'=>false,'error'=>"Too many items (max {$max})"], 413);
            }

            // обработка
            $results   = [];
            $not_found = 0;

            foreach ($items as $it) {
                $sku   = (string)($it['sku'] ?? '');
                $lines = (array) ($it['stocks'] ?? []);
                if ($sku === '' || !$lines) continue;

                $res = lavka_write_stock_for_sku($sku, $lines, $opts);
                $results[] = $res;
                if (empty($res['found'])) $not_found++;
            }

            return new WP_REST_Response([
                'ok'         => true,
                'dry'        => (bool)$opts['dry'],
                'processed'  => count($results),
                'not_found'  => $not_found,
                'results'    => $results,
            ], 200);
        },
    ]);
});

/** ====== МЭППИНГ СКЛАДОВ: GET/PUT /locations/map ====== */

/** нормализация кода внешнего склада */
function lavka_map_norm_code($s){
    $s = trim((string)$s);
    if ($s === '') return '';
    // приводим к верхнему регистру и убираем лишние пробелы
    return strtoupper(preg_replace('~\s+~',' ',$s));
}

/** собрать мэппинг из таксономии: [{term_id, slug, name, codes:[]}, …] */
function lavka_map_collect(): array {
    $terms = get_terms([
        'taxonomy'   => 'location',
        'hide_empty' => false,
        'number'     => 0,
    ]);
    if (is_wp_error($terms) || empty($terms)) return [];

    $out = [];
    foreach ($terms as $t) {
        $codes = get_term_meta($t->term_id, 'lavka_ext_codes', true);
        $codes = is_array($codes) ? array_values(array_unique(array_filter(array_map('lavka_map_norm_code',$codes)))) : [];
        $out[] = [
            'term_id' => (int)$t->term_id,
            'slug'    => (string)$t->slug,
            'name'    => (string)$t->name,
            'codes'   => $codes,
        ];
    }
    return $out;
}

/** записать мэппинг: payload {"mapping":[{"term_id":3943,"codes":["ODESA","WH-7"]}, ...]} */
function lavka_map_write(array $mapping): array {
    $written = 0; $errors = [];
    foreach ($mapping as $row) {
        $tid   = (int)($row['term_id'] ?? 0);
        $codes = $row['codes'] ?? [];
        if ($tid <= 0 || !is_array($codes)) { $errors[] = ['term_id'=>$tid,'error'=>'bad_row']; continue; }

        $codes = array_values(array_unique(array_filter(array_map('lavka_map_norm_code',$codes))));
        update_term_meta($tid, 'lavka_ext_codes', $codes);
        $written++;
    }
    return ['written'=>$written, 'errors'=>$errors];
}

add_action('rest_api_init', function () {
    $ns = 'lavka/v1';

    register_rest_route($ns, '/stock/query-apply', [
        'methods'  => WP_REST_Server::CREATABLE, // POST
        'permission_callback' => 'lavka_rest_auth', // App Password или X-Auth-Token
        'callback' => function( WP_REST_Request $req ) {
            $json = (array)$req->get_json_params();
            $skus = (array)($json['skus'] ?? []);
            $dry  = filter_var($req->get_param('dry') ?? $json['dry'] ?? false, FILTER_VALIDATE_BOOL);
            $res  = lavka_sync_java_query_and_apply($skus, ['dry'=>$dry]);
            return new WP_REST_Response($res, !empty($res['ok']) ? 200 : 400);
        },
        'args' => [
            'dry' => [ 'required'=>false ],
        ],
    ]);
});

add_action('rest_api_init', function(){
    $ns = 'lavka/v1';

    // GET /locations/map — прочитать текущий мэппинг
    register_rest_route($ns, '/locations/map', [
        'methods'  => 'GET',
        'permission_callback' => 'lavka_rest_auth', // cookie+nonce (админ) ИЛИ Basic/X-Auth-Token (Java)
        'callback' => function( WP_REST_Request $req ){
            return new WP_REST_Response([
                'items' => lavka_map_collect()
            ], 200);
        },
    ]);

    // PUT /locations/map — сохранить мэппинг
    register_rest_route($ns, '/locations/map', [
        'methods'  => 'PUT',
        'permission_callback' => 'lavka_rest_auth',
        'callback' => function( WP_REST_Request $req ){
            $json = $req->get_json_params() ?: [];
            $mapping = $json['mapping'] ?? null;
            if (!is_array($mapping)) {
                return new WP_REST_Response(['ok'=>false,'error'=>"Body must contain 'mapping' array"], 422);
            }
            $res = lavka_map_write($mapping);
            return new WP_REST_Response(['ok'=>true] + $res, 200);
        },
    ]);
});