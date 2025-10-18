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
    // Only load assets on our plugin pages
    if (strpos($hook, 'lts-') === false) {
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
        lts_update_options($opts);
        echo '<div class="updated"><p>' . esc_html__('Saved', 'lavka-total-sync') . '</p></div>';
    }

    $opts = lts_get_options();

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
    </div>
    <?php
}