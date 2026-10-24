<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/folio-manager-import.php';
require_once __DIR__.'/folio-manager-export.php';
const LPS_MANAGER_NONCE='lps_folio_manager';
add_action('init',static function(){load_plugin_textdomain('lps-manager',false,dirname(plugin_basename(__DIR__.'/../lavka-price-sync.php')).'/languages');});
function lps_manager_states(): array {
    return ['PREVIEW'=>__('Ready for confirmation','lps-manager'),'READY'=>__('Result ready for download','lps-manager'),'BLOCKED'=>__('Cannot apply: resolve the reported conflicts first.','lps-manager'),
            'APPLIED'=>__('Saved and verified in Folio.','lps-manager'),'APPLYING'=>__('The operation is running. Check status.','lps-manager'),'UNKNOWN'=>__('Outcome unknown. Check status; do not repeat the update.','lps-manager'),
            'STALE'=>__('Source rows changed. Nothing was applied; create a new preview.','lps-manager'),'EXPIRED'=>__('Preview expired. Create a new preview.','lps-manager'),'EMPTY'=>__('No target rows.','lps-manager')];
}
function lps_manager_labels(): array {
    return ['NAME'=>__('Product name','lps-manager'),'GROUPS'=>__('Product groups','lps-manager'),'UNIT'=>__('Unit of measure','lps-manager'),
        'DESCRIPTION'=>__('Product description','lps-manager'),'BASE'=>__('Articles by base article','lps-manager'),'ASSEMBLY'=>__('Assembly invoice calculation','lps-manager')];
}
add_action('admin_menu',static function(){
    add_submenu_page('lps-folio-cards',__('Manager operations','lps-manager'),__('Manager operations','lps-manager'),LPS_CAP,'lps-folio-manager','lps_manager_render');
    add_submenu_page('lps-folio-cards',__('Assembly invoice calculation','lps-manager'),__('Assembly invoice calculation','lps-manager'),LPS_CAP,'lps-folio-assembly','lps_assembly_render');
});
function lps_manager_screen_labels(bool $assembly): array {
    return array_filter(lps_manager_labels(),static fn($key)=>($key==='ASSEMBLY')===$assembly,ARRAY_FILTER_USE_KEY);
}
function lps_manager_render(): void { lps_manager_render_page(false); }
function lps_assembly_render(): void { lps_manager_render_page(true); }
function lps_manager_help(string $title,string $text): void {
    echo '<details><summary>'.esc_html($title).'</summary><p style="max-width:900px">'.esc_html($text).'</p></details>';
}
function lps_manager_render_page(bool $assembly): void {
    if(!current_user_can(LPS_CAP))wp_die(esc_html__('Access denied.','lps-manager'));
    $labels=lps_manager_screen_labels($assembly);
    echo '<div class="wrap"><h1>'.esc_html($assembly?__('Assembly invoice calculation','lps-manager'):__('Manager operations','lps-manager')).'</h1><p>'.esc_html($assembly?__('Calculate assembly and download its results. Folio invoice generation will be added here later.','lps-manager'):__('Five operations for product cards and article selection. Source and destination: Paint_Ua.','lps-manager')).'</p>';
    lps_manager_help(__('Quick guide','lps-manager'),$assembly?__('Upload the assembly input or paste cells from Excel, calculate and check the result tables, then download Excel. This screen does not create a Folio invoice.','lps-manager'):__('Choose an operation, upload a file or paste cells from Excel, then preview. For card changes, check all warehouses and empty values before confirming Apply. For article selection, download the result.','lps-manager'));
    echo '<form id="lps-manager-form">';
    if($assembly)echo '<input id="lps-manager-kind" name="kind" type="hidden" value="ASSEMBLY">';
    else {
        echo '<p><label for="lps-manager-kind">'.esc_html__('Operation','lps-manager').'</label> <select id="lps-manager-kind" name="kind" style="max-width:100%">';
        foreach($labels as $key=>$label)echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';
        echo '</select></p>';
    }
    echo '<p id="lps-manager-columns" style="overflow-wrap:anywhere"></p><p id="lps-manager-scope"></p>';
    echo '<p><label>'.esc_html__('Excel or CSV file','lps-manager').' <input type="file" name="file" style="max-width:100%" accept=".xls,.xlsx,.csv"></label></p><p><label for="lps-manager-paste">'.esc_html__('Or paste cells from Excel','lps-manager').'</label></p><textarea id="lps-manager-paste" name="paste" rows="6" style="width:100%;max-width:1000px;box-sizing:border-box" spellcheck="false"></textarea>';
    lps_manager_help(__('File and empty values','lps-manager'),__('XLS, XLSX or UTF-8 CSV, up to 5 MB and 500 data rows. Only the first sheet is read. Headers are optional, in the displayed order. Use tabs or semicolons for decimal commas. Formulas are rejected. Empty values clear fields to NULL. Keep all six group columns. For assembly, supply SLOG explicitly as 0 or 1 and preserve the initial values used in Access.','lps-manager'));
    wp_nonce_field(LPS_MANAGER_NONCE);
    echo '<p><button class="button button-primary" type="submit">'.esc_html($assembly?__('Calculate assembly','lps-manager'):__('Preview / calculate','lps-manager')).'</button></p></form>';
    echo '<p id="lps-manager-status" role="status" aria-live="polite"></p><div id="lps-manager-result"></div><p><a id="lps-manager-download" class="button" hidden>'.esc_html__('Download Excel','lps-manager').'</a></p>';
    if(!$assembly) {
    lps_manager_help(__('Check before applying','lps-manager'),__('Name, groups and unit are updated on every matching warehouse card. Check the old and new values, missing articles and intended field clearing. A preview is valid for 15 minutes; changed source rows require a new preview.','lps-manager'));
    echo '<p><label><input id="lps-manager-confirm" type="checkbox" disabled> '.esc_html__('I checked all changes, including empty values, and confirm the update in Paint_Ua.','lps-manager').'</label></p><p><button id="lps-manager-apply" class="button button-primary" disabled>'.esc_html__('Apply to Folio','lps-manager').'</button></p>';
    lps_manager_help(__('If the result is unknown','lps-manager'),__('Do not upload and apply the same file again. Use Check status or reopen this operation in history. If the result stays unknown, ask the administrator to verify Folio against the saved preview.','lps-manager'));
    }
    echo '<p><button id="lps-manager-check" class="button" disabled>'.esc_html__('Check status','lps-manager').'</button></p>';
    echo '<h2>'.esc_html($assembly?__('Recent assembly calculations','lps-manager'):__('Recent operations','lps-manager')).'</h2><ul id="lps-manager-history">';
    foreach(array_reverse((array)get_option('lps_manager_history_'.get_current_user_id(),[])) as $id){$job=get_option('lps_manager_job_'.$id);if(!is_array($job)||!isset($labels[$job['result']['operation']??'']))continue;
        echo '<li><button type="button" class="button-link" data-job="'.esc_attr($id).'">'.esc_html($job['created'].' — '.$job['filename'].' — '.(lps_manager_states()[$job['status']]??$job['status'])).'</button></li>';}
    echo '</ul></div>';
}
add_action('admin_enqueue_scripts',static function(){
    $page=$_GET['page']??'';
    if(!in_array($page,['lps-folio-manager','lps-folio-assembly'],true))return;
    wp_enqueue_script('lps-manager',plugins_url('../assets/folio-manager.js',__FILE__),[],(string)filemtime(__DIR__.'/../assets/folio-manager.js'),true);
    $columns=[];foreach(array_keys(lps_manager_screen_labels($page==='lps-folio-assembly')) as $op)$columns[$op]=lps_manager_columns($op);
    wp_localize_script('lps-manager','lpsManager',['url'=>admin_url('admin-ajax.php'),'columns'=>$columns,
        'busy'=>__('Processing…','lps-manager'),'error'=>__('Could not process the operation. Check the input and Java connection. After Apply, check status before repeating.','lps-manager'),
        'headers'=>[__('Article','lps-manager'),__('Warehouse','lps-manager'),__('Field','lps-manager'),__('Old value','lps-manager'),__('New value','lps-manager')],
        'missing'=>__('Articles without existing target rows','lps-manager'),'clear'=>__('NULL / empty','lps-manager'),
        'scopes'=>['NAME'=>__('Changes names on all warehouses.','lps-manager'),'GROUPS'=>__('Replaces all six group fields on all warehouses. Empty cells clear groups.','lps-manager'),'UNIT'=>__('Changes units on all warehouses.','lps-manager'),
            'DESCRIPTION'=>__('Adds missing articles to the common description table and updates descriptions for existing articles.','lps-manager'),
            'BASE'=>__('Read only. Duplicate input rows and unmatched NULL/NULL output rows are retained.','lps-manager'),
            'ASSEMBLY'=>__('Accepts the full Access input table; the simplified manager input is still being clarified. Calculates three result tables, without creating a Folio invoice. Ambiguous source matches stop the calculation.','lps-manager')],
        'states'=>lps_manager_states(),
        'issues'=>[]]);
});
function lps_manager_java(string $action,array $payload): array {
    $response=lps_java_post('/admin/folio/manager/'.$action,$payload,['timeout'=>200]);
    if(is_wp_error($response))throw new RuntimeException('TRANSPORT');
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if(is_array($data)&&isset($data['error'])&&in_array($data['error'],['ASSEMBLY_12_AMBIGUOUS','ASSEMBLY_2_AMBIGUOUS','ASSEMBLY_DUPLICATE_CHILD','INVALID_COLUMNS','INVALID_VALUE','DUPLICATE_ARTICLE','SLOG_REQUIRED','TOO_MANY_RESULTS'],true))throw new RuntimeException($data['error']);
    if(wp_remote_retrieve_response_code($response)!==200||!is_array($data)||($data['sourceDatabase']??'')!=='Paint_Ua'
        ||!in_array($data['status']??'',['PREVIEW','READY','BLOCKED','APPLIED','APPLYING','UNKNOWN','STALE','EXPIRED','EMPTY'],true)
        ||!isset(lps_manager_labels()[$data['operation']??''])||!is_array($data['input']??null)||count($data['input'])>500
        ||!is_array($data['before']??null)||count($data['before'])>5000||!is_array($data['outputs']??null)||count($data['outputs'])>4
        ||!is_string($data['token']??null))throw new RuntimeException('SERVICE');
    foreach($data['outputs'] as $table)if(!is_array($table['columns']??null)||!is_array($table['rows']??null)||count($table['rows'])>5000)throw new RuntimeException('SERVICE');
    return $data;
}
function lps_manager_public(array $job): array {
    $data=$job['result'];unset($data['token']);$data['status']=$job['status'];$data['job']=$job['id'];$data['filename']=$job['filename'];$data['created']=$job['created'];
    $data['download']=wp_nonce_url(add_query_arg(['action'=>'lps_manager_export','job'=>$job['id']],admin_url('admin-post.php')),LPS_MANAGER_NONCE);
    return $data;
}
function lps_manager_job(string $id): array {
    if(!preg_match('/^[a-f0-9-]{36}$/D',$id))throw new RuntimeException('JOB');
    $job=get_option('lps_manager_job_'.$id);
    if(!is_array($job)||$job['user']!==get_current_user_id())throw new RuntimeException('JOB');return $job;
}
function lps_manager_error(string $code): string {
    if(str_starts_with($code,'ASSEMBLY_'))return __('Assembly has ambiguous source matches or duplicate output articles. Check composition links, warehouse 7 prices and repeated child articles.','lps-manager');
    if(preg_match('/^(ROW_|DUPLICATE_)([0-9]+)$/D',$code,$m))return sprintf(__('Check input row %s: column count, value length, numeric format or duplicate article.','lps-manager'),$m[2]);
    if(in_array($code,['CSV_FORMAT','EXCEL_FORMULA','EXCEL_RANGE','ARTICLE_REQUIRED','SLOG_REQUIRED','INVALID_COLUMNS','INVALID_VALUE','DUPLICATE_ARTICLE','EMPTY_FILE','ONE_SOURCE'],true))return __('Check the displayed columns. Supply either a file or pasted cells, with no formulas or repeated update articles. For assembly, SLOG is required.','lps-manager');
    return __('Could not process the operation. Check the input and Java connection. After Apply, check status before repeating.','lps-manager');
}
add_action('wp_ajax_lps_manager',static function(){
    if(!current_user_can(LPS_CAP))wp_send_json_error(['message'=>__('Access denied.','lps-manager')],403);
    check_ajax_referer(LPS_MANAGER_NONCE,'_wpnonce');$lock='';$job=null;$error=null;
    try {
        $action=$_POST['operation']??'';
        if($action==='preview') {
            $kind=$_POST['kind']??'';if(!is_string($kind))throw new RuntimeException('OPERATION');lps_manager_columns($kind);
            $paste=wp_unslash($_POST['paste']??'');if(!is_string($paste))throw new RuntimeException('INPUT');
            $file=$_FILES['file']??[];$hasFile=($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
            if($hasFile&&trim($paste)!=='')throw new RuntimeException('ONE_SOURCE');
            if($hasFile) {
                if(($file['error']??-1)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_string($file['name']??null))throw new RuntimeException('UPLOAD');
                $rows=lps_manager_file($file['tmp_name'],strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)),$kind);
                $name=sanitize_file_name($file['name']);$sha=hash_file('sha256',$file['tmp_name']);
            }else{$rows=lps_manager_text($paste,$kind);$name=__('Pasted cells','lps-manager');$sha=hash('sha256',$paste);}
            $result=lps_manager_java('preview',['operation'=>$kind,'rows'=>$rows]);$id=wp_generate_uuid4();
            $job=['id'=>$id,'user'=>get_current_user_id(),'created'=>current_time('mysql'),'filename'=>$name,'sha256'=>$sha,'status'=>$result['status'],'result'=>$result];
            if(!add_option('lps_manager_job_'.$id,$job,'',false))throw new RuntimeException('STORAGE');
            $history=(array)get_option('lps_manager_history_'.get_current_user_id(),[]);$history[]=$id;update_option('lps_manager_history_'.get_current_user_id(),array_slice($history,-50),false);
        }else {
            $id=$_POST['job']??'';if(!is_string($id))throw new RuntimeException('JOB');$job=lps_manager_job($id);
            if($action==='apply'&&$job['status']==='PREVIEW') {
                if(($_POST['confirmed']??'')!=='1')throw new RuntimeException('CONFIRM');
                if(!function_exists('lavka_ecosystem_lock_acquire'))throw new RuntimeException('LOCK');
                $acquired=lavka_ecosystem_lock_acquire('lavka-price-sync','folio_manager','manual',__('Manager operations','lps-manager'),600,['job'=>$id]);
                if(empty($acquired['ok']))throw new RuntimeException('LOCK');$lock=(string)$acquired['token'];
                if(!add_option('lps_manager_claim_'.$id,time(),'',false))throw new RuntimeException('ALREADY_SENT');
                $job['status']='UNKNOWN';$job['applied_by']=get_current_user_id();if(!update_option('lps_manager_job_'.$id,$job,false))throw new RuntimeException('STORAGE');
                try{$job['result']=lps_manager_java('apply',['token'=>$job['result']['token'],'confirmed'=>true]);$job['status']=$job['result']['status'];}catch(Throwable $e){}
                update_option('lps_manager_job_'.$id,$job,false);
            }elseif($action==='status') {
                if(!in_array($job['status'],['APPLIED','STALE','EXPIRED','EMPTY','READY','BLOCKED'],true)) {
                    try{$remote=lps_manager_java('status',['token'=>$job['result']['token']]);$job['result']=$remote;
                        if($remote['status']!=='PREVIEW'||$job['status']==='PREVIEW')$job['status']=$remote['status'];
                    }catch(Throwable $e){if($job['status']==='PREVIEW')$job['status']='EXPIRED';}
                    update_option('lps_manager_job_'.$id,$job,false);
                }
            }elseif($action!=='apply')throw new RuntimeException('ACTION');
        }
    }catch(Throwable $e){$error=lps_manager_error($e->getMessage());}
    finally{if($lock!==''&&(!is_array($job)||!in_array($job['status'],['UNKNOWN','APPLYING'],true)))lavka_ecosystem_lock_release($lock);}
    if($error!==null)wp_send_json_error(['message'=>$error],400);wp_send_json_success(lps_manager_public($job));
});
