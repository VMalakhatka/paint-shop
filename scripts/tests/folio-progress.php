<?php
// Isolated read-only states: no WordPress, network, documents or payments.
define('ABSPATH',__DIR__);
function add_action(...$args){} function __($s,...$args){return $s;}
class WC_Order {
 public $state='waiting_payment',$status='pending',$method='wayforpay',$verified=[],$transaction='',$date=null,$documents=[],$link=[];
 function get_meta($k){return $k==='_folio_auto_status'?$this->state:($k==='_pc_wfp_verified'?$this->verified:'');}
 function get_payment_method(){return $this->method;}function get_status(){return $this->status;}
 function get_transaction_id(){return $this->transaction;}function get_date_paid(){return $this->date;}
}
function pc_folio_get_order_documents_result($o){return ['documents'=>$o->documents];}
function pc_folio_get_order_document_link($o){return $o->link;}
require dirname(__DIR__,2).'/wp-content/mu-plugins/pc-folio-order-link/progress.php';
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "OK $s\n";}
$o=new WC_Order();$v=pc_folio_customer_progress($o);check($v['poll']&&str_contains($v['payment'],'not yet'),'unpaid waits');
$o->status='processing';check(str_contains(pc_folio_customer_progress($o)['payment'],'not yet'),'Woo processing does not prove payment');
$o->verified=['reference'=>'a'];$o->transaction='b';$o->date='date';check(str_contains(pc_folio_customer_progress($o)['payment'],'not yet'),'mismatched transaction is not paid');
$o->transaction='a';$o->state='running';$v=pc_folio_customer_progress($o);check($v['payment']==='Online payment confirmed.'&&$v['poll']&&str_contains($v['message'],'being created'),'verified payment independent of running documents');
$o->state='success';$o->documents=[['id'=>1]];$v=pc_folio_customer_progress($o);check(!$v['poll']&&str_contains($v['message'],'saved'),'saved documents finish');
$o->state='error';check(!pc_folio_customer_progress($o)['poll'],'error stops');
$o->state='waiting_payment';$o->status='failed';check(!pc_folio_customer_progress($o)['poll'],'failed payment stops');
$o->status='on-hold';$o->method='bacs';$o->documents=[];$v=pc_folio_customer_progress($o);check(!$v['poll']&&$v['payment']==='','BACS does not claim paid');
$o->state='success';check(!str_contains(pc_folio_customer_progress($o)['message'],'have been saved'),'status alone is not document evidence');
