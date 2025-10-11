<?php
// inc/warehouse-map.php
if (!defined('ABSPATH')) exit;

/** ===== term meta helpers ===== */
function lavka_get_location_ext_codes(int $term_id): array {
    $codes = get_term_meta($term_id, 'lavka_ext_codes', true);
    if (is_string($codes)) {
        $codes = array_filter(array_map('trim', explode(',', $codes)));
    }
    return is_array($codes) ? array_values(array_unique(array_map('strval', $codes))) : [];
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
        __('Warehouses mapping', 'lavka-sync'),
        __('Warehouses', 'lavka-sync'),
        'manage_lavka_sync',
        'lavka-warehouses',
        'lavka_render_warehouses_page'
    );
});

/** Подтянутый справочник внешних складов из Java */
function lavka_fetch_ext_warehouses(): array {
    $o    = lavka_sync_get_options();
    $base = rtrim((string)($o['java_base_url'] ?? ''), '/');
    if (!$base) return [];

    $endpoint = apply_filters('lavka_ext_wh_endpoint', $base . '/ref/warehouses');

    $resp = wp_remote_get($endpoint, [
        'timeout' => 60,
        'headers' => array_filter([
            'Accept'       => 'application/json',
            // если на Java включён токен:
            'X-Auth-Token' => $o['api_token'] ?? '',
        ]),
    ]);
    if (is_wp_error($resp)) return [];
    if ((int)wp_remote_retrieve_response_code($resp) < 200) return [];

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    $out  = [];
    foreach ((array)$data as $row) {
        $code = isset($row['code']) ? (string)$row['code'] : '';
        if ($code === '') continue;
        $out[$code] = [
            'code' => $code,
            'name' => isset($row['name']) ? (string)$row['name'] : $code,
        ];
    }
    return array_values($out);
}

/** ====== Рендер страницы мэппинга ====== */
function lavka_render_warehouses_page() {
    if (!current_user_can('manage_lavka_sync')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'lavka-sync'));
    }

    // save
    if (!empty($_POST['_lavka_wh_nonce']) && wp_verify_nonce($_POST['_lavka_wh_nonce'], 'lavka_wh_save')) {
        $codesByTerm = (array)($_POST['codes'] ?? []);
        foreach ($codesByTerm as $tid => $val) {
            $tid = (int)$tid;
            if ($tid <= 0) continue;

            // поддерживаем и CSV, и массив из JS
            $codes = is_array($val)
                ? array_map('strval', $val)
                : array_filter(array_map('trim', explode(',', (string)$val)));

            lavka_set_location_ext_codes($tid, $codes);
        }
        echo '<div class="notice notice-success is-dismissible"><p>' .
             esc_html__('Saved.', 'lavka-sync') .
             '</p></div>';
    }

    // Woo-склады (таксономия можно переопределить фильтром)
    $tax   = apply_filters('lavka_location_taxonomy', 'location');
    $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
    if (is_wp_error($terms)) $terms = [];

    // справочник MSSQL
    $ext = lavka_fetch_ext_warehouses();
    ?>

    <div class="wrap">
      <h1><?php echo esc_html(__('Warehouses mapping', 'lavka-sync')); ?></h1>
      <p>
        <?php echo wp_kses_post(
          __('Match: <strong>Woo → MSSQL</strong>. One Woo location aggregates sums from selected MSSQL warehouses.', 'lavka-sync')
        ); ?>
      </p>

      <form method="post" action="">
        <?php wp_nonce_field('lavka_wh_save', '_lavka_wh_nonce'); ?>

        <table class="widefat fixed striped">
          <thead>
            <tr>
              <th style="width:70px"><?php echo esc_html(__('ID', 'lavka-sync')); ?></th>
              <th><?php echo esc_html(__('Woo location', 'lavka-sync')); ?></th>
              <th><?php echo esc_html(__('Linked MSSQL warehouses (codes)', 'lavka-sync')); ?></th>
              <th style="width:340px"><?php echo esc_html(__('Pick from directory', 'lavka-sync')); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if ($terms): foreach ($terms as $t):
              $tid   = (int)$t->term_id;
              $codes = lavka_get_location_ext_codes($tid);
              $csv   = implode(', ', $codes);
              $input_id = 'lavka-codes-' . $tid;
              $help_id  = 'lavka-help-' . $tid;
          ?>
            <tr>
              <td><?php echo (int)$tid; ?></td>
              <td>
                <strong><?php echo esc_html($t->name); ?></strong><br>
                <code><?php echo esc_html($t->slug); ?></code>
              </td>

              <td>
                <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>">
                  <?php echo esc_html(__('Linked MSSQL warehouses (codes)', 'lavka-sync')); ?>
                </label>
                <input
                  type="text"
                  id="<?php echo esc_attr($input_id); ?>"
                  class="regular-text"
                  name="codes[<?php echo (int)$tid; ?>]"
                  value="<?php echo esc_attr($csv); ?>"
                  placeholder="<?php echo esc_attr(__('e.g. D01, D02, D05', 'lavka-sync')); ?>"
                  aria-describedby="<?php echo esc_attr($help_id); ?>"
                >
                <p id="<?php echo esc_attr($help_id); ?>" class="description">
                  <?php echo esc_html(__('CSV list of codes (spaces optional).', 'lavka-sync')); ?>
                </p>
              </td>

              <td>
                <?php if ($ext): ?>
                  <select multiple size="7" data-target="<?php echo (int)$tid; ?>" class="lavka-ext-multi" style="width:100%">
                    <?php foreach ($ext as $row): ?>
                      <?php $sel = in_array($row['code'], $codes, true) ? 'selected' : ''; ?>
                      <option value="<?php echo esc_attr($row['code']); ?>" <?php echo $sel; ?>>
                        <?php
                          /* translators: 1: external warehouse code, 2: name */
                          echo esc_html(sprintf(__('%1$s — %2$s', 'lavka-sync'), $row['code'], $row['name']));
                        ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <p>
                    <button type="button" class="button lavka-apply" data-target="<?php echo (int)$tid; ?>">
                      <?php echo esc_html(__('Apply codes', 'lavka-sync')); ?>
                    </button>
                    <button type="button" class="button-link lavka-clear" data-target="<?php echo (int)$tid; ?>" style="margin-left:8px">
                      <?php echo esc_html(__('Clear', 'lavka-sync')); ?>
                    </button>
                  </p>
                <?php else: ?>
                  <em>
                    <?php echo wp_kses_post(
                      __('MSSQL directory is not available. Enter codes manually or check Java endpoint <code>/ref/warehouses</code>.', 'lavka-sync')
                    ); ?>
                  </em>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr>
              <td colspan="4">
                <?php
                /* translators: %s: taxonomy name */
                echo esc_html(sprintf(__('No Woo locations found (taxonomy “%s”).', 'lavka-sync'), $tax));
                ?>
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>

        <p style="margin-top:12px">
          <?php submit_button(__('Save mappings', 'lavka-sync'), 'primary', 'submit', false); ?>
        </p>
      </form>
    </div>

    <script>
    (function(){
      function bySel(q){ return document.querySelector(q); }
      function applyCodes(targetId){
        var sel = bySel('select.lavka-ext-multi[data-target="'+targetId+'"]');
        var inp = bySel('input[name="codes['+targetId+']"]');
        if(!sel || !inp) return;
        var arr = Array.from(sel.selectedOptions).map(o => o.value).filter(Boolean);
        inp.value = arr.join(', ');
      }
      function clearCodes(targetId){
        var sel = bySel('select.lavka-ext-multi[data-target="'+targetId+'"]');
        var inp = bySel('input[name="codes['+targetId+']"]');
        if(sel) sel.selectedIndex = -1;
        if(inp) inp.value = '';
      }
      document.querySelectorAll('button.lavka-apply').forEach(function(btn){
        btn.addEventListener('click', function(){
          applyCodes(btn.getAttribute('data-target'));
        });
      });
      document.querySelectorAll('button.lavka-clear').forEach(function(btn){
        btn.addEventListener('click', function(){
          clearCodes(btn.getAttribute('data-target'));
        });
      });
    }());
    </script>

    <?php
}

/** ===== REST: GET /lavka/v1/locations/map ===== */
add_action('rest_api_init', function(){
    $ns = 'lavka/v1';
    register_rest_route($ns, '/locations/map', [
        'methods'  => 'GET',
        'permission_callback' => 'lavka_rest_auth',
        'callback' => function(){
            $tax   = apply_filters('lavka_location_taxonomy', 'location');
            $terms = get_terms(['taxonomy'=>$tax,'hide_empty'=>false]);
            if (is_wp_error($terms)) {
                return new WP_REST_Response(['ok'=>false,'error'=>$terms->get_error_message()], 500);
            }
            $items = [];
            foreach ($terms as $t) {
                $items[] = [
                    'id'    => (int)$t->term_id,
                    'slug'  => (string)$t->slug,
                    'name'  => (string)$t->name,
                    'codes' => lavka_get_location_ext_codes((int)$t->term_id),
                ];
            }
            return new WP_REST_Response(['ok'=>true,'items'=>$items], 200);
        },
    ]);
});