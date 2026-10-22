<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/folio-currency-import.php';
const LPS_CURRENCY_NONCE = 'lps_currency_ua';
add_action('init', static function () { load_plugin_textdomain('lps-currency', false, dirname(plugin_basename(__DIR__.'/../lavka-price-sync.php')).'/languages'); });
add_action('admin_menu', static function () {
    add_submenu_page('lps-folio-cards', __('Currency price Ukraine', 'lps-currency'), __('Currency price Ukraine', 'lps-currency'), LPS_CAP, 'lps-currency-ua', 'lps_currency_render');
});
function lps_currency_render(): void {
    if (!current_user_can(LPS_CAP)) wp_die(esc_html__('Access denied.', 'lps-currency'));
    echo '<div class="wrap"><h1>'.esc_html__('Currency price Ukraine', 'lps-currency').'</h1>';
    echo '<p>'.esc_html__('Updates Folio Paint_Ua: CENA_VALT and COD_VALT on all warehouses, then RUB_PRICE for ЯЯ_Р_ВАЛЮТ on warehouse 7. Both updates are saved together.', 'lps-currency').'</p>';
    echo '<details><summary>'.esc_html__('How to import prices', 'lps-currency').'</summary><ol>';
    foreach ([__('Choose XLS, XLSX or UTF-8 CSV (up to 5 MB, 500 articles). The first Excel sheet is used.', 'lps-currency'),
        __('Use three columns in this order: CODE_ARTIC, PRICE, KOD_VALUT. The header is optional. Decimal comma and decimal point are supported.', 'lps-currency'),
        __('CSV separators: semicolon, tab or comma. Quote prices containing a comma when the separator is also a comma. Formulas and duplicate articles are rejected.', 'lps-currency'),
        __('Preview all target rows and missing articles. Missing cards or prices are skipped, as in Access; no rows are created.', 'lps-currency'),
        __('Confirm the preview and apply within 15 minutes. If target values changed, create a new preview. After a timeout, check this operation status; do not submit the file again until the outcome is known.', 'lps-currency')] as $line) echo '<li>'.esc_html($line).'</li>';
    echo '</ol></details><form id="lps-currency-form"><p><input name="file" type="file" accept=".xls,.xlsx,.csv" required aria-label="'.esc_attr__('Price file', 'lps-currency').'"></p>';
    wp_nonce_field(LPS_CURRENCY_NONCE);
    echo '<button class="button button-primary" type="submit">'.esc_html__('Preview changes', 'lps-currency').'</button></form>';
    echo '<p id="lps-currency-status" role="status" aria-live="polite"></p><div id="lps-currency-result"></div>';
    echo '<p><label><input id="lps-currency-confirm" type="checkbox" disabled> '.esc_html__('I checked the rows and confirm both updates in Folio.', 'lps-currency').'</label></p><p><button id="lps-currency-apply" class="button button-primary" disabled>'.esc_html__('Apply to Folio', 'lps-currency').'</button> <button id="lps-currency-check" class="button" disabled>'.esc_html__('Check status', 'lps-currency').'</button></p>';
    echo '<h2>'.esc_html__('Recent operations', 'lps-currency').'</h2><ul id="lps-currency-history">';
    foreach (array_reverse((array)get_option('lps_currency_history_'.get_current_user_id(), [])) as $id) {
        $job=get_option('lps_currency_job_'.$id); if (!is_array($job)) continue;
        echo '<li><button type="button" class="button-link lps-currency-history" data-job="'.esc_attr($id).'">'.esc_html($job['created'].' — '.$job['filename'].' — '.$job['status']).'</button></li>';
    }
    echo '</ul></div>';
}
add_action('admin_enqueue_scripts', static function () {
    if (($_GET['page'] ?? '') !== 'lps-currency-ua') return;
    wp_enqueue_script('lps-currency', plugins_url('../assets/folio-currency.js', __FILE__), [], (string)filemtime(__DIR__.'/../assets/folio-currency.js'), true);
    wp_localize_script('lps-currency', 'lpsCurrency', ['url'=>admin_url('admin-ajax.php'),
        'busy'=>__('Processing…', 'lps-currency'), 'error'=>__('The request failed. For an apply request, check status before taking another action.', 'lps-currency'),
        'cards'=>__('Card rows', 'lps-currency'), 'retail'=>__('Retail price rows', 'lps-currency'),
        'missingCards'=>__('Articles without cards', 'lps-currency'), 'missingRetail'=>__('Articles without the selected retail price', 'lps-currency'),
        'columns'=>[__('Table', 'lps-currency'),__('Article', 'lps-currency'),__('Warehouse', 'lps-currency'),__('Row ID', 'lps-currency'),__('Old price', 'lps-currency'),__('New price', 'lps-currency'),__('Old currency', 'lps-currency'),__('New currency', 'lps-currency')],
        'states'=>['PREVIEW'=>__('Ready for confirmation', 'lps-currency'), 'APPLIED'=>__('Both updates saved and verified.', 'lps-currency'), 'APPLYING'=>__('The operation is running. Check status.', 'lps-currency'),
            'UNKNOWN'=>__('Outcome unknown. Check status and ask the administrator to verify Folio before repeating.', 'lps-currency'), 'STALE'=>__('Target rows changed. Nothing was applied; create a new preview.', 'lps-currency'),
            'EXPIRED'=>__('Preview expired. Create a new preview.', 'lps-currency'), 'EMPTY'=>__('No target rows.', 'lps-currency')]]);
});
function lps_currency_java(string $action, array $payload): array {
    $response=lps_java_post('/admin/folio/currency-ua/'.$action,$payload,['timeout'=>200]);
    if (is_wp_error($response)) throw new RuntimeException('TRANSPORT');
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if (wp_remote_retrieve_response_code($response)!==200 || !is_array($data) || ($data['sourceDatabase'] ?? '')!=='Paint_Ua'
        || !in_array($data['status'] ?? '',['PREVIEW','APPLIED','APPLYING','UNKNOWN','STALE','EXPIRED','EMPTY'],true)
        || !is_array($data['input'] ?? null) || !is_array($data['before'] ?? null) || count($data['before'])>5000
        || !is_string($data['token'] ?? null)) throw new RuntimeException('SERVICE');
    return $data;
}
function lps_currency_public(array $job): array {
    $data=$job['result']; unset($data['token']);$data['status']=$job['status'];$data['job']=$job['id'];$data['filename']=$job['filename'] ?? '';$data['created']=$job['created'] ?? '';return $data;
}
add_action('wp_ajax_lps_currency', static function () {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['message'=>__('Access denied.', 'lps-currency')],403);
    check_ajax_referer(LPS_CURRENCY_NONCE,'_wpnonce');
    $lock='';$job=null;$error=null;
    try {
        $action=$_POST['operation'] ?? '';
        if ($action==='preview') {
            $file=$_FILES['file'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
                || !is_uploaded_file($file['tmp_name']) || !is_string($file['name'] ?? null)) throw new RuntimeException('UPLOAD');
            $rows=lps_currency_file($file['tmp_name'],strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)));
            $result=lps_currency_java('preview',['rows'=>$rows]);$id=wp_generate_uuid4();
            $job=['id'=>$id,'user'=>get_current_user_id(),'created'=>current_time('mysql'),'filename'=>sanitize_file_name($file['name']),
                'sha256'=>hash_file('sha256',$file['tmp_name']),'status'=>$result['status'],'result'=>$result];
            if (!add_option('lps_currency_job_'.$id,$job,'',false)) throw new RuntimeException('STORAGE');
            $history=(array)get_option('lps_currency_history_'.get_current_user_id(),[]);$history[]=$id;
            update_option('lps_currency_history_'.get_current_user_id(),array_slice($history,-50),false);
        } else {
            $id=$_POST['job'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/D',$id)) throw new RuntimeException('JOB');
            $job=get_option('lps_currency_job_'.$id);
            if (!is_array($job) || $job['user']!==get_current_user_id()) throw new RuntimeException('JOB');
            if ($action==='apply' && $job['status']==='PREVIEW') {
                if (($_POST['confirmed'] ?? '')!=='1') throw new RuntimeException('CONFIRM');
                if (!function_exists('lavka_ecosystem_lock_acquire')) throw new RuntimeException('LOCK');
                $acquired=lavka_ecosystem_lock_acquire('lavka-price-sync','currency_ua','manual',__('Currency price Ukraine', 'lps-currency'),600,['job'=>$id]);
                if (empty($acquired['ok'])) throw new RuntimeException('LOCK');$lock=(string)$acquired['token'];
                // Durable one-use claim: concurrent browser requests cannot launch a second apply.
                if (!add_option('lps_currency_claim_'.$id,time(),'',false)) throw new RuntimeException('ALREADY_SENT');
                $job['status']='UNKNOWN';$job['applied_by']=get_current_user_id();if (!update_option('lps_currency_job_'.$id,$job,false)) throw new RuntimeException('STORAGE');
                try {$job['result']=lps_currency_java('apply',['token'=>$job['result']['token'],'confirmed'=>true]);$job['status']=$job['result']['status'];}
                catch(Throwable $e) { /* Persist UNKNOWN and allow status reads only. */ }
                update_option('lps_currency_job_'.$id,$job,false);
            } elseif ($action==='status') {
                // A completed audit survives service restart; a missing token never authorizes replay.
                if (!in_array($job['status'],['APPLIED','STALE','EXPIRED','EMPTY'],true)) {
                    try {
                        $remote=lps_currency_java('status',['token'=>$job['result']['token']]);
                        $job['result']=$remote;
                        if ($remote['status']!=='PREVIEW' || $job['status']==='PREVIEW') $job['status']=$remote['status'];
                    } catch(Throwable $e) { if($job['status']==='PREVIEW')$job['status']='EXPIRED'; }
                    update_option('lps_currency_job_'.$id,$job,false);
                }
            } elseif ($action!=='apply') throw new RuntimeException('ACTION');
        }
    } catch(Throwable $e) {
        $error=__('Could not process the file or operation. Check the three columns, file limits, duplicate articles, Java connection and whether another synchronization is running.', 'lps-currency');
    } finally { if($lock!=='' && (!is_array($job) || !in_array($job['status'],['UNKNOWN','APPLYING'],true))) lavka_ecosystem_lock_release($lock); }
    if($error!==null)wp_send_json_error(['message'=>$error],400);
    wp_send_json_success(lps_currency_public($job));
});
