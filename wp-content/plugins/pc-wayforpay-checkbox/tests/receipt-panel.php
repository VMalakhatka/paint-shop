<?php
/** Offline rendering + read-only AJAX permission tests. No network or financial calls. */
namespace Paint\CheckboxFiscalization {
    class Plugin {
        public static function instance() { return new self(); }
        public function service() { return new class {
            public function operation($key) { return $GLOBALS['operation']; }
            public function __call($name, $args) { throw new \RuntimeException('Unexpected service mutation: '.$name); }
        }; }
    }
}
namespace {
if (PHP_SAPI !== 'cli') exit;
define('ARRAY_A','ARRAY_A');
function __($s,$domain='') { return $s; }
function esc_html($s) { return htmlspecialchars((string)$s,ENT_QUOTES); }
function esc_html__($s,$domain='') { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function absint($v) { return abs((int)$v); }
function current_user_can($cap,...$args) { return $GLOBALS['caps'][$cap]??false; }
function check_ajax_referer($action,$arg,$die) { return $GLOBALS['nonce'] && $action==='pc_wfp_receipt_status_123'; }
function nocache_headers() { $GLOBALS['no_cache']=true; }
class Response extends \Exception { public function __construct(public array $payload,public int $status) {} }
function wp_send_json_error($data,$status) { throw new Response(['success'=>false],$status); }
function wp_send_json_success($data) { throw new Response(['success'=>true,'data'=>$data],200); }
class WC_Order { function get_meta($key) { return []; } }
function wc_get_order($id) { $GLOBALS['order_reads']++; return $id===123 ? new WC_Order() : false; }
$GLOBALS['wpdb']=new class {
    public $prefix='fixture_';
    function prepare($sql,...$args) { return [$sql,$args]; }
    function get_row($sql,$mode) { $GLOBALS['db_reads']++; return $GLOBALS['row']; }
    function __call($name,$args) { throw new \RuntimeException('Unexpected DB call: '.$name); }
};
require __DIR__.'/../src/Store.php';
require __DIR__.'/../src/ReceiptPanel.php';
use Paint\WayForPayCheckbox\ReceiptPanel;
$checks=0;
function check($ok,$label) { global $checks; if(!$ok) throw new \RuntimeException('FAIL: '.$label); $checks++; }
$row=['status'=>'QUEUED','mode'=>'live','attempts'=>0,'error_code'=>'shiftopened_too_long','updated_at'=>'2026-10-18 12:00:00','command'=>'{"operation_key":"fixture"}'];
$failure=['status'=>'FAILED','http_code'=>400,'error_message'=>'Old <script>error</script>','shift_id'=>'old-shift'];
$html=ReceiptPanel::render($row,$failure,[]);
check(str_contains($html,'Queued for processing.'),'queue explanation');
check(!str_contains($html,'QUEUED / live / shiftopened'),'old error removed from current summary');
check(str_contains($html,'<details><summary>Previous attempt'),'history collapsed and labelled');
check(!str_contains($html,'<script>'),'diagnostic escaped');
check(str_contains($html,'old-shift'),'audit diagnostic retained');
check(str_contains($html,'data-pending="1"'),'pending polling marker');
check(str_contains($html,'Saved status updated (UTC)'),'explicit status timestamp');
$row['status']='BLOCKED';
$html=ReceiptPanel::render($row,$failure,[]);
check(!str_contains($html,'<details>') && str_contains($html,'shiftopened_too_long'),'real blocked error remains prominent');
$row['status']='RECONCILE';
$html=ReceiptPanel::render($row,null,[]);
check(str_contains($html,'Waiting for confirmation'),'reconciliation message does not claim failure');
$success=['status'=>'SUCCEEDED','checkbox_receipt_id'=>'fixture-uuid','fiscal_code'=>'TEST-FISCAL'];
$html=ReceiptPanel::render($row,$success,[]);
check(str_contains($html,'Receipt confirmed.') && str_contains($html,'TEST-FISCAL'),'executor result visible before adapter catches up');
check(!str_contains($html,'shiftopened_too_long'),'confirmed result suppresses stale error');
$row['status']='SUCCEEDED';
$html=ReceiptPanel::render($row,$success,[]);
check(str_contains($html,'data-pending="0"') && str_contains($html,'View receipt'),'terminal success stops polling and shows link');
check(!str_contains($html,'Previous attempt'),'success does not show stale failure');
$row['status']='BLOCKED';
$html=ReceiptPanel::render($row,$failure,['id'=>'wrong','fiscal_code'=>'STALE']);
check(!str_contains($html,'STALE'),'failed operation cannot show stale meta receipt');
$GLOBALS['row']=$row; $GLOBALS['operation']=$failure;
$GLOBALS['caps']=['manage_woocommerce'=>true,'edit_shop_order'=>true];
$GLOBALS['nonce']=true; $GLOBALS['order_reads']=0; $GLOBALS['db_reads']=0;
$_POST=['order_id'=>123];
$panel=new ReceiptPanel();
function request($panel) { try {$panel->refresh();} catch(Response $r) {return $r;} throw new \RuntimeException('No response'); }
$r=request($panel);
check($r->status===200 && str_contains($r->payload['data']['html'],'BLOCKED'),'AJAX returns current saved state');
check($GLOBALS['no_cache']===true,'private response not cached');
foreach(['manage_woocommerce','edit_shop_order'] as $cap) {
    $GLOBALS['caps'][$cap]=false; $before=$GLOBALS['db_reads'];
    check(request($panel)->status===403 && $GLOBALS['db_reads']===$before,'capability checked before lookup: '.$cap);
    $GLOBALS['caps'][$cap]=true;
}
$GLOBALS['nonce']=false; $before=$GLOBALS['order_reads'];
check(request($panel)->status===403 && $GLOBALS['order_reads']===$before,'nonce bound to order before lookup');
$GLOBALS['nonce']=true; $GLOBALS['row']=null;
check(request($panel)->status===404,'missing receipt is not fabricated');
echo "Passed $checks receipt panel checks.\n";
}
