<?php
/*
Plugin Name: Role Price Import — Lite
Description: Import role-based prices from CSV for managers. Updates _wpc_price_role_* by SKU. Optional backup.
Version: 1.0.0
Author: PaintCore
Text Domain: role-price-import-lite
Domain Path: /languages
*/
defined('ABSPATH') || exit;

class RP_Import_Lite {
    const SLUG = 'role-price-import-lite';
    const CAP  = 'manage_woocommerce'; // or 'manage_options'

    private $last = null;

    public function __construct(){
        add_action('admin_menu',  [$this,'menu']);
        add_action('admin_init',  [$this,'maybe_handle']);
    }

    public function menu(){
        add_submenu_page(
            'tools.php',
            __( 'Role Price Import (CSV)', 'role-price-import-lite' ),
            __( 'Role Price Import (CSV)', 'role-price-import-lite' ),
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
        if (!current_user_can(self::CAP)) wp_die( __( 'Insufficient permissions.', 'role-price-import-lite' ) );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Role Price Import (CSV)', 'role-price-import-lite' ); ?></h1>

            <p class="description">
                <?php
                echo wp_kses_post(
                    sprintf(
                        /* translators: keep code columns as-is */
                        __( 'Upload a CSV with columns: %s.', 'role-price-import-lite' ),
                        '<code>sku;partner;opt;opt_osn;schule</code>'
                    )
                );
                ?>
            </p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('rp_import_lite'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php echo esc_html__( 'CSV file', 'role-price-import-lite' ); ?></th>
                        <td><input type="file" name="csv" accept=".csv,text/csv" required></td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html__( 'Backup', 'role-price-import-lite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="rp_backup" value="1">
                                <?php echo wp_kses_post( __( 'Create a backup of current <code>_wpc_price_role_*</code>', 'role-price-import-lite' ) ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button class="button button-primary" name="rp_do_import" value="1">
                        <?php echo esc_html__( 'Import', 'role-price-import-lite' ); ?>
                    </button>
                </p>
            </form>

            <?php if ($this->last): ?>
                <hr>
                <h2><?php echo esc_html__( 'Report', 'role-price-import-lite' ); ?></h2>
                <pre style="background:#fff;border:1px solid #ccd0d4;padding:8px;max-height:420px;overflow:auto"><?php
                    echo esc_html(print_r($this->last, true));
                ?></pre>
            <?php endif; ?>

            <hr>
            <h2><?php echo esc_html__( 'CSV format', 'role-price-import-lite' ); ?></h2>
            <pre>sku;partner;opt;opt_osn;schule
CR-001;10.50;11.00;9.90;10.00</pre>
            <ul>
                <li><?php echo wp_kses_post( __( 'Delimiter: auto (<code>;</code>/<code>,</code>/<code>TAB</code>)', 'role-price-import-lite' ) ); ?></li>
                <li><?php echo esc_html__( 'Encoding: auto (UTF-8 / CP1251, etc.)', 'role-price-import-lite' ); ?></li>
                <li><?php echo esc_html__( 'Empty role cells are skipped (role price unchanged)', 'role-price-import-lite' ); ?></li>
            </ul>
        </div>
        <?php
    }

    private function handle_import(array $file, bool $do_backup): array {
        $t0 = microtime(true);

        // 1) upload
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload($file, ['test_form'=>false, 'mimes'=>['csv'=>'text/csv','txt'=>'text/plain']]);
        if (!empty($upload['error'])) return ['ok'=>false,'stage'=>'upload','error'=>$upload['error']];
        $path = $upload['file'];

        // 2) read + auto-encoding
        $raw0 = file_get_contents($path);
        if ($raw0 === false) return ['ok'=>false,'stage'=>'read','error'=>'file_get_contents failed'];
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw0); // strip BOM
        $enc = 'UTF-8';
        foreach (['UTF-8','Windows-1251','CP1251','ISO-8859-1','Windows-1252'] as $try) {
            $tmp = @iconv($try, 'UTF-8//IGNORE', $raw0);
            if ($tmp !== false && $tmp !== '') { $enc=$try; $raw=$tmp; break; }
        }

        // 3) auto-delimiter
        $comma = substr_count($raw, ',');
        $semi  = substr_count($raw, ';');
        $tab   = substr_count($raw, "\t");
        $delim = ($semi > $comma && $semi > $tab) ? ';' : (($tab > $comma) ? "\t" : ',');

        // 4) parse CSV
        $tmp = wp_tempnam('role_price_utf8.csv');
        file_put_contents($tmp, $raw);
        $fh = fopen($tmp, 'r');
        if (!$fh) return ['ok'=>false,'stage'=>'fopen','error'=>'cannot open tmp'];

        $header = fgetcsv($fh, 0, $delim);
        if (!$header) { fclose($fh); @unlink($tmp); @unlink($path); return ['ok'=>false,'stage'=>'header','error'=>'no header']; }

        // we only need these columns (others ignored)
        $need = ['sku','partner','opt','opt_osn','schule'];
        $map  = [];
        foreach ($header as $i=>$h) {
            $k = strtolower(trim($h));
            if (in_array($k, $need, true)) $map[$k] = $i;
        }
        if (!isset($map['sku'])) { fclose($fh); @unlink($tmp); @unlink($path); return ['ok'=>false,'stage'=>'header','error'=>'no sku column']; }

        // 5) optional backup
        $back_name = '';
        if ($do_backup) {
            global $wpdb;
            $back_name = $wpdb->prefix.'postmeta_backup_role_price_'.gmdate('YmdHis');
            // meta keys to back up
            $keys = "'_wpc_price_role_partner','_wpc_price_role_opt','_wpc_price_role_opt_osn','_wpc_price_role_schule'";
            $wpdb->query( "CREATE TABLE {$back_name} AS SELECT * FROM {$wpdb->postmeta} WHERE meta_key IN ({$keys})" );
        }

        // 6) apply
        $roleColumns = ['partner','opt','opt_osn','schule']; // extend if needed
        $rowN=0; $found=0; $updated=0; $skipped=0; $errors=0;

        while (($r = fgetcsv($fh, 0, $delim)) !== false) {
            $rowN++;
            $sku = isset($r[$map['sku']]) ? trim((string)$r[$map['sku']]) : '';
            if ($sku === '') { $skipped++; continue; }

            // find product by _sku
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
                if ($val === '') continue; // empty — do not touch this role price

                // normalize number
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