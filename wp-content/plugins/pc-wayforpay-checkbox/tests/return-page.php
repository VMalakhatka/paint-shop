<?php
/** No WordPress, network, payments or writes. */
if (PHP_SAPI !== 'cli') exit;
function add_action(...$a) { $GLOBALS['hooks'][]=$a; }
function add_filter(...$a) { $GLOBALS['hooks'][]=$a; }
function __($s,$d='') { return $s; }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); }
function esc_html__($s,$d='') { return esc_html($s); }
function esc_url($s) { return $s; }
function wc_get_checkout_url() { return 'https://example.test/checkout/'; }
function wc_get_endpoint_url($e,$v,$url) { return $url.$e.'/'.$v.'/'; }
function wc_get_account_endpoint_url($e) { return 'https://example.test/my-account/'.$e.'/'; }
function add_query_arg($key,$value,$url) { return $url.'?'.urlencode($key).'='.urlencode($value); }
function wc_get_order($id) { return $GLOBALS['orders'][$id]??false; }
class WC_Order {
    public $method='wayforpay', $status='pending', $paid=null, $verified='', $transaction='';
    public function get_id(){return 7;}
    public function get_order_key(){return 'fixture-key';}
    public function get_payment_method(){return $this->method;}
    public function get_status(){return $this->status;}
    public function get_meta(...$a){return $this->verified;}
    public function get_transaction_id(){return $this->transaction;}
    public function get_date_paid(){return $this->paid;}
}
require __DIR__.'/../src/ReturnPage.php';
$page=new \Paint\WayForPayCheckbox\ReturnPage(); $page->boot();
$orders=[7=>new WC_Order()];$checks=0;
function check($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
check(count($GLOBALS['hooks'])===3,'navigation and message hooks');
$query=['order'=>'7','key'=>'fixture-key'];
check($page->target($query)==='https://example.test/checkout/order-received/7/?key=fixture-key','legacy return redirected to protected Woo endpoint');
foreach ([['order'=>7,'key'=>'wrong'],['order'=>8,'key'=>'fixture-key'],['order'=>['7'],'key'=>'fixture-key'],['order'=>7,'key'=>['fixture-key']],$query+['wc-api'=>'WC_wayforpay']]as $q){check($page->target($q)==='','invalid/callback request untouched');}
check(str_contains($page->message('original',$orders[7]),'waiting for payment confirmation'),'early browser return never claims paid');
$orders[7]->paid=true;$orders[7]->status='processing';
check(str_contains($page->message('original',$orders[7]),'waiting for payment confirmation'),'manual paid status not trusted');
$orders[7]->verified=['reference'=>'verified-ref'];$orders[7]->transaction='verified-ref';
check(str_contains($page->message('original',$orders[7]),'paid successfully'),'verified callback success');
$orders[7]->status='pc-draft';
check(str_contains($page->message('original',$orders[7]),'paid successfully'),'paid split parent success');
$orders[7]->status='refunded';
check(!str_contains($page->message('original',$orders[7]),'paid successfully'),'refunded not success');
$orders[7]->method='bacs';
check($page->target($query)==='' && $page->message('original',$orders[7])==='original','other gateway untouched');
check($page->receivedUrl('original',$orders[7])==='original','other gateway URL untouched');
check($page->message('original',false)==='original','invalid order contains no details');
echo "PASS: $checks return-page checks\n";
