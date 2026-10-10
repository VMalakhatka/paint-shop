<?php
/** Offline fixtures for the reusable report; no database or customer payload. */
define('WP_CLI', true);
class WP_CLI { static function error($s) { throw new RuntimeException($s); } }
function wp_json_encode($v, $flags) { return json_encode($v, $flags); }
function wc_get_order_statuses() { return ['wc-processing' => '', 'wc-pc-draft' => '', 'wc-cancelled' => '', 'wc-refunded' => '']; }
function wc_get_orders($args) { global $fixtures; return $args['page'] === 1 ? array_values($fixtures) : []; }
function wc_get_order($id) { global $fixtures; return $fixtures[$id] ?? false; }
function get_userdata($id) { return (object) ['roles' => $id === 9 ? ['shop_manager'] : ['opt']]; }
class ProductFixture {
    public $sku; function __construct($sku) { $this->sku = $sku; }
    function get_sku() { return $this->sku; } function get_name() { return 'Public ' . $this->sku; }
}
class LineFixture {
    public $sku; public $amount; function __construct($sku, $amount) { $this->sku=$sku; $this->amount=$amount; }
    function get_product() { return new ProductFixture($this->sku); } function get_quantity() { return 2; }
    function get_total() { return $this->amount; } function get_product_id() { return 100; } function get_variation_id() { return 0; }
}
class OrderFixture {
    public $id, $status, $total, $meta, $items; public $user=1; public $refund=0;
    function __construct($id,$status,$total,$meta=[],$items=[]) { $this->id=$id; $this->status=$status; $this->total=$total; $this->meta=$meta; $this->items=$items; }
    function get_id() { return $this->id; } function get_meta($k) { return $this->meta[$k]??''; }
    function get_status() { return $this->status; } function get_customer_id() { return $this->user; }
    function get_total() { return $this->total; } function get_total_refunded() { return $this->refund; }
    function get_currency() { return 'UAH'; } function get_billing_country() { return 'UA'; } function get_billing_state() { return 'UA30'; }
    function get_transaction_id() { return ''; } function get_payment_method() { return 'bacs'; }
    function get_items($type) { return $this->items; } function get_qty_refunded_for_item($id) { return 0; }
    function get_total_refunded_for_item($id) { return 0; }
}
$fixtures = [
    1 => new OrderFixture(1,'pc-draft',100,[],[new LineFixture('PARENT',100)]),
    2 => new OrderFixture(2,'processing',30,['_folio_parent_order_id'=>1],[new LineFixture('SKU-A',30)]),
    3 => new OrderFixture(3,'on-hold',20,['_folio_split_from_order_id'=>1],[new LineFixture('SKU-B',20)]),
    4 => new OrderFixture(4,'cancelled',80,['_wc_order_attribution_source_type'=>'typein','_wc_order_attribution_utm_source'=>'(direct)'],[new LineFixture('CANCELLED',80)]),
    5 => new OrderFixture(5,'processing',500,['_wc_order_attribution_source_type'=>'admin'],[new LineFixture('STAFF',500)]),
    6 => new OrderFixture(6,'pc-draft',99),
    7 => new OrderFixture(7,'refunded',70,[],[new LineFixture('REFUNDED',70)]),
    8 => new OrderFixture(8,'processing',10,['_lca_segment'=>'retail','_lca_source'=>'google','_lca_device'=>'mobile'],[new LineFixture('RETAIL',10)]),
];
$fixtures[5]->user=9; $fixtures[7]->refund=70;
ob_start(); require dirname(__DIR__, 4) . '/scripts/analyze-customer-sales.php'; $result=json_decode(ob_get_clean(),true);
$n=0;
function check($ok,$name) { global $n; if(!$ok) throw new RuntimeException($name); $n++; }
check($result['order_rows']===8,'All fixture rows');
check($result['commercial_groups']===5,'Split counted once');
check($result['draft_only_groups']===1,'Draft-only excluded');
check($result['currency_totals']['UAH']===560,'Parent and cancelled not in accepted totals; refund net zero');
$skus=array_column($result['products'],'sku');
check(!in_array('PARENT',$skus) && !in_array('CANCELLED',$skus) && !in_array('STAFF',$skus) && !in_array('REFUNDED',$skus),'Excluded product rows');
check(count($result['pairs'])===1 && $result['pairs'][0]['groups']===1,'Pair spans split child invoices');
check($result['products_by_segment']['retail'][0]['sku']==='RETAIL','Retail ranking separate');
check($result['sources']['UAH|google']['groups']===1,'Saved source used');
check($result['devices']['UAH|mobile']['groups']===1,'Saved device used');
check($result['payment_evidence']['no_confirmed_payment_evidence']===6,'Processing bacs not paid');
check($result['sources']['unknown|direct']['groups']===1,'Native direct is not unknown');
check($result['sources']['UAH|admin']['groups']===1,'Admin origin is not a visitor referral');
echo "PASS: $n sales report assertions\n";
