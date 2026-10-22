<?php
/** Real MU pipeline, fake Woo persistence/transport. No HTTP, WordPress DB or mail. */
define('ABSPATH',__DIR__.'/');define('DB_NAME','fixture');
function add_action(...$a){} function add_filter(...$a){}
function __($s,$d=null){return $s;}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}function esc_html__($s,$d=null){return esc_html($s);}
function esc_url($s){return esc_html($s);}function get_current_user_id(){return 10;}
function admin_url($s){return '/'.$s;}function wp_nonce_field($s){echo '<input type="hidden" name="nonce" value="fixture">';}function absint($v){return abs((int)$v);}
function apply_filters($name,$value){return $value;}
function current_filter(){return 'admin_post_pc_folio_customer_resume';}
function current_time($s){return gmdate('Y-m-d H:i:s');}
function wp_json_encode($v,...$args){return json_encode($v);}
function is_wp_error($r){return $r instanceof WP_Error;}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
class WP_Error {function get_error_message(){return 'Simulated timeout';}}
class FixtureDB {public $prefix='test_';public bool $busy=false;public int $acquired=0;public int $released=0;function prepare($sql,...$args){return $sql;}function get_var($sql){if(str_contains($sql,'RELEASE')){$this->released++;return '1';}if($this->busy)return '0';$this->acquired++;return '1';}}
$GLOBALS['wpdb']=new FixtureDB();$GLOBALS['stored']=[];$GLOBALS['requests']=0;$GLOBALS['mode']='ok';
class WC_Order {
 public int $id;public array $meta=[],$notes=[];public string $method='wayforpay',$status='pending';public int $user=10;
 function __construct($id){$this->id=$id;}
 function get_id(){return $this->id;}function get_parent_id(){return 0;}function get_customer_id(){return $this->user;}
 function get_payment_method(){return $this->method;}function get_status(){return $this->status;}function is_paid(){return $this->status==='processing';}
 function get_meta($key,...$rest){return $this->meta[$key]??'';}function update_meta_data($k,$v){$this->meta[$k]=$v;}function delete_meta_data($k){unset($this->meta[$k]);}
 function add_order_note($s,...$rest){$this->notes[]=$s;}function save(){$GLOBALS['stored'][$this->id]=clone $this;}
 function read_meta_data($force){}function get_data_store(){return new class {function read($o){$fresh=$GLOBALS['stored'][$o->id];foreach(get_object_vars($fresh) as $k=>$v)$o->$k=$v;}};}
}
function wc_get_order($id){return isset($GLOBALS['stored'][$id])?clone $GLOBALS['stored'][$id]:null;}
function pc_folio_auto_checkout_enabled(){return true;}
function pc_folio_order_has_saved_documents($o){return (bool)$o->get_meta('saved');}
function pc_folio_build_order_preview_payload($o){return ['order'=>$o->get_id()];}
function pc_folio_validate_order_payload_for_create($payload){return [];}
function pc_folio_order_link_java_post($url,$payload,$args){$GLOBALS['requests']++;if($GLOBALS['mode']==='timeout')return new WP_Error();return ['code'=>200,'body'=>'{"ok":true,"documents":[{"id":1}]}'];}
function pc_folio_save_order_java_response($o,$data,$payload){$o->update_meta_data('saved',true);$o->save();}
function pc_folio_apply_saved_response_to_order($o){return ['ok'=>(bool)$o->get_meta('saved'),'status'=>'linked'];}
function pc_folio_clear_current_checkout_cart(){throw new RuntimeException('Recovery must preserve current cart');}
require dirname(__DIR__,2).'/wp-content/mu-plugins/pc-folio-order-link.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "OK $message\n";}
$o=new WC_Order(1);$o->save();pc_folio_auto_process_checkout_order(1);$o=wc_get_order(1);
check($GLOBALS['requests']===0 && $o->get_meta('_folio_auto_status')==='waiting_payment','unpaid online gateway waits without create');
check($o->get_meta('_folio_recovery_safe')==='1' && $o->get_meta('_folio_checked_payment_method')==='wayforpay','safe provenance and checked method saved');
check(!pc_folio_customer_can_resume($o,10),'unpaid gateway cannot be bypassed');
$o->method='bacs';$o->status='on-hold';$o->save();
check(pc_folio_customer_can_resume($o,10),'owner can continue proven unsubmitted BACS order');
check(!pc_folio_customer_can_resume($o,11),'other customer forbidden');
pc_folio_resume_bacs_hold(1);$o=wc_get_order(1);
check($GLOBALS['requests']===1 && $o->get_meta('_folio_auto_status')==='success' && $o->get_meta('saved'),'BACS hold resumes full creation and link');
check(str_contains(implode(' ',$o->notes),'wayforpay → bacs'),'gateway transition recorded');
pc_folio_auto_process_checkout_order(1);pc_folio_create_documents_for_order($o);
check($GLOBALS['requests']===1,'automatic and manual duplicate creation blocked');
$o=new WC_Order(2);$o->method='bacs';$o->status='on-hold';$o->save();$GLOBALS['mode']='timeout';pc_folio_auto_process_checkout_order(2);$o=wc_get_order(2);
check($o->get_meta('_folio_create_attempted_at') && $o->get_meta('_folio_auto_status')==='error','timeout preserves attempted marker');
$n=$GLOBALS['requests'];pc_folio_auto_process_checkout_order(2);pc_folio_create_documents_for_order($o);
check($GLOBALS['requests']===$n && !pc_folio_customer_can_resume($o,10),'unknown result not repeated by any create entry');
$o=new WC_Order(3);$o->method='bacs';$o->status='on-hold';$o->meta['_folio_auto_status']='waiting_payment';$o->save();pc_folio_resume_bacs_hold(3);
check($GLOBALS['requests']===$n && !pc_folio_customer_can_resume(wc_get_order(3),10),'legacy waiting needs manager reconciliation');
$o=new WC_Order(4);$o->method='bacs';$o->save();$GLOBALS['wpdb']->busy=true;pc_folio_auto_process_checkout_order(4);
check($GLOBALS['requests']===$n,'parallel worker blocked by order lock');$GLOBALS['wpdb']->busy=false;
check($GLOBALS['wpdb']->acquired===$GLOBALS['wpdb']->released,'locks released including nested create and errors');
echo "PASS\n";

if (in_array('--html',$argv,true)) {
    $o=new WC_Order(100);$o->method='bacs';$o->status='on-hold';$o->meta=['_folio_auto_status'=>'waiting_payment','_folio_recovery_safe'=>'1'];
    ob_start();pc_folio_customer_recovery_view($o);$html=ob_get_clean();
    check(str_contains($html,'Continue Folio processing') && str_contains($html,'method="post"'),'owner sees explicit POST action');
    $o->user=11;ob_start();pc_folio_customer_recovery_view($o);check(ob_get_clean()==='','other customer receives no status/form');
    file_put_contents('/private/tmp/folio-recovery-preview.html','<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px Arial;max-width:850px;margin:20px;line-height:1.5}section{background:#f5f5f5;padding:20px}button{padding:12px;max-width:100%;white-space:normal}h2{font-size:24px}</style>'.$html);
}
