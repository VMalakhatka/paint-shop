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

    $css = __DIR__ . '/../assets/admin.css';
    $js  = __DIR__ . '/../assets/admin.js';

    wp_enqueue_style('lps-admin',
        plugins_url('../assets/admin.css', __FILE__),
        [],
        @filemtime($css) ?: '1.0'
    );
    wp_enqueue_script('lps-admin',
        plugins_url('../assets/admin.js', __FILE__),
        [],
        @filemtime($js) ?: '1.0',
        true
    );

    wp_localize_script('lps-admin', 'LPS_I18N', [
        'loading'    => esc_html__('Loading…','lavka-price-sync'),
        'saving'     => esc_html__('Saving…','lavka-price-sync'),
        'saved'      => esc_html__('Saved','lavka-price-sync'),
        'neterr'     => esc_html__('Network error','lavka-price-sync'),
        'error'      => esc_html__('Error:','lavka-price-sync'),
        'done'       => esc_html__('Done','lavka-price-sync'),
        'page'       => esc_html__('Page','lavka-price-sync'),
        'of'         => esc_html__('of','lavka-price-sync'),
        'not_found'  => esc_html__('Not found','lavka-price-sync'),
        'updated'    => esc_html__('Updated','lavka-price-sync'),
        'enter_skus' => esc_html__('Enter one or more SKUs','lavka-price-sync'),
    ]);
    wp_localize_script('lps-admin', 'LPS_ADMIN', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('lps_admin_nonce'),
    ]);
});


/** Settings page */
function lps_render_settings_page() {
    if (!current_user_can(LPS_CAP)) return;
    if (isset($_POST['lps_save']) && check_admin_referer('lps_save_options')) {
      // Save cron settings (schedule)
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['_wpnonce_lps_cron'])
        && wp_verify_nonce($_POST['_wpnonce_lps_cron'], 'lps_save_cron')
    ) {
        if (!current_user_can(LPS_CAP)) wp_die('forbidden');

        $in = $_POST['lps_cron'] ?? [];

        // нормализуем вход
        $out = [
            'enabled' => !empty($in['enabled']),
            'mode'    => in_array(($in['mode'] ?? 'daily'), ['daily','weekly','dates'], true) ? $in['mode'] : 'daily',
            'time'    => (string)($in['time'] ?? '03:30'),
            'batch'   => max(50, min(2000, (int)($in['batch'] ?? 500))),
        ];

        if ($out['mode'] === 'weekly') {
            $allow = ['mon','tue','wed','thu','fri','sat','sun'];
            $out['days'] = array_values(array_intersect($allow, (array)($in['days'] ?? [])));
        } else {
            $out['days'] = [];
        }

        if ($out['mode'] === 'dates') {
            $raw   = (string)($in['dates'] ?? '');
            $dates = array_filter(array_map('trim', preg_split('/\R+/', $raw)));
            $out['dates'] = array_values($dates);
        } else {
            $out['dates'] = [];
        }

        update_option(LPS_OPT_CRON, $out, false);

        // пересоздаём расписание
        if (function_exists('lps_cron_reschedule_price')) {
            lps_cron_reschedule_price();
        }

        // "Запустити зараз"
        if (isset($_POST['lps_cron_run_now'])) {
            $res = function_exists('lps_run_price_sync_now') ? lps_run_price_sync_now() : ['ok'=>false,'error'=>'missing'];
            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        __('Запуск виконано: ok=%s, updated_retail=%d, updated_roles=%d, not_found=%d', 'lavka-price-sync'),
                        !empty($res['ok']) ? 'true' : 'false',
                        (int)($res['updated_retail'] ?? 0),
                        (int)($res['updated_roles']  ?? 0),
                        (int)($res['not_found']      ?? 0)
                    )
                )
            );
        } else {
            echo '<div class="notice notice-success"><p>'.esc_html__('Розклад збережено', 'lavka-price-sync').'</p></div>';
        }
    }
        $o = lps_get_options();
        $o['java_base_url']  = esc_url_raw($_POST['java_base_url'] ?? '');
        $o['api_token']      = sanitize_text_field($_POST['api_token'] ?? '');
        $o['path_contracts'] = '/'.ltrim(sanitize_text_field($_POST['path_contracts'] ?? '/ref/ref/contracts'), '/');
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


/** Mapping page */
function lps_render_mapping_page() {
    if (!current_user_can(LPS_CAP)) return;

    // Надёжно получаем роли
    if (function_exists('lps_get_roles')) {
        $roles = lps_get_roles();
    } else {
        // Фоллбек: читаем напрямую из WP
        if (!function_exists('wp_roles')) {
            require_once ABSPATH . 'wp-includes/pluggable.php';
        }
        $wp_roles = wp_roles();
        $roles = [];
        foreach ($wp_roles->roles as $slug => $data) {
            $roles[$slug] = isset($data['name']) ? $data['name'] : $slug;
        }
        ksort($roles);
    }

    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('Mapping', 'lavka-price-sync'); ?></h1>

      <p class="description">
        <?php echo esc_html__('Link WooCommerce roles to external contract codes. Prices for a role will be taken using its mapped contract.', 'lavka-price-sync'); ?>
      </p>

      <div id="lps-mapping-wrap"
           data-ajax="<?php echo esc_attr( admin_url('admin-ajax.php') ); ?>"
           data-nonce="<?php echo esc_attr( wp_create_nonce('lps_admin_nonce') ); ?>">

        <table class="widefat striped" style="max-width:900px">
          <thead>
            <tr>
              <th><?php echo esc_html__('Role', 'lavka-price-sync'); ?></th>
              <th><?php echo esc_html__('Contract', 'lavka-price-sync'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($roles as $slug => $label): ?>
              <tr>
                <td><strong><?php echo esc_html($label); ?></strong><br><code><?php echo esc_html($slug); ?></code></td>
                <td>
                  <!-- Опции контрактов подставит JS; держим data-атрибут с ролью -->
                  <select class="lps-contract" data-lps-role="<?php echo esc_attr($slug); ?>" style="min-width:320px">
                    <option value=""><?php echo esc_html__('— Select contract —', 'lavka-price-sync'); ?></option>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <p style="margin-top:10px">
          <button class="button button-primary" id="lps-mapping-save">
            <?php echo esc_html__('Save mapping', 'lavka-price-sync'); ?>
          </button>
          <span id="lps-mapping-status" style="margin-left:10px;"></span>
        </p>
      </div>
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
      <p class="description">
  <?php _e('Uses mapping from the Mapping page. For each SKU the endpoint returns retail price and all role prices. The plugin updates _regular_price/_price and _wpc_price_role_<role>.','lavka-price-sync'); ?>
</p>
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
          <div id="lps-listed-box" style="margin-top:10px;"></div> <!-- ← контейнер для таблицы -->
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
    <?php
    // --- Schedule (price sync) ---
    $cron = get_option(LPS_OPT_CRON, [
        'enabled' => false,
        'mode'    => 'daily',
        'time'    => '03:30',
        'days'    => ['mon'],
        'dates'   => [],
        'batch'   => 500,
    ]);
    ?>
    <div class="postbox">
      <h2 class="hndle"><span><?php _e('Price sync schedule', 'lavka-price-sync'); ?></span></h2>
      <div class="inside">
        <form method="post">
          <?php wp_nonce_field('lps_save_cron','_wpnonce_lps_cron'); ?>
          <table class="form-table">
            <tr>
              <th><label for="lps-cron-enabled"><?php _e('Enable', 'lavka-price-sync'); ?></label></th>
              <td>
                <label>
                  <input type="checkbox" id="lps-cron-enabled" name="lps_cron[enabled]" value="1" <?php checked(!empty($cron['enabled'])); ?>>
                  <?php _e('Yes', 'lavka-price-sync'); ?>
                </label>
              </td>
            </tr>

            <tr>
              <th><?php _e('Mode', 'lavka-price-sync'); ?></th>
              <td>
                <select name="lps_cron[mode]" id="lps-cron-mode">
                  <option value="daily"  <?php selected(($cron['mode']??'')==='daily');  ?>>
                    <?php _e('Daily at specified time', 'lavka-price-sync'); ?>
                  </option>
                  <option value="weekly" <?php selected(($cron['mode']??'')==='weekly'); ?>>
                    <?php _e('Weekly by days', 'lavka-price-sync'); ?>
                  </option>
                  <option value="dates"  <?php selected(($cron['mode']??'')==='dates');  ?>>
                    <?php _e('On specific dates', 'lavka-price-sync'); ?>
                  </option>
                </select>
              </td>
            </tr>

            <tr class="lps-field lps-field-time">
              <th><label for="lps-cron-time"><?php _e('Time (HH:MM)', 'lavka-price-sync'); ?></label></th>
              <td>
                <input type="text" id="lps-cron-time" name="lps_cron[time]"
                      value="<?php echo esc_attr($cron['time'] ?? '03:30'); ?>"
                      class="regular-text" placeholder="03:30">
              </td>
            </tr>

            <tr class="lps-field lps-field-weekly">
              <th><?php _e('Week days', 'lavka-price-sync'); ?></th>
              <td>
                <?php
                // English labels as source; translators will localize them.
                $days = [
                  'mon' => __('Mon', 'lavka-price-sync'),
                  'tue' => __('Tue', 'lavka-price-sync'),
                  'wed' => __('Wed', 'lavka-price-sync'),
                  'thu' => __('Thu', 'lavka-price-sync'),
                  'fri' => __('Fri', 'lavka-price-sync'),
                  'sat' => __('Sat', 'lavka-price-sync'),
                  'sun' => __('Sun', 'lavka-price-sync'),
                ];
                foreach ($days as $k=>$label) {
                  $checked = in_array($k, (array)($cron['days'] ?? []), true);
                  echo '<label style="margin-right:12px;">'
                    . '<input type="checkbox" name="lps_cron[days][]" value="'.esc_attr($k).'" '.checked($checked,true,false).'> '
                    . esc_html($label)
                    . '</label>';
                }
                ?>
              </td>
            </tr>

            <tr class="lps-field lps-field-dates">
              <th><label for="lps-cron-dates"><?php _e('Dates (one per line)', 'lavka-price-sync'); ?></label></th>
              <td>
                <textarea id="lps-cron-dates" name="lps_cron[dates]" rows="4" class="large-text"
                          placeholder="2025-10-15 14:00&#10;2025-11-01 09:30"><?php
                  echo esc_textarea( implode("\n", (array)($cron['dates'] ?? [])) );
                ?></textarea>
                <p class="description">
                  <?php printf(__('Site timezone: %s', 'lavka-price-sync'), wp_timezone_string()); ?>
                </p>
              </td>
            </tr>

            <tr>
              <th><label for="lps-cron-batch"><?php _e('Batch size per run', 'lavka-price-sync'); ?></label></th>
              <td>
                <input type="number" id="lps-cron-batch" name="lps_cron[batch]"
                      value="<?php echo (int)($cron['batch'] ?? 500); ?>" min="50" max="2000">
              </td>
            </tr>
          </table>

          <p>
            <button class="button button-primary">
              <?php _e('Save schedule', 'lavka-price-sync'); ?>
            </button>
            <button name="lps_cron_run_now" value="1" class="button">
              <?php _e('Run now', 'lavka-price-sync'); ?>
            </button>
          </p>
        </form>
      </div>
    </div>

    <script>
    (function(){
      const modeEl = document.getElementById('lps-cron-mode');
      const rows = {
        weekly: document.querySelector('.lps-field-weekly'),
        dates:  document.querySelector('.lps-field-dates'),
        time:   document.querySelector('.lps-field-time'),
      };
      function apply(){
        const m = modeEl.value;
        if (rows.weekly) rows.weekly.style.display = (m === 'weekly') ? '' : 'none';
        if (rows.dates)  rows.dates.style.display  = (m === 'dates')  ? '' : 'none';
        if (rows.time)   rows.time.style.display   = (m !== 'dates')  ? '' : '';
      }
      if (modeEl){ modeEl.addEventListener('change', apply); apply(); }
    })();
    </script>
    </div>
    <?php
}