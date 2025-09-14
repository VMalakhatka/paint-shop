<?php
/*
Plugin Name: Stock Sync → WooCommerce
Description: Syncs wp_stock_import → product metas (_stock_at_{TERM_ID}), sums into _stock, assigns the 'location' taxonomy and optionally duplicates metas by slug. Can delete processed rows and loop until the table is empty.
Version: 1.4.0
Author: PaintCore
Text Domain: stock-sync-to-woo
Domain Path: /languages
*/
if (!defined('ABSPATH')) exit;

class PC_Stock_Sync_Woo {
    const SLUG = 'pc-stock-sync-woo';
    const CAP  = 'manage_woocommerce'; // or 'manage_options'

    private $report = null;

    public function __construct() {
        add_action('admin_menu',  [$this,'menu']);
        add_action('admin_init',  [$this,'maybe_handle']);
    }

    public function menu() {
        add_submenu_page(
            'tools.php',
            esc_html__('Stock sync → WooCommerce', 'stock-sync-to-woo'),
            esc_html__('Stock sync → Woo', 'stock-sync-to-woo'),
            self::CAP,
            self::SLUG,
            [$this,'page']
        );
    }

    public function page() {
        if (!current_user_can(self::CAP)) wp_die( esc_html__('Insufficient permissions.', 'stock-sync-to-woo') );
        $r = $this->report;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Stock sync → WooCommerce', 'stock-sync-to-woo'); ?></h1>

            <p style="max-width:960px">
                <?php
                echo wp_kses_post(
                    sprintf(
                        /* translators: keep code tags as-is */
                        __('We read rows from %1$s (sku, location_slug, qty), find a product by SKU, resolve a warehouse by %2$s in the %3$s taxonomy and write quantity into %4$s. Then we sum per SKU and update %5$s. Optionally we can auto-attach %3$s terms to products and set Primary.', 'stock-sync-to-woo'),
                        '<code>wp_stock_import</code>',
                        '<code>location_slug</code>',
                        '<code>location</code>',
                        '<code>_stock_at_{TERM_ID}</code>',
                        '<code>_stock</code>'
                    )
                );
                ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('pc_stock_sync'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Batch size', 'stock-sync-to-woo'); ?></th>
                        <td>
                            <input type="number" name="batch" value="<?php echo isset($_POST['batch']) ? intval($_POST['batch']) : 500; ?>" min="1" max="5000" step="1" style="width:120px">
                            <p class="description">
                                <?php
                                echo wp_kses_post(
                                    sprintf(
                                        /* translators: keep code tag */
                                        __('How many rows from %s to process per pass.', 'stock-sync-to-woo'),
                                        '<code>wp_stock_import</code>'
                                    )
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Dry-Run</th>
                        <td>
                            <label><input type="checkbox" name="dry" value="1" <?php checked(!empty($_POST['dry'])); ?>> <?php echo esc_html__('Show only, no writes', 'stock-sync-to-woo'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('SKU filter (prefix)', 'stock-sync-to-woo'); ?></th>
                        <td>
                            <input type="text" name="sku_prefix" value="<?php echo isset($_POST['sku_prefix']) ? esc_attr($_POST['sku_prefix']) : ''; ?>" placeholder="<?php echo esc_attr__('e.g. CR- or AB-', 'stock-sync-to-woo'); ?>" style="width:240px">
                            <p class="description"><?php echo esc_html__('If set, only rows whose SKU starts with this prefix will be processed.', 'stock-sync-to-woo'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Update stock status?', 'stock-sync-to-woo'); ?></th>
                        <td><label><input type="checkbox" name="upd_status" value="1" <?php checked(!empty($_POST['upd_status']), true); ?>> <?php echo esc_html__('Yes', 'stock-sync-to-woo'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable manage_stock?', 'stock-sync-to-woo'); ?></th>
                        <td><label><input type="checkbox" name="set_manage" value="1" <?php checked(!empty($_POST['set_manage']), true); ?>> <?php echo esc_html__('Yes', 'stock-sync-to-woo'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo wp_kses_post( sprintf( __('Delete processed rows from %s?', 'stock-sync-to-woo'), '<code>wp_stock_import</code>' ) ); ?></th>
                        <td><label><input type="checkbox" name="delete_rows" value="1" <?php checked(!empty($_POST['delete_rows'])); ?>> <?php echo esc_html__('Yes, after successful writes', 'stock-sync-to-woo'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Loop until table is empty?', 'stock-sync-to-woo'); ?></th>
                        <td>
                            <label><input type="checkbox" name="loop_until_empty" value="1" <?php checked(!empty($_POST['loop_until_empty'])); ?>> <?php echo esc_html__('Yes', 'stock-sync-to-woo'); ?></label>
                            <span style="margin-left:12px"><?php echo esc_html__('Max loops', 'stock-sync-to-woo'); ?>: <input type="number" name="max_loops" value="<?php echo isset($_POST['max_loops']) ? intval($_POST['max_loops']) : 50; ?>" min="1" max="1000" style="width:90px"></span>
                            <p class="description"><?php echo esc_html__('Safety: looping requires deletion to be enabled.', 'stock-sync-to-woo'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo wp_kses_post( sprintf( __('Attach %s terms to products?', 'stock-sync-to-woo'), '<code>location</code>' ) ); ?></th>
                        <td><label><input type="checkbox" name="attach_terms" value="1" <?php checked(!empty($_POST['attach_terms']), true); ?>> <?php echo esc_html__('Yes', 'stock-sync-to-woo'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Set Primary location if missing?', 'stock-sync-to-woo'); ?></th>
                        <td><label><input type="checkbox" name="set_primary" value="1" <?php checked(!empty($_POST['set_primary']), true); ?>> <?php echo esc_html__('Yes', 'stock-sync-to-woo'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Duplicate metas by slug (compat)?', 'stock-sync-to-woo'); ?></th>
                        <td><label><input type="checkbox" name="duplicate_slug_meta" value="1" <?php checked(!empty($_POST['duplicate_slug_meta'])); ?>> <?php echo wp_kses_post( __('Yes, also write <code>_stock_at_{slug}</code>', 'stock-sync-to-woo') ); ?></label></td>
                    </tr>
                </table>

                <p class="submit">
                    <button class="button button-primary"><?php echo esc_html__('Synchronize', 'stock-sync-to-woo'); ?></button>
                </p>
            </form>

            <?php if ($r): ?>
                <hr>
                <h2><?php echo esc_html__('Report', 'stock-sync-to-woo'); ?></h2>
                <pre style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:500px;overflow:auto"><?php
                    echo esc_html(print_r($r, true));
                ?></pre>
            <?php endif; ?>

            <p style="margin-top:10px;color:#666">
                <?php
                echo wp_kses_post(
                    sprintf(
                        /* translators: keep code tag */
                        __('Tip: check current table size with %s', 'stock-sync-to-woo'),
                        '<code>SELECT COUNT(*) FROM wp_stock_import;</code>'
                    )
                );
                ?>
            </p>
        </div>
        <?php
    }

    public function maybe_handle() {
        if (!is_admin() || empty($_GET['page']) || $_GET['page'] !== self::SLUG) return;
        if (!current_user_can(self::CAP)) return;
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'pc_stock_sync')) return;

        // lock against double-run
        $lock_key = 'pc_stock_sync_lock';
        if (get_transient($lock_key)) {
            $this->report = ['ok'=>0,'error'=>esc_html__('Sync is already running. Try again in ~30s.', 'stock-sync-to-woo')];
            return;
        }
        set_transient($lock_key, 1, 30); // 30 sec

        $opts = [
            'batch'               => max(1, min(2000, intval($_POST['batch'] ?? 500))),
            'dry'                 => !empty($_POST['dry']),
            'sku_prefix'          => trim((string)($_POST['sku_prefix'] ?? '')),
            'upd_status'          => !empty($_POST['upd_status']),
            'set_manage'          => !empty($_POST['set_manage']),
            'delete_rows'         => !empty($_POST['delete_rows']),
            'loop_until_empty'    => !empty($_POST['loop_until_empty']),
            'max_loops'           => max(1, min(200, intval($_POST['max_loops'] ?? 50))),
            'attach_terms'        => !empty($_POST['attach_terms']),
            'set_primary'         => !empty($_POST['set_primary']),
            'duplicate_slug_meta' => !empty($_POST['duplicate_slug_meta']),
            // time limits (sec)
            'hard_request_sec'    => 22,
            'hard_loop_sec'       => 4.5,
        ];

        // safety: loop-until-empty only allowed when deletion is enabled
        if ($opts['loop_until_empty'] && !$opts['delete_rows']) {
            $opts['loop_until_empty'] = false;
        }

        $this->report = $this->run_sync($opts);

        delete_transient($lock_key);
    }

    /** get 'location' term by slug (per-request cache) */
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

    /** attach 'location' term to product if not yet attached */
    private function ensure_product_has_location_term( int $product_id, int $term_id ): void {
        $terms = wp_get_object_terms($product_id, 'location', ['fields'=>'ids']);
        if (is_wp_error($terms)) return;
        $ints = array_map('intval', (array)$terms);
        if (!in_array($term_id, $ints, true)) {
            wp_set_object_terms($product_id, array_merge($ints ?: [], [$term_id]), 'location', false);
        }
    }

    /** set Primary location if missing */
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
        $not_found_locations = [];
        $sum_by_sku = [];
        $product_ids = [];
        $meta_keys_used = [];

        do {
            $loops++;
            $t_loop0 = microtime(true);

            // 1) fetch a batch
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

            // 2) group by SKU
            $perSku = [];
            foreach ($rows as $r) {
                $sku = (string)$r['sku'];
                $loc = (string)$r['location_slug'];
                $qty = (float)$r['qty'];
                $perSku[$sku]['lines'][] = [$loc, $qty];
                if (!isset($perSku[$sku]['sum'])) $perSku[$sku]['sum'] = 0.0;
                $perSku[$sku]['sum'] += $qty;
            }

            // 3) process each SKU
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
                        continue; // unknown location -> skip
                    }

                    // write strictly by TERM_ID
                    $meta_key_id = '_stock_at_' . (int)$term->term_id;
                    $meta_keys_used[$meta_key_id] = true;
                    if (!$o['dry']) update_post_meta($pid, $meta_key_id, wc_format_decimal($qty, 3));
                    $meta_records++;

                    // optional: duplicate by slug
                    if (!empty($o['duplicate_slug_meta'])) {
                        $meta_key_slug = '_stock_at_' . $term->slug;
                        $meta_keys_used[$meta_key_slug] = true;
                        if (!$o['dry']) update_post_meta($pid, $meta_key_slug, wc_format_decimal($qty, 3));
                        $meta_records++;
                    }

                    // optional: attach terms
                    if (!empty($o['attach_terms']) && !$o['dry']) {
                        $this->ensure_product_has_location_term($pid, (int)$term->term_id);
                    }
                }

                // final total stock
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

                // per-loop time limit (keep UI reactive)
                if (microtime(true) - $t_loop0 > $o['hard_loop_sec']) break;
            }

            // 4) delete processed rows (if enabled)
            if ($o['delete_rows'] && !$o['dry'] && !empty($rows)) {
                // de-dup pairs just in case
                $pairs = [];
                foreach ($rows as $r) { $pairs[$r['sku'].'|'.$r['location_slug']] = [$r['sku'],$r['location_slug']]; }
                $pairs = array_values($pairs);

                // limit delete to ~400 pairs per query (safer for prepare)
                $chunk = array_slice($pairs, 0, 400);
                $place = []; $vals = [];
                foreach ($chunk as [$s,$l]) { $place[]='(%s,%s)'; $vals[]=$s; $vals[]=$l; }
                if ($place) {
                    $del = "DELETE FROM {$table} WHERE (sku, location_slug) IN (".implode(',',$place).")";
                    $wpdb->query($wpdb->prepare($del, $vals));
                }
            }

            $total_rows += count($rows);

            // tail reached
            if (count($rows) < $o['batch']) break;

            // hard request-time limit
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
            'not_found_locations'  => $not_found_locations,
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