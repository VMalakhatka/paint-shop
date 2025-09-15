<?php
/**
 * Plugin Name: Lavka Sync
 * Description: Кнопки и настройки синхронизации с Java-сервисом.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

const LAVKA_SYNC_OPTION = 'lavka_sync_options';

add_action('admin_menu', function () {
    add_menu_page(
        'Lavka Sync',
        'Lavka Sync',
        'manage_options',
        'lavka-sync',
        'lavka_sync_render_page',
        'dashicons-update',
        58
    );
});

add_action('admin_init', function () {
    register_setting('lavka_sync', LAVKA_SYNC_OPTION);
});

function lavka_sync_get_options() {
    $defaults = [
        'java_base_url' => 'http://127.0.0.1:8083', // или https://api.kreul.com.ua (reverse proxy)
        'api_token'     => '',                      // общий секрет для Java
        'supplier'      => 'KREUL',
        'stock_id'      => '7',
        'schedule'      => 'off',                   // off|hourly|twicedaily|daily
    ];
    return wp_parse_args(get_option(LAVKA_SYNC_OPTION, []), $defaults);
}

function lavka_sync_render_page() {
    if (!current_user_can('manage_options')) return;

    $opts  = lavka_sync_get_options();
    $nonce = wp_create_nonce('lavka_sync_nonce');
    ?>
    <div class="wrap">
      <h1>Lavka Sync</h1>

      <form method="post" action="options.php">
        <?php settings_fields('lavka_sync'); ?>
        <?php $o = lavka_sync_get_options(); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="java_base_url">Java Base URL</label></th>
            <td><input name="<?php echo LAVKA_SYNC_OPTION; ?>[java_base_url]" id="java_base_url" type="url" value="<?php echo esc_attr($o['java_base_url']); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="api_token">API Token (для Java)</label></th>
            <td><input name="<?php echo LAVKA_SYNC_OPTION; ?>[api_token]" id="api_token" type="text" value="<?php echo esc_attr($o['api_token']); ?>" class="regular-text" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="supplier">Supplier</label></th>
            <td><input name="<?php echo LAVKA_SYNC_OPTION; ?>[supplier]" id="supplier" type="text" value="<?php echo esc_attr($o['supplier']); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="stock_id">Stock ID</label></th>
            <td><input name="<?php echo LAVKA_SYNC_OPTION; ?>[stock_id]" id="stock_id" type="number" value="<?php echo esc_attr($o['stock_id']); ?>" /></td>
          </tr>
          <tr>
            <th scope="row"><label for="schedule">Автосинк (WP-Cron)</label></th>
            <td>
              <select name="<?php echo LAVKA_SYNC_OPTION; ?>[schedule]" id="schedule">
                <option value="off"        <?php selected($o['schedule'], 'off'); ?>>Выключен</option>
                <option value="hourly"     <?php selected($o['schedule'], 'hourly'); ?>>Каждый час</option>
                <option value="twicedaily" <?php selected($o['schedule'], 'twicedaily'); ?>>Дважды в день</option>
                <option value="daily"      <?php selected($o['schedule'], 'daily'); ?>>Раз в день</option>
              </select>
            </td>
          </tr>
        </table>
        <?php submit_button('Сохранить настройки'); ?>
      </form>

      <hr />

      <h2>Ручной запуск</h2>
      <p>
        <button id="lavka-sync-dry" class="button">Dry-run</button>
        <button id="lavka-sync-run" class="button button-primary">Синхронизировать</button>
        <span id="lavka-sync-result" style="margin-left:10px;"></span>
      </p>
    </div>

    <script>
      (function() {
        const ajaxUrl = "<?php echo admin_url('admin-ajax.php'); ?>";
        const nonce   = "<?php echo esc_js($nonce); ?>";

        async function call(action, dry) {
          const resEl = document.getElementById('lavka-sync-result');
          resEl.textContent = 'Выполняю...';
          try {
            const form = new FormData();
            form.append('action', action);
            form.append('_wpnonce', nonce);
            form.append('dry', dry ? '1' : '0');
            const resp = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form });
            const json = await resp.json();
            resEl.textContent = (json && json.ok) ? 'OK' : ('Ошибка: ' + (json && json.error ? json.error : 'unknown'));
            console.log(json);
          } catch(e) {
            resEl.textContent = 'Ошибка сети';
            console.error(e);
          }
        }

        document.getElementById('lavka-sync-dry').addEventListener('click', () => call('lavka_sync_run', true));
        document.getElementById('lavka-sync-run').addEventListener('click', () => call('lavka_sync_run', false));
      })();
    </script>
    <?php
}

// AJAX handler: дернуть Java /sync/stock
add_action('wp_ajax_lavka_sync_run', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['error' => 'forbidden'], 403);
    check_ajax_referer('lavka_sync_nonce');

    $o = lavka_sync_get_options();
    $dry = !empty($_POST['dry']);

    $url = trailingslashit($o['java_base_url']) . 'sync/stock';
    $qs  = [
        'supplier' => $o['supplier'],
        'stockId'  => (int)$o['stock_id'],
        'dry'      => $dry ? 'true' : 'false',
    ];
    $url = add_query_arg($qs, $url);

    $args = [
        'method'      => 'POST',
        'timeout'     => 20,
        'headers'     => [
            'X-Auth-Token' => $o['api_token'], // см. проверку в Java
            'Accept'       => 'application/json',
        ],
        'blocking'    => true,
    ];

    $resp = wp_remote_post($url, $args);
    if (is_wp_error($resp)) {
        wp_send_json_error(['error' => $resp->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);

    if ($code >= 200 && $code < 300) {
        wp_send_json_success($body ?: ['ok' => true]);
    }
    wp_send_json_error(['error' => 'Java status ' . $code, 'body' => $body]);
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
        'Lavka Reports',
        'Lavka Reports',
        'view_lavka_reports',        // доступ только с этим правом
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
    if (!current_user_can('view_lavka_reports')) wp_die('Недостаточно прав');
    ?>
    <div class="wrap">
      <h1>Lavka Reports</h1>

      <form id="filters" style="margin: 12px 0">
        <label>Поставщик: <input type="text" name="supplier" value="KREUL"></label>
        <label style="margin-left:10px;">Склад: <input type="number" name="stockId" value="7"></label>
        <button class="button">Обновить</button>
      </form>

      <canvas id="salesChart" height="100"></canvas>

      <h2 style="margin-top:16px;">Данные</h2>
      <table class="widefat fixed striped" id="reportTable">
        <thead><tr><th>SKU</th><th>Название</th><th>Остаток</th><th>Цена</th></tr></thead>
        <tbody><tr><td colspan="4">Загрузка...</td></tr></tbody>
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
    $opts = get_option('lavka_sync_options', []);
    $java = rtrim($opts['java_base_url'] ?? 'http://127.0.0.1:8083', '/');

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
    // создать роль на базе shop_manager (если нужна отдельная)
    $base = get_role('shop_manager');
    if ($base && !get_role('lavka_manager')) {
        add_role('lavka_manager', 'Lavka Manager', $base->capabilities);
    }
    // кастомные capability
    $caps = ['manage_lavka_sync', 'view_lavka_reports'];
    foreach (['shop_manager','lavka_manager','administrator'] as $role) {
        if ($r = get_role($role)) {
            foreach ($caps as $c) { $r->add_cap($c); }
        }
    }
});

register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'lavka_sync_logs';
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("
        CREATE TABLE {$table} (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          ts DATETIME NOT NULL,
          action VARCHAR(50) NOT NULL,          -- dry-run | sync
          supplier VARCHAR(100) NOT NULL,
          stock_id INT NOT NULL,
          since_hours INT NULL,                 -- null или число
          updated_count INT DEFAULT 0,          -- сколько реально обновили
          preview_count INT DEFAULT 0,          -- сколько было в превью
          errors LONGTEXT NULL,                 -- json
          user_id BIGINT UNSIGNED NULL,         -- кто запустил
          PRIMARY KEY (id),
          KEY ts (ts),
          KEY action (action)
        ) {$charset};
    ");
});

function lavka_sync_log(array $row) {
    global $wpdb;
    $table = $wpdb->prefix . 'lavka_sync_logs';
    $wpdb->insert($table, [
        'ts'            => current_time('mysql'),
        'action'        => $row['action'] ?? '',
        'supplier'      => $row['supplier'] ?? '',
        'stock_id'      => (int)($row['stock_id'] ?? 0),
        'since_hours'   => isset($row['since_hours']) ? (int)$row['since_hours'] : null,
        'updated_count' => (int)($row['updated_count'] ?? 0),
        'preview_count' => (int)($row['preview_count'] ?? 0),
        'errors'        => !empty($row['errors']) ? wp_json_encode($row['errors'], JSON_UNESCAPED_UNICODE) : null,
        'user_id'       => get_current_user_id() ?: null,
    ], [
        '%s','%s','%s','%d','%d','%d','%d','%s','%d'
    ]);
    return $wpdb->insert_id ?: 0;
}

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
    // ... у тебя тут уже есть проверка прав/nonce и запрос к Java
    // добавь сбор метрик перед wp_remote_post
    $t0 = microtime(true);

    // читаем changedSinceHours из POST (добавим кнопку ниже)
    $changed = isset($_POST['changed_since_hours']) ? (int)$_POST['changed_since_hours'] : null;

    // формируем URL к Java
    $url = trailingslashit($o['java_base_url']) . 'sync/stock';
    $qs  = [
        'supplier' => $o['supplier'],
        'stockId'  => (int)$o['stock_id'],
        'dry'      => $dry ? 'true' : 'false',
    ];
    if ($changed !== null && $changed > 0) {
        $qs['changedSinceHours'] = $changed;
    }
    $url = add_query_arg($qs, $url);

    $resp = wp_remote_post($url, $args);
    $duration = (int) round((microtime(true) - $t0) * 1000);
    $code = is_wp_error($resp) ? 0 : wp_remote_retrieve_response_code($resp);
    $bodyArr = is_wp_error($resp) ? null : json_decode(wp_remote_retrieve_body($resp), true);

    // считаем updated / not_found из ответа Java, если есть
    $updated   = (int)($bodyArr['willUpdate'] ?? $bodyArr['updated'] ?? 0);
    $notFound  = is_array($bodyArr['notFoundSkus'] ?? null) ? count($bodyArr['notFoundSkus']) : 0;

    lavka_log_write([
        'action'              => 'sync_stock',
        'supplier'            => $o['supplier'],
        'stock_id'            => (int)$o['stock_id'],
        'dry'                 => $dry,
        'changed_since_hours' => $changed,
        'updated'             => $updated,
        'not_found'           => $notFound,
        'duration_ms'         => $duration,
        'status'              => ($code >= 200 && $code < 300) ? 'OK' : 'ERROR',
        'message'             => is_wp_error($resp) ? $resp->get_error_message() : ('HTTP ' . $code),
    ]);

    // дальше как у тебя: wp_send_json_success / error
});

// 1) Пункт меню "Logs" под вашим родительским "lavka-sync"
add_action('admin_menu', function () {
    add_submenu_page(
        'lavka-sync',                // slug родительской страницы (вашей)
        'Lavka Logs',                // Title
        'Logs',                      // Label в меню
        'manage_lavka_sync',         // capability
        'lavka-logs',                // slug страницы логов
        'lavka_logs_render_page'     // callback рендера
    );
});


// 2) Рендер страницы логов с пагинацией
function lavka_logs_render_page() {
    if (!current_user_can('manage_lavka_sync')) wp_die('Недостаточно прав');
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
      <h1>Lavka Logs</h1>

      <p>
        <a class="button" href="<?php echo esc_url($csv_url); ?>">Экспорт CSV</a>
      </p>

      <table class="widefat fixed striped">
        <thead>
          <tr>
            <th>ID</th><th>Время (UTC)</th><th>Action</th><th>Supplier</th><th>Stock</th>
            <th>Dry</th><th>Changed, ч</th><th>Updated</th><th>Not found</th>
            <th>ms</th><th>Status</th><th>Message</th>
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
            <td><?php echo $r['dry'] ? 'yes' : 'no'; ?></td>
            <td><?php echo $r['changed_since_hours'] !== null ? (int)$r['changed_since_hours'] : ''; ?></td>
            <td><?php echo (int)$r['updated']; ?></td>
            <td><?php echo (int)$r['not_found']; ?></td>
            <td><?php echo (int)$r['duration_ms']; ?></td>
            <td><?php echo esc_html($r['status']); ?></td>
            <td><?php echo esc_html($r['message']); ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="12">Логов пока нет.</td></tr>
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
    if (!current_user_can('manage_lavka_sync')) wp_die('Недостаточно прав');
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