<?php
/*
Plugin Name: Role Price Import — Lite
Description: Импорт цен по ролям из CSV для менеджеров. Обновляет _wpc_price_role_* по SKU. Есть опция бэкапа.
Version: 1.0.0
Author: PaintCore
*/
defined('ABSPATH') || exit;

class RP_Import_Lite {
    const SLUG = 'role-price-import-lite';
    const CAP  = 'manage_woocommerce'; // или 'manage_options'

    private $last = null;

    public function __construct(){
        add_action('admin_menu',  [$this,'menu']);
        add_action('admin_init',  [$this,'maybe_handle']);
    }

    public function menu(){
        add_submenu_page(
            'tools.php',
            'Импорт цен (CSV)',
            'Импорт цен (CSV)',
            self::CAP,
            self::SLUG,
            [$this,'page']
        );
    }

    public function maybe_handle(){
        if (!is_admin()) return;
        if (empty($_GET['page']) || $_GET['page'] !== self::SLUG) return;
        if (!current_user_can(self::CAP)) return;

        if (!empty($_POST['rp_do_import']) && !empty($_FILES['csv']['tmp_name'])) {
            check_admin_referer('rp_import_lite');
            $do_backup = !empty($_POST['rp_backup']);
            $this->last = $this->handle_import($_FILES['csv'], $do_backup);
        }
    }

    public function page(){
        if (!current_user_can(self::CAP)) wp_die('Недостаточно прав.');
        ?>
        <div class="wrap">
            <h1>Импорт цен по ролям (CSV)</h1>
            <p class="description">Загрузите CSV с колонками: <code>sku;partner;opt;opt_osn;schule</code>.</p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('rp_import_lite'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>CSV-файл</th>
                        <td><input type="file" name="csv" accept=".csv,text/csv" required></td>
                    </tr>
                    <tr>
                        <th>Бэкап</th>
                        <td><label><input type="checkbox" name="rp_backup" value="1"> Сделать бэкап текущих <code>_wpc_price_role_*</code></label></td>
                    </tr>
                </table>
                <p class="submit">
                    <button class="button button-primary" name="rp_do_import" value="1">Импортировать</button>
                </p>
            </form>

            <?php if ($this->last): ?>
                <hr>
                <h2>Отчёт</h2>
                <pre style="background:#fff;border:1px solid #ccd0d4;padding:8px;max-height:420px;overflow:auto"><?php
                    echo esc_html(print_r($this->last, true));
                ?></pre>
            <?php endif; ?>

            <hr>
            <h2>Формат CSV</h2>
            <pre>sku;partner;opt;opt_osn;schule
CR-001;10.50;11.00;9.90;10.00</pre>
            <ul>
                <li>Разделитель: авто (<code>;</code>/<code>,</code>/<code>TAB</code>)</li>
                <li>Кодировка: авто (UTF-8 / CP1251 и т.д.)</li>
                <li>Пустые клетки роли — пропускаем (не трогаем цену по этой роли)</li>
            </ul>
        </div>
        <?php
    }

    private function handle_import(array $file, bool $do_backup): array {
        $t0 = microtime(true);

        // 1) загрузка файла
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload($file, ['test_form'=>false, 'mimes'=>['csv'=>'text/csv','txt'=>'text/plain']]);
        if (!empty($upload['error'])) return ['ok'=>false,'stage'=>'upload','error'=>$upload['error']];
        $path = $upload['file'];

        // 2) чтение + авто-кодировка
        $raw0 = file_get_contents($path);
        if ($raw0 === false) return ['ok'=>false,'stage'=>'read','error'=>'file_get_contents failed'];
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw0);
        $enc = 'UTF-8';
        foreach (['UTF-8','Windows-1251','CP1251','ISO-8859-1','Windows-1252'] as $try) {
            $tmp = @iconv($try, 'UTF-8//IGNORE', $raw0);
            if ($tmp !== false && $tmp !== '') { $enc=$try; $raw=$tmp; break; }
        }

        // 3) авто-разделитель
        $comma = substr_count($raw, ',');
        $semi  = substr_count($raw, ';');
        $tab   = substr_count($raw, "\t");
        $delim = ($semi > $comma && $semi > $tab) ? ';' : (($tab > $comma) ? "\t" : ',');

        // 4) разбор CSV
        $tmp = wp_tempnam('role_price_utf8.csv');
        file_put_contents($tmp, $raw);
        $fh = fopen($tmp, 'r');
        if (!$fh) return ['ok'=>false,'stage'=>'fopen','error'=>'cannot open tmp'];

        $header = fgetcsv($fh, 0, $delim);
        if (!$header) { fclose($fh); @unlink($tmp); @unlink($path); return ['ok'=>false,'stage'=>'header','error'=>'no header']; }

        // понадобятся именно эти колонки (остальные игнор)
        $need = ['sku','partner','opt','opt_osn','schule'];
        $map  = [];
        foreach ($header as $i=>$h) {
            $k = strtolower(trim($h));
            if (in_array($k, $need, true)) $map[$k] = $i;
        }
        if (!isset($map['sku'])) { fclose($fh); @unlink($tmp); @unlink($path); return ['ok'=>false,'stage'=>'header','error'=>'no sku column']; }

        // 5) опц. бэкап
        $back_name = '';
        if ($do_backup) {
            global $wpdb;
            $back_name = $wpdb->prefix.'postmeta_backup_role_price_'.gmdate('YmdHis');
            $keys = "'_wpc_price_role_partner','_wpc_price_role_opt',''_wpc_price_role_opt_osn'','_wpc_price_role_schule'";
            // исправим двойную кавычку в строке:
            $keys = "'_wpc_price_role_partner','_wpc_price_role_opt','_wpc_price_role_opt_osn','_wpc_price_role_schule'";
            $wpdb->query( "CREATE TABLE {$back_name} AS SELECT * FROM {$wpdb->postmeta} WHERE meta_key IN ({$keys})" );
        }

        // 6) применяем
        $roleColumns = ['partner','opt','opt_osn','schule']; // расширяем при необходимости
        $rowN=0; $found=0; $updated=0; $skipped=0; $errors=0;

        while (($r = fgetcsv($fh, 0, $delim)) !== false) {
            $rowN++;
            $sku = isset($r[$map['sku']]) ? trim((string)$r[$map['sku']]) : '';
            if ($sku === '') { $skipped++; continue; }

            // ищем товар по _sku
            global $wpdb;
            $pid = (int)$wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value=%s LIMIT 1",
                $sku
            ));
            if (!$pid) { $skipped++; continue; }
            $found++;

            foreach ($roleColumns as $role) {
                if (!isset($map[$role])) continue;
                $val = trim((string)($r[$map[$role]] ?? ''));
                if ($val === '') continue; // пусто — не трогаем эту роль

                // нормализуем число
                $val = str_replace(',', '.', $val);
                if (!is_numeric($val)) { $errors++; continue; }

                $meta_key = '_wpc_price_role_'.$role;
                $ok = update_post_meta($pid, $meta_key, wc_format_decimal($val));
                if ($ok !== false) $updated++;
            }
        }

        fclose($fh); @unlink($tmp); @unlink($path);

        return [
            'ok'        => true,
            'encoding'  => $enc,
            'delimiter' => ($delim === "\t" ? 'TAB' : $delim),
            'rows_read' => $rowN,
            'sku_found' => $found,
            'meta_updated' => $updated,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'backup_table' => $do_backup ? $back_name : '',
            'time_sec'  => round(microtime(true) - $t0, 3),
        ];
    }
}
new RP_Import_Lite();