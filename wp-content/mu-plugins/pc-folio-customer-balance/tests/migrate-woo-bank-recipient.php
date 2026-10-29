<?php
/** Local integration for the explicit CLI migration. Original local option rows are restored byte-for-byte in finally. */
defined('ABSPATH') || exit;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local fixture only.');
require_once __DIR__.'/payment-fixture.php';
global $wpdb;
$checks=0;$names=['woocommerce_bacs_accounts','pc_folio_invoice_settings'];$original=[];
$assert=static function($ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
$read=static function()use($wpdb,$names){$rows=[];foreach($names as $name)$rows[$name]=$wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name=%s",$name),ARRAY_A);return $rows;};
$flush=static function()use($names){foreach($names as $name)wp_cache_delete($name,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');};
$write=static function(array $values)use($wpdb,$flush){foreach($values as $name=>$value){$serialized=maybe_serialize($value);$exists=$wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name=%s",$name));if($exists)$wpdb->update($wpdb->options,['option_value'=>$serialized],['option_name'=>$name],['%s'],['%s']);else $wpdb->insert($wpdb->options,['option_name'=>$name,'option_value'=>$serialized,'autoload'=>'off'],['%s','%s','%s']);}$flush();};
$directory='/private/tmp/woo-migration-fixture-'.wp_generate_uuid4();
if(!mkdir($directory,0700))throw new RuntimeException('Cannot create private local fixture directory');chmod($directory,0700);
$guard=$directory.'/block-external.php';
file_put_contents($guard, <<<'GUARD'
<?php
if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON', true);
WP_CLI::add_hook('after_wp_load', static function () {
    add_filter('pre_http_request', static function () { file_put_contents(getenv('PC_FOLIO_TEST_OUTPUT').'/unexpected-external-call','http',FILE_APPEND); return new WP_Error('blocked','Local fixture blocks HTTP'); }, PHP_INT_MAX, 3);
    add_filter('pre_wp_mail', static function () { file_put_contents(getenv('PC_FOLIO_TEST_OUTPUT').'/unexpected-external-call','mail',FILE_APPEND); return false; }, PHP_INT_MAX, 2);
});
GUARD
);
$run=static function(array $args,string $backup_dir='')use($directory,$guard):array{
    $command=array_merge([PHP_BINARY,'/usr/local/bin/wp','--path='.ABSPATH,'--require='.$guard,'eval-file',ABSPATH.'scripts/migrate-woo-bank-recipient.php'],$args);
    $env=getenv();$env['PC_FOLIO_TEST_OUTPUT']=$directory;$env['PC_FOLIO_PAYMENT_BACKUP_DIR']=$backup_dir?:$directory;
    $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,ABSPATH,$env);
    if(!is_resource($process))throw new RuntimeException('Cannot run local CLI fixture');
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$data=null;
    foreach(explode("\n",$stdout) as $line){$decoded=json_decode($line,true);if(is_array($decoded))$data=$decoded;}
    return ['exit'=>$exit,'data'=>$data,'error'=>$stderr];
};
$settings=['name'=>'Synthetic migration recipient','tax_id'=>'00000000','iban'=>'UA89'.str_repeat('0',25),'bank'=>'Synthetic migration bank','mfo'=>'000000','confirmed'=>true,'logo_id'=>73];
$complete=pc_payment_fixture_account($settings);$old=$complete;unset($old['tax_id'],$old['pc_folio_confirmed_hash']);
$base=['woocommerce_bacs_accounts'=>[$old],'pc_folio_invoice_settings'=>$settings];
$original=$read();
try {
    $write($base);$before=$read();$preview=$run(['preview']);
    $assert($preview['exit']===0 && ($preview['data']['ready']??false) && !empty($preview['data']['preview_hash']),'Exact-match preview supplies a review token');
    $assert($read()===$before && glob($directory.'/woo-recipient-*.json')===[],'Preview performs no option writes and creates no backup');
    $wrong=$run(['apply',str_repeat('0',64)]);
    $assert($wrong['exit']!==0 && $read()===$before,'Wrong preview token cannot change local payment settings');
    $public=$run(['apply',$preview['data']['preview_hash']],ABSPATH);
    $assert($public['exit']!==0 && $read()===$before,'Public-site backup location is rejected before mutation');
    $world=$directory.'/nonprivate';mkdir($world,0755);chmod($world,0755);
    $weak=$run(['apply',$preview['data']['preview_hash']],$world);
    $assert($weak['exit']!==0 && $read()===$before,'Nonprivate backup permissions are rejected before mutation');rmdir($world);
    $changed_legacy=$settings;$changed_legacy['logo_id']=74;$write(['pc_folio_invoice_settings'=>$changed_legacy]);$changed_before=$read();
    $stale=$run(['apply',$preview['data']['preview_hash']]);
    $assert($stale['exit']!==0 && $read()===$changed_before,'Changed preview source invalidates previously reviewed token without writes');
    foreach([
        [array_replace($old,['account_name'=>'Another recipient'])],
        [array_replace($old,['bank_name'=>'Another bank'])],
        [array_replace($old,['sort_code'=>'111111'])],
        [array_replace($old,['account_number'=>'Unexpected alternate account'])],
        [array_replace($old,['tax_id'=>'11111111'])],
        [$old,$old],[],
    ] as $ambiguous){
        $write(['woocommerce_bacs_accounts'=>$ambiguous,'pc_folio_invoice_settings'=>$settings]);$before=$read();$rejected=$run(['preview']);
        $assert($rejected['exit']!==0 && $read()===$before,'Ambiguous or mismatched destination cannot be migrated automatically');
    }
    $write($base);$before=$read();$preview=$run(['preview']);$applied=$run(['apply',$preview['data']['preview_hash']]);$after=$read();
    $assert($applied['exit']===0 && ($applied['data']['updated']??false),'Reviewed exact match applies successfully');
    $saved=maybe_unserialize($after['woocommerce_bacs_accounts']['option_value']);
    $assert($saved===[$complete] && array_diff_key($saved[0],['tax_id'=>true,'pc_folio_confirmed_hash'=>true])===$old,'Apply adds only tax ID and confirmation proof; all destination fields are unchanged');
    $assert($after['pc_folio_invoice_settings']===$before['pc_folio_invoice_settings'],'Migration keeps legacy invoice settings byte-for-byte for pre-deploy compatibility');
    $backups=glob($directory.'/woo-recipient-*.json');$assert(count($backups)===1 && (fileperms($backups[0])&0777)===0600,'One private fixture backup is created with owner-only access');
    $backup=json_decode(file_get_contents($backups[0]),true);
    $assert($backup['accounts']===[$old] && $backup['legacy']===$settings && $backup['new_accounts']===[$complete],'Private backup records only the synthetic before/after fixture');
    $repeat=$run(['apply',$preview['data']['preview_hash']]);
    $assert($repeat['exit']===0 && ($repeat['data']['already_ready']??false) && $read()===$after && count(glob($directory.'/woo-recipient-*.json'))===1,'Repeated apply is idempotent and does not create another backup');
    $assert(!file_exists($directory.'/unexpected-external-call'),'Migration never requests external HTTP or sends mail');
    echo "PASS: $checks explicit Woo migration checks; synthetic local options restored\n";
} finally {
    foreach($original as $name=>$row){if($row===null)$wpdb->delete($wpdb->options,['option_name'=>$name],['%s']);else $wpdb->update($wpdb->options,$row,['option_name'=>$name],['%s','%s'],['%s']);}
    $flush();
    foreach(glob($directory.'/*') as $path){if(is_file($path))unlink($path);elseif(is_dir($path))rmdir($path);}rmdir($directory);
    if($read()!==$original)throw new RuntimeException('Local option restore verification failed');
}
