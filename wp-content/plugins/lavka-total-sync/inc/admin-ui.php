<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin UI for Lavka Total Sync
 *
 * Provides Settings and Run pages for configuring and triggering
 * synchronization of WooCommerce products (excluding price and stock).
 *
 * Text domain: lavka-total-sync
 */

/**
 * Register admin menu and submenus.
 */
add_action('admin_menu', function() {
    // Top-level menu
    add_menu_page(
        __('Lavka Total Sync', 'lavka-total-sync'),
        __('Total Sync', 'lavka-total-sync'),
        LTS_CAP,
        'lts-main',
        'lts_render_settings_page',
        'dashicons-admin-generic',
        65
    );

    // Settings
    add_submenu_page(
        'lts-main',
        __('Settings', 'lavka-total-sync'),
        __('Settings', 'lavka-total-sync'),
        LTS_CAP,
        'lts-main',
        'lts_render_settings_page'
    );

    // Run
    add_submenu_page(
        'lts-main',
        __('Run', 'lavka-total-sync'),
        __('Run', 'lavka-total-sync'),
        LTS_CAP,
        'lts-run',
        'lts_render_run_page'
    );
});

/**
 * Enqueue admin assets for our plugin pages.
 */
add_action('admin_enqueue_scripts', function($hook) {
    // Load assets on any of our plugin screens. Hook names vary across setups
    // (e.g. 'toplevel_page_lts-main', 'lavka-total-sync_page_lts-run', etc.).
    $is_ours = (strpos($hook, 'lavka-total-sync') !== false)
            || (strpos($hook, 'lts-') !== false);
    if (!$is_ours) {
        return;
    }

    $css = plugin_dir_path(__FILE__) . '../assets/admin.css';
    $js  = plugin_dir_path(__FILE__) . '../assets/admin.js';

    // Enqueue styles and scripts
    wp_enqueue_style('lts-admin', plugins_url('../assets/admin.css', __FILE__), [], @filemtime($css) ?: '1.0');
    wp_enqueue_script('lts-admin', plugins_url('../assets/admin.js', __FILE__), ['jquery'], @filemtime($js) ?: '1.0', true);

    // Localize strings for JS
    wp_localize_script('lts-admin', 'LTS_I18N', [
        'loading'    => esc_html__('Loading…', 'lavka-total-sync'),
        'saving'     => esc_html__('Saving…', 'lavka-total-sync'),
        'saved'      => esc_html__('Saved', 'lavka-total-sync'),
        'error'      => esc_html__('Error:', 'lavka-total-sync'),
        'updated'    => esc_html__('Updated', 'lavka-total-sync'),
    ]);
    wp_localize_script('lts-admin', 'LTS_ADMIN', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('lts_admin_nonce'),
    ]);
});

/**
 * Background sync: start, worker, status
 */
add_action('wp_ajax_lts_bg_start', function () {
    if (!current_user_can(LTS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','_ajax_nonce');

    // Collect args from POST
    $args = [
        'limit'   => isset($_POST['limit']) ? (int)$_POST['limit'] : 500,
        'after'   => isset($_POST['after']) ? trim((string)$_POST['after']) : null,
        'max'     => (isset($_POST['max_items']) && $_POST['max_items'] !== '') ? max(1,(int)$_POST['max_items']) : null,
        'dry_run' => !empty($_POST['dry_run']),
    ];
    if (isset($_POST['draft_stale_seconds']) && $_POST['draft_stale_seconds'] !== '') {
        $args['draft_stale_seconds'] = max(60, (int)$_POST['draft_stale_seconds']);
    }
    if (isset($_POST['max_seconds']) && $_POST['max_seconds'] !== '') {
        $args['max_seconds'] = max(1, (int)$_POST['max_seconds']);
    }

    $run_id = uniqid('lts_', true);
    $args['run_id'] = $run_id;

    // Persist run meta
    $runs = get_option('lts_bg_runs', []);
    $runs[$run_id] = [
        'status'     => 'running',
        'started_at' => time(),
        'args'       => $args,
    ];
    update_option('lts_bg_runs', $runs, false);

    // Fallback: schedule WP-Cron worker in case loopback requests are blocked
    if ( ! wp_next_scheduled('lts_bg_worker_event', [ 'run_id' => $run_id ]) ) {
        wp_schedule_single_event( time() + 2, 'lts_bg_worker_event', [ 'run_id' => $run_id ] );
    }

    // Fire background worker (non-blocking)
    $url = admin_url('admin-ajax.php');
    $payload = [
        'action'     => 'lts_bg_worker',
        'run_id'     => $run_id,
        '_ajax_nonce'=> wp_create_nonce('lts_admin_nonce'),
    ];
    // Send as non-blocking request
    wp_remote_post($url, [
        'timeout'   => 0.01,
        'blocking'  => false,
        'sslverify' => apply_filters('https_local_ssl_verify', false),
        'body'      => $payload,
    ]);

    wp_send_json_success(['run_id'=>$run_id]);
});

// Reusable background worker function for both AJAX and WP-Cron
if ( ! function_exists('lts_run_bg_worker') ) {
    function lts_run_bg_worker( $run_id ) {
        $runs = get_option('lts_bg_runs', []);
        if ( ! $run_id || empty( $runs[ $run_id ] ) ) {
            return ['ok'=>false,'error'=>'unknown_run'];
        }
        $args = $runs[ $run_id ]['args'];
        $args['run_id'] = (string) $run_id;

        // helper to log with run id
        if ( ! function_exists('lts_log_db_with_run') ) {
            function lts_log_db_with_run( $level, $tag, $ctx, $run_id ) {
                if ( ! is_array( $ctx ) ) $ctx = [];
                $ctx['run'] = $run_id;
                lts_log_db( $level, $tag, $ctx );
            }
        }
        lts_log_db_with_run('info','bg','start', $run_id);

        $res = lts_sync_goods_run( $args );

        $runs[ $run_id ]['status']      = 'finished';
        $runs[ $run_id ]['finished_at'] = time();
        $runs[ $run_id ]['result']      = $res;
        update_option('lts_bg_runs', $runs, false);

        lts_log_db_with_run('info','bg','finish', $run_id);
        return $res;
    }
}

add_action('wp_ajax_lts_bg_worker', function () {
    if (!current_user_can(LTS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','_ajax_nonce');

    $run_id = isset($_POST['run_id']) ? (string)$_POST['run_id'] : '';
    $res = lts_run_bg_worker( $run_id );
    // terminate fast for loopback call
    wp_die();
});

add_action('lts_bg_worker_event', function( $run_id ) {
    // Run without capability/nonces – it is internal cron
    lts_run_bg_worker( (string) $run_id );
});

add_action('wp_ajax_lts_bg_status', function () {
    if (!current_user_can(LTS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','_ajax_nonce');

    global $wpdb;
    $run_id = isset($_GET['run_id']) ? (string)$_GET['run_id'] : '';
    $after_id = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;

    $status = null;
    $runs = get_option('lts_bg_runs', []);
    if ($run_id && isset($runs[$run_id])) {
        $status = $runs[$run_id]['status'];
    }

    // Tail last 200 log rows for this run (search in ctx JSON)
    $table = $wpdb->prefix . 'lts_sync_logs';
    $rows = [];
    if ($run_id && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
        $like = '%"run":"' . $wpdb->esc_like($run_id) . '"%';
        $sql  = $wpdb->prepare("
            SELECT id, ts, level, tag, message, ctx
            FROM {$table}
            WHERE id > %d AND ctx LIKE %s
            ORDER BY id ASC
            LIMIT 200
        ", $after_id, $like);
        $rows = $wpdb->get_results($sql, ARRAY_A);
    }

    // If finished and we have result
    $result = null;
    if ($run_id && isset($runs[$run_id]['result'])) {
        $result = $runs[$run_id]['result'];
    }

    wp_send_json_success([
        'status' => $status,
        'rows'   => $rows ?: [],
        'result' => $result,
    ]);
});

add_action('wp_ajax_lts_run_total_sync', function(){
    if (!current_user_can(LTS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_admin_referer('lts_total_sync');

    $args = [
        'limit'   => isset($_POST['limit']) ? (int)$_POST['limit'] : 500,
        'after'   => isset($_POST['after']) ? (string)$_POST['after'] : null,
        'dry_run' => !empty($_POST['dry']),
        // «звёздочка»: пометить устаревшие как draft, если старее 2 часов
        'draft_stale_seconds' => !empty($_POST['draft_stale']) ? (int)$_POST['draft_stale'] : (2*HOUR_IN_SECONDS),
    ];
    $res = lts_sync_goods_run($args);
    if (empty($res['ok'])) wp_send_json_error($res);
    wp_send_json_success($res);
});

add_action('wp_ajax_lts_recount_cats', function () {
    if (!current_user_can(LTS_CAP)) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce','_ajax_nonce');

    if (function_exists('lts_recount_all_product_cat_counts')) {
        try {
            lts_recount_all_product_cat_counts();
            wp_send_json_success(['ok' => true]);
        } catch (\Throwable $e) {
            wp_send_json_error(['error' => $e->getMessage()]);
        }
    } else {
        wp_send_json_error(['error' => 'missing_helper']);
    }
});

/**
 * Render the settings page.
 */
function lts_render_settings_page() {
    if (!current_user_can(LTS_CAP)) return;

    // Save options
    if (isset($_POST['lts_save']) && check_admin_referer('lts_save_options')) {
        $opts = lts_get_options();
        $opts['java_base_url'] = esc_url_raw($_POST['java_base_url'] ?? '');
        $opts['api_token'] = sanitize_text_field($_POST['api_token'] ?? '');
        $opts['path_sync']  = '/' . ltrim(sanitize_text_field($_POST['path_sync'] ?? '/sync/goods'), '/');
        $opts['path_status'] = '/' . ltrim(sanitize_text_field($_POST['path_status'] ?? '/sync/status'), '/');
        $opts['path_cancel'] = '/' . ltrim(sanitize_text_field($_POST['path_cancel'] ?? '/sync/cancel'), '/');
        $opts['batch']     = max(LTS_MIN_BATCH, min(LTS_MAX_BATCH, (int)($_POST['batch'] ?? LTS_DEF_BATCH)));
        $opts['timeout']   = max(30, min(600, (int)($_POST['timeout'] ?? 160)));
        // [LTS] ANCHOR: save-cat-desc-glue
        $opts['cat_desc_prefix_html'] = wp_kses_post($_POST['cat_desc_prefix_html'] ?? '');
        $opts['cat_desc_suffix_html'] = wp_kses_post($_POST['cat_desc_suffix_html'] ?? '');
        // Save defaults for new API (/sync/run) and the "first" SKU
        $opts['new_api_defaults'] = [
            'limit'       => isset($_POST['lts_def_limit']) ? (int)$_POST['lts_def_limit'] : 40000,
            'pageSizeWoo' => isset($_POST['lts_def_page'])  ? (int)$_POST['lts_def_page']  : 200,
            'cursorAfter' => isset($_POST['lts_def_after']) ? sanitize_text_field((string)$_POST['lts_def_after']) : '',
            'dryRun'      => !empty($_POST['lts_def_dry']),
        ];
        $opts['first_sku'] = isset($_POST['lts_first_sku']) ? sanitize_text_field((string)$_POST['lts_first_sku']) : '___';
        lts_update_options($opts);
        echo '<div class="updated"><p>' . esc_html__('Saved', 'lavka-total-sync') . '</p></div>';
    }

    $opts = lts_get_options();
    $def = isset($opts['new_api_defaults']) && is_array($opts['new_api_defaults']) ? $opts['new_api_defaults'] : [];
    $firstSku = isset($opts['first_sku']) ? (string)$opts['first_sku'] : '___';

    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Lavka Total Sync', 'lavka-total-sync'); ?></h1>
        <form method="post" class="lps-sync-settings">
            <?php wp_nonce_field('lts_save_options'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="java_base_url">
                            <?php _e('Java Base URL', 'lavka-total-sync'); ?>
                        </label>
                    </th>
                    <td>
                        <input name="java_base_url" id="java_base_url" type="url" class="regular-text" value="<?php echo esc_attr($opts['java_base_url']); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="api_token">
                            <?php _e('API Token', 'lavka-total-sync'); ?>
                        </label>
                    </th>
                    <td>
                        <input name="api_token" id="api_token" type="text" class="regular-text" value="<?php echo esc_attr($opts['api_token']); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="path_sync">
                            <?php _e('Sync endpoint', 'lavka-total-sync'); ?>
                        </label>
                    </th>
                    <td>
                        <input name="path_sync" id="path_sync" type="text" class="regular-text" value="<?php echo esc_attr($opts['path_sync']); ?>" />
                        <code>POST</code>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="path_status">
                            <?php _e('Status endpoint', 'lavka-total-sync'); ?>
                        </label>
                    </th>
                    <td>
                        <input name="path_status" id="path_status" type="text" class="regular-text" value="<?php echo esc_attr($opts['path_status']); ?>" />
                        <code>GET</code>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="path_cancel">
                            <?php _e('Cancel endpoint', 'lavka-total-sync'); ?>
                        </label>
                    </th>
                    <td>
                        <input name="path_cancel" id="path_cancel" type="text" class="regular-text" value="<?php echo esc_attr($opts['path_cancel']); ?>" />
                        <code>POST</code>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php _e('Batch size', 'lavka-total-sync'); ?>
                    </th>
                    <td>
                        <input name="batch" type="number" min="<?php echo LTS_MIN_BATCH; ?>" max="<?php echo LTS_MAX_BATCH; ?>" value="<?php echo (int)$opts['batch']; ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php _e('HTTP timeout, sec', 'lavka-total-sync'); ?>
                    </th>
                    <td>
                        <input name="timeout" type="number" min="30" max="600" value="<?php echo (int)$opts['timeout']; ?>" />
                    </td>
                </tr>
                <!-- [LTS] ANCHOR: form-cat-desc-glue -->
                <tr>
                    <th scope="row">
                        <label for="cat_desc_prefix_html">
                            <?php _e('Category description — prefix (HTML)', 'lavka-total-sync'); ?>
                        </label>
                    </th>
                    <td>
                        <textarea name="cat_desc_prefix_html" id="cat_desc_prefix_html" rows="3" class="large-text" placeholder='<p style="margin: .75rem 0 0; font-size: clamp(1rem,2.5vw,3rem); line-height:1.2; display:-webkit-box; overflow:hidden; font-weight:500; color:#8a4b2a">'><?php echo esc_textarea($opts['cat_desc_prefix_html'] ?? ''); ?></textarea>
                        <p class="description"><?php _e('HTML to prepend before category description. Leave empty to disable.', 'lavka-total-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cat_desc_suffix_html">
                            <?php _e('Category description — suffix (HTML)', 'lavka-total-sync'); ?>
                        </label>
                    </th>
                    <td>
                        <textarea name="cat_desc_suffix_html" id="cat_desc_suffix_html" rows="2" class="large-text" placeholder='</p>'><?php echo esc_textarea($opts['cat_desc_suffix_html'] ?? ''); ?></textarea>
                        <p class="description"><?php _e('HTML to append after category description. Example: closing &lt;/p&gt; tag.', 'lavka-total-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row" colspan="2"><h2 style="margin:1.5rem 0 .25rem;"><?php _e('New API defaults (/sync/run)', 'lavka-total-sync'); ?></h2></th>
                </tr>
                <tr>
                    <th scope="row"><label for="lts_def_limit"><?php _e('Default limit', 'lavka-total-sync'); ?></label></th>
                    <td><input type="number" id="lts_def_limit" name="lts_def_limit" class="regular-text" value="<?php echo isset($def['limit']) ? (int)$def['limit'] : 40000; ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="lts_def_page"><?php _e('Default Woo batch size', 'lavka-total-sync'); ?></label></th>
                    <td><input type="number" id="lts_def_page" name="lts_def_page" class="regular-text" value="<?php echo isset($def['pageSizeWoo']) ? (int)$def['pageSizeWoo'] : 200; ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="lts_def_after"><?php _e('Default cursorAfter', 'lavka-total-sync'); ?></label></th>
                    <td><input type="text" id="lts_def_after" name="lts_def_after" class="regular-text" value="<?php echo esc_attr(isset($def['cursorAfter']) ? $def['cursorAfter'] : ''); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Default dry run', 'lavka-total-sync'); ?></th>
                    <td><label><input type="checkbox" id="lts_def_dry" name="lts_def_dry" <?php checked(!empty($def['dryRun'])); ?>> <?php _e('Calculate only, no writes', 'lavka-total-sync'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="lts_first_sku"><?php _e('First SKU (lexicographically first)', 'lavka-total-sync'); ?></label></th>
                    <td>
                        <input type="text" id="lts_first_sku" name="lts_first_sku" class="regular-text" value="<?php echo esc_attr($firstSku ?: '___'); ?>">
                        <p class="description"><?php _e('Used by cron full sync as cursorAfter. Should be lower than any real SKU.', 'lavka-total-sync'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save', 'lavka-total-sync'), 'primary', 'lts_save', false); ?>
        </form>
    </div>
    <?php
}

/**
 * Render the run page.
 */
function lts_render_run_page() {
    if (!current_user_can(LTS_CAP)) return;

    $result = null;
    $error  = null;

    // Handle submit (no JS needed)
    if (!empty($_POST['lts_run_submit']) && check_admin_referer('lts_total_sync')) {
        $args = [
            'limit'   => isset($_POST['limit']) ? (int)$_POST['limit'] : 500,
            'after'   => isset($_POST['after']) ? trim((string)$_POST['after']) : null,
            'max'     => (isset($_POST['max_items']) && $_POST['max_items'] !== '') ? max(1, (int)$_POST['max_items']) : null,
            'dry_run' => !empty($_POST['dry_run']),
        ];
        if (isset($_POST['draft_stale_seconds']) && $_POST['draft_stale_seconds'] !== '') {
            $args['draft_stale_seconds'] = max(60, (int)$_POST['draft_stale_seconds']);
        }
        if (isset($_POST['max_seconds']) && $_POST['max_seconds'] !== '') {
            $args['max_seconds'] = max(1, (int)$_POST['max_seconds']);
        }
        $res = lts_sync_goods_run($args);
        if (!empty($res['ok'])) {
            $result = $res;
        } else {
            $error = $res;
        }
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Run Total Sync', 'lavka-total-sync'); ?></h1>
        <p class="description">
            <?php _e('Run a full synchronization of WooCommerce products (except price and stock) from the external MSSQL source. Uses keyset pagination (limit/after).', 'lavka-total-sync'); ?>
        </p>

        <?php if ($error): ?>
            <div class="notice notice-error"><p><strong><?php _e('Error', 'lavka-total-sync'); ?>:</strong> <?php echo esc_html(print_r($error, true)); ?></p></div>
        <?php elseif ($result): ?>
            <div class="notice notice-success"><p><?php _e('Sync completed.', 'lavka-total-sync'); ?></p></div>
        <?php endif; ?>

        <form method="post" class="lts-run-form" style="max-width:820px">
            <?php wp_nonce_field('lts_total_sync'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="limit"><?php _e('Page size (limit)', 'lavka-total-sync'); ?></label></th>
                    <td><input id="limit" name="limit" type="number" min="50" max="1000" value="<?php echo isset($_POST['limit']) ? (int)$_POST['limit'] : 500; ?>" style="width:7rem"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="after"><?php _e('Cursor (after)', 'lavka-total-sync'); ?></label></th>
                    <td><input id="after" name="after" type="text" class="regular-text" placeholder="SKU cursor e.g. DR-DA000123" value="<?php echo isset($_POST['after']) ? esc_attr((string)$_POST['after']) : ''; ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="max_items"><?php _e('Max items to modify', 'lavka-total-sync'); ?></label></th>
                    <td>
                        <input id="max_items" name="max_items" type="number" min="1" step="1" placeholder="20" value="<?php echo isset($_POST['max_items']) ? (int)$_POST['max_items'] : ''; ?>" style="width:7rem">
                        <p class="description"><?php _e('If set, process only this many SKUs starting from the cursor (after) and stop.', 'lavka-total-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="max_seconds"><?php _e('Max run time (seconds)', 'lavka-total-sync'); ?></label></th>
                    <td>
                        <input id="max_seconds" name="max_seconds" type="number" min="1" step="1" placeholder="60" value="<?php echo isset($_POST['max_seconds']) ? (int)$_POST['max_seconds'] : ''; ?>" style="width:7rem">
                        <p class="description"><?php _e('Optional safety limit: stop the sync when this many seconds have elapsed.', 'lavka-total-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Dry run', 'lavka-total-sync'); ?></th>
                    <td><label><input type="checkbox" name="dry_run" <?php checked(!empty($_POST['dry_run'])); ?>> <?php _e('Do not modify Woo, only fetch pages', 'lavka-total-sync'); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="draft_stale_seconds"><?php _e('Draft items not updated for (seconds)', 'lavka-total-sync'); ?></label></th>
                    <td>
                        <input id="draft_stale_seconds" name="draft_stale_seconds" type="number" min="60" step="60" placeholder="7200" value="<?php echo isset($_POST['draft_stale_seconds']) ? (int)$_POST['draft_stale_seconds'] : 7200; ?>" style="width:9rem">
                        <p class="description"><?php _e('After the run, products with _sync_updated_at older than this threshold will be moved to draft.', 'lavka-total-sync'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Run', 'lavka-total-sync'), 'primary', 'lts_run_submit', false); ?>
        </form>

        <?php if ($result): ?>
            <h2><?php _e('Result', 'lavka-total-sync'); ?></h2>
            <textarea readonly rows="10" style="width:100%;max-width:820px;font-family:monospace"><?php echo esc_textarea(wp_json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); ?></textarea>
        <?php endif; ?>

        <h2 style="margin-top:2rem;"><?php _e('Run (new API /sync/run)', 'lavka-total-sync'); ?></h2>
            <p><?php _e('Start Java sync via /sync/run (fields only).', 'lavka-total-sync'); ?></p>

            <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="lts_new_limit"><?php _e('Limit (max items)', 'lavka-total-sync'); ?></label></th>
                <td><input type="number" id="lts_new_limit" class="regular-text" placeholder="40000"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lts_new_page"><?php _e('Woo batch size (1..200)', 'lavka-total-sync'); ?></label></th>
                <td><input type="number" id="lts_new_page" class="regular-text" placeholder="200"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lts_new_after"><?php _e('Cursor (after SKU)', 'lavka-total-sync'); ?></label></th>
                <td><input type="text" id="lts_new_after" class="regular-text" placeholder="A-P1431Å-GOLD 90"></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Dry run', 'lavka-total-sync'); ?></th>
                <td><label><input type="checkbox" id="lts_new_dry"> <?php _e('Calculate only, no writes', 'lavka-total-sync'); ?></label></td>
            </tr>
            </table>

            <p>
            <button type="button" class="button button-primary" id="lts_btn_new_run">
                <?php _e('Run (new API)', 'lavka-total-sync'); ?>
            </button>
            <span id="lts_new_run_status" style="margin-left:.6rem;color:#555;"></span>
            </p>

            <pre id="lts_new_run_output" style="max-height:260px;overflow:auto;background:#111;color:#9fe;padding:10px;border-radius:6px;"></pre>

        <hr class="wp-header-end">
        <h2><?php _e('Background total sync', 'lavka-total-sync'); ?></h2>
        <p class="description"><?php _e('Run sync in background and watch live log. This will not block the page.', 'lavka-total-sync'); ?></p>
        <div id="lts-bg-sync">
            <p>
                <button id="lts-bg-start" class="button button-primary"><?php _e('Start', 'lavka-total-sync'); ?></button>
                <button id="lts-bg-cancel" class="button" disabled><?php _e('Cancel', 'lavka-total-sync'); ?></button>
                <span id="lts-bg-status">[status: ]</span>
            </p>
            <textarea id="lts-bg-log" class="large-text code" rows="12" readonly> </textarea>
        </div>
        <script>
        (function($){
            var pollTimer = null;
            var runId = '';
            var lastRowId = 0;

            function formArgs(){
                return {
                    limit: parseInt($('#limit').val(), 10) || 500,
                    after: ($('#after').val() || '').trim() || null,
                    max_items: parseInt($('#max_items').val(), 10) || 200,
                    max_seconds: parseInt($('#max_seconds').val(), 10) || 10,
                    draft_stale_seconds: parseInt($('#draft_stale_seconds').val(), 10) || 0
                };
            }

            function appendLog(line){
                var $ta = $('#lts-bg-log');
                var ta = $ta.get(0);
                var atEnd = (ta.scrollTop + ta.clientHeight + 8) >= ta.scrollHeight;
                $ta.val($ta.val() + line + "\n");
                if (atEnd) ta.scrollTop = ta.scrollHeight;
            }

            function setUiRunning(running){
                $('#lts-bg-start').prop('disabled', running);
                $('#lts-bg-cancel').prop('disabled', !running);
                $('#lts-bg-status').text('[status: ' + (running ? 'running' : '') + ']');
            }

            function poll(){
                $.get(LTS_ADMIN.ajaxUrl, {
                    action: 'lts_bg_status',
                    _ajax_nonce: LTS_ADMIN.nonce,
                    run_id: runId,
                    after_id: lastRowId
                }).done(function(resp){
                    if (!resp || !resp.success) return;

                    // status
                    var status = resp.data.status || '';
                    $('#lts-bg-status').text('[status: ' + status + ']');

                    // stream log rows (if any)
                    if (resp.data.rows && resp.data.rows.length) {
                        resp.data.rows.forEach(function(r){
                            var tag = r.tag ? (' [' + r.tag + ']') : '';
                            var msg = '';
                            // Try to use explicit message first
                            if (r.message && String(r.message).trim() !== '') {
                                msg = r.message;
                            } else {
                                // Parse ctx JSON to extract useful info
                                var ctx = {};
                                try { ctx = r.ctx ? JSON.parse(r.ctx) : {}; } catch(e) { ctx = {}; }
                                if (ctx.line) {
                                    // progress lines come here
                                    msg = ctx.line;
                                } else if (r.tag === 'upsert' && ctx.sku) {
                                    // human-friendly upsert line
                                    var res = (ctx.result || 'upsert');
                                    msg = res + ': ' + ctx.sku + (ctx.post_id ? (' -> post_id=' + ctx.post_id) : '');
                                } else if (r.tag === 'draft' && ctx.count !== undefined) {
                                    msg = 'drafted: ' + ctx.count;
                                } else if (r.tag === 'diff' && ctx.seen_cnt !== undefined) {
                                    msg = 'diff request: after="' + (ctx.after || '') + '", limit=' + (ctx.limit || '') + ', seen=' + ctx.seen_cnt;
                                } else if (ctx.sku) {
                                    msg = (r.tag || 'log') + ': ' + ctx.sku;
                                } else {
                                    // as a last resort, show compact ctx JSON
                                    try { msg = JSON.stringify(ctx); } catch(e) { msg = ''; }
                                }
                            }
                            appendLog('#' + r.id + tag + ': ' + (msg || ''));
                            lastRowId = r.id;
                        });
                    }

                    // If finished and we have a result — print and stop
                    if (status === 'finished' || status === 'done' || status === 'error' || status === 'canceled') {
                        clearInterval(pollTimer); pollTimer = null;
                        setUiRunning(false);
                        appendLog('=== FINISHED ===');
                        if (resp.data.result) {
                            try {
                                appendLog(JSON.stringify(resp.data.result));
                            } catch(e){}
                        }
                    }
                });
            }

            $('#lts-bg-start').on('click', function(e){
                e.preventDefault();
                $('#lts-bg-log').val('Started background sync.\n[status: ]\n');

                var args = formArgs();
                $.post(LTS_ADMIN.ajaxUrl, $.extend({
                    action: 'lts_bg_start',
                    _ajax_nonce: LTS_ADMIN.nonce
                }, args)).done(function(resp){
                    if (!resp || !resp.success) { appendLog('Failed to start'); return; }
                    setUiRunning(true);
                    runId = (resp.data && resp.data.run_id) ? resp.data.run_id : '';
                    lastRowId = 0;
                    if (pollTimer) clearInterval(pollTimer);
                    pollTimer = setInterval(poll, 1500);
                    poll(); // first immediate poll
                }).fail(function(){
                    appendLog('Start error');
                });
            });

            $('#lts-bg-cancel').on('click', function(e){
                e.preventDefault();
                $.post(LTS_ADMIN.ajaxUrl, {
                    action: 'lts_job_cancel',
                    nonce:  LTS_ADMIN.nonce
                }).always(function(){
                    if (pollTimer) clearInterval(pollTimer);
                    setUiRunning(false);
                    appendLog('Canceled by user.');
                });
            });

            // ensure the "dry run" checkbox has an id if present (not used by worker)
            $('input[name="dry_run"]').attr('id','lts-run-form-dry');
        })(jQuery);
        </script>

        <hr>
        <h2><?php _e('Force recount product categories', 'lavka-total-sync'); ?></h2>
        <p class="description"><?php _e('Manually recalculate product_cat counters (stored term counts) for all categories. Useful if catalog shows wrong counts.', 'lavka-total-sync'); ?></p>
        <p>
            <button id="lts-recount-cats" class="button"><?php _e('Force recount now', 'lavka-total-sync'); ?></button>
            <span id="lts-recount-status"></span>
        </p>
        <script>
        (function($){
            $('#lts-recount-cats').on('click', function(e){
                e.preventDefault();
                var $btn = $(this);
                var $st = $('#lts-recount-status');
                $btn.prop('disabled', true);
                $st.text('<?php echo esc_js(__('Working…', 'lavka-total-sync')); ?>');
                $.post(LTS_ADMIN.ajaxUrl, {
                    action: 'lts_recount_cats',
                    _ajax_nonce: LTS_ADMIN.nonce
                }).done(function(resp){
                    if (resp && resp.success) {
                        $st.text('<?php echo esc_js(__('Done.', 'lavka-total-sync')); ?>');
                    } else {
                        $st.text('<?php echo esc_js(__('Error', 'lavka-total-sync')); ?>');
                    }
                }).fail(function(){
                    $st.text('<?php echo esc_js(__('Error', 'lavka-total-sync')); ?>');
                }).always(function(){
                    $btn.prop('disabled', false);
                });
            });
        })(jQuery);
        </script>
        <script>
            (function($){
            const ajaxUrl = ajaxurl;
            const nonce   = '<?php echo esc_js( wp_create_nonce('lts_admin_nonce') ); ?>';

            function print(obj){
                try { return JSON.stringify(obj, null, 2); } catch(e){ return String(obj); }
            }

            $('#lts_btn_new_run').on('click', async function(){
                $('#lts_new_run_status').text('<?php echo esc_js(__('Working…','lavka-total-sync')); ?>');
                $('#lts_new_run_output').text('');

                const data = {
                action: 'lts_sync_run',
                nonce:  nonce,
                limit:       $('#lts_new_limit').val(),
                pageSizeWoo: $('#lts_new_page').val(),
                cursorAfter: $('#lts_new_after').val(),
                dryRun:      $('#lts_new_dry').is(':checked') ? 1 : 0
                };

                try {
                const res = await $.post(ajaxUrl, data);
                if (!res || !res.success) {
                    $('#lts_new_run_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                    $('#lts_new_run_output').text(print(res && res.data ? res.data : res));
                    return;
                }
                $('#lts_new_run_status').text('<?php echo esc_js(__('Done.','lavka-total-sync')); ?>');

                const d = res.data;
                if (d.json) {
                    $('#lts_new_run_output').text(print(d.json));
                } else {
                    $('#lts_new_run_output').text(d.raw || '(no body)');
                }
                } catch(e){
                $('#lts_new_run_status').text('<?php echo esc_js(__('Error','lavka-total-sync')); ?>');
                $('#lts_new_run_output').text(String(e));
                }
            });
            })(jQuery);
            </script>
    </div>
    <?php
}