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

function lavka_get_location_folio_warehouses(int $term_id): array {
    $rows = get_term_meta($term_id, 'lavka_folio_warehouses', true);
    if (is_string($rows)) {
        $rows = lavka_parse_folio_warehouse_pairs($rows);
    }
    if (!is_array($rows)) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $priority = isset($row['priority']) ? (int)$row['priority'] : 100;
        $out[$id] = [
            'id'       => $id,
            'priority' => max(0, $priority),
        ];
    }

    uasort($out, function($a, $b) {
        return ((int)$a['priority'] <=> (int)$b['priority']) ?: strcmp((string)$a['id'], (string)$b['id']);
    });

    return array_values($out);
}

function lavka_parse_folio_warehouse_pairs(string $value): array {
    $out = [];
    $parts = preg_split('/[,;\r\n]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    foreach ((array)$parts as $idx => $part) {
        $part = trim((string)$part);
        if ($part === '') {
            continue;
        }
        $bits = array_map('trim', explode(':', $part, 2));
        $id = (string)($bits[0] ?? '');
        if ($id === '') {
            continue;
        }
        $priority = isset($bits[1]) && $bits[1] !== '' ? (int)$bits[1] : (($idx + 1) * 10);
        $out[] = [
            'id'       => $id,
            'priority' => max(0, $priority),
        ];
    }
    return $out;
}

function lavka_format_folio_warehouse_pairs(array $warehouses): string {
    $parts = [];
    foreach (lavka_get_normalized_folio_warehouses($warehouses) as $row) {
        $parts[] = $row['id'] . ':' . (int)$row['priority'];
    }
    return implode(', ', $parts);
}

function lavka_get_normalized_folio_warehouses(array $warehouses): array {
    $out = [];
    foreach ($warehouses as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $out[$id] = [
            'id'       => $id,
            'priority' => max(0, (int)($row['priority'] ?? 100)),
        ];
    }
    uasort($out, function($a, $b) {
        return ((int)$a['priority'] <=> (int)$b['priority']) ?: strcmp((string)$a['id'], (string)$b['id']);
    });
    return array_values($out);
}

function lavka_set_location_folio_warehouses(int $term_id, array $warehouses): void {
    $rows = lavka_get_normalized_folio_warehouses($warehouses);
    if ($rows) update_term_meta($term_id, 'lavka_folio_warehouses', $rows);
    else       delete_term_meta($term_id, 'lavka_folio_warehouses');
}

const LAVKA_PUBLIC_WAREHOUSE_LABELS_OPTION = 'lavka_public_warehouse_labels';

function lavka_get_public_warehouse_labels(): array {
    $labels = get_option(LAVKA_PUBLIC_WAREHOUSE_LABELS_OPTION, []);
    if (!is_array($labels)) return [];

    $out = [];
    foreach ($labels as $id => $label) {
        $id = trim((string)$id);
        $label = trim((string)$label);
        if ($id !== '' && $label !== '') $out[$id] = $label;
    }
    return $out;
}

add_filter('pc_folio_warehouse_labels', function (array $labels): array {
    foreach (lavka_get_public_warehouse_labels() as $id => $label) {
        $labels[(string)$id] = $label;
    }
    return $labels;
});

/**
 * Returns the global warehouse groups used by stock display and analytics.
 * Stable relations are based on taxonomy term IDs/slugs and Folio IDs, never
 * on the editable customer-facing name.
 */
function lavka_get_global_warehouse_groups(): array {
    $taxonomy = apply_filters('lavka_location_taxonomy', 'location');
    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
    if (is_wp_error($terms)) return [];

    $groups = [];
    foreach ($terms as $term) {
        $warehouses = lavka_get_location_folio_warehouses((int)$term->term_id);
        $warehouse_ids = array_values(array_map(
            static fn(array $row): int => (int)$row['id'],
            array_filter($warehouses, static fn(array $row): bool => (int)$row['id'] > 0)
        ));
        if (!$warehouse_ids) continue;

        $groups[] = [
            'termId' => (int)$term->term_id,
            'code' => (string)$term->slug,
            'name' => (string)$term->name,
            'warehouseIds' => $warehouse_ids,
            'availabilityMode' => 'ANY_ELIGIBLE_MEMBER',
        ];
    }
    return $groups;
}

function lavka_get_global_warehouse_groups_revision(): string {
    return hash('sha256', wp_json_encode(lavka_get_global_warehouse_groups()));
}

/** ===== admin page: Warehouses mapping ===== */
add_action('admin_menu', function () {
    add_submenu_page(
        function_exists('paint_core_lavka_admin_parent_slug') ? paint_core_lavka_admin_parent_slug() : 'lavka-sync',
        __('Lavka settings', 'lavka-sync'),
        __('Lavka settings', 'lavka-sync'),
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
        'timeout' => 160,
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
        $tax = apply_filters('lavka_location_taxonomy', 'location');
        $names_by_term = (array)wp_unslash($_POST['location_names'] ?? []);
        foreach ($names_by_term as $tid => $name) {
            $tid = (int)$tid;
            $name = trim(sanitize_text_field($name));
            $term = $tid > 0 ? get_term($tid, $tax) : null;
            if ($name === '' || !$term || is_wp_error($term)) continue;

            wp_update_term($tid, $tax, [
                'name' => $name,
                'slug' => (string)$term->slug,
            ]);
        }

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

        $folioByTerm = (array)($_POST['folio_warehouses'] ?? []);
        foreach ($folioByTerm as $tid => $val) {
            $tid = (int)$tid;
            if ($tid <= 0) continue;

            $warehouses = is_array($val)
                ? $val
                : lavka_parse_folio_warehouse_pairs((string)$val);

            lavka_set_location_folio_warehouses($tid, $warehouses);
        }

        $public_labels = [];
        foreach ((array)wp_unslash($_POST['warehouse_labels'] ?? []) as $warehouse_id => $label) {
            $warehouse_id = trim(sanitize_text_field($warehouse_id));
            $label = trim(sanitize_text_field($label));
            if ($warehouse_id !== '' && $label !== '') $public_labels[$warehouse_id] = $label;
        }
        update_option(LAVKA_PUBLIC_WAREHOUSE_LABELS_OPTION, $public_labels, false);

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
      <h1><?php echo esc_html(__('Lavka settings', 'lavka-sync')); ?></h1>
      <p>
        <?php echo wp_kses_post(
          __('Configure global warehouse groups and customer-facing names. Analytics scenarios reference these groups and do not copy their warehouse membership.', 'lavka-sync')
        ); ?>
      </p>

      <div class="notice notice-info inline"><p>
        <?php echo esc_html__('Relations use stable term IDs, system slugs and Folio warehouse IDs. Changing a public name does not change stock, order or analytics references.', 'lavka-sync'); ?>
      </p></div>

      <form method="post" action="">
        <?php wp_nonce_field('lavka_wh_save', '_lavka_wh_nonce'); ?>

        <table class="widefat fixed striped">
          <thead>
            <tr>
              <th style="width:70px"><?php echo esc_html(__('ID', 'lavka-sync')); ?></th>
              <th><?php echo esc_html(__('Customer-facing group name', 'lavka-sync')); ?></th>
              <th><?php echo esc_html(__('Linked MSSQL warehouses (codes)', 'lavka-sync')); ?></th>
              <th><?php echo esc_html(__('Folio warehouses (id:priority)', 'lavka-sync')); ?></th>
              <th style="width:340px"><?php echo esc_html(__('Pick from directory', 'lavka-sync')); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if ($terms): foreach ($terms as $t):
              $tid   = (int)$t->term_id;
              $codes = lavka_get_location_ext_codes($tid);
              $csv   = implode(', ', $codes);
              $folio_warehouses = lavka_get_location_folio_warehouses($tid);
              $folio_csv = lavka_format_folio_warehouse_pairs($folio_warehouses);
              $input_id = 'lavka-codes-' . $tid;
              $folio_input_id = 'lavka-folio-warehouses-' . $tid;
              $help_id  = 'lavka-help-' . $tid;
              $folio_help_id = 'lavka-folio-help-' . $tid;
          ?>
            <tr>
              <td><?php echo (int)$tid; ?></td>
              <td>
                <label class="screen-reader-text" for="lavka-location-name-<?php echo (int)$tid; ?>">
                  <?php echo esc_html__('Customer-facing group name', 'lavka-sync'); ?>
                </label>
                <input
                  type="text"
                  id="lavka-location-name-<?php echo (int)$tid; ?>"
                  class="regular-text"
                  name="location_names[<?php echo (int)$tid; ?>]"
                  value="<?php echo esc_attr($t->name); ?>"
                ><br>
                <small><?php echo esc_html__('System slug:', 'lavka-sync'); ?> <code><?php echo esc_html($t->slug); ?></code></small>
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
                <label class="screen-reader-text" for="<?php echo esc_attr($folio_input_id); ?>">
                  <?php echo esc_html(__('Folio warehouses (id:priority)', 'lavka-sync')); ?>
                </label>
                <input
                  type="text"
                  id="<?php echo esc_attr($folio_input_id); ?>"
                  class="regular-text"
                  name="folio_warehouses[<?php echo (int)$tid; ?>]"
                  value="<?php echo esc_attr($folio_csv); ?>"
                  placeholder="<?php echo esc_attr(__('e.g. 7:10, 8:20', 'lavka-sync')); ?>"
                  aria-describedby="<?php echo esc_attr($folio_help_id); ?>"
                >
                <p id="<?php echo esc_attr($folio_help_id); ?>" class="description">
                  <?php echo esc_html(__('Lower priority number is used first. Used for Folio account split.', 'lavka-sync')); ?>
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
                    <button type="button" class="button lavka-apply-folio" data-target="<?php echo (int)$tid; ?>" style="margin-left:6px">
                      <?php echo esc_html(__('Apply Folio', 'lavka-sync')); ?>
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
              <td colspan="5">
                <?php
                /* translators: %s: taxonomy name */
                echo esc_html(sprintf(__('No Woo locations found (taxonomy “%s”).', 'lavka-sync'), $tax));
                ?>
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>

        <?php
        $public_labels = lavka_get_public_warehouse_labels();
        $warehouse_options = [];
        foreach ($ext as $row) {
            $warehouse_options[(string)$row['code']] = (string)$row['name'];
        }
        foreach ($terms as $term) {
            foreach (lavka_get_location_folio_warehouses((int)$term->term_id) as $warehouse) {
                $id = (string)$warehouse['id'];
                if (!isset($warehouse_options[$id])) $warehouse_options[$id] = $id;
            }
        }
        foreach ($public_labels as $id => $label) {
            if (!isset($warehouse_options[$id])) $warehouse_options[$id] = $label;
        }
        uksort($warehouse_options, 'strnatcasecmp');
        ?>

        <h2><?php echo esc_html__('Public Folio warehouse names', 'lavka-sync'); ?></h2>
        <p><?php echo esc_html__('These names are shown in orders, reports and customer notices. Folio warehouse IDs remain unchanged.', 'lavka-sync'); ?></p>
        <table class="widefat striped" style="max-width:960px">
          <thead><tr>
            <th style="width:120px"><?php echo esc_html__('Folio warehouse ID', 'lavka-sync'); ?></th>
            <th><?php echo esc_html__('Directory name', 'lavka-sync'); ?></th>
            <th><?php echo esc_html__('Customer-facing name', 'lavka-sync'); ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($warehouse_options as $warehouse_id => $directory_name): ?>
            <tr>
              <td><code><?php echo esc_html($warehouse_id); ?></code></td>
              <td><?php echo esc_html($directory_name); ?></td>
              <td><input type="text" class="regular-text" name="warehouse_labels[<?php echo esc_attr($warehouse_id); ?>]" value="<?php echo esc_attr($public_labels[$warehouse_id] ?? $directory_name); ?>"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <p style="margin-top:12px">
          <?php submit_button(__('Save Lavka settings', 'lavka-sync'), 'primary', 'submit', false); ?>
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
      function applyFolioWarehouses(targetId){
        var sel = bySel('select.lavka-ext-multi[data-target="'+targetId+'"]');
        var inp = bySel('input[name="folio_warehouses['+targetId+']"]');
        if(!sel || !inp) return;
        var existing = {};
        inp.value.split(/[,;\r\n]+/).forEach(function(part){
          var bits = part.trim().split(':');
          var id = (bits[0] || '').trim();
          var priority = parseInt((bits[1] || '').trim(), 10);
          if(id && !isNaN(priority)) existing[id] = Math.max(0, priority);
        });
        var used = Object.keys(existing).map(function(id){ return existing[id]; });
        var nextPriority = used.length ? (Math.max.apply(Math, used) + 10) : 10;
        var arr = Array.from(sel.selectedOptions).map(function(o){
          var id = o.value;
          var priority = Object.prototype.hasOwnProperty.call(existing, id) ? existing[id] : nextPriority;
          if (!Object.prototype.hasOwnProperty.call(existing, id)) nextPriority += 10;
          return id + ':' + priority;
        }).filter(Boolean);
        inp.value = arr.join(', ');
      }
      function clearCodes(targetId){
        var sel = bySel('select.lavka-ext-multi[data-target="'+targetId+'"]');
        var inp = bySel('input[name="codes['+targetId+']"]');
        var folioInp = bySel('input[name="folio_warehouses['+targetId+']"]');
        if(sel) sel.selectedIndex = -1;
        if(inp) inp.value = '';
        if(folioInp) folioInp.value = '';
      }
      document.querySelectorAll('button.lavka-apply').forEach(function(btn){
        btn.addEventListener('click', function(){
          applyCodes(btn.getAttribute('data-target'));
        });
      });
      document.querySelectorAll('button.lavka-apply-folio').forEach(function(btn){
        btn.addEventListener('click', function(){
          applyFolioWarehouses(btn.getAttribute('data-target'));
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
