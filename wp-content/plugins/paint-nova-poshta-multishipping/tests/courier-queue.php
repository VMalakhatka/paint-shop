<?php
/** Pure saved-snapshot projection: no database, transport or real customer data. */
define('ABSPATH', __DIR__.'/');
function wp_json_encode($v) { return json_encode($v); }
require __DIR__.'/../src/Infrastructure/ShipmentLifecycle.php';
require __DIR__.'/../src/Infrastructure/CourierQueue.php';
use Paint\NovaPoshta\Infrastructure\CourierQueue;
$checks=0;
$check=static function ($ok,$message) use (&$checks) { if (!$ok) throw new RuntimeException($message); ++$checks; };
$ref=static fn($n)=>sprintf('00000000-0000-0000-0000-%012d',$n);
$sender=['enabled'=>'yes','sender_type'=>'doors','counterparty_ref'=>$ref(1),'city_ref'=>$ref(2),'sender_address_ref'=>$ref(3),'contact_ref'=>$ref(4),'phone'=>'380000000000'];
$row=['id'=>1,'order_id'=>1,'location_id'=>1,'source'=>'store_api','status'=>'manager_draft','ttn_number'=>'','np_ref'=>'','sender_snapshot'=>json_encode($sender),'request_snapshot'=>json_encode(['boxes'=>[['weight'=>1.5],['weight'=>2]]])];
$other=array_replace($row,['id'=>2,'order_id'=>2,'location_id'=>2]);
$groups=CourierQueue::project([$row,$other]);
$check(count($groups)===1 && count($groups[0]['rows'])===2,'Same physical origin across Woo locations is grouped');
$check($groups[0]['weight']===7.0 && $groups[0]['places']===4 && $groups[0]['totals_known'],'Packed totals are summed');
foreach (['counterparty_ref','city_ref','sender_address_ref','contact_ref','phone'] as $field) {
    $different=$sender;$different[$field]=$field==='phone'?'380000000001':$ref(10);
    $other['sender_snapshot']=json_encode($different);
    $check(count(CourierQueue::project([$row,$other]))===2,'Different '.$field.' stays separate');
}
foreach (['customer_external','manager_external'] as $source) $check(CourierQueue::project([array_replace($row,['source'=>$source])])===[],'External TTN excluded');
foreach (['np_created','np_unknown','np_creating','np_recipient_ready'] as $status) $check(CourierQueue::project([array_replace($row,['status'=>$status])])===[],'Non-draft excluded');
$check(CourierQueue::project([array_replace($row,['ttn_number'=>'20450000000001'])])===[],'A numbered row cannot masquerade as a draft');
$branch=$sender;$branch['sender_type']='warehouse';
$check(CourierQueue::project([array_replace($row,['sender_snapshot'=>json_encode($branch)])])===[],'Branch handover excluded');
$missing=$sender;unset($missing['sender_address_ref']);
$bad=array_replace($row,['sender_snapshot'=>json_encode($missing)]);
$groups=CourierQueue::project([$bad,array_replace($bad,['id'=>2])]);
$check(count($groups)===2 && !$groups[0]['complete'],'Incomplete origins never merge');
$bad=array_replace($row,['request_snapshot'=>json_encode(['boxes'=>[['weight'=>'bad']]])]);
$groups=CourierQueue::project([$bad]);
$check(!$groups[0]['totals_known'] && $groups[0]['rows'][0]['queue_weight']===null,'Unknown weight is not presented as zero');
$check(CourierQueue::project([array_replace($row,['sender_snapshot'=>'"malformed"'])])===[],'Malformed snapshots excluded');
echo "Courier queue: $checks checks passed\n";
