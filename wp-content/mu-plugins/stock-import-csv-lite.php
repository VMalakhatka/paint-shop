<?php
/*
Plugin Name: Stock Import CSV — Lite (stable + docs)
Description: Production stock import into wp_stock_import. Supports long (sku,location_slug,qty) and wide (sku,<warehouses>...) CSV formats. Includes a SMOKE TEST. The page contains built-in docs and CSV examples.
Version: 1.2.0
Author: PaintCore
Text Domain: stock-import-csv-lite
Domain Path: /languages
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
            __( 'Stock Import (Lite)', 'stock-import-csv-lite' ),
            __( 'Stock Import (Lite)', 'stock-import-csv-lite' ),
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
        if (!current_user_can(self::CAP)) wp_die( __( 'Insufficient permissions.', 'stock-import-csv-lite' ) );
        $rep = $this->last_report;

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Stock Import (CSV → wp_stock_import) — LITE', 'stock-import-csv-lite' ); ?></h1>
            <p class="description">
                <?php
                echo wp_kses_post(
                    __( 'Formats: <strong>long</strong> (<code>sku,location_slug,qty</code>) and <strong>wide</strong> (<code>sku,kiev1,odesa,...</code>).<br>Encoding: UTF-8/CP1251 (auto). Delimiter: <code>,</code> / <code>;</code> / <code>TAB</code> (auto).', 'stock-import-csv-lite' )
                );
                ?>
            </p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('stock_import_lite'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'CSV file', 'stock-import-csv-lite' ); ?></th>
                        <td><input type="file" name="csv" accept=".csv,text/csv" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'TRUNCATE', 'stock-import-csv-lite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="truncate" value="1">
                                <?php echo wp_kses_post( __( 'Clear <code>wp_stock_import</code> before import', 'stock-import-csv-lite' ) ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button class="button button-primary" name="do_import" value="1"><?php echo esc_html__( 'Import CSV', 'stock-import-csv-lite' ); ?></button>
                    <button class="button" name="smoke" value="1"><?php echo esc_html__( 'SMOKE-TEST (insert CR-TEST-SMOKE)', 'stock-import-csv-lite' ); ?></button>
                </p>
            </form>

            <?php
            // Built-in documentation
            $this->render_docs_block();
            ?>

            <?php if ($rep !== null): ?>
                <hr>
                <h2><?php echo esc_html__( 'Report', 'stock-import-csv-lite' ); ?></h2>
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
            // aliases -> normalized slugs
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
                'ok'=>false,'stage'=>'format','error'=>'format not recognized',
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
                    if ($rawv==='') continue;
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

    /** Built-in documentation */
    private function render_docs_block() { ?>
        <hr>
        <style>
            .stock-docs{background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:16px;margin-top:12px}
            .stock-docs h2{margin:.2em 0 .6em}
            .stock-docs h3{margin:1.2em 0 .4em}
            .stock-docs code{background:#f6f7f7;padding:2px 4px;border-radius:4px}
            .stock-docs pre{background:#f6f7f7;border:1px solid #e2e4e7;border-radius:6px;padding:10px;overflow:auto}
            .stock-docs .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
            @media (max-width: 1024px){ .stock-docs .grid{grid-template-columns:1fr} }
            .copy-btn{margin-top:6px}
            .muted{color:#666}
        </style>

        <div class="stock-docs"
             data-lbl-docs="<?php echo esc_attr__( 'Documentation', 'stock-import-csv-lite' ); ?>"
             data-lbl-copied="<?php echo esc_attr__( 'Copied ✓', 'stock-import-csv-lite' ); ?>"
             data-lbl-copy="<?php echo esc_attr__( 'Copy example', 'stock-import-csv-lite' ); ?>">
          <h2><?php echo esc_html__( 'Documentation', 'stock-import-csv-lite' ); ?></h2>
          <p class="muted"><?php echo wp_kses_post( __( 'This page imports CSV into the <code>wp_stock_import</code> table.', 'stock-import-csv-lite' ) ); ?></p>

          <h3><?php echo esc_html__( 'Table structure', 'stock-import-csv-lite' ); ?></h3>
          <pre>CREATE TABLE wp_stock_import (
  sku           VARCHAR(191) NOT NULL,
  location_slug VARCHAR(191) NOT NULL,
  qty           DECIMAL(18,3) NOT NULL,
  PRIMARY KEY (sku, location_slug)
);</pre>
          <ul>
            <li><code>sku</code> — <?php echo esc_html__( 'SKU (product code).', 'stock-import-csv-lite' ); ?></li>
            <li><code>location_slug</code> — <?php echo esc_html__( 'Warehouse (slug).', 'stock-import-csv-lite' ); ?></li>
            <li><code>qty</code> — <?php echo esc_html__( 'Quantity (up to 3 decimals).', 'stock-import-csv-lite' ); ?></li>
          </ul>

          <h3><?php echo esc_html__( 'Supported CSV formats', 'stock-import-csv-lite' ); ?></h3>
          <div class="grid">
            <div>
              <strong><?php echo esc_html__( '1) Long format', 'stock-import-csv-lite' ); ?></strong> — <code>sku,location_slug,qty</code>
              <pre id="ex-long">sku;location_slug;qty
CR-TEST-001;kiev1;10
CR-TEST-001;odesa;3.5
CR-TEST-002;kiev1;0
CR-TEST-003;odesa;7</pre>
              <button type="button" class="button copy-btn" data-copy="#ex-long"><?php echo esc_html__( 'Copy example', 'stock-import-csv-lite' ); ?></button>
            </div>
            <div>
              <strong><?php echo esc_html__( '2) Wide format', 'stock-import-csv-lite' ); ?></strong> — <code>sku,&lt;warehouses&gt;…</code>
              <pre id="ex-wide">sku,kiev1,odesa
A-AZ MISC,"68583,91",0
AB-111-10X15,0,0
AB-111-20X20,0,0
AB-111-20X30,0,0</pre>
              <button type="button" class="button copy-btn" data-copy="#ex-wide"><?php echo esc_html__( 'Copy example', 'stock-import-csv-lite' ); ?></button>
              <p class="muted"><?php echo esc_html__( 'Empty/zero warehouse cells are skipped.', 'stock-import-csv-lite' ); ?></p>
            </div>
          </div>

          <h3><?php echo esc_html__( 'Delimiters and encoding', 'stock-import-csv-lite' ); ?></h3>
          <ul>
            <li><?php echo wp_kses_post( __( 'Delimiter: auto — <code>;</code> / <code>,</code> / <code>TAB</code>.', 'stock-import-csv-lite' ) ); ?></li>
            <li><?php echo esc_html__( 'Encoding: auto — UTF-8 / Windows-1251 / ISO-8859-1 / Windows-1252.', 'stock-import-csv-lite' ); ?></li>
            <li><?php echo wp_kses_post( __( 'Decimals: both <code>.</code> and <code>,</code> are recognized.', 'stock-import-csv-lite' ) ); ?></li>
          </ul>

          <h3><?php echo esc_html__( 'Warehouse aliases', 'stock-import-csv-lite' ); ?></h3>
          <p><?php echo esc_html__( 'The following variants normalize to slugs:', 'stock-import-csv-lite' ); ?></p>
          <table class="widefat striped">
            <thead><tr><th><?php echo esc_html__( 'Input', 'stock-import-csv-lite' ); ?></th><th><?php echo esc_html__( 'Becomes', 'stock-import-csv-lite' ); ?></th></tr></thead>
            <tbody>
              <tr><td>киев / київ / kiev / к</td><td><code>kiev1</code></td></tr>
              <tr><td>одесса / одеса / odessa / odesa / о</td><td><code>odesa</code></td></tr>
            </tbody>
          </table>
          <p class="muted"><?php echo wp_kses_post( __( 'Unknown names are converted to a slug via <code>sanitize_title()</code>.', 'stock-import-csv-lite' ) ); ?></p>

          <h3><?php echo esc_html__( 'Import rules', 'stock-import-csv-lite' ); ?></h3>
          <ul>
            <li><?php echo wp_kses_post( __( 'Key: <code>(sku, location_slug)</code>. On conflict — update (<em>upsert</em>).', 'stock-import-csv-lite' ) ); ?></li>
            <li><?php echo wp_kses_post( __( '<strong>TRUNCATE</strong> — removes all old rows before inserting.', 'stock-import-csv-lite' ) ); ?></li>
            <li><?php echo esc_html__( 'Wide format: empty/zero cells do not create rows.', 'stock-import-csv-lite' ); ?></li>
          </ul>

          <h3><?php echo esc_html__( 'How to prepare CSV', 'stock-import-csv-lite' ); ?></h3>
          <ol>
            <li><?php echo wp_kses_post( __( 'Export from Excel/Sheets as <em>CSV</em> (preferably UTF-8).', 'stock-import-csv-lite' ) ); ?></li>
            <li><?php echo wp_kses_post( __( 'For the wide format — first column is <code>sku</code>, then warehouse names (<code>kiev1</code>, <code>odesa</code>, …).', 'stock-import-csv-lite' ) ); ?></li>
          </ol>

          <h3><?php echo esc_html__( 'Diagnostics', 'stock-import-csv-lite' ); ?></h3>
          <ul>
            <li><?php echo wp_kses_post( __( 'The <strong>SMOKE-TEST</strong> button inserts a test row <code>(CR-TEST-SMOKE, kiev1, 7)</code>.', 'stock-import-csv-lite' ) ); ?></li>
            <li><?php echo wp_kses_post( __( 'For verbose logs you can temporarily enable a verbose build and check <code>wp-content/debug.log</code>.', 'stock-import-csv-lite' ) ); ?></li>
          </ul>
        </div>

        <script>
        (function(){
          document.addEventListener('click', function(e){
            if(!e.target.matches('.copy-btn')) return;
            const root = document.querySelector('.stock-docs');
            const lblCopied = root ? root.getAttribute('data-lbl-copied') : 'Copied ✓';
            const lblCopy   = root ? root.getAttribute('data-lbl-copy')   : 'Copy example';
            const sel = e.target.getAttribute('data-copy');
            const el = document.querySelector(sel);
            if(!el) return;
            navigator.clipboard.writeText(el.textContent).then(()=>{
              e.target.textContent = lblCopied;
              setTimeout(()=>{ e.target.textContent = lblCopy; }, 1500);
            });
          });
        })();
        </script>
        <?php
    }
}

new Stock_Import_CSV_Lite();