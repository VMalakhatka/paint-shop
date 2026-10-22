<?php
/** Offline behavioral tests: actual adapter + storage SQL calls, fake Woo/DB/Checkbox boundaries. */
namespace Paint\CheckboxFiscalization {
    final class Plugin {
        public static function instance(): self { return new self(); }
        public function service(): object { return $GLOBALS['service']; }
    }
}
namespace {
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('PC_WFP_CHECKBOX_MODE', 'test');
define('PC_WFP_MERCHANT_MODE', 'test');
define('PC_WFP_CHECKBOX_TAX_CODE', 8);
define('PC_WFP_PRRO_DISABLED_CONFIRMED', true);
define('PC_WFP_CHECKBOX_START_AT', '2026-01-01T00:00:00Z');
class WP_Error {
    public function __construct(private $code, private $message = '', private $data = []) {}
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function is_email($v) { return filter_var($v, FILTER_VALIDATE_EMAIL); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($v)); }
function wp_salt($v) { return 'offline-only'; }
function __($v, $domain = '') { return $v; }
function do_action($hook, ...$args) {}
function apply_filters($hook, $value, ...$args) { return $value; }
function get_option($key, $default = false) { return $key === 'woocommerce_wayforpay_settings' ? ['merchant_account'=>'fixture','secret_key'=>'fixture-secret'] : $default; }
function current_user_can($cap) { return $GLOBALS['can_manage']; }
function wp_generate_uuid4() { return sprintf('12345678-1234-4234-8234-%012d', ++$GLOBALS['uuid']); }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
function as_schedule_single_action(...$args) { $GLOBALS['jobs'][] = $args; return count($GLOBALS['jobs']); }
function pc_checkbox_fiscalize($command, $mode) { return $GLOBALS['service']->execute($command, $mode); }
class Item {
    public function __construct(public $total='10.00', public $qty=1, public $type='line_item', public $tax='0.00') {}
    public function get_total() { return $this->total; }
    public function get_total_tax() { return $this->tax; }
    public function get_type() { return $this->type; }
    public function get_quantity() { return $this->qty; }
    public function get_product() { return false; }
    public function get_name() { return $this->type === 'shipping' ? 'Shipping' : 'Fixture product'; }
}
class WC_Order {
    public $total='10.00', $currency='UAH', $gateway='wayforpay', $status='pending', $datePaid=null, $meta=[], $notes=[], $paidCalls=0, $items, $email='buyer@example.test', $created='2026-10-15';
    public function __construct(public $id) { $this->items=[1=>new Item()]; }
    public function get_id() { return $this->id; }
    public function get_type() { return 'shop_order'; }
    public function get_currency() { return $this->currency; }
    public function get_total() { return $this->total; }
    public function get_items($types) { return $this->items; }
    public function get_payment_method() { return $this->gateway; }
    public function get_status() { return $this->status; }
    public function get_date_created() { return new \DateTimeImmutable($this->created); }
    public function get_date_paid() { return $this->datePaid; }
    public function is_paid() { return $this->datePaid !== null; }
    public function update_meta_data($k,$v) { $this->meta[$k]=$v; }
    public function get_meta($k) { return $this->meta[$k]??''; }
    public function save() {}
    public function payment_complete($ref) { $this->paidCalls++; $this->datePaid=new \DateTimeImmutable(); $this->status='processing'; $this->meta['transaction_id']=$ref; }
    public function get_billing_email() { return $this->email; }
    public function add_order_note($v) { $this->notes[]=$v; }
}
class FakeDB {
    public $prefix='test_', $rows=[], $locks=[], $deny=false;
    public function prepare($sql,...$args) { return [$sql,$args]; }
    public function get_var($q) {
        [$sql,$args]=$q; $key=$args[0];
        if (str_contains($sql,'RELEASE_LOCK')) { unset($this->locks[$key]); return 1; }
        if ($this->deny || isset($this->locks[$key])) return 0;
        $this->locks[$key]=true; return 1;
    }
    public function get_row($q,$mode) { return $this->rows[$q[1][0]]??null; }
    public function insert($table,$row) {
        if (isset($this->rows[$row['order_id']])) return false;
        $this->rows[$row['order_id']]=$row+['attempts'=>0,'payment_applied'=>0,'refunded'=>0,'delivery_hash'=>'','next_run'=>0]; return 1;
    }
    public function update($table,$data,$where) { $id=$where['order_id']; $this->rows[$id]=array_merge($this->rows[$id],$data); return 1; }
}
class FakeService {
    public $states=[], $sent=[], $outcome='success', $environment='test', $reconciles=0;
    public function operation($key) { return $this->states[$key]??null; }
    public function connectionSummary() { return ['environment'=>$this->environment]; }
    public function execute($c,$mode) {
        $this->sent[]=$c;
        $key=$c['operation_key'];
        $r=['status'=>'SUCCEEDED','operation_key'=>$key,'checkbox_receipt_id'=>$c['receipt_id'],'fiscal_code'=>'TEST'];
        if ($this->outcome==='timeout') {
            $this->states[$key]=array_merge($r,['status'=>'UNCERTAIN']);
            return new WP_Error('reconciliation_required','',['status'=>409]);
        }
        if ($this->outcome==='invalid') return new WP_Error('invalid_tax','',['status'=>422]);
        if (in_array($this->outcome,['shift_open_pending','shift_open_failed'],true)) return new WP_Error($this->outcome,'',['status'=>409]);
        return $this->states[$key]=$r;
    }
    public function reconcile($key) {
        $this->reconciles++;
        if ($this->outcome==='not_found') return new WP_Error('receipt_not_confirmed','',['status'=>409]);
        return $this->states[$key]=array_merge($this->states[$key],['status'=>'SUCCEEDED']);
    }
}
$GLOBALS['wpdb']=new FakeDB(); $GLOBALS['service']=new FakeService(); $GLOBALS['orders']=[]; $GLOBALS['uuid']=0; $GLOBALS['jobs']=[]; $GLOBALS['can_manage']=true;
foreach (['Protocol','Command','Store','Bridge'] as $file) require __DIR__.'/../src/'.$file.'.php';
require __DIR__.'/../../pc-checkbox-fiscalization/src/Domain/ValidationException.php';
require __DIR__.'/../../pc-checkbox-fiscalization/src/Domain/CommandValidator.php';
use Paint\WayForPayCheckbox\Protocol;
use Paint\WayForPayCheckbox\Command;
use Paint\WayForPayCheckbox\Bridge;
$bridge=new Bridge(); $checks=0;
function check($ok,$label) { global $checks; if (!$ok) throw new \RuntimeException('FAIL: '.$label); $checks++; }
function fixture($id) { $o=new WC_Order($id); $GLOBALS['orders'][$id]=$o; return $o; }
function signed($id,$changes=[]) {
    $b=array_merge(['merchantAccount'=>'fixture','orderReference'=>$id.'_woo_w4p_1792000000','amount'=>'10.00','currency'=>'UAH','authCode'=>'','cardPan'=>'','transactionStatus'=>'Approved','reasonCode'=>1100],$changes);
    $b['merchantSignature']=hash_hmac('md5',implode(';',array_map(fn($k)=>(string)$b[$k],Protocol::FIELDS)),'fixture-secret'); return $b;
}
function ready($id) { $GLOBALS['wpdb']->rows[$id]['next_run']=0; }
fixture(1); $b=signed(1); $bad=$b; $bad['amount']='1.00';
check($bridge->accept($bad)->get_error_code()==='invalid_signature','tampered amount signature rejected');
check($bridge->accept(signed(1,['amount'=>'9.99']))->get_error_code()==='payment_total_mismatch','signed wrong total rejected');
check($bridge->accept(signed(1,['currency'=>'USD']))->get_error_code()==='payment_total_mismatch','wrong currency rejected');
check($bridge->accept(signed(1,['merchantAccount'=>'another']))->get_error_code()==='invalid_signature','merchant mismatch rejected');
check(!is_wp_error($bridge->accept(signed(1,['transactionStatus'=>'Declined']))),'decline acknowledged');
check(empty($GLOBALS['wpdb']->rows) && !$GLOBALS['orders'][1]->is_paid(),'decline no payment or receipt');
check(!is_wp_error($bridge->accept(signed(1,['transactionStatus'=>'WaitingAuthComplete']))),'hold acknowledged');
check(empty($GLOBALS['wpdb']->rows),'hold no receipt');
check(!is_wp_error($bridge->accept(signed(1,['transactionStatus'=>'Voided']))) && empty($GLOBALS['wpdb']->rows),'cancelled payment cannot create a sale');
check(!is_wp_error($bridge->accept(signed(1,['reasonCode'=>1101]))) && empty($GLOBALS['wpdb']->rows),'Approved without successful reason cannot pay');
$ack=$bridge->accept($b); check($ack['status']==='accept','approved accepted');
check($GLOBALS['orders'][1]->status==='processing','payment_complete used without completed override');
check(isset($GLOBALS['orders'][1]->meta['_pc_wfp_verified']),'verified marker exists');
check(count($GLOBALS['service']->sent)===0,'callback does not call Checkbox');
check(!is_wp_error($bridge->accept($b)) && $GLOBALS['orders'][1]->paidCalls===1,'duplicate callback payment once');
$bridge->work(1); check($GLOBALS['wpdb']->rows[1]['status']==='SUCCEEDED','sale succeeds');
$bridge->work(1); $bridge->accept($b); $bridge->manualRetry($GLOBALS['orders'][1]);
check(count($GLOBALS['service']->sent)===1,'duplicate jobs/callback/manual success never resend');
check(!str_contains($GLOBALS['wpdb']->rows[1]['command'],'@'),'no email in fiscal snapshot');
check(!str_contains($GLOBALS['wpdb']->rows[1]['command'],'fixture-secret'),'no secret in snapshot');
fixture(2); $bridge->accept(signed(2)); $GLOBALS['service']->outcome='timeout'; $bridge->work(2);
check($GLOBALS['wpdb']->rows[2]['status']==='RECONCILE','timeout becomes reconcile');
$before=count($GLOBALS['service']->sent); ready(2); $GLOBALS['service']->outcome='not_found'; $bridge->work(2);
check(count($GLOBALS['service']->sent)===$before,'404 reconciliation never resends');
$GLOBALS['service']->outcome='success'; ready(2); $bridge->work(2);
check($GLOBALS['wpdb']->rows[2]['status']==='SUCCEEDED' && count($GLOBALS['service']->sent)===$before,'async success reconciles only');
fixture(3); $bridge->accept(signed(3)); $GLOBALS['service']->environment='live'; $bridge->work(3);
check($GLOBALS['wpdb']->rows[3]['error_code']==='fiscal_environment_mismatch' && count($GLOBALS['service']->sent)===$before,'test/live mismatch blocked');
$GLOBALS['service']->environment='test';
fixture(4); $bridge->accept(signed(4)); $bridge->accept(signed(4,['transactionStatus'=>'Refunded','amount'=>'5.00'])); $bridge->work(4); $bridge->accept(signed(4));
check($GLOBALS['wpdb']->rows[4]['status']==='REFUND_REVIEW' && count($GLOBALS['service']->sent)===$before,'refund before job blocks sale; late approval does not undo');
fixture(5)->gateway='cod'; check($bridge->accept(signed(5))->get_error_code()==='order_not_eligible','non-WayForPay order blocked');
fixture(6)->created='2025-01-01'; check($bridge->accept(signed(6))->get_error_code()==='historical_or_ineligible_order','historical order blocked');
fixture(7); $GLOBALS['wpdb']->deny=true; check($bridge->accept(signed(7))->get_error_code()==='payment_busy','concurrent callback blocked'); $GLOBALS['wpdb']->deny=false;
$o=fixture(8); $o->items=[1=>new Item('10.00',3),2=>new Item('2.01',1,'shipping')]; $o->total='12.01';
$bridge->accept(signed(8,['amount'=>'12.01'])); $c=json_decode($GLOBALS['wpdb']->rows[8]['command'],true);
check(array_sum(array_column($c['goods'],'line_total_cents'))===1201,'shipping and rounding exact');
check(array_sum(array_column($c['goods'],'quantity_thousandths'))===4000,'quantity conserved while splitting pennies');
foreach($c['goods'] as $g) check(intdiv($g['price_cents']*$g['quantity_thousandths'],1000)===$g['line_total_cents'],'Checkbox price times quantity matches');
$o=fixture(9); $o->items=[new Item('10.00','1.5')]; $bridge->accept(signed(9));
check($GLOBALS['wpdb']->rows[9]['status']==='BLOCKED' && $o->is_paid(),'mapping failure retains authoritative payment');
fixture(10); $bridge->accept(signed(10)); $GLOBALS['service']->outcome='invalid'; $bridge->work(10);
check($GLOBALS['wpdb']->rows[10]['status']==='BLOCKED','permanent error stops retries');
$GLOBALS['can_manage']=false; $bridge->manualRetry($GLOBALS['orders'][10]); check($GLOBALS['wpdb']->rows[10]['status']==='BLOCKED','manual action capability checked'); $GLOBALS['can_manage']=true;
$bridge->manualRetry($GLOBALS['orders'][10]); $GLOBALS['orders'][10]->email='changed@example.test'; $bridge->work(10);
check($GLOBALS['wpdb']->rows[10]['error_code']==='receipt_email_changed','changed delivery blocks command mutation');
fixture(11); $bridge->accept(signed(11)); $GLOBALS['wpdb']->rows[11]['mode']='live'; $bridge->work(11);
check($GLOBALS['wpdb']->rows[11]['error_code']==='order_or_mode_changed','queued mode never silently promoted');
fixture(12); $bridge->accept(signed(12)); $GLOBALS['wpdb']->rows[12]['attempts']=8; $before=count($GLOBALS['service']->sent); $bridge->work(12);
check(count($GLOBALS['service']->sent)===$before,'retry limit enforced');
check($bridge->accept(signed(12,['orderReference'=>'12_woo_w4p_1792000001']))->get_error_code()==='payment_identity_conflict','second payment reference flagged');
fixture(13); $bridge->accept(signed(13,['transactionStatus'=>'Refunded'])); $bridge->accept(signed(13)); $bridge->work(13);
check($GLOBALS['wpdb']->rows[13]['status']==='REFUND_REVIEW' && !$GLOBALS['orders'][13]->is_paid(),'refund before first approval creates tombstone');
check(count($GLOBALS['service']->sent)===$before,'refund-first callback never creates sale');
$GLOBALS['service']->outcome='success';
fixture(14); $bridge->accept(signed(14)); $GLOBALS['wpdb']->rows[14]['payment_applied']=0; $bridge->work(14);
check($GLOBALS['orders'][14]->paidCalls===1 && $GLOBALS['wpdb']->rows[14]['payment_applied']===1,'recovery after payment transition never repeats payment_complete');
$o=fixture(16); $o->items=[1=>new Item('7.00'),2=>new Item('0.00',1,'shipping'),3=>new Item('3.00',1,'fee')];
$bridge->accept(signed(16)); $c=json_decode($GLOBALS['wpdb']->rows[16]['command'],true);
check(count($c['goods'])===2 && array_sum(array_column($c['goods'],'line_total_cents'))===1000,'discounted line total plus fee and free shipping maps exactly');
$o=fixture(17); $o->items=[new Item('9.99')]; $bridge->accept(signed(17));
check($GLOBALS['wpdb']->rows[17]['status']==='BLOCKED' && $o->is_paid(),'line sum mismatch blocks receipt not verified payment');
$o=fixture(18); $o->items=[new Item('12.00'),new Item('-2.00',1,'fee')]; $bridge->accept(signed(18));
check($GLOBALS['wpdb']->rows[18]['status']==='BLOCKED','negative fees require explicit mapping');
fixture(19); $bridge->accept(signed(19)); $GLOBALS['service']->outcome='shift_open_pending'; $bridge->work(19);
check($GLOBALS['wpdb']->rows[19]['status']==='QUEUED','pending opening schedules safe executor lookup, not receipt reconciliation');
$GLOBALS['service']->outcome='success'; ready(19); $bridge->work(19);
check($GLOBALS['wpdb']->rows[19]['status']==='SUCCEEDED' && $GLOBALS['orders'][19]->paidCalls===1,'after opening resumes original sale without repeating payment transition');
fixture(20); $bridge->accept(signed(20)); $GLOBALS['service']->outcome='shift_open_failed'; $bridge->work(20);
check($GLOBALS['wpdb']->rows[20]['status']==='BLOCKED','terminal opening failure needs operator review');
fixture(15); $bridge->accept(signed(15)); $before=count($GLOBALS['service']->sent); define('PC_WFP_CHECKBOX_PAUSED',true); $bridge->work(15);
check(count($GLOBALS['service']->sent)===$before && $GLOBALS['wpdb']->rows[15]['attempts']===0 && $GLOBALS['orders'][15]->is_paid(),'pause preserves validated payments without fiscal calls or spending attempts');
$ack=Protocol::acknowledgement('fixture_ref','fixture-secret',123);
check($ack['signature']===hash_hmac('md5','fixture_ref;accept;123','fixture-secret'),'acknowledgement signature');
echo "Passed $checks behavioral checks (offline boundaries).\n";
}
