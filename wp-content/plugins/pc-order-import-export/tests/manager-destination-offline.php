<?php
/** Isolated read-only rendering checks, no WP/DB/API. */
define('ABSPATH',__DIR__);
function __($s,$d=null){return $s;}
function esc_html__($s,$d=null){return esc_html($s);}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
class WC_Order {
    public array $meta=[];public int $customer=1;public array $shipping=[];public string $email='fixture@example.invalid';
    public function get_meta($k){return $this->meta[$k]??'';}
    public function get_items($type){return $this->shipping;}
    public function get_customer_id(){return $this->customer;}
    public function get_billing_email(){return $this->email;}
}
function wc_get_order($id){return $GLOBALS['orders'][$id]??null;}
require dirname(__DIR__).'/inc/ManagerOrderDetails.php';
use PaintCore\PCOE\ManagerOrderDetails as Details;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "OK $message\n";}
$o=new WC_Order();
check(Details::destination($o)==='','legacy orders remain unchanged');
$o->meta['_pnpm_recipient']=['delivery_type'=>'parcel_locker','city_label'=>'Test city','point_label'=>'Locker 123 <script>bad</script>'];
$html=Details::destination($o);
check(str_contains($html,'Parcel locker') && str_contains($html,'Test city') && str_contains($html,'Locker 123'),'saved locker and city rendered');
check(!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'),'saved labels escaped');
$o->meta['_pnpm_recipient']['delivery_type']='branch';check(str_contains(Details::destination($o),'Nova Poshta branch'),'branch type rendered');
$o->meta['_pnpm_recipient']['delivery_type']='address';check(!str_contains(Details::destination($o),'Locker 123') && str_contains(Details::destination($o),'Courier delivery'),'courier does not display stale point');
$o->meta['_pnpm_recipient']=['delivery_type'=>'parcel_locker','point_label'=>['bad']];check(str_contains(Details::destination($o),'Pickup point is not saved'),'missing or malformed label requires review');
$parent=new WC_Order();$parent->meta['_pnpm_recipient']=['delivery_type'=>'parcel_locker','point_label'=>'Parent locker'];$GLOBALS['orders'][99]=$parent;
$o->meta=['_folio_parent_order_id'=>99];check(str_contains(Details::destination($o),'Parent locker'),'split child inherits original delivery');
$o->customer=2;check(Details::destination($o)==='','different customer parent rejected');
$o->customer=1;$o->shipping=['own'];check(Details::destination($o)==='','own delivery not replaced by parent');
$o->shipping=[];$o->customer=0;$parent->customer=0;$parent->email='other@example.invalid';check(Details::destination($o)==='','guest parent email mismatch rejected');
if(in_array('--html',$argv,true))echo '<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{font:16px Arial;margin:20px;max-width:800px} .pcoe-np-destination{padding:16px;background:#f3f5f7;overflow-wrap:anywhere}</style>'.$html;
echo "PASS\n";
