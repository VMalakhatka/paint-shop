<?php
/** Actual executor/repository, in-memory SQL and API boundaries; no network or WordPress required. */
namespace Paint\CheckboxFiscalization\Infrastructure {
    final class ApiClient {
        public int $sends = 0;
        public string $outcome = 'success';
        public string $shift = 'OPENED';
        public int $opens = 0;
        public function safeMessage($s): string { return $s; }
        public function signIn(): array { return ['ok'=>true,'access_token'=>'fixture']; }
        public string $resolved = 'OPENED';
        public string $register = 'fixture-register';
        public function cashierProfile($token): array { return ['ok'=>true,'body'=>['id'=>'fixture-cashier','is_test'=>true,'organization'=>['id'=>'org']]]; }
        public function registerInfo($token): array { return ['ok'=>true,'body'=>['id'=>$this->register,'is_test'=>true,'organization_id'=>'org']]; }
        public function environment($body): string { return 'test'; }
        public function activeShift($token): array {
            if ($this->shift === 'lookup_error') return ['ok'=>false,'http_code'=>503,'error_code'=>'unavailable','error_message'=>'lookup unavailable'];
            return ['ok'=>true,'body'=>['id'=>'fixture-shift','status'=>$this->shift,'cash_register'=>['id'=>'fixture-register']]];
        }
        public function openShift($token,$id): array {
            $this->opens++;
            if ($this->outcome === 'shift_rejected') return ['ok'=>false,'http_code'=>400,'error_code'=>'basebad_request','error_message'=>'Signing service is unavailable.','transport_error'=>false];
            if ($this->outcome === 'shift_timeout') return ['ok'=>false,'http_code'=>0,'error_code'=>'transport_error','error_message'=>'timeout','transport_error'=>true];
            return ['ok'=>true,'http_code'=>202];
        }
        public function getShift($token,$id): array {
            if ($this->resolved === '404') return ['ok'=>false,'http_code'=>404,'error_code'=>'not_found','error_message'=>'not found'];
            return ['ok'=>true,'body'=>['id'=>$id,'status'=>$this->resolved,'cash_register'=>['id'=>'fixture-register']]];
        }
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
    public function get_error_data() { return $this->data; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
$options=[]; $optionWriteFails=false;
function get_option($name,$default=false) { global $options; return $name === 'pccf_settings' ? ['shift_policy'=>'open_if_missing'] : ($options[$name] ?? $default); }
function update_option($name,$value,$autoload=false) { global $options,$optionWriteFails; if($optionWriteFails) return false; $options[$name]=$value; return true; }
function wp_generate_uuid4() { return '22345678-1234-4234-8234-123456789012'; }
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
foreach(['Config','Domain/ValidationException','Domain/CommandValidator','Infrastructure/OperationRepository','Domain/ShiftManager','Domain/FiscalizationService'] as $f) require __DIR__.'/../src/'.$f.'.php';
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
$command['operation_key']='offline:shift:SALE:v1'; $command['receipt_id']='32345678-1234-4234-8234-123456789012';
$api->shift='lookup_error';
$error=$service->execute($command,'fiscalize');
check($error->get_error_code()==='unavailable' && $api->opens===0, 'failed lookup never opens shift');
check(str_contains($service->operation($command['operation_key'])['error_message'],'shift.lookup'), 'lookup stage persisted');
$api->shift='CLOSED'; $api->outcome='shift_rejected';
$error=$service->execute($command,'fiscalize');
$saved=$service->operation($command['operation_key']);
check($error->get_error_code()==='basebad_request' && $api->sends===1, 'rejected shift never sends sale');
check($saved['status']==='SHIFT_FAILED' && $saved['http_code']===400 && str_contains($saved['error_message'],'[shift.open] Signing service'), '400 reason and stage durable');
check($saved['shift_id']!=='', 'shift attempt ID retained');
$api->outcome='shift_timeout';
$error=$service->execute($command,'fiscalize');
check($error->get_error_code()==='transport_error', 'opening timeout explicit');
check(array_values($options)[0]['status']==='PENDING', 'opening timeout durable in shared register journal');
$opens=$api->opens; $api->outcome='success';
check($service->execute($command,'fiscalize')['status']==='SUCCEEDED' && $api->opens===$opens, 'saved opening reconciled without another POST');
$manager=new \Paint\CheckboxFiscalization\Domain\ShiftManager(new \Paint\CheckboxFiscalization\Config(),$api,$repository);
$api->shift='CLOSED'; $api->resolved='CREATED'; $options=[];
$preview=$manager->preview();
check($api->opens===$opens && $preview['environment']==='test', 'preview never opens a shift');
check($manager->openConfirmed('wrong')->get_error_code()==='shift_preview_changed' && $api->opens===$opens, 'stale preview cannot open');
$optionWriteFails=true;
check($manager->openConfirmed($preview['fingerprint'])->get_error_code()==='shift_storage_error' && $api->opens===$opens, 'journal failure prevents POST');
$optionWriteFails=false;
check($manager->openConfirmed($preview['fingerprint'])->get_error_code()==='shift_open_pending' && $api->opens===$opens+1, 'confirmed manual opening pending');
check($manager->ensure('fixture',true)->get_error_code()==='shift_open_pending' && $api->opens===$opens+1, 'automatic sale shares manual pending journal');
$api->resolved='404';
check($manager->openConfirmed($preview['fingerprint'])->get_error_code()==='not_found' && $api->opens===$opens+1, 'missing saved shift never resends');
$api->resolved='CLOSED';
check($manager->openConfirmed($preview['fingerprint'])->get_error_code()==='shift_open_failed' && $api->opens===$opens+1, 'closed failed opening requires operator review');
$api->resolved='OPENED';
check($manager->openConfirmed($preview['fingerprint'])['status']==='OPENED' && $api->opens===$opens+1, 'manual late success reconciles');
$options=[]; $api->shift='OPENED'; $api->register='different';
check($manager->ensure('fixture',true)->get_error_code()==='shift_register_mismatch' && $api->opens===$opens+1, 'wrong register shift rejected');
$api->register='fixture-register'; $api->shift='CLOSED';
check($manager->ensure('fixture',false)->get_error_code()==='open_shift_required' && $api->opens===$opens+1, 'require-open policy does not open');
$wpdb->deny=true;
check($manager->ensure('fixture',true)->get_error_code()==='shift_busy', 'shared shift lock prevents concurrent opens');
echo "Passed $checks executor safety checks (offline boundaries).\n";
}
