<?php
if (!defined('ABSPATH')) exit;

/** Menu */
add_action('admin_menu', function () {
    add_menu_page(
        __('Lavka Price Sync', 'lavka-price-sync'),
        __('Price Sync', 'lavka-price-sync'),
        LPS_CAP,
        'lps-main',
        'lps_render_settings_page',
        'dashicons-tag', // 💰 более подходящая иконка для цен
        66                // свободная позиция в меню
    );
    add_submenu_page('lps-main',
        __('Settings', 'lavka-price-sync'),
        __('Settings', 'lavka-price-sync'),
        LPS_CAP,
        'lps-main',
        'lps_render_settings_page'
    );
    add_submenu_page('lps-main',
        __('Mapping', 'lavka-price-sync'),
        __('Mapping', 'lavka-price-sync'),
        LPS_CAP,
        'lps-mapping',
        'lps_render_mapping_page'
    );
    add_submenu_page('lps-main',
        __('Run', 'lavka-price-sync'),
        __('Run', 'lavka-price-sync'),
        LPS_CAP,
        'lps-run',
        'lps_render_run_page'
    );
});

/** Assets */
add_action('admin_enqueue_scripts', function($hook){
    if (strpos($hook, 'lps-') === false) return;
    wp_enqueue_style('lps-admin', plugins_url('../assets/admin.css', __FILE__), [], '1.0');
    wp_enqueue_script('lps-admin', plugins_url('../assets/admin.js', __FILE__), [], '1.0', true);
    wp_localize_script('lps-admin', 'LPS_I18N', [
        'loading'        => esc_html__('Loading…','lavka-price-sync'),
        'saving'         => esc_html__('Saving…','lavka-price-sync'),
        'saved'          => esc_html__('Saved','lavka-price-sync'),
        'neterr'         => esc_html__('Network error','lavka-price-sync'),
        'error'          => esc_html__('Error:','lavka-price-sync'),
        'done'           => esc_html__('Done','lavka-price-sync'),
        'page'           => esc_html__('Page','lavka-price-sync'),
        'of'             => esc_html__('of','lavka-price-sync'),
        'not_found'      => esc_html__('Not found','lavka-price-sync'),
        'updated'        => esc_html__('Updated','lavka-price-sync'),
        'enter_skus'     => esc_html__('Enter one or more SKUs','lavka-price-sync'),
    ]);
});

/** Settings page */
function lps_render_settings_page() {
    if (!current_user_can(LPS_CAP)) return;
    if (isset($_POST['lps_save']) && check_admin_referer('lps_save_options')) {
        $o = lps_get_options();
        $o['java_base_url']  = esc_url_raw($_POST['java_base_url'] ?? '');
        $o['api_token']      = sanitize_text_field($_POST['api_token'] ?? '');
        $o['path_contracts'] = '/'.ltrim(sanitize_text_field($_POST['path_contracts'] ?? '/contracts'), '/');
        $o['path_prices']    = '/'.ltrim(sanitize_text_field($_POST['path_prices'] ?? '/prices/query'), '/');
        $o['batch']          = max(LPS_MIN_BATCH, min(LPS_MAX_BATCH, (int)($_POST['batch'] ?? LPS_DEF_BATCH)));
        $o['timeout']        = max(30, min(600, (int)($_POST['timeout'] ?? 160)));
        $o['cron_enabled']   = !empty($_POST['cron_enabled']);
        lps_update_options($o);
        echo '<div class="updated"><p>'.esc_html__('Saved','lavka-price-sync').'</p></div>';
    }
    $o = lps_get_options();
    ?>
        <div class="wrap">
      <h1><?php echo esc_html__('Lavka Price Sync', 'lavka-price-sync'); ?></h1>
      <form method="post">
        <?php wp_nonce_field('lps_save_options'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th><label for="java_base_url"><?php _e('Java Base URL','lavka-price-sync'); ?></label></th>
            <td>
              <input type="url" id="java_base_url" name="java_base_url"
                     class="regular-text" value="<?php echo esc_attr($o['java_base_url']); ?>">
            </td>
          </tr>

          <tr>
            <th><label for="api_token"><?php _e('API Token','lavka-price-sync'); ?></label></th>
            <td>
              <input type="text" id="api_token" name="api_token"
                     class="regular-text" value="<?php echo esc_attr($o['api_token']); ?>">
            </td>
          </tr>

          <tr>
            <th><label for="path_contracts"><?php _e('Contracts endpoint','lavka-price-sync'); ?></label></th>
            <td>
              <input type="text" id="path_contracts" name="path_contracts"
                     value="<?php echo esc_attr($o['path_contracts']); ?>">
              <code>GET</code>
            </td>
          </tr>

          <tr>
            <th><label for="path_prices"><?php _e('Prices endpoint','lavka-price-sync'); ?></label></th>
            <td>
              <input type="text" id="path_prices" name="path_prices"
                     value="<?php echo esc_attr($o['path_prices']); ?>">
              <code>POST</code>
            </td>
          </tr>

          <tr>
            <th><?php _e('Batch size','lavka-price-sync'); ?></th>
            <td>
              <input type="number" name="batch" min="<?php echo LPS_MIN_BATCH; ?>" max="<?php echo LPS_MAX_BATCH; ?>"
                     value="<?php echo (int)$o['batch']; ?>" style="width:7rem">
            </td>
          </tr>

          <tr>
            <th><?php _e('HTTP timeout, sec','lavka-price-sync'); ?></th>
            <td>
              <input type="number" name="timeout" min="30" max="600"
                     value="<?php echo (int)$o['timeout']; ?>" style="width:7rem">
            </td>
          </tr>

          <tr>
            <th><?php _e('Cron','lavka-price-sync'); ?></th>
            <td>
              <label>
                <input type="checkbox" name="cron_enabled" <?php checked($o['cron_enabled']); ?>>
                <?php _e('Run hourly','lavka-price-sync'); ?>
              </label>
            </td>
          </tr>
        </table>

        <p><?php submit_button(__('Save','lavka-price-sync'), 'primary', 'lps_save', false); ?></p>
      </form>
    </div>
    <?php
}

/** Run page (manual) */
function lps_render_run_page() {
    if (!current_user_can(LPS_CAP)) return;
    $nonce_listed = wp_create_nonce('lps_sync_skus');
    $nonce_all    = wp_create_nonce('lps_sync_all');
    $o = lps_get_options();
    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('Run price sync', 'lavka-price-sync'); ?></h1>

      <h2><?php _e('Sync listed SKUs','lavka-price-sync'); ?></h2>
      <p>
        <textarea id="lps-skus" rows="4" style="width:100%;max-width:800px"
                  placeholder="SKU1, SKU2, ..."></textarea>
      </p>
      <p>
        <button class="button button-primary" id="lps-sync-listed"
                data-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                data-nonce="<?php echo esc_attr($nonce_listed); ?>">
          <?php _e('Sync listed SKUs','lavka-price-sync'); ?>
        </button>
        <span id="lps-listed-status" style="margin-left:10px;"></span>
      </p>

      <hr>

      <h2><?php _e('Sync ALL (paged)','lavka-price-sync'); ?></h2>
      <p>
        <?php _e('Batch size','lavka-price-sync'); ?>:
        <input type="number" id="lps-batch" min="<?php echo LPS_MIN_BATCH; ?>" max="<?php echo LPS_MAX_BATCH; ?>"
               value="<?php echo (int)$o['batch']; ?>" style="width:7rem">
        <button class="button button-primary" id="lps-sync-all"
                data-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                data-nonce="<?php echo esc_attr($nonce_all); ?>">
          <?php _e('Sync ALL (paged)','lavka-price-sync'); ?>
        </button>
        <span id="lps-all-status" style="margin-left:10px;"></span>
      </p>
    </div>
    <?php
}