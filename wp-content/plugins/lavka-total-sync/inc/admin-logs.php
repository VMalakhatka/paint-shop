<?php
// [LTS] ANCHOR: guard
if (!defined('ABSPATH')) exit;

if (!defined('LTS_CAP')) define('LTS_CAP', 'manage_lavka_sync');

function lts_logs_decode_context($raw) {
    if (!is_string($raw) || $raw === '') return null;

    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
}

function lts_logs_render_context($action, $raw) {
    $context = lts_logs_decode_context($raw);
    if ($context === null || $context === '' || $context === []) {
        echo '&mdash;';
        return;
    }

    if ($action === 'cron_full_sync' && is_array($context)) {
        $payload = isset($context['payload']) && is_array($context['payload']) ? $context['payload'] : [];
        $result  = isset($context['result']) && is_array($context['result']) ? $context['result'] : [];

        if ($result) {
            $parts = [
                sprintf('%s: %d', esc_html__('Processed', 'lavka-total-sync'), (int)($result['processed'] ?? 0)),
                sprintf('%s: %d', esc_html__('Created', 'lavka-total-sync'), (int)($result['created'] ?? 0)),
                sprintf('%s: %d', esc_html__('Updated', 'lavka-total-sync'), (int)($result['updated'] ?? 0)),
                sprintf('%s: %d', esc_html__('Drafted', 'lavka-total-sync'), (int)($result['drafted'] ?? 0)),
            ];
            echo '<strong>'.esc_html(implode(' | ', $parts)).'</strong><br>';

            $next_after = isset($result['nextAfter']) ? (string)$result['nextAfter'] : '';
            $last       = !empty($result['last']) ? esc_html__('Yes', 'lavka-total-sync') : esc_html__('No', 'lavka-total-sync');
            $errors     = isset($result['errors']) && is_array($result['errors']) ? count($result['errors']) : 0;
            echo esc_html(sprintf(
                '%s: %s | %s: %s | %s: %d',
                __('Next cursor', 'lavka-total-sync'),
                $next_after !== '' ? $next_after : '-',
                __('Last flag', 'lavka-total-sync'),
                $last,
                __('Errors', 'lavka-total-sync'),
                $errors
            ));
            echo '<br>';
        }

        if ($payload) {
            echo esc_html(sprintf(
                '%s: %d | %s: %d | %s: %s | %s: %s',
                __('Limit', 'lavka-total-sync'),
                (int)($payload['limit'] ?? 0),
                __('Woo batch', 'lavka-total-sync'),
                (int)($payload['pageSizeWoo'] ?? 0),
                __('Cursor', 'lavka-total-sync'),
                isset($payload['cursorAfter']) ? (string)$payload['cursorAfter'] : '-',
                __('Dry run', 'lavka-total-sync'),
                !empty($payload['dryRun']) ? __('Yes', 'lavka-total-sync') : __('No', 'lavka-total-sync')
            ));
            echo '<br>';
        }
    } elseif (is_array($context) && isset($context['line'])) {
        echo esc_html((string)$context['line']).'<br>';
    }

    $pretty = is_string($context)
        ? $context
        : wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    echo '<details><summary>'.esc_html__('View details', 'lavka-total-sync').'</summary>';
    echo '<pre style="max-width:52rem;max-height:20rem;overflow:auto;white-space:pre-wrap;word-break:break-word">'.esc_html($pretty).'</pre>';
    echo '</details>';
}

// [LTS] ANCHOR: submenu - Logs
add_action('admin_menu', function(){
    add_submenu_page(
        'lts-main',
        __('Total Sync Logs','lavka-total-sync'),
        __('Logs','lavka-total-sync'),
        LTS_CAP,
        'lts-logs',
        'lts_render_logs_page'
    );
});

function lts_render_logs_page(){
    if (!current_user_can(LTS_CAP)) return;
    $nonce = wp_create_nonce('lts_logs_actions');

    $level  = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
    $action = isset($_GET['action_f']) ? sanitize_text_field($_GET['action_f']) : '';
    $sku    = isset($_GET['sku']) ? sanitize_text_field($_GET['sku']) : '';
    $days   = isset($_GET['days']) ? max(1,(int)$_GET['days']) : 7;

    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('Total Sync — Logs', 'lavka-total-sync'); ?></h1>

      <form method="get" style="margin:12px 0">
        <input type="hidden" name="page" value="lts-logs">
        <label><?php _e('Level','lavka-total-sync'); ?>:
          <select name="level">
            <option value=""><?php _e('Any','lavka-total-sync'); ?></option>
            <option value="info"  <?php selected($level==='info');  ?>>info</option>
            <option value="warn"  <?php selected($level==='warn');  ?>>warn</option>
            <option value="error" <?php selected($level==='error'); ?>>error</option>
          </select>
        </label>
        <label style="margin-left:12px"><?php _e('Action','lavka-total-sync'); ?>:
          <select name="action_f">
            <option value=""><?php _e('Any','lavka-total-sync'); ?></option>
            <option value="sync"   <?php selected($action==='sync');   ?>>sync</option>
            <option value="upsert" <?php selected($action==='upsert'); ?>>upsert</option>
            <option value="draft"  <?php selected($action==='draft');  ?>>draft</option>
            <option value="export" <?php selected($action==='export'); ?>>export</option>
            <option value="system" <?php selected($action==='system'); ?>>system</option>
          </select>
        </label>
        <label style="margin-left:12px"><?php _e('SKU','lavka-total-sync'); ?>:
          <input type="text" name="sku" value="<?php echo esc_attr($sku); ?>" style="width:14rem">
        </label>
        <label style="margin-left:12px"><?php _e('Days','lavka-total-sync'); ?>:
          <input type="number" name="days" min="1" value="<?php echo (int)$days; ?>" style="width:6rem">
        </label>
        <button class="button"><?php _e('Filter','lavka-total-sync'); ?></button>

        <button class="button button-primary" style="margin-left:12px"
                formaction="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
                formmethod="post" name="lts_export_csv" value="1">
          <?php _e('Export CSV','lavka-total-sync'); ?>
        </button>
        <input type="hidden" name="action" value="lts_logs_export_csv">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
      </form>
    <?php
    // таблица последних 200
    global $wpdb;
    $table = lts_logs_table();

    $where = ["ts >= (NOW() - INTERVAL %d DAY)"];
    $args  = [$days];
    if ($level !== '')  { $where[] = "level = %s";  $args[] = $level; }
    if ($action !== '') { $where[] = "action = %s"; $args[] = $action; }
    if ($sku !== '')    { $where[] = "sku = %s";    $args[] = $sku;   }

    $sql = "SELECT * FROM $table WHERE ".implode(' AND ', $where)." ORDER BY id DESC LIMIT 200";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
    ?>
      <table class="widefat striped">
        <thead>
          <tr>
            <th>#</th><th><?php _e('Time','lavka-total-sync'); ?></th>
            <th><?php _e('Level','lavka-total-sync'); ?></th>
            <th><?php _e('Action','lavka-total-sync'); ?></th>
            <th><?php _e('SKU','lavka-total-sync'); ?></th>
            <th><?php _e('Post','lavka-total-sync'); ?></th>
            <th><?php _e('Result','lavka-total-sync'); ?></th>
            <th><?php _e('Message','lavka-total-sync'); ?></th>
            <th><?php _e('Details','lavka-total-sync'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows): foreach ($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r->id; ?></td>
              <td><code><?php echo esc_html($r->ts); ?></code></td>
              <td><?php echo esc_html($r->level); ?></td>
              <td><?php echo esc_html($r->action); ?></td>
              <td><?php echo esc_html($r->sku); ?></td>
              <td><?php
                if ($r->post_id) {
                    echo '<a href="'.esc_url(get_edit_post_link((int)$r->post_id)).'">'.(int)$r->post_id.'</a>';
                } else echo '—';
              ?></td>
              <td><?php echo esc_html($r->result); ?></td>
              <td><?php echo esc_html($r->message); ?></td>
              <td><?php lts_logs_render_context($r->action, $r->ctx); ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="9"><?php _e('No logs','lavka-total-sync'); ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}

// [LTS] ANCHOR: ajax - CSV export
add_action('wp_ajax_lts_logs_export_csv', function(){
    if (!current_user_can(LTS_CAP)) wp_die('forbidden', 403);
    check_admin_referer('lts_logs_actions');

    global $wpdb;
    $table = lts_logs_table();

    $level  = isset($_REQUEST['level']) ? sanitize_text_field($_REQUEST['level']) : '';
    $action = isset($_REQUEST['action_f']) ? sanitize_text_field($_REQUEST['action_f']) : '';
    $sku    = isset($_REQUEST['sku']) ? sanitize_text_field($_REQUEST['sku']) : '';
    $days   = isset($_REQUEST['days']) ? max(1,(int)$_REQUEST['days']) : 7;

    $where = ["ts >= (NOW() - INTERVAL %d DAY)"];
    $args  = [$days];
    if ($level !== '')  { $where[] = "level = %s";  $args[] = $level; }
    if ($action !== '') { $where[] = "action = %s"; $args[] = $action; }
    if ($sku !== '')    { $where[] = "sku = %s";    $args[] = $sku;   }

    $sql = "SELECT id, ts, level, action, sku, post_id, result, message, ctx
            FROM $table WHERE ".implode(' AND ', $where)." ORDER BY id DESC";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

    $filename = 'lts-logs-'.date('Ymd-His').'.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');

    $out = fopen('php://output', 'w');
    // BOM для Excel
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['id','ts','level','action','sku','post_id','result','message','ctx']);
    if ($rows) {
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['ts'], $r['level'], $r['action'], $r['sku'],
                $r['post_id'], $r['result'], $r['message'], $r['ctx'],
            ]);
        }
    }
    fclose($out);
    exit;
});
