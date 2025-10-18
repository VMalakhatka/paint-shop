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
        $opts['base_url']   = esc_url_raw($_POST['base_url'] ?? '');
        $opts['api_token'] = sanitize_text_field($_POST['api_token'] ?? '');
        $opts['path_sync']  = '/' . ltrim(sanitize_text_field($_POST['path_sync'] ?? '/sync/goods'), '/');
        $opts['path_status'] = '/' . ltrim(sanitize_text_field($_POST['path_status'] ?? '/sync/status'), '/');
        $opts['path_cancel'] = '/' . ltrim(sanitize_text_field($_POST['path_cancel'] ?? '/sync/cancel'), '/');
        $opts['batch']     = max(LTS_MIN_BATCH, min(LTS_MAX_BATCH, (int)($_POST['batch'] ?? LTS_DEF_BATCH)));
        $opts['timeout']   = max(30, min(600, (int)($_POST['timeout'] ?? 160)));
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
                        <label for="base_url">
                            <?php _e('Java Base URL', 'lavka-total-sync'); ?>
                        </label>
                    </th>
                    <td>
                        <input name="base_url" id="base_url" type="url" class="regular-text" value="<?php echo esc_attr($opts['base_url']); ?>" />
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
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Run Total Sync', 'lavka-total-sync'); ?></h1>
        <p class="description">
            <?php _e('This page will allow you to start a full synchronization of WooCommerce products (except price and quantity) using data from an external MSSQL source.', 'lavka-total-sync'); ?>
        </p>
        <p>
            <?php _e('The full run page is under development.', 'lavka-total-sync'); ?>
        </p>
    </div>
    <?php
}