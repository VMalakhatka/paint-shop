<?php
/**
 * Plugin Name: Lavka Sync
 * Description: Provides buttons and settings to synchronize with the Java service.
 * Version: 0.1.0
 * Text Domain: lavka-sync
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

if (!defined('LAVKA_SYNC_OPTION')) define('LAVKA_SYNC_OPTION', 'lavka_sync_options');
if (!defined('LAVKA_BATCH_MIN')) define('LAVKA_BATCH_MIN', 10);
if (!defined('LAVKA_BATCH_MAX')) define('LAVKA_BATCH_MAX', 1000);
if (!defined('LAVKA_AUTO_OPTION')) define('LAVKA_AUTO_OPTION', 'lavka_sync_auto');
// Movement / incremental sync
if (!defined('LAVKA_LAST_TO_OPTION'))  define('LAVKA_LAST_TO_OPTION', 'lavka_sync_last_to'); // хранит последний serverTo
if (!defined('LAVKA_MOV_DEF_PAGESIZE')) define('LAVKA_MOV_DEF_PAGESIZE', 500);
if (!defined('LAVKA_MOV_MAX_PAGESIZE')) define('LAVKA_MOV_MAX_PAGESIZE', 2000);
if (!defined('LAVKA_MOV_OVERLAP_PCT'))  define('LAVKA_MOV_OVERLAP_PCT', 20); // overlap +20%
if (!defined('LAVKA_MOV_EMPTY_BREAK')) define('LAVKA_MOV_EMPTY_BREAK', 2); // после 2 пустых подряд — стоп


/** Меню + страница настроек */
add_action('admin_menu', function () {
    add_menu_page(
        __('Lavka Sync', 'lavka-sync'),
        __('Lavka Sync', 'lavka-sync'),
        'manage_lavka_sync',
        'lavka-sync',
        'lavka_sync_render_page',
        'dashicons-update',
        58
    );

    add_submenu_page(
      'lavka-sync',
      __('Lavka Mapping', 'lavka-sync'),
      __('Mapping', 'lavka-sync'),
      'manage_lavka_sync',
      'lavka-mapping',
      'lavka_render_mapping_page'
    );

    // (если есть) Logs/Reports тоже лучше на manage_lavka_sync
});

function lavka_render_mapping_page() {
    // словарь для фронта
    $i18n = [
        // общие
        'page_title'        => esc_html__('Lavka Mapping', 'lavka-sync'),
        'page_intro'        => __(
            'Match Woo locations (taxonomy <code>location</code>) with external MS warehouses. One Woo location may aggregate several MS warehouses.',
            'lavka-sync'
        ),
        'loading'           => esc_html__('Loading…', 'lavka-sync'),
        'saving'            => esc_html__('Saving…', 'lavka-sync'),
        'saved_ok'          => esc_html__('OK: saved', 'lavka-sync'),
        'save_error'        => esc_html__('Save error', 'lavka-sync'),
        'network_error'     => esc_html__('Network error', 'lavka-sync'),
        'data_load_error'   => esc_html__('Failed to load data.', 'lavka-sync'),

        // таблица
        'th_woo_location'   => esc_html__('Woo location', 'lavka-sync'),
        'th_ms_multi'       => esc_html__('MS warehouses (multi-select)', 'lavka-sync'),
        'btn_save_mapping'  => esc_html__('Save mapping', 'lavka-sync'),

        // строки с параметрами
        'ok_written'        => esc_html__('OK: written %s', 'lavka-sync'),

        'th_sku'        => esc_html__('SKU', 'lavka-sync'),
        'th_total'      => esc_html__('Total', 'lavka-sync'),
        'th_by_location'=> esc_html__('By warehouses', 'lavka-sync'),
        'th_found'      => esc_html__('Found', 'lavka-sync'),
        'no_data'       => esc_html__('No data', 'lavka-sync'),

      'i18n_enter_skus'    => esc_html__('Enter one or more SKUs', 'lavka-sync'),
      'i18n_running'       => esc_html__('Running…', 'lavka-sync'),
      'i18n_error_prefix'  => esc_html__('Error:', 'lavka-sync'),
      'i18n_network_error' => esc_html__('Network error', 'lavka-sync'),
      'i18n_page'          => esc_html__('Page', 'lavka-sync'),
      'i18n_of'            => esc_html__('of', 'lavka-sync'),
      'i18n_done'          => esc_html__('Done', 'lavka-sync'),
      'i18n_updated'       => esc_html__('Updated', 'lavka-sync'),
      'i18n_not_found'     => esc_html__('Not found', 'lavka-sync'),

      'i18n_ok_processed' => esc_html__('OK: processed %1$s, not found %2$s', 'lavka-sync'),

      'i18n_working' => esc_html__('Working…', 'lavka-sync'),
      'i18n_error'   => esc_html__('Error:', 'lavka-sync'),
      'i18n_neterr'  => esc_html__('Network error', 'lavka-sync'),
      'i18n_ok'      => esc_html__('OK', 'lavka-sync'),
    ];

    if (!current_user_can('manage_lavka_sync')) {
        wp_die( esc_html__('You do not have sufficient permissions to access this page.', 'lavka-sync') );
    }

    // nonce для REST cookie-авторизации
    $rest_nonce = wp_create_nonce('wp_rest');
    ?>
    <div class="wrap">
      <h1><?php echo esc_html( $i18n['page_title'] ); ?></h1>
      <p><?php echo wp_kses_post( $i18n['page_intro'] ); ?></p>

      <div id="lavka-mapping-app"><?php echo esc_html( $i18n['loading'] ); ?></div>
    </div>

    <script>
    // i18n в JS
    const LAVKA_I18N = <?php echo wp_json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

    (function(){
      const REST_ROOT  = "<?php echo esc_js( rest_url('lavka/v1/') ); ?>";
      const REST_NONCE = "<?php echo esc_js( $rest_nonce ); ?>";
      const AJAX_URL   = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
      const AJAX_NONCE = "<?php echo esc_js( wp_create_nonce('lavka_mapping_nonce') ); ?>";

      function restGet(path){
        return fetch(REST_ROOT + path, {
          headers: {'X-WP-Nonce': REST_NONCE}
        }).then(r => r.json());
      }
      function restPut(path, body){
        return fetch(REST_ROOT + path, {
          method: 'PUT',
          headers: {'X-WP-Nonce': REST_NONCE, 'Content-Type':'application/json'},
          body: JSON.stringify(body || {})
        }).then(r => r.json());
      }
      function ajax(action, data){
        const f = new FormData();
        f.append('action', action);
        f.append('_wpnonce', AJAX_NONCE);
        if (data) for (const k in data) f.append(k, data[k]);
        return fetch(AJAX_URL, {
          method:'POST',
          credentials:'same-origin',
          body:f
        }).then(r => r.json());
      }

      const el = document.getElementById('lavka-mapping-app');

      async function load(){
        el.textContent = LAVKA_I18N.loading;

        const [woo, map, ms] = await Promise.all([
          restGet('locations?hide_empty=0&per_page=1000'),
          restGet('locations/map'),
          ajax('lavka_ms_wh_list', {})
        ]);

        if (!woo || !woo.items || !map || !map.items || !ms || !ms.success) {
          el.textContent = LAVKA_I18N.data_load_error;
          console.log({woo, map, ms});
          return;
        }

        // выбранные коды по term_id
        const selectedByTid = {};
        (map.items || []).forEach(row => {
          selectedByTid[row.id || row.term_id] = new Set(row.codes || []);
        });

        const msList = (ms.data && ms.data.items) ? ms.data.items : (ms.items || []);

        const wrap = document.createElement('div');
        wrap.innerHTML = `
          <style>
            .lm-table{border-collapse:collapse;width:100%;max-width:1100px}
            .lm-table th,.lm-table td{border:1px solid #e5e5e5;padding:8px;vertical-align:top}
            .lm-multi{min-width:340px;min-height:92px}
            .lm-actions{margin:10px 0}
          </style>
          <table class="lm-table">
            <thead>
              <tr>
                <th>${LAVKA_I18N.th_woo_location}</th>
                <th>${LAVKA_I18N.th_ms_multi}</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
          <div class="lm-actions">
            <button class="button button-primary" id="lm-save">${LAVKA_I18N.btn_save_mapping}</button>
            <span id="lm-status" style="margin-left:10px;"></span>
          </div>
        `;
        const tbody = wrap.querySelector('tbody');

        (woo.items || []).forEach(loc => {
          const tr  = document.createElement('tr');
          const td1 = document.createElement('td');
          const td2 = document.createElement('td');

          td1.innerHTML = `<strong>${loc.name}</strong><br><code>${loc.slug}</code> (id=${loc.id})`;

          const sel = document.createElement('select');
          sel.className = 'lm-multi';
          sel.multiple  = true;

          const selected = selectedByTid[loc.id] || new Set();

          msList.forEach(x => {
            const code = (x.code || '').toString();
            const name = (x.name || x.title || code);
            if (!code) return;
            const opt = document.createElement('option');
            opt.value = code;
            opt.textContent = `${code} — ${name}`;
            if (selected.has(code)) opt.selected = true;
            sel.appendChild(opt);
          });

          td2.appendChild(sel);
          tr.appendChild(td1);
          tr.appendChild(td2);
          tr.dataset.tid = String(loc.id);
          tbody.appendChild(tr);
        });

        el.innerHTML = '';
        el.appendChild(wrap);

        // сохранение
        document.getElementById('lm-save').addEventListener('click', async () => {
          const rows = el.querySelectorAll('tbody tr');
          const mapping = [];
          rows.forEach(tr => {
            const tid = parseInt(tr.dataset.tid, 10);
            const sel = tr.querySelector('select');
            const codes = Array.from(sel.selectedOptions).map(o => o.value);
            mapping.push({ term_id: tid, codes });
          });

          const status = document.getElementById('lm-status');
          status.textContent = LAVKA_I18N.saving;

          try {
            const res = await restPut('locations/map', { mapping });
            if (res && (res.ok || typeof res.written !== 'undefined')) {
              const n = (res.written != null) ? res.written : '';
              status.textContent = n ? LAVKA_I18N.ok_written.replace('%s', n) : LAVKA_I18N.saved_ok;
            } else {
              status.textContent = LAVKA_I18N.save_error;
              console.log(res);
            }
          } catch (e) {
            status.textContent = LAVKA_I18N.network_error;
            console.error(e);
          }
        });
      }

      load();
    }());
    </script>
    <?php
}

// AJAX: тянем список MS-складов из Java (сервер-сайд, чтобы избежать CORS)
add_action('wp_ajax_lavka_ms_wh_list', function(){
    if (!current_user_can('manage_lavka_sync')) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lavka_mapping_nonce');

    $o   = lavka_sync_get_options();
    $url = rtrim($o['java_base_url'] ?? '', '/');
    $pth = ltrim($o['java_wh_path'] ?? '/warehouses', '/');
    $full = $url . '/' . $pth;

    $resp = wp_remote_get($full, [
        'timeout' => 160,
        'headers' => [
            'X-Auth-Token' => $o['api_token'] ?? '',
            'Accept'       => 'application/json',
        ],
    ]);

    if (is_wp_error($resp)) wp_send_json_error(['error'=>$resp->get_error_message()]);
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);

    if ($code < 200 || $code >= 300) {
        wp_send_json_error(['error'=>'Java status '.$code, 'body'=>$body]);
    }

    // Нормализуем ответ к виду: { ok:true, items:[{code, name}] }
    $items = [];
    if (isset($body['items']) && is_array($body['items'])) {
        $src = $body['items'];
    } elseif (is_array($body)) {
        $src = $body;
    } else {
        $src = [];
    }
    foreach ($src as $x){
        $code = $x['code'] ?? ($x['id'] ?? ($x['slug'] ?? ($x['extCode'] ?? '')));
        $name = $x['name'] ?? ($x['title'] ?? $code);
        $code = is_string($code) ? $code : (string)$code;
        if ($code !== '') $items[] = ['code'=>$code, 'name'=>(string)$name];
    }

    wp_send_json_success(['items'=>$items]);
});

add_action('admin_init', function () {
    register_setting('lavka_sync', LAVKA_SYNC_OPTION);
});

/**
 * Забрать маппинг локаций -> коды MS для запроса в Java.
 * Возвращает массив вида: [{id, codes:[...]}]
 */
function lavka_get_locations_mapping_for_java(): array {
    if (!function_exists('rest_do_request')) return [];
    $req  = new WP_REST_Request('GET', '/lavka/v1/locations/map');
    $resp = rest_do_request($req);
    $data = is_wp_error($resp) ? [] : $resp->get_data();
    $items = [];
    foreach (($data['items'] ?? []) as $row) {
        $tid   = (int)($row['id'] ?? $row['term_id'] ?? 0);
        $codes = array_values(array_filter(array_map('strval', (array)($row['codes'] ?? []))));
        if ($tid && $codes) $items[] = ['id'=>$tid, 'codes'=>$codes];
    }
    return $items;
}

/**
 * Рассчитать значение from (ISO8601) для /stock/movement.
 * Если есть сохранённый last serverTo — берём его и даём overlap (>=5 мин).
 * Иначе — берём длину окна по режиму авто и добавляем +20%.
 */
function lavka_calc_movement_from(array $auto_cfg, ?string $last_server_to_iso): string {
    $now = time();

    if ($last_server_to_iso) {
        $to = strtotime($last_server_to_iso) ?: $now;
        $base = 0;
        switch ($auto_cfg['mode'] ?? 'interval') {
            case 'interval': $base = max(300, (int)$auto_cfg['interval'] * 60); break;
            case 'daily':    $base = 24*3600; break;
            case 'weekly':   $base = 7*24*3600; break;
            case 'dates':    $base = 30*24*3600; break;
            default:         $base = 3600;
        }
        $overlap = max(300, (int)round($base * (LAVKA_MOV_OVERLAP_PCT/100)));
        return gmdate('c', $to - $overlap);
    }

    // fallback: окно расписания +20%
    switch ($auto_cfg['mode'] ?? 'interval') {
        case 'interval': $win = max(300, (int)$auto_cfg['interval']*60); break;
        case 'daily':    $win = 24*3600; break;
        case 'weekly':   $win = 7*24*3600; break;
        case 'dates':    $win = 30*24*3600; break;
        default:         $win = 3600;
    }
    $fromTs = $now - (int)round($win * (1 + LAVKA_MOV_OVERLAP_PCT/100));
    return gmdate('c', $fromTs);
}

function lavka_sync_render_page() {
    if (!current_user_can('manage_lavka_sync')) return;

    $opts  = lavka_sync_get_options();
    $nonce = wp_create_nonce('lavka_sync_nonce');
    $nonce_movement = wp_create_nonce('lavka_pull_movement');
    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('Lavka Sync', 'lavka-sync'); ?></h1>

      <form method="post" action="options.php">
        <?php settings_fields('lavka_sync'); $o = lavka_sync_get_options(); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="java_base_url"><?php echo esc_html__('Java Base URL', 'lavka-sync'); ?></label></th>
            <td><input name="<?php echo LAVKA_SYNC_OPTION; ?>[java_base_url]" id="java_base_url" type="url" value="<?php echo esc_attr($o['java_base_url'] ?? ''); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="api_token"><?php echo esc_html__('API Token (for Java)', 'lavka-sync'); ?></label></th>
            <td><input name="<?php echo LAVKA_SYNC_OPTION; ?>[api_token]" id="api_token" type="text" value="<?php echo esc_attr($o['api_token'] ?? ''); ?>" class="regular-text" /></td>
          </tr>
        </table>
        <?php submit_button( __('Save settings', 'lavka-sync') ); ?>
      </form>

      <hr />

      <h2><?php echo esc_html__('Manual run', 'lavka-sync'); ?></h2>
      <h2><?php echo esc_html__('Pull from Java (mapped)', 'lavka-sync'); ?></h2>

      <p>
        <label for="lavka-skus">
          <strong><?php echo esc_html__('SKUs', 'lavka-sync'); ?></strong>
          (<?php echo esc_html__('comma or newline separated', 'lavka-sync'); ?>):
        </label><br>
        <textarea id="lavka-skus" rows="4" style="width:100%;max-width:720px"></textarea>
      </p>

      <p style="margin-top:8px">
        <button id="lavka-pull-java" class="button"><?php echo esc_html__('Pull from Java (mapped)', 'lavka-sync'); ?></button>
        <label style="margin-left:8px;">
          <input type="checkbox" id="lavka-pull-dry" checked> <?php echo esc_html__('dry-run', 'lavka-sync'); ?>
        </label>
        <span id="lavka-pull-status" style="margin-left:10px;"></span>
      </p>

      <div style="margin-top:12px">
        <label><?php echo esc_html__('Batch size', 'lavka-sync'); ?>, <?php echo esc_html__('items', 'lavka-sync'); ?>:
          <input type="number" id="lavka-batch" value="200" min="10" max="1000" step="10" style="width:7rem">
        </label>
        <button id="lavka-pull-all" class="button button-primary" style="margin-left:8px"><?php echo esc_html__('Pull ALL (paged)', 'lavka-sync'); ?></button>
        <span id="lavka-pull-all-status" style="margin-left:10px;"></span>
      </div>

      <div class="lavka-movement" style="margin-top:18px">
          <h3><?php echo esc_html_x('Incremental (movement)', 'heading: manual movement section', 'lavka-sync'); ?></h3>
          <p>
              <label>
                  <?php echo esc_html__('Page size', 'lavka-sync'); ?>:
                  <input type="number"
                        id="lavka-mov-pagesize"
                        min="10" max="2000"
                        value="500"
                        style="width:7rem">
              </label>

              <label style="margin-left:10px;">
                  <?php echo esc_html__('From (ISO 8601, optional)', 'lavka-sync'); ?>:
                  <input type="text"
                        id="lavka-mov-from"
                        placeholder="<?php echo esc_attr__('e.g. 2025-09-30T00:00:00Z', 'lavka-sync'); ?>"
                        style="width:16rem">
                  <small class="description" style="display:block;margin-left:4px;color:#666;">
                      <?php echo esc_html__('Example: 2025-09-30T00:00:00Z (leave empty for auto)', 'lavka-sync'); ?>
                  </small>
              </label>

              <label style="margin-left:10px;">
                  <input type="checkbox" id="lavka-mov-dry" checked>
                  <?php echo esc_html__('Dry run', 'lavka-sync'); ?>
              </label>

              <button id="lavka-mov-run"
                      class="button button-primary"
                      style="margin-left:10px">
                  <?php echo esc_html__('Run movement', 'lavka-sync'); ?>
              </button>

              <span id="lavka-mov-status" style="margin-left:10px;"></span>
          </p>
      </div>
      
            <div class="lavka-auto" style="margin-top:18px">
        <h3><?php echo esc_html__('Auto sync (paged)', 'lavka-sync'); ?></h3>

        <p>
          <label>
            <input type="checkbox" id="lavka-auto-enabled">
            <?php echo esc_html__('Enabled', 'lavka-sync'); ?>
          </label>
        </p>

        <p>
          <label style="margin-right:10px;">
            <?php echo esc_html__('Mode', 'lavka-sync'); ?>:
            <select id="lavka-auto-mode">
              <option value="off"><?php echo esc_html__('Disabled', 'lavka-sync'); ?></option>
              <option value="interval"><?php echo esc_html__('Every N minutes', 'lavka-sync'); ?></option>
              <option value="daily"><?php echo esc_html__('Daily at time', 'lavka-sync'); ?></option>
              <option value="weekly"><?php echo esc_html__('Weekly on days', 'lavka-sync'); ?></option>
              <option value="dates"><?php echo esc_html__('Specific dates', 'lavka-sync'); ?></option>
            </select>
          </label>

          <span id="lavka-auto-interval-wrap" style="display:none;margin-right:10px;">
            <?php echo esc_html__('Every (minutes)', 'lavka-sync'); ?>:
            <input type="number" id="lavka-auto-interval" min="5" max="1440" value="60" style="width:6rem">
          </span>

          <span id="lavka-auto-time-wrap" style="display:none;margin-right:10px;">
            <?php echo esc_html__('Time (HH:MM)', 'lavka-sync'); ?>:
            <input type="time" id="lavka-auto-time" value="03:00">
          </span>

          <span id="lavka-auto-dow-wrap" style="display:none;margin-right:10px;">
            <?php echo esc_html__('Days', 'lavka-sync'); ?>:
            <?php
              $days = [
                0 => esc_html__('Sun', 'lavka-sync'),
                1 => esc_html__('Mon', 'lavka-sync'),
                2 => esc_html__('Tue', 'lavka-sync'),
                3 => esc_html__('Wed', 'lavka-sync'),
                4 => esc_html__('Thu', 'lavka-sync'),
                5 => esc_html__('Fri', 'lavka-sync'),
                6 => esc_html__('Sat', 'lavka-sync'),
              ];
              foreach ($days as $i => $label) {
                echo '<label style="margin-right:6px"><input type="checkbox" class="lavka-auto-dow" value="'.esc_attr($i).'"> '.$label.'</label>';
              }
            ?>
          </span>

          <span id="lavka-auto-dates-wrap" style="display:none;margin-right:10px;">
            <?php echo esc_html__('Dates (comma-separated DD)', 'lavka-sync'); ?>:
            <input type="text" id="lavka-auto-dates" placeholder="1, 10, 28" style="width:10rem">
          </span>

          <label>
            <?php echo esc_html__('Batch size', 'lavka-sync'); ?>:
            <input type="number" id="lavka-auto-batch" min="10" max="1000" value="200" style="width:7rem">
          </label>
          <label style="margin-left:10px;">
            <?php echo esc_html__('Strategy', 'lavka-sync'); ?>:
            <select id="lavka-auto-strategy">
              <option value="full">
                <?php echo esc_html_x('Full (ALL, paged)', 'select: auto-sync strategy', 'lavka-sync'); ?>
              </option>
              <option value="movement">
                <?php echo esc_html_x('Incremental (movement)', 'select: auto-sync strategy', 'lavka-sync'); ?>
              </option>
            </select>
          </label>
        </p>

        <p>
          <button class="button button-primary" id="lavka-auto-save"><?php echo esc_html__('Save auto sync', 'lavka-sync'); ?></button>
          <span id="lavka-auto-status" style="margin-left:10px;"></span>
        </p>
        <p id="lavka-auto-next" style="opacity:.8"></p>
        <p id="lavka-auto-lastto" style="opacity:.8"></p>
      </div>

    </div>
  <?php
    $i18n_settings = [
      'th_sku'            => esc_html__('SKU', 'lavka-sync'),
      'th_total'          => esc_html__('Total', 'lavka-sync'),
      'th_by_location'    => esc_html__('By warehouses', 'lavka-sync'),
      'th_found'          => esc_html__('Found', 'lavka-sync'),
      'no_data'           => esc_html__('No data', 'lavka-sync'),

      'i18n_enter_skus'   => esc_html__('Enter one or more SKUs', 'lavka-sync'),
      'i18n_running'      => esc_html__('Running…', 'lavka-sync'),
      'i18n_ok_processed' => esc_html__('OK: processed %1$s, not found %2$s', 'lavka-sync'),
      'i18n_error_prefix' => esc_html__('Error:', 'lavka-sync'),
      'i18n_network_error'=> esc_html__('Network error', 'lavka-sync'),

      'i18n_working'      => esc_html__('Working…', 'lavka-sync'),
      'i18n_error'        => esc_html__('Error:', 'lavka-sync'),
      'i18n_neterr'       => esc_html__('Network error', 'lavka-sync'),
      'i18n_ok'           => esc_html__('OK', 'lavka-sync'),

      'i18n_page'         => esc_html__('Page', 'lavka-sync'),
      'i18n_of'           => esc_html__('of', 'lavka-sync'),
      'i18n_done'         => esc_html__('Done', 'lavka-sync'),
      'i18n_updated'      => esc_html__('Updated', 'lavka-sync'),
      'i18n_not_found'    => esc_html__('Not found', 'lavka-sync'),
    ];
  ?>
  <script>
    const LAVKA_I18N = <?php echo wp_json_encode($i18n_settings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  </script>
<script>
(function(){
  const ajaxUrl = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
  const nonce   = "<?php echo esc_js(wp_create_nonce('lavka_pull_java')); ?>";
  const btn     = document.getElementById('lavka-pull-java');
  const dryEl   = document.getElementById('lavka-pull-dry');
  const status  = document.getElementById('lavka-pull-status');
  const skusEl  = document.getElementById('lavka-skus');

  btn?.addEventListener('click', async ()=>{
    const raw = (skusEl.value || '').trim();
    const list = raw.split(/[\s,;]+/).filter(Boolean);
    if (!list.length) {
      status.textContent = LAVKA_I18N.i18n_enter_skus;
      return;
    }

    status.textContent = LAVKA_I18N.i18n_running;

    const f = new FormData();
    f.append('action','lavka_pull_java');
    f.append('_wpnonce', nonce);
    f.append('dry', dryEl.checked ? '1':'0');
    // отправляем как массив skus[]
    list.forEach(s => f.append('skus[]', s));

    try {
  const r = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:f });

  const ct = (r.headers.get('content-type') || '').toLowerCase();
  let j = null, raw = '';

  if (ct.includes('application/json')) {
    j = await r.json();
  } else {
    raw = await r.text(); // сервер вернул HTML/текст (например, 504/524)
    throw new Error(`HTTP ${r.status} ${r.statusText}. Body: ${raw.slice(0,300)}`);
  }

  if (j?.success) {
    const d = j.data || {};
    status.textContent =
      `${LAVKA_I18N.i18n_done}: ${d.processed||0}, ` +
      `${LAVKA_I18N.i18n_not_found} ${d.not_found||0}`;

    // рисуем таблицу, как раньше
    const boxId = 'lavka-pull-details';
    let box = document.getElementById(boxId);
    if (!box) {
      box = document.createElement('div');
      box.id = boxId;
      box.style.marginTop = '8px';
      status.parentNode.appendChild(box);
    }

    const rows = (d.results || []).map(r => {
      const lines = (r.lines || []).map(l => `${l.term_id}: ${l.qty}`).join(', ');
      return `<tr>
                <td>${r.sku}</td>
                <td>${r.total ?? ''}</td>
                <td>${lines}</td>
                <td>${r.found ? '✓' : '—'}</td>
              </tr>`;
    }).join('');

    box.innerHTML = `
      <table class="widefat striped" style="max-width:720px">
        <thead>
          <tr>
            <th>${LAVKA_I18N.th_sku}</th>
            <th>${LAVKA_I18N.th_total}</th>
            <th>${LAVKA_I18N.th_by_location}</th>
            <th>${LAVKA_I18N.th_found}</th>
          </tr>
        </thead>
        <tbody>${rows || `<tr><td colspan="4">${LAVKA_I18N.no_data}</td></tr>`}</tbody>
      </table>
    `;

    console.log('OK:', d);
  } else {
    status.textContent = `${LAVKA_I18N.i18n_error_prefix} ${j?.data?.error || 'unknown'}`;
    console.warn('AJAX fail:', j);
  }

} catch (e) {
  console.error('AJAX network error:', e);
  status.textContent = `${LAVKA_I18N.i18n_network_error}${e?.message ? ' — ' + e.message : ''}`;
}
  });
})();
</script>
    <script>
      (function(){
  const ajaxUrl = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
  const nonce   = "<?php echo esc_js(wp_create_nonce('lavka_pull_java')); ?>";
  const btn     = document.getElementById('lavka-pull-java');
  const dryEl   = document.getElementById('lavka-pull-dry');
  const status  = document.getElementById('lavka-pull-status');
  const skusEl  = document.getElementById('lavka-skus');

  btn?.addEventListener('click', async ()=>{
    const raw = (skusEl.value || '').trim();
    const list = raw.split(/[\s,;]+/).filter(Boolean);
    if (!list.length) {
      status.textContent = LAVKA_I18N.i18n_enter_skus;
      return;
    }

    status.textContent = LAVKA_I18N.i18n_running;

    const f = new FormData();
    f.append('action','lavka_pull_java');
    f.append('_wpnonce', nonce);
    f.append('dry', dryEl.checked ? '1':'0');
    list.forEach(s => f.append('skus[]', s));

    try {
      const r = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:f });

      const ct = (r.headers.get('content-type') || '').toLowerCase();
      let j = null, rawBody = '';

      if (ct.includes('application/json')) {
        j = await r.json();
      } else {
        rawBody = await r.text(); // сервер вернул HTML/текст (например, 504/524)
        throw new Error(`HTTP ${r.status} ${r.statusText}. Body: ${rawBody.slice(0,300)}`);
      }

      if (j?.success) {
        const d = j.data || {};
        status.textContent =
          `${LAVKA_I18N.i18n_done}: ${d.processed||0}, ` +
          `${LAVKA_I18N.i18n_not_found} ${d.not_found||0}`;

        const boxId = 'lavka-pull-details';
        let box = document.getElementById(boxId);
        if (!box) {
          box = document.createElement('div');
          box.id = boxId;
          box.style.marginTop = '8px';
          status.parentNode.appendChild(box);
        }

        const rows = (d.results || []).map(r => {
          const lines = (r.lines || []).map(l => `${l.term_id}: ${l.qty}`).join(', ');
          return `<tr>
                    <td>${r.sku}</td>
                    <td>${r.total ?? ''}</td>
                    <td>${lines}</td>
                    <td>${r.found ? '✓' : '—'}</td>
                  </tr>`;
        }).join('');

        box.innerHTML = `
          <table class="widefat striped" style="max-width:720px">
            <thead>
              <tr>
                <th>${LAVKA_I18N.th_sku}</th>
                <th>${LAVKA_I18N.th_total}</th>
                <th>${LAVKA_I18N.th_by_location}</th>
                <th>${LAVKA_I18N.th_found}</th>
              </tr>
            </thead>
            <tbody>${rows || `<tr><td colspan="4">${LAVKA_I18N.no_data}</td></tr>`}</tbody>
          </table>
        `;

        console.log('OK:', d);
      } else {
        status.textContent = `${LAVKA_I18N.i18n_error_prefix} ${j?.data?.error || 'unknown'}`;
        console.warn('AJAX fail:', j);
      }

    } catch (e) {
      console.error('AJAX network error:', e);
      status.textContent = `${LAVKA_I18N.i18n_network_error}${e?.message ? ' — ' + e.message : ''}`;
    }
  });
}());

    </script>

    <script>
(function(){
  const ajaxUrl  = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
  const autoNonce = "<?php echo esc_js(wp_create_nonce('lavka_auto_nonce')); ?>";

  const elEnabled = document.getElementById('lavka-auto-enabled');
  const elMode    = document.getElementById('lavka-auto-mode');
  const elInterval= document.getElementById('lavka-auto-interval');
  const elTime    = document.getElementById('lavka-auto-time');
  const elBatch   = document.getElementById('lavka-auto-batch');
  const elDates   = document.getElementById('lavka-auto-dates');
  const elDows    = Array.from(document.querySelectorAll('.lavka-auto-dow'));
  const wInterval = document.getElementById('lavka-auto-interval-wrap');
  const wTime     = document.getElementById('lavka-auto-time-wrap');
  const wDow      = document.getElementById('lavka-auto-dow-wrap');
  const wDates    = document.getElementById('lavka-auto-dates-wrap');
  const elSave    = document.getElementById('lavka-auto-save');
  const elStatus  = document.getElementById('lavka-auto-status');
  const elNext    = document.getElementById('lavka-auto-next');
  const elStrategy = document.getElementById('lavka-auto-strategy');
  const elLastTo   = document.getElementById('lavka-auto-lastto');

  function toggleByMode(){
    const m = elMode.value;
    wInterval.style.display = (m === 'interval') ? '' : 'none';
    wTime.style.display     = (m === 'daily' || m === 'weekly' || m === 'dates') ? '' : 'none';
    wDow.style.display      = (m === 'weekly')  ? '' : 'none';
    wDates.style.display    = (m === 'dates')   ? '' : 'none';
  }
  elMode.addEventListener('change', toggleByMode);

  async function autoAjax(action, payload){
    const f = new FormData();
    f.append('action', action);
    f.append('_wpnonce', autoNonce);
    if (payload) Object.entries(payload).forEach(([k,v])=>{
      if (Array.isArray(v)) v.forEach(x=>f.append(k+'[]', x));
      else f.append(k, v);
    });
    const r = await fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:f});
    return r.json();
  }

  function fill(o){
    elEnabled.checked = !!o.enabled;
    elMode.value      = o.mode || 'off';
    elInterval.value  = o.interval || 60;
    elTime.value      = o.time || '03:00';
    elBatch.value     = o.batch || 200;
    elDates.value     = (o.dates || []).join(', ');
    elDows.forEach(ch => ch.checked = (o.days || []).includes(parseInt(ch.value,10)));
    toggleByMode();
    if (o.next_ts) {
      const d = new Date(o.next_ts * 1000);
      elNext.textContent = "<?php echo esc_js(__('Next run:', 'lavka-sync')); ?> " + d.toLocaleString();
    } else {
      elNext.textContent = '';
    }
    elStrategy.value = (o.strategy || 'full');
    if (o.last_to) {
      const raw = o.last_to;
      let txt = '';
      if (typeof raw === 'number' || /^\d+(\.\d+)?$/.test(String(raw))) {
        const d = new Date(Math.floor(Number(raw)) * 1000);
        txt = d.toLocaleString();
      } else {
        const d = new Date(String(raw));
        txt = isNaN(d.getTime()) ? String(raw) : d.toLocaleString();
      }
      elLastTo.textContent = "<?php echo esc_js(__('Last serverTo:', 'lavka-sync')); ?> " + txt;
    } else {
      elLastTo.textContent = "";
    }
  }

  // load current
  autoAjax('lavka_auto_get', {}).then(j=>{
    if (j?.success) fill(j.data || {});
  });

  elSave?.addEventListener('click', async ()=>{
    elStatus.textContent = "<?php echo esc_js(__('Saving…', 'lavka-sync')); ?>";
    try{
      const payload = {
        enabled: elEnabled.checked ? '1':'0',
        mode: elMode.value,
        interval: elInterval.value,
        time: elTime.value,
        batch: elBatch.value,
        dates: elDates.value,
        days: elDows.filter(x=>x.checked).map(x=>x.value),
        strategy: elStrategy.value,
      };
      const j = await autoAjax('lavka_auto_save', payload);
      if (j?.success) {
        elStatus.textContent = "<?php echo esc_js(__('Saved', 'lavka-sync')); ?>";
        fill(j.data || {});
      } else {
        elStatus.textContent = "<?php echo esc_js(__('Error:', 'lavka-sync')); ?> " + (j?.data?.error || 'unknown');
      }
    } catch(e){
      console.error(e);
      elStatus.textContent = "<?php echo esc_js(__('Network error', 'lavka-sync')); ?>";
    }
  });
})();
</script>
<script>
(function(){
  const ajaxUrl        = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
  const nonceMovement  = "<?php echo esc_js($nonce_movement); ?>";
  const btn   = document.getElementById('lavka-mov-run');
  const st    = document.getElementById('lavka-mov-status');

  btn?.addEventListener('click', async ()=>{
    st.textContent = LAVKA_I18N.i18n_working;

    const ps   = Math.max(10, Math.min(2000, parseInt(document.getElementById('lavka-mov-pagesize').value,10) || 500));
    const dry  = document.getElementById('lavka-mov-dry').checked ? '1' : '0';
    const from = (document.getElementById('lavka-mov-from').value || '').trim();

    const f = new FormData();
    f.append('action', 'lavka_pull_movement');
    f.append('_wpnonce', nonceMovement);
    f.append('pageSize', String(ps));
    f.append('dry', dry);
    if (from) f.append('from', from);

    try {
  console.time('lavka-movement-ajax'); // замер длительности

  const r = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:f });

  const ct = (r.headers.get('content-type') || '').toLowerCase();
  let j = null, raw = '';

  if (ct.includes('application/json')) {
    j = await r.json();
  } else {
    raw = await r.text(); // HTML/текст ошибки от сервера (503/504/…)
    throw new Error(`HTTP ${r.status} ${r.statusText}. Body: ${raw.slice(0,300)}`);
  }

  if (j?.success) {
    const d = j.data || {};
    st.textContent =
      `${LAVKA_I18N.i18n_done}: ${d.updated||0}, ` +
      `${LAVKA_I18N.i18n_not_found} ${d.not_found||0}, ` +
      `pages ${d.pages||0}` + (d.earlyStop ? ' (early stop)' : '');
    console.log('OK (movement):', d);
  } else {
    st.textContent = `${LAVKA_I18N.i18n_error} ${j?.data?.error || 'unknown'}`;
    console.warn('AJAX fail (movement):', j);
  }
} catch (e) {
  console.error('AJAX network error (movement):', e);
  st.textContent = `${LAVKA_I18N.i18n_neterr}${e?.message ? ' — ' + e.message : ''}`;
} finally {
  console.timeEnd('lavka-movement-ajax');
}
  });
})();
</script>

    <?php
}

// Ручной запуск инкрементальной синхронизации (movement)
add_action('wp_ajax_lavka_pull_movement', function () {
    if (!current_user_can('manage_lavka_sync')) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lavka_pull_movement');

    $pageSize = max(10, min(LAVKA_MOV_MAX_PAGESIZE, (int)($_POST['pageSize'] ?? LAVKA_MOV_DEF_PAGESIZE)));
    $dry      = filter_var($_POST['dry'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $fromIso  = (string)($_POST['from'] ?? '');

    $t0  = microtime(true);
    // повышаем выживаемость long-poll запроса
ignore_user_abort(true);
if (function_exists('set_time_limit')) @set_time_limit(600);
@ini_set('max_execution_time', '600');
@ini_set('default_socket_timeout', '600'); // чтобы сокет HTTP не отвалился раньше


    $res = lavka_sync_java_movement_apply_loop([
        'pageSize' => $pageSize,
        'dry'      => $dry,
        'from'     => $fromIso,
    ]);
    if (empty($res['ok'])) {
        error_log('[lavka] movement ajax fail: '.print_r($res,true));
        wp_send_json_error(['error'=>$res['error'] ?? 'movement_error']);
    }

        $log_id = lavka_log_write([
            'action'      => 'movement_pull',
            'supplier'    => '',
            'stock_id'    => 0,
            'dry'         => $dry ? 1 : 0,
            'updated'     => (int)$res['updated'],
            'not_found'   => (int)$res['not_found'],
            'duration_ms' => (int)round((microtime(true)-$t0)*1000),
            'status'      => 'OK',
            'message'     => sprintf(
                'Movement window [%s..%s], pages=%d%s',
                $res['serverFrom'] ?? '-', $res['serverTo'] ?? '-', (int)$res['pages'],
                !empty($res['earlyStop']) ? ', earlyStop=1' : ''
            ),
            'errors'      => wp_json_encode($res['details'] ?? [], JSON_UNESCAPED_UNICODE),
            'user_id'     => get_current_user_id(), // <-- добавить сюда
        ]);

    if ($log_id && !empty($res['details'])) {
        $det = $res['details'];

        $csvUpdated = array_merge([['sku','total','lines_json']], $det['updated'] ?? []);
        $csvNF      = array_merge([['sku']], $det['not_found'] ?? []);

        $ts = gmdate('Ymd-His');
        $f1 = lavka_save_csv("movement-$ts-$log_id-updated.csv",   $csvUpdated);
        $f2 = lavka_save_csv("movement-$ts-$log_id-not-found.csv", $csvNF);

        $parts = [];
        if ($f1) $parts[] = 'updated_csv=' . $f1['url'];
        if ($f2) $parts[] = 'not_found_csv=' . $f2['url'];
        if (!empty($det['truncated'])) $parts[] = 'truncated=1 (limited to 300 rows per list)';
        if ($parts) lavka_log_append_message($log_id, '['.implode(' | ', $parts).']');
    }

    wp_send_json_success($res);
});

// AJAX: Pull from Java with SKUs list (string or array)
add_action('wp_ajax_lavka_pull_java', function(){
    if (!current_user_can('manage_lavka_sync')) {
        wp_send_json_error(['error'=>'forbidden'], 403);
    }
    check_ajax_referer('lavka_pull_java');

    // Принимаем и массив skus[] и строку skus; не забываем wp_unslash()
    $skus = [];
    if (isset($_POST['skus']) && is_array($_POST['skus'])) {
        $skus = array_map('strval', wp_unslash($_POST['skus']));
    } elseif (isset($_POST['skus'])) {
        $rawSkus = (string) wp_unslash($_POST['skus']);
        $skus = preg_split('/[\s,;]+/u', $rawSkus, -1, PREG_SPLIT_NO_EMPTY);
    }

    // Нормализуем
    $skus = array_values(array_filter(array_unique(array_map(function($s){
        $s = trim((string)$s);
        return $s !== '' ? $s : null;
    }, $skus))));

    if (!$skus) {
        wp_send_json_error(['error'=>'empty_skus']);
    }

    $dry = filter_var($_POST['dry'] ?? false, FILTER_VALIDATE_BOOLEAN);

    // === LOG START ===
    $t0 = microtime(true);
    // === /LOG START ===

    // ВАЖНО: дергаем именно lavka_sync_java_query_and_apply (POST /admin/stock/stock/query)
    $res = lavka_sync_java_query_and_apply($skus, ['dry'=>$dry]);

    $updatedRows   = [];
    $notFoundRows  = [];
    $TRUNCATE_LIMIT = 300;
    $truncated     = false;

    $det = $res['results'] ?? [];
    foreach ($det as $row) {
        $sku = (string)($row['sku'] ?? '');
        if ($sku === '') continue;

        if (!empty($row['found'])) {
            if (count($updatedRows) < $TRUNCATE_LIMIT) {
                $total = $row['total'] ?? null;
                $lines = isset($row['lines']) ? wp_json_encode($row['lines']) : '';
                $updatedRows[] = [$sku, $total, $lines];
            } else {
                $truncated = true;
            }
        } else {
            if (count($notFoundRows) < $TRUNCATE_LIMIT) {
                $notFoundRows[] = [$sku];
            } else {
                $truncated = true;
            }
        }
    }

        // === WRITE LOG (успех/ошибка) ===
    if (function_exists('lavka_log_write')) {
        lavka_log_write([
            'action'      => 'manual_pull_mapped',
            'supplier'    => '',
            'stock_id'    => 0,
            'dry'         => $dry ? 1 : 0,
            'updated'     => (int)($res['processed'] ?? 0),
            'not_found'   => (int)($res['not_found'] ?? 0),
            'duration_ms' => (int)round((microtime(true)-$t0)*1000),
            'status'      => !empty($res['ok']) ? 'OK' : 'ERR',
            'message'     => sprintf('SKUs=%d', is_countable($skus)?count($skus):0),
            'errors' => wp_json_encode([
                'updated'   => $updatedRows,
                'not_found' => $notFoundRows,
                'truncated' => $truncated ? 1 : 0,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
    // === /WRITE LOG ===

    if (!empty($res['ok'])) {
        wp_send_json_success($res);
    }
    wp_send_json_error(['error'=>$res['error'] ?? 'unknown','body'=>$res['body'] ?? null]);
});


add_action('admin_menu', function () {
    add_menu_page(
        __('Lavka Reports', 'lavka-sync'),
        __('Lavka Reports', 'lavka-sync'),
        'view_lavka_reports',
        'lavka-reports',
        'lavka_reports_render_page',
        'dashicons-chart-line',
        59
    );
});

// Подключаем скрипты/стили только на нашей странице
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_lavka-reports') return;
    // Chart.js
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    // Наш JS
    wp_enqueue_script('lavka-reports-js', plugins_url('reports.js', __FILE__), ['chartjs'], '1.0', true);
    // Наш CSS (по желанию)
    wp_enqueue_style('lavka-reports-css', plugins_url('reports.css', __FILE__), [], '1.0');
    // Передаём в JS URL AJAX и nonce
    wp_localize_script('lavka-reports-js', 'LavkaReports', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('lavka_reports_nonce'),
    ]);
});

// Рендер страницы
function lavka_reports_render_page() {
    if (!current_user_can('view_lavka_reports')) {
        wp_die( esc_html__('You do not have sufficient permissions to access this page.', 'lavka-sync') );
    }
    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('Lavka Reports', 'lavka-sync'); ?></h1>

      <form id="filters" style="margin: 12px 0">
        <label>
          <?php echo esc_html__('Supplier', 'lavka-sync'); ?>:
          <input type="text" name="supplier" value="KREUL">
        </label>
        <label style="margin-left:10px;">
          <?php echo esc_html__('Warehouse', 'lavka-sync'); ?>:
          <input type="number" name="stockId" value="7">
        </label>
        <button class="button" type="submit">
          <?php echo esc_html__('Refresh', 'lavka-sync'); ?>
        </button>
      </form>

      <canvas id="salesChart" height="100"></canvas>

      <h2 style="margin-top:16px;"><?php echo esc_html__('Data', 'lavka-sync'); ?></h2>
      <table class="widefat fixed striped" id="reportTable">
        <thead>
          <tr>
            <th><?php echo esc_html__('SKU', 'lavka-sync'); ?></th>
            <th><?php echo esc_html__('Title', 'lavka-sync'); ?></th>
            <th><?php echo esc_html__('Stock', 'lavka-sync'); ?></th>
            <th><?php echo esc_html__('Price', 'lavka-sync'); ?></th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td colspan="4"><?php echo esc_html__('Loading…', 'lavka-sync'); ?></td>
          </tr>
        </tbody>
      </table>
    </div>
    <?php
}

// AJAX: отдаём данные для отчёта (можно сходить в Java)
add_action('wp_ajax_lavka_reports_data', function () {
    if (!current_user_can('view_lavka_reports')) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lavka_reports_nonce');

    $supplier = sanitize_text_field($_POST['supplier'] ?? 'KREUL');
    $stockId  = (int)($_POST['stockId'] ?? 7);

    // Пример: тянем из Java API /sync/stock?dry=true, но можно сделать отдельный метод /reports/stock
    $opts = lavka_sync_get_options();
    $java = rtrim($opts['java_base_url'] ?? 'http://127.0.0.1:8080', '/');

    $url  = add_query_arg([
        'supplier' => $supplier,
        'stockId'  => $stockId,
        'dry'      => 'true'
    ], $java . '/sync/stock');
    $resp = wp_remote_post($url, [
        'timeout' => 160,
        'headers' => [
            'X-Auth-Token' => $opts['api_token'] ?? '',
            'Accept'       => 'application/json',
        ],
    ]);
    if (is_wp_error($resp)) wp_send_json_error(['error'=>$resp->get_error_message()]);
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);

    if ($code >= 200 && $code < 300) {
        // Ожидаем в ответе preview: [{id, sku, qty}]
        wp_send_json_success($body);
    }
    wp_send_json_error(['error'=>'Java status '.$code, 'body'=>$body]);
});

// Сколько уникальных SKU в базе
function lavka_count_all_skus(): int {
    global $wpdb;
    return (int) $wpdb->get_var("
        SELECT COUNT(DISTINCT m.meta_value)
        FROM {$wpdb->postmeta} m
        JOIN {$wpdb->posts} p ON p.ID = m.post_id
        WHERE m.meta_key = '_sku' AND m.meta_value <> ''
          AND p.post_type IN ('product','product_variation')
          AND p.post_status IN ('publish','private','draft','pending')
    ");
}

// Отдать порцию SKU
function lavka_get_skus_slice(int $offset, int $limit): array {
    global $wpdb;
    $sql = $wpdb->prepare("
        SELECT DISTINCT m.meta_value
        FROM {$wpdb->postmeta} m
        JOIN {$wpdb->posts} p ON p.ID = m.post_id
        WHERE m.meta_key = '_sku' AND m.meta_value <> ''
          AND p.post_type IN ('product','product_variation')
          AND p.post_status IN ('publish','private','draft','pending')
        ORDER BY m.meta_value ASC
        LIMIT %d OFFSET %d
    ", $limit, $offset);
    $rows = $wpdb->get_col($sql) ?: [];
    return array_values(array_filter(array_map('strval', $rows)));
}


add_action('wp_ajax_lavka_pull_java_all_page', function () {
    if (!current_user_can('manage_lavka_sync')) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lavka_pull_java_all');

    $batch = max(LAVKA_BATCH_MIN, min(LAVKA_BATCH_MAX, (int)($_POST['batch'] ?? 200)));
    $page  = max(0, (int)($_POST['page'] ?? 0));
    $dry = filter_var($_POST['dry'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $t0 = microtime(true); // замер

    $total = lavka_count_all_skus();
    $pages = (int) ceil($total / $batch);

    if ($total <= 0) wp_send_json_error(['error'=>'no_skus']);
    if ($page >= $pages) {
        wp_send_json_success([
            'page'      => $page,
            'pages'     => $pages,
            'total'     => $total,
            'processed' => 0,
            'not_found' => 0,
            'dry'       => (bool)$dry,
            'sample'    => [],
        ]);
    }

    $offset = $page * $batch;
    $skus   = lavka_get_skus_slice($offset, $batch);

    if (!$skus) {
        wp_send_json_success([
            'page'      => $page,
            'pages'     => $pages,
            'total'     => $total,
            'processed' => 0,
            'not_found' => 0,
            'dry'       => (bool)$dry,
            'sample'    => [],
        ]);
    }

    $res = lavka_sync_java_query_and_apply($skus, ['dry'=>$dry]);

    $updatedRows   = [];
    $notFoundRows  = [];
    $TRUNCATE_LIMIT = 300;
    $truncated     = false;

    $det = $res['results'] ?? [];
    foreach ($det as $row) {
        $sku = (string)($row['sku'] ?? '');
        if ($sku === '') continue;

        if (!empty($row['found'])) {
            if (count($updatedRows) < $TRUNCATE_LIMIT) {
                $total = $row['total'] ?? null;
                $lines = isset($row['lines']) ? wp_json_encode($row['lines']) : '';
                $updatedRows[] = [$sku, $total, $lines];
            } else {
                $truncated = true;
            }
        } else {
            if (count($notFoundRows) < $TRUNCATE_LIMIT) {
                $notFoundRows[] = [$sku];
            } else {
                $truncated = true;
            }
        }
    }

    if (empty($res['ok'])) {
        if (function_exists('lavka_log_write')) {
            lavka_log_write([
                'action'      => 'manual_paged_pull',
                'supplier'    => '',
                'stock_id'    => 0,
                'dry'         => $dry ? 1 : 0,
                'updated'     => 0,
                'not_found'   => 0,
                'duration_ms' => (int)round((microtime(true)-$t0)*1000),
                'status'      => 'ERR',
                'message'     => sprintf('page %d/%d: %s', $page+1, max(1,$pages), (string)($res['error'] ?? 'unknown')),
                'errors' => wp_json_encode([
                    'updated'   => $updatedRows,
                    'not_found' => $notFoundRows,
                    'truncated' => (count($updatedRows) >= $TRUNCATE_LIMIT || count($notFoundRows) >= $TRUNCATE_LIMIT) ? 1 : 0,
                ], JSON_UNESCAPED_UNICODE),
                'user_id' => get_current_user_id(),
            ]);
        }
        wp_send_json_error(['error'=>$res['error'] ?? 'unknown','body'=>$res['body'] ?? null]);
    }

    // Чтобы ответ не раздувался, даём только сэмпл
    $sample = $res['results'] ?? [];
    if (count($sample) > 20) $sample = array_slice($sample, 0, 20);

        // Логируем ТОЛЬКО последнюю страницу (чтобы не плодить записи)
    if (function_exists('lavka_log_write') && ($page + 1) >= $pages) {
        lavka_log_write([
            'action'      => 'manual_paged_pull',
            'supplier'    => '',
            'stock_id'    => 0,
            'dry'         => $dry ? 1 : 0,
            'updated'     => (int)($res['processed'] ?? 0),
            'not_found'   => (int)($res['not_found'] ?? 0),
            'duration_ms' => (int)round((microtime(true)-$t0)*1000),
            'status'      => 'OK',
            'message'     => sprintf('completed: pages=%d, batch=%d, total=%d', max(1,$pages), $batch, $total),
                'errors' => wp_json_encode([
                    'updated'   => $updatedRows,
                    'not_found' => $notFoundRows,
                    'truncated' => (count($updatedRows) >= $TRUNCATE_LIMIT || count($notFoundRows) >= $TRUNCATE_LIMIT) ? 1 : 0,
                ], JSON_UNESCAPED_UNICODE),
                'user_id' => get_current_user_id(),
        ]);
    }


    wp_send_json_success([
        'page'      => $page,
        'pages'     => $pages,
        'total'     => $total,
        'processed' => (int)$res['processed'],
        'not_found' => (int)$res['not_found'],
        'dry'       => (bool)$res['dry'],
        'sample'    => $sample,
    ]);
});

// === Auto-sync config helpers ===
function lavka_get_auto_cfg(): array {
    $cfg = get_option(LAVKA_AUTO_OPTION, []);
    return wp_parse_args(is_array($cfg) ? $cfg : [], [
        'enabled'  => false,
        'mode'     => 'off',     // off|interval|daily|weekly|dates
        'interval' => 60,        // minutes
        'time'     => '03:00',   // HH:MM
        'days'     => [1],       // 0..6 (Sun..Sat)
        'dates'    => [],        // [1..28]
        'batch'    => 200,
        'strategy' => 'full', // full | movement
    ]);
}

function lavka_calc_next_ts(array $cfg, ?int $from_ts = null): int {
    $from = $from_ts ?: time();
    if (empty($cfg['enabled']) || ($cfg['mode'] ?? 'off') === 'off') return 0;

    $tz = wp_timezone();
    $dt = new DateTime('now', $tz);
    $dt->setTimestamp($from);

    $time = is_string($cfg['time'] ?? '') ? ($cfg['time'] ?: '03:00') : '03:00';
    [$h,$m] = array_map('intval', array_pad(explode(':', $time, 2), 2, 0));

    switch ($cfg['mode']) {
      case 'interval':
        $mins = max(5, min(1440, (int)$cfg['interval']));
        return $from + $mins * 60;

      case 'daily': {
        $next = clone $dt;
        $next->setTime($h,$m,0);
        if ($next->getTimestamp() <= $from) $next->modify('+1 day');
        return $next->getTimestamp();
      }

      case 'weekly': {
        $days = array_map('intval', is_array($cfg['days']) ? $cfg['days'] : []);
        if (!$days) $days = [1]; // Monday
        sort($days);
        for ($i=0; $i<14; $i++) {
          $cand = clone $dt;
          $cand->modify("+$i day");
          $w = (int)$cand->format('w'); // 0..6
          if (in_array($w, $days, true)) {
            $cand->setTime($h,$m,0);
            if ($cand->getTimestamp() > $from) return $cand->getTimestamp();
          }
        }
        return 0;
      }

      case 'dates': {
        $dates = array_filter(array_map('intval', is_array($cfg['dates']) ? $cfg['dates'] : []), function($d){ return $d>=1 && $d<=28; });
        if (!$dates) $dates = [1];
        sort($dates);
        $cand = clone $dt;
        for ($i=0; $i<62; $i++) {
          $d = (int)$cand->format('j');
          if (in_array($d, $dates, true)) {
            $cand2 = clone $cand;
            $cand2->setTime($h,$m,0);
            if ($cand2->getTimestamp() > $from) return $cand2->getTimestamp();
          }
          $cand->modify('+1 day');
        }
        return 0;
      }
    }
    return 0;
}

// Schedule runner: processes ALL SKUs by pages with mapping
add_action('lavka_auto_pull_all', function () {
    $cfg = lavka_get_auto_cfg();
    if (empty($cfg['enabled'])) return;

    $t0 = microtime(true);
    $batch = max(LAVKA_BATCH_MIN, min(LAVKA_BATCH_MAX, (int)$cfg['batch']));
    $total = lavka_count_all_skus();
    $pages = (int) ceil($total / $batch);

    $updated = 0;
    $notFound = 0;

    $detUpdated   = [];
    $detNotFound  = [];
    $TRUNCATE_LIMIT = 300;
    $truncated    = false;

    for ($page = 0; $page < $pages; $page++) {
        $skus = lavka_get_skus_slice($page * $batch, $batch);
        if (!$skus) continue;

        $res = lavka_sync_java_query_and_apply($skus, ['dry' => false]);
        if (!empty($res['ok'])) {
            $updated  += (int)($res['processed'] ?? 0);
            $notFound += (int)($res['not_found'] ?? 0);
            $updatedRows   = [];
            $notFoundRows  = [];

            $det = $res['results'] ?? [];
            foreach ($det as $row) {
                $sku = (string)($row['sku'] ?? '');
                if ($sku === '') continue;

                if (!empty($row['found'])) {
                    if (count($updatedRows) < $TRUNCATE_LIMIT) {
                        $total = $row['total'] ?? null;
                        $lines = isset($row['lines']) ? wp_json_encode($row['lines']) : '';
                        $updatedRows[] = [$sku, $total, $lines];
                    } else {
                        $truncated = true;
                    }
                } else {
                    if (count($notFoundRows) < $TRUNCATE_LIMIT) {
                        $notFoundRows[] = [$sku];
                    } else {
                        $truncated = true;
                    }
                }
            }
            if (!$truncated && $updatedRows) {
                    foreach ($updatedRows as $r) {
                        if (count($detUpdated) < $TRUNCATE_LIMIT) {
                            $detUpdated[] = $r;
                        } else { $truncated = true; break; }
                    }
                }
                if (!$truncated && $notFoundRows) {
                    foreach ($notFoundRows as $r) {
                        if (count($detNotFound) < $TRUNCATE_LIMIT) {
                            $detNotFound[] = $r;
                        } else { $truncated = true; break; }
                    }
                }
        } else {
            // Можно логировать ошибку и продолжать
        }
    }

    lavka_log_write([
        'action'      => 'auto_pull_all',
        'supplier'    => '',
        'stock_id'    => 0,
        'dry'         => 0,
        'updated'     => $updated,
        'not_found'   => $notFound,
        'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
        'status'      => 'OK',
        'message'     => sprintf('Auto paged pull completed (batch=%d, pages=%d)', $batch, $pages),
        'errors'      => wp_json_encode([
            'updated'   => $detUpdated,
            'not_found' => $detNotFound,
            'truncated' => $truncated ? 1 : 0,
        ], JSON_UNESCAPED_UNICODE),
        'user_id'     => 0, // крон — системный
    ]);

    // Планируем следующий запуск
    $next = lavka_calc_next_ts($cfg, time());
    if ($next > 0) {
        wp_schedule_single_event($next, 'lavka_auto_pull_all');
    }
});

// Auto-runner: incremental movement
add_action('lavka_auto_pull_movement', function () {
    $cfg = lavka_get_auto_cfg();
    if (empty($cfg['enabled']) || ($cfg['strategy'] ?? 'full') !== 'movement') return;

    $t0  = microtime(true);

    // pageSize берём из batch, dry=false (боевой режим)
    if (!function_exists('lavka_sync_java_movement_apply_loop')) {
        // если файл с функцией не подключён — не падаем, просто выходим
        return;
    }

        // повышаем выживаемость long-poll запроса
ignore_user_abort(true);
if (function_exists('set_time_limit')) @set_time_limit(600);
@ini_set('max_execution_time', '600');
@ini_set('default_socket_timeout', '600'); // чтобы сокет HTTP не отвалился раньше

    $res = lavka_sync_java_movement_apply_loop([
        'pageSize' => (int)$cfg['batch'],
        'dry'      => false,
    ]);

    lavka_log_write([
        'action'      => 'auto_movement',
        'supplier'    => '',
        'stock_id'    => 0,
        'dry'         => 0,
        'updated'     => (int)($res['updated'] ?? 0),
        'not_found'   => (int)($res['not_found'] ?? 0),
        'duration_ms' => (int)round((microtime(true)-$t0)*1000),
        'status'      => !empty($res['ok']) ? 'OK' : 'ERR',
        'message'     => sprintf(
            'Movement window [%s..%s], pages=%d%s',
            $res['serverFrom'] ?? '-', $res['serverTo'] ?? '-', (int)($res['pages'] ?? 0),
            !empty($res['earlyStop']) ? ' (early-stop)' : ''
        ),
        'errors'  => wp_json_encode($res['details'] ?? [], JSON_UNESCAPED_UNICODE),
        'user_id' => 0,
    ]);

    $next = lavka_calc_next_ts($cfg, time());
    if ($next > 0) wp_schedule_single_event($next, 'lavka_auto_pull_movement');
});

// AJAX: загрузить текущие настройки автосинка
add_action('wp_ajax_lavka_auto_get', function () {
    if (!current_user_can('manage_lavka_sync')) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lavka_auto_nonce');

    $cfg = lavka_get_auto_cfg();
    $next = lavka_calc_next_ts($cfg, time());
    wp_send_json_success([
        'enabled'  => (bool)$cfg['enabled'],
        'mode'     => (string)$cfg['mode'],
        'interval' => (int)$cfg['interval'],
        'time'     => (string)$cfg['time'],
        'days'     => array_values(array_map('intval', (array)$cfg['days'])),
        'dates'    => array_values(array_map('intval', (array)$cfg['dates'])),
        'batch'    => (int)$cfg['batch'],
        'next_ts'  => $next ?: null,
        'strategy' => (string)($cfg['strategy'] ?? 'full'),
        'last_to'  => get_option(LAVKA_LAST_TO_OPTION, '') ?: null,
    ]);
});

// AJAX: сохранить настройки и перепланировать
add_action('wp_ajax_lavka_auto_save', function () {
    if (!current_user_can('manage_lavka_sync')) wp_send_json_error(['error'=>'forbidden'], 403);
    check_ajax_referer('lavka_auto_nonce');

    $enabled  = !empty($_POST['enabled']);
    $mode     = sanitize_text_field($_POST['mode'] ?? 'off');
    $interval = max(5, min(1440, (int)($_POST['interval'] ?? 60)));
    $time = preg_replace('/[^0-9:]/', '', (string)($_POST['time'] ?? '03:00'));
    if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $time, $m)) {
        $h   = str_pad((string)min(23, (int)$m[1]), 2, '0', STR_PAD_LEFT);
        $min = str_pad((string)min(59, (int)$m[2]), 2, '0', STR_PAD_LEFT);
        $time = "$h:$min";
    } else {
        $time = '03:00';
    }
    $batch    = max(LAVKA_BATCH_MIN, min(LAVKA_BATCH_MAX, (int)($_POST['batch'] ?? 200)));

    $days = array_map('intval', (array)($_POST['days'] ?? []));
    $days = array_values(array_filter($days, fn($d)=> $d>=0 && $d<=6));

    // dates строкой "1,10,28" или массивом
    $dates_raw = $_POST['dates'] ?? '';
    if (is_array($dates_raw)) {
        $dates = array_map('intval', $dates_raw);
    } else {
        $dates = array_map('intval', preg_split('/[^\d]+/', (string)$dates_raw, -1, PREG_SPLIT_NO_EMPTY));
    }
    $dates = array_values(array_filter($dates, fn($d)=> $d>=1 && $d<=28));

    // <<< НОВОЕ: разбор стратегии >>>
    $strategy = sanitize_text_field($_POST['strategy'] ?? 'full');
    $strategy = in_array($strategy, ['full','movement'], true) ? $strategy : 'full';

    $cfg = [
        'enabled'  => $enabled,
        'mode'     => in_array($mode, ['off','interval','daily','weekly','dates'], true) ? $mode : 'off',
        'interval' => $interval,
        'time'     => $time ?: '03:00',
        'days'     => $days,
        'dates'    => $dates,
        'batch'    => $batch,
        'strategy' => $strategy, // <<< НОВОЕ
    ];
    update_option(LAVKA_AUTO_OPTION, $cfg, false);

    // Снимаем предыдущие хуки и планируем НУЖНЫЙ
    wp_clear_scheduled_hook('lavka_auto_pull_all');
    wp_clear_scheduled_hook('lavka_auto_pull_movement'); // <<< НОВОЕ
    $next = lavka_calc_next_ts($cfg, time());
    if ($enabled && $next > 0) {
        if ($strategy === 'movement') {
            wp_schedule_single_event($next, 'lavka_auto_pull_movement'); // <<< НОВОЕ
        } else {
            wp_schedule_single_event($next, 'lavka_auto_pull_all');
        }
    }

    wp_send_json_success(array_merge($cfg, [
        'next_ts' => $next ?: null,
        'last_to' => get_option(LAVKA_LAST_TO_OPTION, '') ?: null, // <<< удобно отдать в UI
    ]));
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('lavka_auto_pull_all');
    wp_clear_scheduled_hook('lavka_auto_pull_movement'); // <<< НОВОЕ
});

function lavka_log_write(array $data) {
    global $wpdb;
    $t = $wpdb->prefix . 'lavka_sync_logs';

    $wpdb->insert($t, [
        'ts'                  => gmdate('Y-m-d H:i:s'),
        'action'              => $data['action'] ?? 'sync_stock',
        'supplier'            => $data['supplier'] ?? '',
        'stock_id'            => (int)($data['stock_id'] ?? 0),
        'dry'                 => !empty($data['dry']) ? 1 : 0,
        'changed_since_hours' => isset($data['changed_since_hours']) ? (int)$data['changed_since_hours'] : null,
        'updated'             => (int)($data['updated'] ?? 0),
        'not_found'           => (int)($data['not_found'] ?? 0),
        'duration_ms'         => (int)($data['duration_ms'] ?? 0),
        'status'              => $data['status'] ?? 'OK',
        'message'             => $data['message'] ?? null,

        // НОВОЕ:
        'errors'              => isset($data['errors']) ? (string)$data['errors'] : null,
        'user_id'             => (int)($data['user_id'] ?? get_current_user_id()),
    ]);

    if ($wpdb->last_error && defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[lavka] log insert failed: ' . $wpdb->last_error);
    }

    return (int)$wpdb->insert_id; // ← важно: вернуть ID
}

// 1) Пункт меню "Logs" под вашим родительским "lavka-sync"
add_action('admin_menu', function () {
    add_submenu_page(
        'lavka-sync',                // slug родительской страницы (вашей)
        __('Lavka Logs', 'lavka-sync'),  // Title
        __('Logs', 'lavka-sync'),    // Label в меню
        'manage_lavka_sync',         // capability
        'lavka-logs',                // slug страницы логов
        'lavka_logs_render_page'     // callback рендера
    );
});

// 2) Рендер страницы логов с пагинацией
function lavka_logs_render_page() {
    if (!current_user_can('manage_lavka_sync')) 
      wp_die( esc_html__('You do not have sufficient permissions to access this page.', 'lavka-sync') );
    global $wpdb;
    $t = $wpdb->prefix . 'lavka_sync_logs';

    $per  = max(10, min(200, (int)($_GET['per_page'] ?? 50)));
    $page = max(1, (int)($_GET['paged'] ?? 1));
    $off  = ($page - 1) * $per;

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t ORDER BY id DESC LIMIT %d OFFSET %d", $per, $off
    ), ARRAY_A);

    // ссылка на CSV с nonce
    $csv_url = wp_nonce_url(
        admin_url('admin-post.php?action=lavka_logs_csv'),
        'lavka_logs_csv'
    );
    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('Lavka Logs', 'lavka-sync'); ?></h1>

        <p>
          <a class="button" href="<?php echo esc_url($csv_url); ?>">
            <?php echo esc_html__('Export CSV', 'lavka-sync'); ?>
          </a>
        </p>

      <table class="widefat fixed striped">
        <thead>
            <tr>
              <th><?php echo esc_html__('ID', 'lavka-sync'); ?></th>
              <th><?php echo esc_html__('Time (UTC)', 'lavka-sync'); ?></th>
              <th><?php echo esc_html__('Action', 'lavka-sync'); ?></th>
              <th><?php echo esc_html__('Supplier', 'lavka-sync'); ?></th>
              <th><?php echo esc_html__('Stock', 'lavka-sync'); ?></th>
              <th><?php echo esc_html__('Dry', 'lavka-sync'); ?></th>
              <th><?php echo esc_html__('Changed, h', 'lavka-sync'); ?></th>
              <th><?php echo esc_html__('Updated', 'lavka-sync'); ?></th>
              <th><?php echo esc_html__('Not found', 'lavka-sync'); ?></th>
              <th><?php echo esc_html__('ms', 'lavka-sync'); ?></th>
              <th><?php echo esc_html__('Status', 'lavka-sync'); ?></th>
              <th><?php echo esc_html__('Message', 'lavka-sync'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($rows): foreach ($rows as $r): ?>
          <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <td><?php echo esc_html($r['ts']); ?></td>
            <td><?php echo esc_html($r['action']); ?></td>
            <td><?php echo esc_html($r['supplier']); ?></td>
            <td><?php echo (int)$r['stock_id']; ?></td>
            <td><?php echo $r['dry'] ? esc_html__('yes', 'lavka-sync') : esc_html__('no', 'lavka-sync'); ?></td>
            <td><?php echo $r['changed_since_hours'] !== null ? (int)$r['changed_since_hours'] : ''; ?></td>
            <td><?php echo (int)$r['updated']; ?></td>
            <td><?php echo (int)$r['not_found']; ?></td>
            <td><?php echo (int)$r['duration_ms']; ?></td>
            <td><?php echo esc_html($r['status']); ?></td>
            <td><?php echo esc_html($r['message']); ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="12"><?php echo esc_html__('No logs yet.', 'lavka-sync'); ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <?php
      // простая пагинация
      $pages = (int) ceil($total / $per);
      if ($pages > 1) {
          $base = remove_query_arg(['paged'], $_SERVER['REQUEST_URI']);
          echo '<div class="tablenav"><div class="tablenav-pages">';
          for ($i = 1; $i <= $pages; $i++) {
              $url = esc_url(add_query_arg('paged', $i, $base));
              $cls = $i === $page ? ' class="page-numbers current"' : ' class="page-numbers"';
              echo "<a$cls href=\"$url\">$i</a> ";
          }
          echo '</div></div>';
      }
      ?>
    </div>
    <?php
}
// 3) Экспорт CSV (admin-post)
add_action('admin_post_lavka_logs_csv', function () {
    if (!current_user_can('manage_lavka_sync')) 
        wp_die( esc_html__('You do not have sufficient permissions to access this page.', 'lavka-sync') );
    check_admin_referer('lavka_logs_csv');

    global $wpdb;
    $t = $wpdb->prefix . 'lavka_sync_logs';
    $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY id DESC LIMIT 5000", ARRAY_A);

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="lavka-logs.csv"');

    $out = fopen('php://output', 'w');

    // шапка
    fputcsv($out, [
        'id','ts','action','supplier','stock_id','dry','changed_since_hours',
        'updated','not_found','duration_ms','status','message'
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['ts'],
            $r['action'],
            $r['supplier'],
            $r['stock_id'],
            $r['dry'],
            $r['changed_since_hours'],
            $r['updated'],
            $r['not_found'],
            $r['duration_ms'],
            $r['status'],
            $r['message'],
        ]);
    }
    fclose($out);
    exit;
});