<?php
/*
Plugin Name: Stock Sync → WooCommerce
Description: Синхронизирует wp_stock_import → меты товара (_stock_at_{TERM_ID}), суммирует в _stock, проставляет таксономию location и, при желании, дублирует меты по slug. Умеет удалять обработанные строки и крутиться до опустошения таблицы.
Version: 1.3.0
Author: PaintCore
*/
if (!defined('ABSPATH')) exit;

class PC_Stock_Sync_Woo {
    const SLUG = 'pc-stock-sync-woo';
    const CAP  = 'manage_woocommerce'; // можно заменить на manage_options

    public function __construct() {
        add_action('admin_menu', [$this,'menu']);
        add_action('admin_init', [$this,'maybe_handle']);
    }

    public function menu() {
        add_submenu_page(
            'tools.php',
            'Синхронизация остатков → WooCommerce',
            'Синхр. остатков → Woo',
            self::CAP,
            self::SLUG,
            [$this,'page']
        );
    }

    private $report = null;

    public function page() {
        if (!current_user_can(self::CAP)) wp_die('Недостаточно прав.');
        $r = $this->report;
        ?>
        <div class="wrap">
            <h1>Синхронизация остатков → WooCommerce</h1>

            <p style="max-width:960px">
                Берём строки из <code>wp_stock_import (sku, location_slug, qty)</code>, ищем товар по SKU, находим склад по
                <code>location_slug</code> в таксономии <code>location</code> и пишем остаток в мету
                <code>_stock_at_{TERM_ID}</code>. Затем суммируем по SKU и обновляем <code>_stock</code>.
                Можно автоматически привязать товар к терминам <code>location</code> и выставить Primary.
            </p>

            <form method="post">
                <?php wp_nonce_field('pc_stock_sync'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Batch size</th>
                        <td>
                            <input type="number" name="batch" value="<?php echo isset($_POST['batch']) ? intval($_POST['batch']) : 500; ?>" min="1" max="5000" step="1" style="width:120px">
                            <p class="description">Сколько строк из <code>wp_stock_import</code> обработать за один проход.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Dry‑Run</th>
                        <td>
                            <label><input type="checkbox" name="dry" value="1" <?php checked(!empty($_POST['dry'])); ?>> Только показать, без записи</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Фильтр по SKU (префикс)</th>
                        <td>
                            <input type="text" name="sku_prefix" value="<?php echo isset($_POST['sku_prefix']) ? esc_attr($_POST['sku_prefix']) : ''; ?>" placeholder="например, CR- или AB-" style="width:240px">
                            <p class="description">Если указать префикс, обработаем только строки, где SKU начинается с него.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Обновлять статус наличия?</th>
                        <td><label><input type="checkbox" name="upd_status" value="1" <?php checked(!empty($_POST['upd_status']), true); ?>> Да</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Включать manage_stock?</th>
                        <td><label><input type="checkbox" name="set_manage" value="1" <?php checked(!empty($_POST['set_manage']), true); ?>> Да</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Удалять обработанные строки из wp_stock_import?</th>
                        <td><label><input type="checkbox" name="delete_rows" value="1" <?php checked(!empty($_POST['delete_rows'])); ?>> Да, после успешной записи</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Крутиться до пустой таблицы?</th>
                        <td>
                            <label><input type="checkbox" name="loop_until_empty" value="1" <?php checked(!empty($_POST['loop_until_empty'])); ?>> Да</label>
                            <span style="margin-left:12px">Max loops: <input type="number" name="max_loops" value="<?php echo isset($_POST['max_loops']) ? intval($_POST['max_loops']) : 50; ?>" min="1" max="1000" style="width:90px"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Привязывать tax. <code>location</code> к товарам?</th>
                        <td><label><input type="checkbox" name="attach_terms" value="1" <?php checked(!empty($_POST['attach_terms']), true); ?>> Да</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Ставить Primary location, если отсутствует?</th>
                        <td><label><input type="checkbox" name="set_primary" value="1" <?php checked(!empty($_POST['set_primary']), true); ?>> Да</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Дублировать меты по slug (для совместимости)?</th>
                        <td><label><input type="checkbox" name="duplicate_slug_meta" value="1" <?php checked(!empty($_POST['duplicate_slug_meta'])); ?>> Да, писать ещё <code>_stock_at_{slug}</code></label></td>
                    </tr>
                </table>

                <p class="submit">
                    <button class="button button-primary">Синхронизировать</button>
                </p>
            </form>

            <?php if ($r): ?>
                <hr>
                <h2>Отчёт</h2>
                <pre style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:500px;overflow:auto"><?php
                    echo esc_html(print_r($r, true));
                ?></pre>
            <?php endif; ?>

            <p style="margin-top:10px;color:#666">Подсказка: текущий размер таблицы можно посмотреть запросом <code>SELECT COUNT(*) FROM wp_stock_import;</code></p>
        </div>
        <?php
    }

    public function maybe_handle() {
        if (!is_admin() || empty($_GET['page']) || $_GET['page'] !== self::SLUG) return;
        if (!current_user_can(self::CAP)) return;
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'pc_stock_sync')) return;

        // блокировка от повторного запуска
        $lock_key = 'pc_stock_sync_lock';
        if (get_transient($lock_key)) {
            $this->report = ['ok'=>0,'error'=>'Sync is already running. Try again in ~30s.'];
            return;
        }
        set_transient($lock_key, 1, 30); // 30 сек

        $opts = [
            'batch'              => max(1, min(2000, intval($_POST['batch'] ?? 500))),
            'dry'                => !empty($_POST['dry']),
            'sku_prefix'         => trim((string)($_POST['sku_prefix'] ?? '')),
            'upd_status'         => !empty($_POST['upd_status']),
            'set_manage'         => !empty($_POST['set_manage']),
            'delete_rows'        => !empty($_POST['delete_rows']),
            'loop_until_empty'   => !empty($_POST['loop_until_empty']),
            'max_loops'          => max(1, min(200, intval($_POST['max_loops'] ?? 50))),
            'attach_terms'       => !empty($_POST['attach_terms']),
            'set_primary'        => !empty($_POST['set_primary']),
            'duplicate_slug_meta'=> !empty($_POST['duplicate_slug_meta']),
            // лимиты по времени (сек)
            'hard_request_sec'   => 22,
            'hard_loop_sec'      => 4.5,
        ];

        // безопасность: крутиться до пустой таблицы только при удалении строк
        if ($opts['loop_until_empty'] && !$opts['delete_rows']) {
            $opts['loop_until_empty'] = false;
        }

        $this->report = $this->run_sync($opts);

        delete_transient($lock_key);
    }

    /** получить term по slug в таксономии location (кэшируем на время запроса) */
    private static $loc_cache = [];
    private function get_location_term_by_slug( string $slug ) {
        $slug = sanitize_title($slug);
        if ($slug === '') return null;
        if (isset(self::$loc_cache[$slug])) return self::$loc_cache[$slug];

        $term = get_term_by('slug', $slug, 'location');
        if ($term && !is_wp_error($term)) {
            self::$loc_cache[$slug] = $term;
            return $term;
        }
        self::$loc_cache[$slug] = null;
        return null;
    }

    /** привязать товар к термину location, если ещё не привязан */
    private function ensure_product_has_location_term( int $product_id, int $term_id ): void {
        $terms = wp_get_object_terms($product_id, 'location', ['fields'=>'ids']);
        if (is_wp_error($terms)) return;
        if (!in_array($term_id, array_map('intval',$terms), true)) {
            wp_set_object_terms($product_id, array_merge($terms ?: [], [$term_id]), 'location', false);
        }
    }

    /** выставить primary, если не стоит */
    private function ensure_primary_location( int $product_id, int $term_id ): void {
        $cur = (int) get_post_meta($product_id, '_yoast_wpseo_primary_location', true);
        if (!$cur) {
            update_post_meta($product_id, '_yoast_wpseo_primary_location', (int)$term_id);
        }
    }

    private function run_sync(array $o): array {
        global $wpdb;

        $t_request0 = microtime(true);
        $table = $wpdb->prefix.'stock_import';

        $loops = 0;
        $total_rows = 0;
        $affected_products = 0;
        $meta_records = 0;
        $not_found_skus = [];
        $not_found_locations = []; // <— копим неизвестные склады
        $sum_by_sku = [];
        $product_ids = [];
        $meta_keys_used = [];

        do {
            $loops++;
            $t_loop0 = microtime(true);

            // 1) взять порцию
            $where = '1=1';
            $args  = [];
            if ($o['sku_prefix'] !== '') { $where .= ' AND sku LIKE %s'; $args[] = $o['sku_prefix'] . '%'; }
            $sql = "SELECT sku, location_slug, qty
                    FROM {$table}
                    WHERE {$where}
                    ORDER BY sku, location_slug
                    LIMIT %d";
            $args[] = $o['batch'];

            $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
            if (!$rows) break;

            // 2) сгруппировать по SKU
            $perSku = [];
            foreach ($rows as $r) {
                $sku = (string)$r['sku'];
                $loc = (string)$r['location_slug'];
                $qty = (float)$r['qty'];
                $perSku[$sku]['lines'][] = [$loc, $qty];
                if (!isset($perSku[$sku]['sum'])) $perSku[$sku]['sum'] = 0.0;
                $perSku[$sku]['sum'] += $qty;
            }

            // 3) обработка SKU
            foreach ($perSku as $sku => $data) {
                $pid = function_exists('wc_get_product_id_by_sku') ? wc_get_product_id_by_sku($sku) : 0;
                if (!$pid) {
                    $pid = (int)$wpdb->get_var($wpdb->prepare(
                        "SELECT p.ID
                        FROM {$wpdb->posts} p
                        JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_sku'
                        WHERE m.meta_value=%s AND p.post_type IN ('product','product_variation')
                        LIMIT 1", $sku
                    ));
                }
                if (!$pid) { $not_found_skus[$sku]=true; continue; }

                $affected_products++;
                $product_ids[$sku] = $pid;

                foreach ($data['lines'] as [$locSlug, $qty]) {
                    $term = $this->get_location_term_by_slug($locSlug);
                    if (!$term) {
                        $not_found_locations[$sku][] = (string)$locSlug;
                        continue; // неизвестный склад не пишем
                    }

                    // пишем строго по TERM_ID
                    $meta_key_id = '_stock_at_' . (int)$term->term_id;
                    $meta_keys_used[$meta_key_id] = true;
                    if (!$o['dry']) update_post_meta($pid, $meta_key_id, wc_format_decimal($qty, 3));
                    $meta_records++;

                    // опция: дублировать по slug
                    if (!empty($o['duplicate_slug_meta'])) {
                        $meta_key_slug = '_stock_at_' . $term->slug;
                        $meta_keys_used[$meta_key_slug] = true;
                        if (!$o['dry']) update_post_meta($pid, $meta_key_slug, wc_format_decimal($qty, 3));
                        $meta_records++;
                    }

                    // опция: привязать термины
                    if (!empty($o['attach_terms']) && !$o['dry']) {
                        $this->ensure_product_has_location_term($pid, (int)$term->term_id);
                    }
                }

                // итоговый stock
                $total = (float)$data['sum'];
                $sum_by_sku[$sku] = $total;

                if (!$o['dry']) {
                    update_post_meta($pid, '_stock', wc_format_decimal($total, 3));
                    if (!empty($o['set_manage'])) update_post_meta($pid, '_manage_stock', 'yes');
                    if (!empty($o['upd_status'])) {
                        update_post_meta($pid, '_stock_status', $total > 0 ? 'instock' : 'outofstock');
                        if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
                    }
                    if (!empty($o['set_primary'])) {
                        $firstSlug = $data['lines'][0][0] ?? '';
                        $term = $this->get_location_term_by_slug($firstSlug);
                        if ($term) $this->ensure_primary_location($pid, (int)$term->term_id);
                    }
                }

                // лимит времени на один цикл (чтобы UI не «замирал»)
                if (microtime(true) - $t_loop0 > $o['hard_loop_sec']) break;
            }

            // 4) удаление обработанных строк (если включено)
            if ($o['delete_rows'] && !$o['dry'] && !empty($rows)) {
                // убираем дубли пар на всякий случай
                $pairs = [];
                foreach ($rows as $r) { $pairs[$r['sku'].'|'.$r['location_slug']] = [$r['sku'],$r['location_slug']]; }
                $pairs = array_values($pairs);

                // ограничим удаление 200–400 парами за раз (безопасно для prepare)
                $chunk = array_slice($pairs, 0, 400);
                $place = []; $vals = [];
                foreach ($chunk as [$s,$l]) { $place[]='(%s,%s)'; $vals[]=$s; $vals[]=$l; }
                if ($place) {
                    $del = "DELETE FROM {$table} WHERE (sku, location_slug) IN (".implode(',',$place).")";
                    $wpdb->query($wpdb->prepare($del, $vals));
                }
            }

            $total_rows += count($rows);

            // если строк было меньше батча — вероятно, дошли до хвоста
            if (count($rows) < $o['batch']) break;

            // общий жёсткий лимит по времени
            if (microtime(true) - $t_request0 > $o['hard_request_sec']) break;

        } while (!empty($o['loop_until_empty']) && $loops < $o['max_loops']);

        ksort($sum_by_sku);
        ksort($product_ids);

        return [
            'ok'                   => 1,
            'dry_run'              => $o['dry'] ? 'YES' : 'NO',
            'batch'                => $o['batch'],
            'loops'                => $loops,
            'processed_rows'       => $total_rows,
            'affected_products'    => $affected_products,
            'meta_records'         => $meta_records,
            'deleted_from_table'   => (!empty($o['delete_rows']) && !$o['dry']) ? 'YES' : 'NO',
            'loop_until_empty'     => !empty($o['loop_until_empty']) ? 'YES' : 'NO',
            'not_found_skus'       => array_keys($not_found_skus),
            'not_found_locations'  => $not_found_locations, // <— теперь видно проблемные склады
            'sum_by_sku'           => $sum_by_sku,
            'product_ids'          => $product_ids,
            'meta_keys_used'       => array_keys($meta_keys_used),
            'time_sec'             => round(microtime(true) - $t_request0, 3),
            'table'                => $table,
            'sku_prefix'           => $o['sku_prefix'],
            'writes_by'            => 'TERM_ID (optional slug duplicate)',
        ];
    }
}
new PC_Stock_Sync_Woo();