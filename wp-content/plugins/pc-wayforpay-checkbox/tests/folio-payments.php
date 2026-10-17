<?php
/** Offline: real Bridge signature verification and Folio worker, fake database/HTTP/Woo boundaries. */
if (PHP_SAPI !== 'cli') exit;
define('ARRAY_A', 'ARRAY_A');
define('PC_WFP_CHECKBOX_MODE', 'live'); define('PC_WFP_MERCHANT_MODE', 'live');
define('PC_WFP_CHECKBOX_TAX_CODE', 8); define('PC_WFP_PRRO_DISABLED_CONFIRMED', true);
define('PC_WFP_CHECKBOX_START_AT', '2026-01-01T00:00:00Z');
define('PC_WFP_FOLIO_MODE', $argv[1] ?? 'live'); define('PC_WFP_FOLIO_START_AT', '2026-01-01T00:00:00Z');
define('PC_WFP_FOLIO_SOURCE_SCOPE', 'fixture'); define('PC_GUEST_CUSTOMER_FOLIO_ID', 'ИНЕТ КЛ');
class WP_Error {
    public function __construct(private $code, private $message='', private $data=[]) {}
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); }
function __($v, $domain='') { return $v; }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($v)); }
function get_option($key, $default=[]) { return $key === 'woocommerce_wayforpay_settings' ? ['merchant_account'=>'fixture','secret_key'=>'offline-secret'] : $default; }
function get_user_meta($id,$key,$single) { return $GLOBALS['payer']; }
function current_user_can($cap) { return $GLOBALS['manage']; }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
function wp_generate_uuid4() { return '12345678-1234-4234-8234-123456789012'; }
function as_schedule_single_action(...$args) { return 1; }
function apply_filters($hook,$value,...$args) { return $GLOBALS['folio']->payerSnapshot($value,...$args); }
function do_action($hook,...$args) {
    if ($hook === 'pc_wfp_verified_payment_saved') $GLOBALS['folio']->capture(...$args);
    if ($hook === 'pc_wfp_verified_refund_saved') $GLOBALS['folio']->refund(...$args);
}
class WC_Order {
    public $meta=[], $notes=[], $paid=null, $paidCalls=0, $created='2026-10-16T00:00:00Z';
    public function __construct(public $id) {}
    public function get_id() { return $this->id; }
    public function get_user_id() { return 1; }
    public function get_type() { return 'shop_order'; }
    public function get_payment_method() { return 'wayforpay'; }
    public function get_total() { return '4.00'; }
    public function get_currency() { return 'UAH'; }
    public function get_status() { return 'pending'; }
    public function get_date_created() { return new DateTimeImmutable($this->created); }
    public function get_date_paid() { return $this->paid; }
    public function is_paid() { return $this->paid !== null; }
    public function get_meta($k,...$args) { return $this->meta[$k] ?? ''; }
    public function update_meta_data($k,$v) { $this->meta[$k]=$v; }
    public function save() {}
    public function payment_complete($ref) { $this->paidCalls++; $this->paid=new DateTimeImmutable(); }
    public function add_order_note($v) { $this->notes[]=$v; }
    public function get_items($types) { return []; } // Receipt mapping fails deliberately; bank income must still work.
}
class DB {
    public $prefix='test_', $tables=[], $locks=[], $failInsert=false, $failSent=false;
    public function prepare($sql,...$args) { return [$sql,$args]; }
    public function get_var($q) {
        [$sql,$args]=$q; $key=$args[0];
        if (str_contains($sql,'RELEASE_LOCK')) { unset($this->locks[$key]); return 1; }
        if (isset($this->locks[$key])) return 0;
        $this->locks[$key]=true; return 1;
    }
    public function get_row($q,$mode) {
        preg_match('/FROM (\w+)/',$q[0],$m); return $this->tables[$m[1]][$q[1][0]] ?? null;
    }
    public function insert($table,$data) {
        if ($this->failInsert && str_contains($table,'folio')) return false;
        $id=$data['order_id']; if (isset($this->tables[$table][$id])) return false;
        $this->tables[$table][$id]=$data+['payment_applied'=>0,'refunded'=>0,'attempts'=>0,'next_run'=>0,'sent'=>0,'payment_id'=>0,'payment_number'=>0]; return 1;
    }
    public function update($table,$data,$where) {
        if ($this->failSent && !empty($data['sent'])) return false;
        $id=$where['order_id']; $this->tables[$table][$id]=array_merge($this->tables[$table][$id],$data); return 1;
    }
}
foreach (['Protocol','Command','Store','Bridge','FolioStore','FolioCommand','FolioClient','FolioPayments'] as $file) require __DIR__.'/../src/'.$file.'.php';
class Client extends Paint\WayForPayCheckbox\FolioClient {
    public $calls=[], $ledger=[], $timeout=false, $persist=true, $tamper=false, $previewFail=false;
    public function call(string $method,string $path,?array $body=null): array {
        $this->calls[]=[$method,$path,$body];
        if ($method==='GET') return isset($this->ledger[$path]) ? ['http'=>200,'code'=>'','data'=>$this->ledger[$path]] : ['http'=>404,'code'=>'payment_not_found'];
        if ($path==='preview' && $this->previewFail) return ['http'=>409,'code'=>'payment_payer_missing'];
        $r=['status'=>$path==='preview'?'READY':'SUCCEEDED','operationKey'=>$body['operationKey'],
            'requestHash'=>Paint\WayForPayCheckbox\FolioCommand::hash($body),'amountCents'=>$body['amountCents'],
            'paymentWarehouse'=>5,'revision'=>str_repeat('a',64),'paymentId'=>100+$body['orderId'],'paymentNumber'=>400+$body['orderId']];
        if ($this->tamper) $r['amountCents']=1;
        if ($path==='apply') {
            if ($this->persist) $this->ledger[$body['operationKey']]=$r;
            if ($this->timeout) return ['http'=>0,'code'=>'folio_transport_unknown'];
        }
        return ['http'=>200,'code'=>'','data'=>$r];
    }
    public function writes(): int { return count(array_filter($this->calls,fn($r)=>$r[1]==='apply')); }
}
$GLOBALS['wpdb']=new DB(); $GLOBALS['payer']='ИНЕТ КЛ'; $GLOBALS['manage']=true; $GLOBALS['orders']=[];
$client=new Client(); $GLOBALS['folio']=$folio=new Paint\WayForPayCheckbox\FolioPayments($client); $bridge=new Paint\WayForPayCheckbox\Bridge();
$checks=0;
function ok($v,$label) { global $checks; if (!$v) throw new RuntimeException('FAIL '.$label); $checks++; }
function row($id) { return $GLOBALS['wpdb']->tables['test_pc_wfp_folio_payments'][$id]??null; }
function ready($id) { $GLOBALS['wpdb']->tables['test_pc_wfp_folio_payments'][$id]['next_run']=0; }
function signed($id,$status='Approved') {
    $b=['merchantAccount'=>'fixture','orderReference'=>$id.'_woo_w4p_1792000000','amount'=>'4.00','currency'=>'UAH','authCode'=>'','cardPan'=>'','transactionStatus'=>$status,'reasonCode'=>1100];
    $b['merchantSignature']=hash_hmac('md5',implode(';',array_map(fn($k)=>(string)$b[$k],Paint\WayForPayCheckbox\Protocol::FIELDS)),'offline-secret'); return $b;
}
function order($id) { return $GLOBALS['orders'][$id]=new WC_Order($id); }
order(1); $bad=signed(1); $bad['amount']='5.00'; $bridge->accept($bad);
ok(row(1)===null,'unsigned/tampered callback cannot queue income');
$bridge->accept(signed(1,'Declined')); ok(row(1)===null,'decline cannot queue income');
ok(!is_wp_error($bridge->accept(signed(1))),'Approved accepted');
if (PC_WFP_FOLIO_MODE==='off') { ok(row(1)===null && $client->writes()===0,'default off no outbox or HTTP'); echo "PASS $checks Folio off checks\n"; exit; }
ok(row(1)['status']==='QUEUED','verified income queued despite receipt mapping failure/no invoice');
$before=row(1)['command']; $GLOBALS['payer']='OTHER'; $bridge->accept(signed(1));
ok(row(1)['command']===$before && json_decode($before,true)['payerKey']==='ИНЕТ КЛ','payer and command frozen despite reassignment');
$folio->work(1);
if (PC_WFP_FOLIO_MODE==='preview') { ok(row(1)['status']==='PREVIEW' && $client->writes()===0,'preview cannot apply'); $folio->retry($GLOBALS['orders'][1]); ok(row(1)['status']==='PREVIEW','manual action cannot promote preview'); echo "PASS $checks Folio preview checks\n"; exit; }
ok(row(1)['status']==='SUCCEEDED' && $client->writes()===1,'independent bank income created');
$folio->work(1); $folio->retry($GLOBALS['orders'][1]); $bridge->accept(signed(1));
ok($client->writes()===1 && $GLOBALS['orders'][1]->paidCalls===1,'duplicate jobs/callbacks/manual actions do not duplicate income/payment_complete');
$o=order(2); $o->meta['_folio_documents_result']=['documents'=>[['document_id'=>1],['document_id'=>2]]];
$bridge->accept(signed(2)); $client->timeout=true; $folio->work(2); ok(row(2)['status']==='RECONCILE','timeout reconciles');
$writes=$client->writes(); ready(2); $folio->work(2); ok(row(2)['status']==='SUCCEEDED' && $writes===$client->writes(),'lost response reconciles same key; multiple invoices irrelevant');
order(3); $bridge->accept(signed(3)); $client->persist=false; $folio->work(3); ready(3); $folio->work(3);
ok(row(3)['status']==='REVIEW' && row(3)['error_code']==='folio_unknown_outcome_not_found','404 after unknown apply stops; never blindly resends');
$writes=$client->writes(); $folio->retry($GLOBALS['orders'][3]); $folio->work(3); ok($writes===$client->writes(),'manual reconcile does not reset sent marker');
$client->timeout=false; $client->persist=true;
order(4); $bridge->accept(signed(4)); $bridge->accept(signed(4,'Refunded')); $folio->work(4);
ok(row(4)['status']==='REFUND_REVIEW' && row(4)['payment_id']>0,'refund preserves confirmed income and flags separate expense');
$bridge->accept(signed(1,'Refunded')); ok(row(1)['status']==='REFUND_REVIEW','refund after income cannot erase it');
order(5); $bridge->accept(signed(5)); $client->tamper=true; $writes=$client->writes(); $folio->work(5);
ok(row(5)['status']==='REVIEW' && $writes===$client->writes(),'mismatched preview cannot apply'); $client->tamper=false;
order(6); $GLOBALS['wpdb']->failInsert=true; ok(is_wp_error($bridge->accept(signed(6))),'outbox failure rejects ACK for retry');
ok($GLOBALS['orders'][6]->paidCalls===0,'capture persists before Woo transition');
$GLOBALS['payer']='NEW'; $GLOBALS['wpdb']->failInsert=false; $bridge->accept(signed(6));
ok(json_decode(row(6)['command'],true)['payerKey']==='OTHER','crash recovery uses Approved-time payer snapshot, not current user');
order(7); $bridge->accept(signed(7)); $GLOBALS['wpdb']->failSent=true; $writes=$client->writes(); $folio->work(7);
ok($client->writes()===$writes,'failure persisting sent marker prevents HTTP apply'); $GLOBALS['wpdb']->failSent=false;
order(8)->meta['_folio_manual_payment_recovery']=['payment_id'=>1]; $bridge->accept(signed(8));
ok(row(8)['status']==='REVIEW','manual historical recovery cannot be booked again');
order(9)->created='2025-12-01'; $bridge->accept(signed(9)); ok(row(9)===null,'historical orders not enrolled');
order(10); $bridge->accept(signed(10,'Refunded')); ok(row(10)===null,'refund-first alone is not Approved evidence');
$bridge->accept(signed(10)); $folio->work(10); ok(row(10)['status']==='REFUND_REVIEW','later verified Approved records income, not duplicate Woo payment');
order(11); $bridge->accept(signed(11)); $GLOBALS['wpdb']->tables['test_pc_wfp_folio_payments'][11]['mode']='preview'; $folio->work(11);
ok(row(11)['status']==='REVIEW','queued modes cannot promote silently');
$GLOBALS['manage']=false; $folio->retry($GLOBALS['orders'][11]); ok(row(11)['status']==='REVIEW','manual action capability enforced');
define('PC_WFP_FOLIO_PAUSED',true); order(12); $bridge->accept(signed(12)); $writes=$client->writes(); $folio->work(12);
ok(row(12)['status']==='QUEUED' && $writes===$client->writes(),'pause retains intent without HTTP');
echo "PASS $checks Folio flow checks\n";
