<?php
if (!defined('ABSPATH')) exit;

function lps_sp_labels(): array {
    return [
        'sku'=>__('Internal SKU','lavka-price-sync'), 'status'=>__('Status','lavka-price-sync'), 'row'=>__('Source row','lavka-price-sync'),
        'uploaded'=>__('Uploaded','lavka-price-sync'), 'draft'=>__('Draft','lavka-price-sync'), 'active'=>__('Active','lavka-price-sync'), 'archived'=>__('Archived','lavka-price-sync'),
        'title'=>__('Supplier price lists','lavka-price-sync'),
        'supplier'=>__('Supplier','lavka-price-sync'), 'database'=>__('Source database','lavka-price-sync'),
        'upload'=>__('Upload XLSX','lavka-price-sync'), 'sheet'=>__('Worksheet','lavka-price-sync'),
        'header'=>__('Header row','lavka-price-sync'), 'article'=>__('Supplier article','lavka-price-sync'),
        'description'=>__('Description','lavka-price-sync'), 'gtin'=>__('GTIN','lavka-price-sync'),
        'pack'=>__('VE Unit','lavka-price-sync'), 'listPrice'=>__('List price','lavka-price-sync'),
        'discount'=>__('Discount','lavka-price-sync'), 'price'=>__('Invoice price','lavka-price-sync'),
        'net'=>__('Netto marker','lavka-price-sync'), 'currency'=>__('Currency','lavka-price-sync'),
        'validFrom'=>__('Valid from','lavka-price-sync'), 'priceBasis'=>__('Price basis','lavka-price-sync'),
        'UNIT'=>__('Per unit','lavka-price-sync'), 'PACK'=>__('Per pack','lavka-price-sync'),
        'full'=>__('This is the complete supplier price list. Mark missing products as discontinued.','lavka-price-sync'),
        'netFallback'=>__('For Netto rows with an empty invoice price, use the list price without another discount.','lavka-price-sync'),
        'preview'=>__('Check import','lavka-price-sync'), 'activate'=>__('Activate this version','lavka-price-sync'),
        'download'=>__('Download original','lavka-price-sync'), 'export'=>__('Export review CSV','lavka-price-sync'),
        'rows'=>__('Rows','lavka-price-sync'), 'matched'=>__('Matched rows','lavka-price-sync'),
        'review'=>__('Rows requiring review','lavka-price-sync'), 'discontinued'=>__('Discontinued products','lavka-price-sync'),
        'PRESENT'=>__('Present in price list','lavka-price-sync'), 'DISCONTINUED'=>__('Discontinued','lavka-price-sync'),
        'REVIEW'=>__('Review required','lavka-price-sync'), 'NOT_IN_PARTIAL'=>__('Not included in partial update','lavka-price-sync'),
        'INVALID_GTIN'=>__('GTIN is missing or invalid.','lavka-price-sync'), 'MISSING_ARTICLE'=>__('Supplier article is missing.','lavka-price-sync'),
        'AMBIGUOUS_GTIN'=>__('GTIN matches several internal products.','lavka-price-sync'),
        'UNMATCHED_GTIN'=>__('No internal product matches this GTIN.','lavka-price-sync'),
        'INVALID_PACK'=>__('Pack quantity is missing or invalid.','lavka-price-sync'),
        'INVALID_PRICE'=>__('Price is missing or contains an Excel error.','lavka-price-sync'),
        'FORMULA_REVIEW'=>__('The price formula or its saved result requires review.','lavka-price-sync'),
        'DUPLICATE_VARIANT'=>__('The same supplier article, GTIN and pack occur more than once.','lavka-price-sync'),
        'CATALOG_NOT_READY'=>__('No supplier catalog is available. Check the supplier name and analytics snapshots.','lavka-price-sync'),
        'CATALOG_CHANGED'=>__('The catalog changed. Check the import again before activation.','lavka-price-sync'),
        'VERSION_CHANGED'=>__('The version changed. Reload and check the import again.','lavka-price-sync'),
        'FULL_IMPORT_BLOCKED'=>__('Invalid GTIN rows prevent activation of a full list. Correct them or import a partial update.','lavka-price-sync'),
        'ALREADY_ACTIVE'=>__('This file and import configuration are already active.','lavka-price-sync'),
        'INVALID_SETTINGS'=>__('Check the worksheet, column letters, date and currency.','lavka-price-sync'),
        'SHEET_NOT_FOUND'=>__('Select an existing worksheet.','lavka-price-sync'),
        'NO_ROWS'=>__('No product rows were found with these settings.','lavka-price-sync'),
        'UPLOAD_ERROR'=>__('The file could not be uploaded.','lavka-price-sync'),
        'XLSX_ONLY'=>__('Upload an XLSX file.','lavka-price-sync'),
        'FILE_TOO_LARGE'=>__('The file exceeds the import size limit.','lavka-price-sync'),
        'TOO_MANY_ROWS'=>__('The import exceeds the row limit.','lavka-price-sync'),
        'XLSX_SUPPORT_REQUIRED'=>__('PHP ZIP and SimpleXML extensions are required.','lavka-price-sync'),
        'EXTERNAL_CONTENT'=>__('Files with macros or external workbook links are not accepted.','lavka-price-sync'),
        'UNSAFE_XML'=>__('The workbook contains unsupported XML declarations.','lavka-price-sync'),
        'INVALID_XLSX'=>__('The XLSX file could not be read.','lavka-price-sync'),
        'STORAGE_ERROR'=>__('The import could not be saved. No site prices were changed.','lavka-price-sync'),
        'VERSION_NOT_FOUND'=>__('The price list version was not found.','lavka-price-sync'),
    ];
}

function lps_sp_label(string $key): string { return lps_sp_labels()[$key] ?? $key; }
function lps_sp_url(int $id=0): string { return admin_url('admin.php?page=lps-supplier-prices'.($id?'&version='.$id:'')); }
function lps_sp_form(string $operation, int $id=0): void {
    echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('lps_supplier_prices');
    echo '<input type="hidden" name="action" value="lps_supplier_prices"><input type="hidden" name="operation" value="'.esc_attr($operation).'"><input type="hidden" name="id" value="'.$id.'">';
}

add_action('admin_menu',static function () {
    add_submenu_page(function_exists('paint_core_lavka_admin_parent_slug')?paint_core_lavka_admin_parent_slug():'lps-main',
        lps_sp_label('title'),lps_sp_label('title'),LPS_CAP,'lps-supplier-prices','lps_sp_render');
});

add_action('admin_post_lps_supplier_prices', static function () {
    if (!current_user_can(LPS_CAP)) wp_die(esc_html__('Access denied.','lavka-price-sync'),'', ['response'=>403]);
    check_admin_referer('lps_supplier_prices');
    $id=absint($_POST['id']??0);
    try {
        lps_sp_install(); $op=sanitize_key($_POST['operation']??'');
        if ($op==='upload') $id=lps_sp_upload($_FILES['price_file']??[],sanitize_text_field(wp_unslash($_POST['supplier']??'')),sanitize_text_field($_POST['database']??''));
        elseif ($op==='preview') lps_sp_build($id,wp_unslash($_POST['config']??[]));
        elseif ($op==='activate') lps_sp_activate($id,sanitize_text_field($_POST['approval']??''));
        elseif ($op==='download') {
            $v=lps_sp_version($id); nocache_headers();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="supplier-price-'.$id.'.xlsx"');
            echo base64_decode($v['original_base64'],true); exit;
        } elseif ($op==='export') {
            $v=lps_sp_version($id);$p=lps_sp_decode($v['preview_json']);$records=[];
            foreach ($p['offers']??[] as $r) $records[]=['sku'=>$r['sku'],'article'=>$r['article'],'gtin'=>$r['originalGtin'],
                'pack'=>$r['pack'],'price'=>$r['price'],'currency'=>$r['currency'],'status'=>implode('; ',array_map('lps_sp_label',$r['issues'])),'row'=>$r['row']];
            foreach ($p['statuses']??[] as $sku=>$status) if ($status!=='PRESENT') $records[]=['sku'=>$sku,'status'=>lps_sp_label($status)];
            lps_product_analytics_export_csv(array_map(static fn($k)=>['key'=>$k,'label'=>lps_sp_label($k)],['sku','article','gtin','pack','price','currency','status','row']),$records,'supplier-review-'.$id.'.csv');
        } else throw new RuntimeException('INVALID_SETTINGS');
        wp_safe_redirect(lps_sp_url($id)); exit;
    } catch (Throwable $e) {
        $code=array_key_exists($e->getMessage(),lps_sp_labels())?$e->getMessage():'STORAGE_ERROR';
        wp_safe_redirect(add_query_arg('import_error',$code,lps_sp_url($id))); exit;
    }
});

function lps_sp_render(): void {
    if (!current_user_can(LPS_CAP)) return;
    global $wpdb;
    echo '<div class="wrap lps-sp"><h1>'.esc_html(lps_sp_label('title')).'</h1>';
    echo '<p>'.esc_html__('Supplier information only. Site and Folio prices, stock and product visibility are never changed.','lavka-price-sync').'</p>';
    echo '<style>.lps-sp .sp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;max-width:1000px}.lps-sp label{display:block;margin:8px 0}.lps-sp input:not([type=checkbox]),.lps-sp select{max-width:100%;box-sizing:border-box}.lps-sp .sp-scroll{overflow:auto}.lps-sp td{overflow-wrap:anywhere}.lps-sp .sp-scroll table{min-width:820px}.lps-sp td:nth-child(-n+3){white-space:nowrap}.lps-sp form{margin:16px 0}.lps-sp .sp-actions{display:flex;gap:15px;flex-wrap:wrap}</style>';
    if (isset($_GET['import_error'])) echo '<div class="notice notice-error"><p>'.esc_html(lps_sp_labels()[(string)$_GET['import_error']]??lps_sp_label('STORAGE_ERROR')).'</p></div>';
    try {
        lps_sp_install(); $id=absint($_GET['version']??0);
        if (!$id) {
            lps_sp_form('upload');
            echo '<div class="sp-grid"><label>'.esc_html(lps_sp_label('supplier')).' <input name="supplier" required maxlength="191" placeholder="Kreul"></label><label>'.esc_html(lps_sp_label('database')).' <select name="database"><option>Paint_Ua</option><option>Paint_Rus</option></select></label><label>'.esc_html(lps_sp_label('upload')).' <input type="file" name="price_file" accept=".xlsx" required></label></div><p> XLSX · 8 MB</p><button class="button button-primary">'.esc_html(lps_sp_label('upload')).'</button></form>';
            $versions=$wpdb->get_results('SELECT id,supplier,filename,status,created_at FROM '.lps_sp_table().' ORDER BY id DESC LIMIT 100',ARRAY_A);
            echo '<ul>';foreach ($versions?:[] as $v) echo '<li><a href="'.esc_url(lps_sp_url((int)$v['id'])).'">#'.(int)$v['id'].' '.esc_html($v['supplier'].' · '.$v['filename'].' · '.lps_sp_label($v['status']).' · '.$v['created_at']).'</a></li>';echo '</ul>';
        } else {
            $v=lps_sp_version($id);$config=lps_sp_decode($v['config_json']);$preview=lps_sp_decode($v['preview_json']);
            echo '<p><a href="'.esc_url(lps_sp_url()).'">'.esc_html(lps_sp_label('title')).'</a></p><h2>#'.$id.' '.esc_html($v['supplier'].' · '.$v['filename'].' · '.lps_sp_label($v['status'])).'</h2>';
            if ($config) echo '<p>'.esc_html(lps_sp_label('sheet').': '.$config['sheet'].' · '.lps_sp_label('validFrom').': '.$config['validFrom'].' · '.$config['currency'].' · '.lps_sp_label($config['priceBasis'])).'</p>';
            echo '<div class="sp-actions">';foreach (['download','export'] as $op) { lps_sp_form($op,$id);echo '<button class="button">'.esc_html(lps_sp_label($op)).'</button></form>'; }echo '</div>';
            if (in_array($v['status'],['uploaded','draft'],true)) {
                $sheets=lps_sp_temporary($v,static fn($p)=>lps_sp_xlsx($p)['sheets']);lps_sp_form('preview',$id);
                echo '<div class="sp-grid"><label>'.esc_html(lps_sp_label('sheet')).' <select name="config[sheet]">';
                foreach ($sheets as $sheet) echo '<option '.selected($config['sheet']??$sheets[0],$sheet,false).'>'.esc_html($sheet).'</option>';
                echo '</select></label>';
                foreach (['header'=>7,'article'=>'A','description'=>'C','gtin'=>'J','pack'=>'H','listPrice'=>'D','discount'=>'E','price'=>'F','net'=>'I','currency'=>'EUR','validFrom'=>''] as $key=>$default) {
                    echo '<label>'.esc_html(lps_sp_label($key)).' <input name="config['.$key.']" value="'.esc_attr($config[$key]??$default).'" type="'.($key==='validFrom'?'date':($key==='header'?'number':'text')).'" required></label>';
                }
                echo '<label>'.esc_html(lps_sp_label('priceBasis')).' <select name="config[priceBasis]">';
                foreach (['UNIT','PACK'] as $basis) echo '<option value="'.$basis.'" '.selected($config['priceBasis']??'UNIT',$basis,false).'>'.esc_html(lps_sp_label($basis)).'</option>';
                echo '</select></label></div>';
                foreach (['full','netFallback'] as $key) echo '<label><input type="checkbox" name="config['.$key.']" value="1" '.checked(!empty($config[$key]),true,false).'> '.esc_html(lps_sp_label($key)).'</label>';
                echo '<button class="button button-primary">'.esc_html(lps_sp_label('preview')).'</button></form>';
            }
            if ($preview) {
                echo '<p>';foreach ($preview['counts'] as $key=>$value) echo esc_html(lps_sp_label($key)).': '.(int)$value.' · ';echo '</p>';
                if ($v['status']==='draft') {
                    lps_sp_form('activate',$id);echo '<input type="hidden" name="approval" value="'.esc_attr($v['approval_hash']).'">';
                    echo '<label><input type="checkbox" required> '.esc_html__('I reviewed the variants, price errors and discontinued products. Activate these supplier data only.','lavka-price-sync').'</label><button class="button button-primary" '.(!empty($config['full'])&&!empty($preview['blockers'])?'disabled':'').'>'.esc_html(lps_sp_label('activate')).'</button></form>';
                }
                $filter=sanitize_text_field(wp_unslash($_GET['sku']??''));$page=max(1,absint($_GET['p']??1));
                echo '<form method="get"><input type="hidden" name="page" value="lps-supplier-prices"><input type="hidden" name="version" value="'.$id.'"><label>'.esc_html__('Find SKU, GTIN or supplier article','lavka-price-sync').' <input name="sku" value="'.esc_attr($filter).'"></label><button class="button">'.esc_html__('Search','lavka-price-sync').'</button></form>';
                $offers=array_values(array_filter($preview['offers'],static fn($r)=>$filter===''||stripos(($r['sku']??'').' '.$r['article'].' '.$r['originalGtin'],$filter)!==false));
                echo '<div class="sp-scroll"><table class="widefat striped"><thead><tr>';
                foreach (['sku','article','gtin','pack','price','currency','review'] as $k) echo '<th>'.esc_html(lps_sp_label($k)).'</th>';echo '</tr></thead><tbody>';
                foreach (array_slice($offers,($page-1)*50,50) as $r) {
                    echo '<tr>';foreach (['sku','article','originalGtin','pack','price','currency'] as $k) echo '<td>'.esc_html($r[$k]??'—').'</td>';
                    echo '<td>'.esc_html(implode(' ',array_map('lps_sp_label',$r['issues']))).'</td></tr>';
                }
                echo '</tbody></table></div><p>';
                if ($page>1) echo '<a class="button" href="'.esc_url(add_query_arg(['p'=>$page-1,'sku'=>$filter],lps_sp_url($id))).'">'.esc_html__('Previous','lavka-price-sync').'</a> ';
                echo $page.' / '.max(1,(int)ceil(count($offers)/50));
                if ($page*50<count($offers)) echo ' <a class="button" href="'.esc_url(add_query_arg(['p'=>$page+1,'sku'=>$filter],lps_sp_url($id))).'">'.esc_html__('Next','lavka-price-sync').'</a>';
                echo '</p><details><summary>'.esc_html(lps_sp_label('discontinued')).'</summary><p>';
                foreach ($preview['statuses'] as $sku=>$status) if ($status==='DISCONTINUED') echo esc_html($sku).' ';echo '</p></details>';
            }
        }
    } catch (Throwable $e) { echo '<div class="notice notice-error"><p>'.esc_html(lps_sp_labels()[$e->getMessage()]??lps_sp_label('STORAGE_ERROR')).'</p></div>'; }
    echo '<script>document.querySelectorAll(".lps-sp form[method=post]").forEach(f=>f.addEventListener("submit",()=>{if(["download","export"].includes(f.querySelector("[name=operation]").value))return;f.setAttribute("aria-busy","true");f.querySelectorAll("button").forEach(b=>{b.disabled=true;b.textContent=' . wp_json_encode(__('Processing...', 'lavka-price-sync')) . '})}));</script></div>';
}
