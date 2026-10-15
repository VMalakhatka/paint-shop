<?php
/** Actual executor/repository, in-memory SQL and API boundaries; no network or WordPress required. */
namespace Paint\CheckboxFiscalization\Infrastructure {
    final class ApiClient {
        public int $sends = 0;
        public string $outcome = 'success';
        public function signIn(): array { return ['ok'=>true,'access_token'=>'fixture']; }
        public function cashierProfile($token): array { return ['ok'=>true,'body'=>['is_test'=>true]]; }
        public function environment($body): string { return 'test'; }
        public function activeShift($token): array { return ['ok'=>true,'body'=>['id'=>'fixture-shift','status'=>'OPENED']]; }
        public function createReceipt($token,$command): array {
            $this->sends++;
            if ($this->outcome==='timeout') return ['ok'=>false,'transport_error'=>true,'http_code'=>0,'error_code'=>'transport_error','error_message'=>'fixture'];
            return ['ok'=>true,'http_code'=>200,'body'=>['id'=>$command['receipt_id'],'status'=>'DONE','fiscal_code'=>'TEST']];
        }
        public function getReceipt($token,$id): array {
            if ($this->outcome==='not_found') return ['ok'=>false,'http_code'=>404];
            return ['ok'=>true,'http_code'=>200,'body'=>['id'=>$id,'status'=>'DONE','fiscal_code'=>'TEST']];
        }
    }
}
namespace {
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH',__DIR__.'/'); define('ARRAY_A','ARRAY_A'); define('PCCF_VERSION','test');
define('PC_CHECKBOX_ALLOW_FISCALIZATION',true); define('PC_CHECKBOX_ALLOW_LIVE',false);
define('PC_CHECKBOX_LICENSE_KEY','fixture'); define('PC_CHECKBOX_CASHIER_PIN','fixture');
class WP_Error {
    public function __construct(private $code,private $message='',private $data=[]) {}
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function get_option($name,$default=false) { return $default; }
function wp_parse_args($a,$d) { return array_merge($d,$a); }
function wp_json_encode($v,$flags=0) { return json_encode($v,$flags); }
function current_time(...$args) { return gmdate('Y-m-d H:i:s'); }
function do_action(...$args) {}
function is_email($v) { return filter_var($v,FILTER_VALIDATE_EMAIL)!==false; }
class DB {
    public $prefix='fixture_', $rows=[], $locks=[], $deny=false, $failUpdate=false;
    public function prepare($sql,...$args) { return [$sql,$args]; }
    public function get_var($q) {
        [$sql,$a]=$q;
        if(str_contains($sql,'RELEASE_LOCK')) { unset($this->locks[$a[0]]); return 1; }
        if($this->deny||isset($this->locks[$a[0]])) return 0;
        $this->locks[$a[0]]=true; return 1;
    }
    public function query($q) {
        [$sql,$a]=$q;
        if(str_contains($sql,'INSERT IGNORE')) {
            if(isset($this->rows[$a[0]])) return 0;
            $keys=['operation_key','receipt_uuid','source_system','source_type','source_id','operation_type','request_hash','status','mode','total_cents','created_at','updated_at'];
            $this->rows[$a[0]]=array_combine($keys,$a)+['attempts'=>0]; return 1;
        }
        if(str_contains($sql,'attempts = attempts + 1')) { $this->rows[$a[2]]['attempts']++; return 1; }
        throw new \RuntimeException('Unexpected SQL');
    }
    public function get_row($q,$format) { return $this->rows[$q[1][0]]??null; }
    public function update($table,$data,$where) {
        if($this->failUpdate) return false;
        $key=$where['operation_key']; $this->rows[$key]=array_merge($this->rows[$key],$data); return 1;
    }
}
foreach(['Config','Domain/ValidationException','Domain/CommandValidator','Infrastructure/OperationRepository','Domain/FiscalizationService'] as $f) require __DIR__.'/../src/'.$f.'.php';
$wpdb=new DB(); $api=new \Paint\CheckboxFiscalization\Infrastructure\ApiClient();
$service=new \Paint\CheckboxFiscalization\Domain\FiscalizationService(new \Paint\CheckboxFiscalization\Config(),new \Paint\CheckboxFiscalization\Domain\CommandValidator(),new \Paint\CheckboxFiscalization\Infrastructure\OperationRepository(),$api);
$command=json_decode(file_get_contents(__DIR__.'/../docs/examples/folio-sale-command.json'),true);
$checks=0;
function check($ok,$label) { global $checks; if(!$ok) throw new \RuntimeException('FAIL: '.$label); $checks++; }
$wpdb->deny=true;
check($service->execute($command,'fiscalize')->get_error_code()==='operation_busy','concurrent execution rejected');
check($service->reconcile($command['operation_key'])->get_error_code()==='operation_busy','concurrent reconciliation rejected');
check($api->sends===0 && !$wpdb->rows,'busy executor never reserves or sends');
$wpdb->deny=false;
$repository=new \Paint\CheckboxFiscalization\Infrastructure\OperationRepository();
$repository->lock($command['operation_key']); $padded=$command; $padded['operation_key']=' '.$command['operation_key'].' ';
check($service->execute($padded,'fiscalize')->get_error_code()==='operation_busy','normalized keys share the same lock');
$repository->unlock($command['operation_key']); $wpdb->failUpdate=true;
check($service->execute($command,'fiscalize')->get_error_code()==='operation_processing_failed','PROCESSING persistence failure surfaces safely');
check($api->sends===0 && !$wpdb->locks,'failed PROCESSING write prevents external create and releases lock');
$wpdb->failUpdate=false; $api->outcome='timeout';
check($service->execute($command,'fiscalize')->get_error_code()==='reconciliation_required','timeout requires reconciliation');
check($wpdb->rows[$command['operation_key']]['status']==='UNCERTAIN','uncertain state durable');
check($service->execute($command,'fiscalize')->get_error_code()==='reconciliation_required' && $api->sends===1,'duplicate uncertain never sends again');
$api->outcome='not_found';
check($service->reconcile($command['operation_key'])->get_error_code()==='receipt_not_confirmed' && $api->sends===1,'404 never recreates receipt');
$api->outcome='success';
check($service->reconcile($command['operation_key'])['status']==='SUCCEEDED','reconciliation recovers late success');
check($service->execute($command,'fiscalize')['replayed']===true && $api->sends===1,'successful operation is replayed');
check(!$wpdb->locks,'all executor locks released');
$command['operation_key']='offline:preview:SALE:v1'; $command['receipt_id']='12345678-1234-4234-8234-123456789012';
check($service->execute($command,'preview')['status']==='PREVIEW' && $api->sends===1,'preview never sends to Checkbox');
echo "Passed $checks executor safety checks (offline boundaries).\n";
}
