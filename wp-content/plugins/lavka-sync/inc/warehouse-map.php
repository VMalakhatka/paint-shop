<?php
if (!defined('ABSPATH')) exit;

/** ===== term meta helpers ===== */
function lavka_get_location_ext_codes(int $term_id): array {
    $codes = get_term_meta($term_id, 'lavka_ext_codes', true);
    if (is_string($codes)) {
        // исторически могли хранить CSV
        $codes = array_filter(array_map('trim', explode(',', $codes)));
    }
    return is_array($codes) ? array_values(array_unique(array_map('strval',$codes))) : [];
}
function lavka_set_location_ext_codes(int $term_id, array $codes): void {
    $norm = [];
    foreach ($codes as $c) {
        $c = trim((string)$c);
        if ($c !== '') $norm[$c] = true;
    }
    $arr = array_keys($norm);
    if ($arr) update_term_meta($term_id, 'lavka_ext_codes', $arr);
    else      delete_term_meta($term_id, 'lavka_ext_codes');
}

/** ===== admin page: Warehouses mapping ===== */
add_action('admin_menu', function () {
    add_submenu_page(
        'lavka-sync',
        'Warehouses mapping',
        'Warehouses',
        'manage_lavka_sync',
        'lavka-warehouses',
        'lavka_render_warehouses_page'
    );
});

/** Подтянуть справочник внешних складов из Java (если доступен).
 *  Ожидание: GET {java_base_url}/ref/warehouses -> [{code:"D01", name:"Київ—склад 1"}, ...]
 *  Можно переопределить endpoint фильтром 'lavka_ext_wh_endpoint'.
 */
function lavka_fetch_ext_warehouses(): array {
    $o = lavka_sync_get_options();
    $base = rtrim((string)($o['java_base_url'] ?? ''), '/');
    if (!$base) return [];

    $endpoint = apply_filters('lavka_ext_wh_endpoint', $base . '/ref/warehouses');

    $resp = wp_remote_get($endpoint, [
        'timeout' => 15,
        'headers' => array_filter([
            'Accept'       => 'application/json',
            // если в Java ожидается токен:
            'X-Auth-Token' => $o['api_token'] ?? '',
        ]),
    ]);
    if (is_wp_error($resp)) return [];
    $code = wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) return [];

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $out = [];
    foreach ((array)$data as $row) {
        $code = isset($row['code']) ? (string)$row['code'] : '';
        if ($code === '') continue;
        $out[] = [
            'code' => $code,
            'name' => isset($row['name']) ? (string)$row['name'] : $code,
        ];
    }
    // уникализируем по коду
    $uniq = [];
    foreach ($out as $r) $uniq[$r['code']] = $r;
    return array_values($uniq);
}

function lavka_render_warehouses_page() {
    if (!current_user_can('manage_lavka_sync')) wp_die('Недостаточно прав.');

    // save
    if (!empty($_POST['_lavka_wh_nonce']) && wp_verify_nonce($_POST['_lavka_wh_nonce'], 'lavka_wh_save')) {
        $codesByTerm = (array)($_POST['codes'] ?? []);
        foreach ($codesByTerm as $tid => $csv) {
            $tid = (int)$tid;
            if ($tid <= 0) continue;
            // поддержим и csv и массив (если из JS отправим массив)
            if (is_array($csv)) {
                $codes = $csv;
            } else {
                $codes = array_filter(array_map('trim', explode(',', (string)$csv)));
            }
            lavka_set_location_ext_codes($tid, $codes);
        }
        echo '<div class="notice notice-success"><p>Saved.</p></div>';
    }

    // Woo склады
    $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
    if (is_wp_error($terms)) $terms = [];

    // Внешние склады (может быть пусто — тогда вводим руками)
    $ext = lavka_fetch_ext_warehouses();
    ?>
    <div class="wrap">
      <h1>Warehouses mapping</h1>
      <p>Соответствие: <strong>Woo → MSSQL</strong>. Один Woo-склад агрегирует суммы из выбранных MSSQL складов.</p>

      <form method="post">
        <?php wp_nonce_field('lavka_wh_save', '_lavka_wh_nonce'); ?>

        <table class="widefat fixed striped">
          <thead>
            <tr>
              <th style="width:60px">ID</th>
              <th>Woo склад</th>
              <th>Привязанные MSSQL-склады (коды)</th>
              <th style="width:320px">Выбрать из справочника</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($terms): foreach ($terms as $t):
              $tid   = (int)$t->term_id;
              $codes = lavka_get_location_ext_codes($tid);
              $csv   = implode(', ', $codes);
          ?>
            <tr>
              <td><?php echo (int)$tid; ?></td>
              <td>
                <strong><?php echo esc_html($t->name); ?></strong><br>
                <code><?php echo esc_html($t->slug); ?></code>
              </td>
              <td>
                <input type="text" class="regular-text" name="codes[<?php echo $tid; ?>]" value="<?php echo esc_attr($csv); ?>" placeholder="напр. D01, D02, D05">
                <p class="description">CSV список кодов (без пробелов необязательно).</p>
              </td>
              <td>
                <?php if ($ext): ?>
                  <select multiple size="6" data-target="<?php echo $tid; ?>" class="lavka-ext-multi" style="width:100%">
                    <?php foreach ($ext as $row): ?>
                      <?php $sel = in_array($row['code'], $codes, true) ? 'selected' : ''; ?>
                      <option value="<?php echo esc_attr($row['code']); ?>" <?php echo $sel; ?>>
                        <?php echo esc_html($row['code'] . ' — ' . $row['name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <p><button type="button" class="button lavka-apply" data-target="<?php echo $tid; ?>">Применить коды</button></p>
                <?php else: ?>
                  <em>Справочник MSSQL сейчас недоступен. Введите коды вручную, либо проверьте Java endpoint <code>/ref/warehouses</code>.</em>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="4">Складов Woo (такса <code>location</code>) не найдено.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>

        <p style="margin-top:12px"><?php submit_button('Сохранить соответствия'); ?></p>
      </form>
    </div>

    <script>
    (function(){
      // копирует выбранные <option> в CSV-инпут рядом
      function apply(targetId){
        var sel = document.querySelector('select.lavka-ext-multi[data-target="'+targetId+'"]');
        var inp = document.querySelector('input[name="codes['+targetId+']"]');
        if(!sel || !inp) return;
        var arr = Array.from(sel.selectedOptions).map(o => o.value).filter(Boolean);
        inp.value = arr.join(', ');
      }
      document.querySelectorAll('button.lavka-apply').forEach(function(btn){
        btn.addEventListener('click', function(){
          apply(btn.getAttribute('data-target'));
        });
      });
    }());
    </script>
    <?php
}

/** ===== REST: GET /lavka/v1/locations/map =====
 *  Отдаём Java полный маппинг: Woo term + связанные внешние коды.
 */
add_action('rest_api_init', function(){
    $ns = 'lavka/v1';
    register_rest_route($ns, '/locations/map', [
        'methods'  => 'GET',
        'permission_callback' => 'lavka_rest_auth',
        'callback' => function(){
            $terms = get_terms(['taxonomy'=>'location','hide_empty'=>false]);
            if (is_wp_error($terms)) {
                return new WP_REST_Response(['ok'=>false,'error'=>$terms->get_error_message()], 500);
            }
            $items = [];
            foreach ($terms as $t) {
                $items[] = [
                    'id'    => (int)$t->term_id,
                    'slug'  => (string)$t->slug,
                    'name'  => (string)$t->name,
                    'codes' => lavka_get_location_ext_codes((int)$t->term_id), // MSSQL codes array
                ];
            }
            return new WP_REST_Response([
                'ok'    => true,
                'items' => $items,
            ], 200);
        },
    ]);
});