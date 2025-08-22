<?php
/*
Plugin Name: Stock Import CSV — Lite (stable)
Description: Боевой импорт остатков в wp_stock_import. Поддерживает длинный (sku,location_slug,qty) и широкий (sku,<склады>...) форматы. Есть SMOKE‑TEST.
Version: 1.1.1
Author: PaintCore
*/
if (!defined('ABSPATH')) exit;

class Stock_Import_CSV_Lite {
    const SLUG = 'stock-import-csv-lite';
    const CAP  = 'manage_options';

    private $last_report = null;

    public function __construct() {
        add_action('admin_menu',  [$this,'menu']);
        add_action('admin_init',  [$this,'maybe_handle']);
    }

    public function menu() {
        add_submenu_page(
            'tools.php',
            'Импорт остатков (Lite)',
            'Импорт остатков (Lite)',
            self::CAP,
            self::SLUG,
            [$this,'page']
        );
    }

    public function maybe_handle() {
        if (!is_admin()) return;
        if (empty($_GET['page']) || $_GET['page'] !== self::SLUG) return;
        if (!current_user_can(self::CAP)) return;

        if (isset($_POST['smoke'])) {
            check_admin_referer('stock_import_lite');
            $this->last_report = $this->smoke_test();
            return;
        }

        if (!empty($_POST['do_import']) && !empty($_FILES['csv']['tmp_name'])) {
            check_admin_referer('stock_import_lite');
            $this->last_report = $this->handle_import($_FILES['csv'], !empty($_POST['truncate']));
        }
    }

    public function page() {
        if (!current_user_can(self::CAP)) wp_die('Недостаточно прав.');
        $rep = $this->last_report;

        ?>
        <div class="wrap">
            <h1>Импорт остатков (CSV → wp_stock_import) — LITE</h1>
            <p>
                Форматы:<br>
                1) длинный: <code>sku,location_slug,qty</code><br>
                2) широкий: <code>sku,kiev1,odesa,...</code>
            </p>
            <p>Кодировка: UTF‑8/CP1251 (авто). Разделитель: запятая / точка с запятой / TAB (авто).</p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('stock_import_lite'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">CSV‑файл</th>
                        <td><input type="file" name="csv" accept=".csv,text/csv" required></td>
                    </tr>
                    <tr>
                        <th scope="row">TRUNCATE</th>
                        <td><label><input type="checkbox" name="truncate" value="1"> Очистить <code>wp_stock_import</code> перед импортом</label></td>
                    </tr>
                </table>
                <p class="submit">
                    <button class="button button-primary" name="do_import" value="1">Импортировать CSV</button>
                    <button class="button" name="smoke" value="1">SMOKE‑TEST (вставить CR‑TEST‑SMOKE)</button>
                </p>
            </form>

            <?php if ($rep !== null): ?>
                <hr>
                <h2>Отчёт</h2>
                <pre style="background:#fff;border:1px solid #ccd0d4;padding:8px;max-height:420px;overflow:auto"><?php
                    echo esc_html(print_r($rep, true));
                ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    private function smoke_test(): array {
        global $wpdb;
        $table = $wpdb->prefix.'stock_import';

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME, $table
        ) );

        $sql = "INSERT INTO {$table} (sku,location_slug,qty)
                VALUES (%s,%s,%f)
                ON DUPLICATE KEY UPDATE qty=VALUES(qty)";
        $q = $wpdb->prepare($sql, 'CR-TEST-SMOKE','kiev1', 7.0);
        $res = $wpdb->query($q);

        return [
            'action'       => 'smoke',
            'table'        => $table,
            'table_exists' => (int)$exists,
            'query'        => $q,
            'result'       => (int)$res,
            'last_error'   => $wpdb->last_error,
        ];
    }

    private function handle_import(array $file, bool $truncate): array {
        $t0 = microtime(true);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $overrides = ['test_form'=>false, 'mimes'=>['csv'=>'text/csv','txt'=>'text/plain']];
        $upload = wp_handle_upload($file, $overrides);
        if (!empty($upload['error'])) {
            return ['ok'=>false,'stage'=>'upload','error'=>$upload['error']];
        }
        $path = $upload['file'];

        $raw0 = file_get_contents($path);
        if ($raw0 === false) return ['ok'=>false,'stage'=>'read','error'=>'file_get_contents failed'];
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw0);
        $enc = 'UTF-8';
        foreach (['UTF-8','Windows-1251','CP1251','ISO-8859-1','Windows-1252'] as $try) {
            $tmp = @iconv($try, 'UTF-8//IGNORE', $raw0);
            if ($tmp !== false && $tmp !== '') { $enc=$try; $raw=$tmp; break; }
        }

        $comma = substr_count($raw, ',');
        $semi  = substr_count($raw, ';');
        $tab   = substr_count($raw, "\t");
        $delim = ($semi > $comma && $semi > $tab) ? ';' : (($tab > $comma) ? "\t" : ',');

        $tmp = wp_tempnam('stock_utf8.csv');
        file_put_contents($tmp, $raw);

        $fh = fopen($tmp, 'r');
        if (!$fh) return ['ok'=>false,'stage'=>'fopen','error'=>'cannot open tmp'];
        $headers = fgetcsv($fh, 0, $delim);
        if (!$headers) { fclose($fh); @unlink($tmp); @unlink($path); return ['ok'=>false,'stage'=>'header','error'=>'no header']; }

        $aliasMap = [
            'киев'=>'kiev1','київ'=>'kiev1','kiev'=>'kiev1','к'=>'kiev1',
            'одесса'=>'odesa','одеса'=>'odesa','odessa'=>'odesa','odesa'=>'odesa','о'=>'odesa',
        ];
        $norm = function(string $h) use($aliasMap){
            $lc = mb_strtolower(trim($h),'UTF-8');
            if (in_array($lc, ['sku','артикул'], true)) return 'sku';
            if (in_array($lc, ['location_slug','location','slug','склад'], true)) return 'location_slug';
            if (in_array($lc, ['qty','quantity','количество','к-во','кол-во'], true)) return 'qty';
            if (isset($aliasMap[$lc])) return $aliasMap[$lc];
            $slug = sanitize_title($h);
            return $slug ?: $lc;
        };
        $headers_norm = array_map($norm, $headers);

        $idxSku = array_search('sku',$headers_norm,true);
        $idxLoc = array_search('location_slug',$headers_norm,true);
        $idxQty = array_search('qty',$headers_norm,true);

        $isLong = ($idxSku !== false && $idxLoc !== false && $idxQty !== false);
        $isWide = ($idxSku !== false && $idxLoc === false && $idxQty === false);

        $wideCols = [];
        if ($isWide) {
            foreach ($headers_norm as $i => $hn) {
                if ($i === $idxSku) continue;
                if ($hn === '' || $hn === '—' || $hn === '-') continue;
                $wideCols[] = [$i, $hn];
            }
        }

        if (!$isLong && !$isWide) {
            fclose($fh); @unlink($tmp); @unlink($path);
            return [
                'ok'=>false,'stage'=>'format','error'=>'Не распознан формат',
                'headers'=>$headers,'headers_norm'=>$headers_norm,'encoding'=>$enc,'delimiter'=>($delim === "\t" ? 'TAB' : $delim)
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix.'stock_import';
        if ($truncate) {
            $wpdb->query("TRUNCATE TABLE {$table}");
        }

        $batch=[]; $errors=0; $rows=0; $pushed=0;
        $push = function($sku,$loc,$qty) use (&$batch,&$pushed){
            $batch[] = [wp_strip_all_tags($sku), $loc, (float)$qty];
            $pushed++;
        };

        while (($r = fgetcsv($fh, 0, $delim)) !== false) {
            $rows++;
            if ($isLong) {
                $sku = trim((string)($r[$idxSku] ?? ''));
                $loc = trim((string)($r[$idxLoc] ?? ''));
                $qty = trim((string)($r[$idxQty] ?? ''));
                if ($sku===''||$loc===''){ $errors++; continue; }
                $loc = $norm($loc);
                $qty = str_replace(',','.',$qty);
                if ($qty==='' || !is_numeric($qty)){ $errors++; continue; }
                $push($sku,$loc,$qty);
            } else {
                $sku = trim((string)($r[$idxSku] ?? ''));
                if ($sku===''){ $errors++; continue; }
                foreach ($wideCols as [$i,$locSlug]) {
                    $rawv = isset($r[$i]) ? trim((string)$r[$i]) : '';
                    if ($rawv==='' || $rawv==='0') continue;
                    $val = str_replace(',','.',$rawv);
                    if (!is_numeric($val)){ $errors++; continue; }
                    $push($sku,$locSlug,$val);
                }
            }

            if (count($batch) >= 1000) {
                $this->flush_batch($table, $batch);
                $batch = [];
            }
        }
        fclose($fh);
        @unlink($tmp);
        @unlink($path);

        if ($batch) {
            $this->flush_batch($table, $batch);
        }

        $t = microtime(true)-$t0;
        return [
            'ok'          => true,
            'format'      => $isLong ? 'long' : 'wide',
            'encoding'    => $enc,
            'delimiter'   => ($delim === "\t" ? 'TAB' : $delim),
            'rows_read'   => $rows,
            'rows_pushed' => $pushed,
            'errors'      => $errors,
            'time_sec'    => round($t,3),
            'last_error'  => $wpdb->last_error,
        ];
    }

    private function flush_batch(string $table, array $rows): void {
        global $wpdb;
        $values=[]; $place=[];
        foreach ($rows as $r) {
            [$sku,$loc,$qty] = $r;
            $values[]=$sku; $values[]=$loc; $values[]=$qty;
            $place[]='(%s,%s,%f)';
        }
        $sql = "INSERT INTO {$table} (sku,location_slug,qty) VALUES ".implode(',',$place)." ON DUPLICATE KEY UPDATE qty=VALUES(qty)";
        $q   = $wpdb->prepare($sql, $values);
        $wpdb->query($q);
    }
}
new Stock_Import_CSV_Lite();