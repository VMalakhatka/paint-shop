<?php
/**
 * Plugin Name: Lavka Sync
 * Description: Provides buttons and settings to synchronize with the Java service.
 * Version: 0.1.0
 * Text Domain: lavka-sync
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

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
        'timeout' => 20,
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

function lavka_sync_render_page() {
    if (!current_user_can('manage_lavka_sync')) return;

    $opts  = lavka_sync_get_options();
    $nonce = wp_create_nonce('lavka_sync_nonce');
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
          <tr>
            <th scope="row"><label for="supplier"><?php echo esc_html__('Supplier', 'lavka-sync'); ?></label></th>
            <td><input name="<?php echo LAVKA_SYNC_OPTION; ?>[supplier]" id="supplier" type="text" value="<?php echo esc_attr($o['supplier'] ?? ''); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="stock_id"><?php echo esc_html__('Stock ID', 'lavka-sync'); ?></label></th>
            <td><input name="<?php echo LAVKA_SYNC_OPTION; ?>[stock_id]" id="stock_id" type="number" value="<?php echo esc_attr($o['stock_id'] ?? 0); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="schedule"><?php echo esc_html__('Auto sync (WP-Cron)', 'lavka-sync'); ?></label></th>
            <td>
              <select name="<?php echo LAVKA_SYNC_OPTION; ?>[schedule]" id="schedule">
                <option value="off"        <?php selected($o['schedule'] ?? 'off', 'off'); ?>><?php echo esc_html__('Disabled', 'lavka-sync'); ?></option>
                <option value="hourly"     <?php selected($o['schedule'] ?? 'off', 'hourly'); ?>><?php echo esc_html__('Hourly', 'lavka-sync'); ?></option>
                <option value="twicedaily" <?php selected($o['schedule'] ?? 'off', 'twicedaily'); ?>><?php echo esc_html__('Twice daily', 'lavka-sync'); ?></option>
                <option value="daily"      <?php selected($o['schedule'] ?? 'off', 'daily'); ?>><?php echo esc_html__('Daily', 'lavka-sync'); ?></option>
              </select>
            </td>
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
      
      <p>
        <button id="lavka-sync-dry" class="button"><?php echo esc_html__('Dry-run', 'lavka-sync'); ?></button>
        <button id="lavka-sync-run" class="button button-primary"><?php echo esc_html__('Synchronize', 'lavka-sync'); ?></button>
        <span id="lavka-sync-result" style="margin-left:10px;"></span>
      </p>

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

    try{
      const r = await fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:f});
      const j = await r.json();
      if (j?.success) {
        status.textContent = LAVKA_I18N.i18n_ok_processed
          .replace('%1$s', j.data.processed)
          .replace('%2$s', j.data.not_found);
        const boxId = 'lavka-pull-details';
        let box = document.getElementById(boxId);
        if (!box) {
          box = document.createElement('div');
          box.id = boxId;
          box.style.marginTop = '8px';
          status.parentNode.appendChild(box);
        }
        const rows = (j.data.results || []).map(r => {
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
        status.textContent = LAVKA_I18N.i18n_ok_processed
          .replace('%1$s', j.data.processed)
          .replace('%2$s', j.data.not_found);
        console.log(j.data);
      } else {
        status.textContent = `${LAVKA_I18N.i18n_error_prefix} ${j?.data?.error || 'unknown'}`;
        console.log(j);
      }
    }catch(e){
      status.textContent = LAVKA_I18N.i18n_network_error;
      console.error(e);
    }
  });
})();
</script>
    <script>
      (function() {
        const ajaxUrl = "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
        const nonce   = "<?php echo esc_js($nonce); ?>";

        async function call(action, dry) {
          const resEl = document.getElementById('lavka-sync-result');
          resEl.textContent = LAVKA_I18N.i18n_working;
          try {
            const form = new FormData();
            form.append('action', action);
            form.append('_wpnonce', nonce);
            form.append('dry', dry ? '1' : '0');
            const resp = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form });
            const json = await resp.json();
            resEl.textContent = (json && json.success) ? LAVKA_I18N.i18n_ok
              : `${LAVKA_I18N.i18n_error} ${(json && json.data && json.data.error) ? json.data.error : 'unknown'}`;
            console.log(json);
          } catch(e) {
            resEl.textContent = LAVKA_I18N.i18n_neterr;
            console.error(e);
          }
        }

        document.getElementById('lavka-sync-dry').addEventListener('click', () => call('lavka_sync_run', true));
        document.getElementById('lavka-sync-run').addEventListener('click', () => call('lavka_sync_run', false));
      })();

      (function(){
        const ajaxUrl     = "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
        const nonceAll    = "<?php echo esc_js(wp_create_nonce('lavka_pull_java_all')); ?>";
        const btnAll      = document.getElementById('lavka-pull-all');
        const batchEl     = document.getElementById('lavka-batch');
        const dryEl       = document.getElementById('lavka-pull-dry');
        const allStatus   = document.getElementById('lavka-pull-all-status');

        btnAll?.addEventListener('click', async ()=>{
          const batch = Math.max(10, Math.min(1000, parseInt(batchEl.value,10) || 200));
          const dry   = dryEl.checked ? '1' : '0';

          btnAll.disabled = true;
          let page = 0, pages = null, total = 0, done = 0, nf = 0;

          try{
            while (pages === null || page < pages) {
              allStatus.textContent = `${LAVKA_I18N.i18n_page} ${page+1}${pages ? ' ' + LAVKA_I18N.i18n_of + ' ' + pages : ''}…`;

              const f = new FormData();
              f.append('action','lavka_pull_java_all_page');
              f.append('_wpnonce', nonceAll);
              f.append('page', String(page));
              f.append('batch', String(batch));
              f.append('dry', dry);

              const r = await fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body:f });
              const j = await r.json();
              if (!j?.success) {
                allStatus.textContent = `${LAVKA_I18N.i18n_error_prefix} ${j?.data?.error || 'unknown'}`;
                break;
              }

              const d = j.data;
              pages = d.pages; total = d.total;
              done  += (d.processed || 0);
              nf    += (d.not_found || 0);

              allStatus.textContent =
                `${LAVKA_I18N.i18n_done}: ${Math.min((page+1)*batch, total)}/${total}. ` +
                `${LAVKA_I18N.i18n_updated} ${done}, ${LAVKA_I18N.i18n_not_found} ${nf}.`;
              page++;
            }
          } catch(e){
            console.error(e);
            allStatus.textContent =  LAVKA_I18N.i18n_network_error;
          } finally {
            btnAll.disabled = false;
          }
        });
      })();

    </script>
    <?php
}


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

    // ВАЖНО: дергаем именно lavka_sync_java_query_and_apply (POST /admin/stock/stock/query)
    $res = lavka_sync_java_query_and_apply($skus, ['dry'=>$dry]);

    if (!empty($res['ok'])) {
        wp_send_json_success($res);
    }
    wp_send_json_error(['error'=>$res['error'] ?? 'unknown','body'=>$res['body'] ?? null]);
});

// Крон: планировщик
add_action('update_option_' . LAVKA_SYNC_OPTION, function ($old, $new) {
    $old = wp_parse_args($old ?: [], []);
    $new = wp_parse_args($new ?: [], []);
    if (($old['schedule'] ?? 'off') === ($new['schedule'] ?? 'off')) return;

    // снять старые
    wp_clear_scheduled_hook('lavka_sync_cron');

    // поставить новый
    if (!empty($new['schedule']) && $new['schedule'] !== 'off') {
        if (!wp_next_scheduled('lavka_sync_cron')) {
            wp_schedule_event(time()+60, $new['schedule'], 'lavka_sync_cron');
        }
    }
}, 10, 2);

add_action('lavka_sync_cron', function () {
    // дергаем тот же AJAX-хендлер логикой PHP (без UI)
    $o = lavka_sync_get_options();

    $url = trailingslashit($o['java_base_url']) . 'sync/stock';
    $url = add_query_arg([
        'supplier' => $o['supplier'],
        'stockId'  => (int)$o['stock_id'],
        'dry'      => 'false',
    ], $url);

    $args = [
        'method'  => 'POST',
        'timeout' => 30,
        'headers' => [
            'X-Auth-Token' => $o['api_token'],
            'Accept'       => 'application/json',
        ],
        'blocking' => false,
    ];
    wp_remote_post($url, $args);
}
);

// Создаём собственное право и роль на активации плагина
//1й
register_activation_hook(__FILE__, function () {
    // Базируемся на shop_manager
    $base = get_role('shop_manager');
    if ($base && !get_role('lavka_manager')) {
        add_role('lavka_manager', 'Lavka Manager', $base->capabilities);
    }
    // Заводим кастомные права
    $caps = ['manage_lavka_sync', 'view_lavka_reports'];
    foreach (['shop_manager','lavka_manager','administrator'] as $role) {
        $r = get_role($role);
        if ($r) { foreach ($caps as $c) { $r->add_cap($c); } }
    }
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
        'timeout' => 20,
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

register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table   = $wpdb->prefix . 'lavka_sync_logs';
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("
        CREATE TABLE {$table} (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          ts DATETIME NOT NULL,
          action VARCHAR(50) NOT NULL,
          supplier VARCHAR(100) NOT NULL,
          stock_id INT NOT NULL,
          dry TINYINT(1) NOT NULL DEFAULT 0,
          changed_since_hours INT NULL,
          updated INT NOT NULL DEFAULT 0,
          not_found INT NOT NULL DEFAULT 0,
          duration_ms INT NOT NULL DEFAULT 0,
          status VARCHAR(20) NOT NULL,
          message TEXT NULL,
          PRIMARY KEY (id),
          KEY ts (ts),
          KEY action (action),
          KEY supplier (supplier)
        ) {$charset};
    ");
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

    $batch = max(10, min(1000, (int)($_POST['batch'] ?? 200)));
    $page  = max(0, (int)($_POST['page'] ?? 0));
    $dry = filter_var($_POST['dry'] ?? false, FILTER_VALIDATE_BOOLEAN);

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

    if (empty($res['ok'])) {
        wp_send_json_error(['error'=>$res['error'] ?? 'unknown','body'=>$res['body'] ?? null]);
    }

    // Чтобы ответ не раздувался, даём только сэмпл
    $sample = $res['results'] ?? [];
    if (count($sample) > 20) $sample = array_slice($sample, 0, 20);

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
    ]);
}

add_action('wp_ajax_lavka_sync_run', function () {
    if (!current_user_can('manage_lavka_sync')) wp_send_json_error(['error' => 'forbidden'], 403);
    check_ajax_referer('lavka_sync_nonce');

    $o   = lavka_sync_get_options();
    $dry = !empty($_POST['dry']);
    $changed = isset($_POST['changed_since_hours']) ? (int)$_POST['changed_since_hours'] : null;

    $url = trailingslashit($o['java_base_url']) . 'sync/stock';
    $qs  = [
        'supplier' => $o['supplier'],
        'stockId'  => (int)$o['stock_id'],
        'dry'      => $dry ? 'true' : 'false',
    ];
    if ($changed !== null && $changed > 0) $qs['changedSinceHours'] = $changed;
    $url = add_query_arg($qs, $url);

    $args = [
        'method'  => 'POST',
        'timeout' => 20,
        'headers' => [
            'X-Auth-Token' => $o['api_token'],
            'Accept'       => 'application/json',
        ],
        'blocking' => true,
    ];

    $t0   = microtime(true);
    $resp = wp_remote_post($url, $args);
    $ms   = (int) round((microtime(true) - $t0) * 1000);

    if (is_wp_error($resp)) {
        lavka_log_write([
            'action'              => 'sync_stock',
            'supplier'            => $o['supplier'],
            'stock_id'            => (int)$o['stock_id'],
            'dry'                 => $dry,
            'changed_since_hours' => $changed,
            'updated'             => 0,
            'not_found'           => 0,
            'duration_ms'         => $ms,
            'status'              => 'ERROR',
            'message'             => $resp->get_error_message(),
        ]);
        wp_send_json_error(['error' => $resp->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $updated  = (int)($body['willUpdate'] ?? $body['updated'] ?? 0);
    $notFound = is_array($body['notFoundSkus'] ?? null) ? count($body['notFoundSkus']) : 0;

    lavka_log_write([
        'action'              => 'sync_stock',
        'supplier'            => $o['supplier'],
        'stock_id'            => (int)$o['stock_id'],
        'dry'                 => $dry,
        'changed_since_hours' => $changed,
        'updated'             => $updated,
        'not_found'           => $notFound,
        'duration_ms'         => $ms,
        'status'              => ($code >= 200 && $code < 300) ? 'OK' : 'ERROR',
        'message'             => 'HTTP ' . $code,
    ]);

    if ($code >= 200 && $code < 300) wp_send_json_success($body ?: ['ok' => true]);
    wp_send_json_error(['error' => 'Java status ' . $code, 'body' => $body]);
});

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