<?php
/** Standalone source/field semantics: no WordPress database or HTTP. */
define('ABSPATH',__DIR__);
require dirname(__DIR__).'/src/Registry/Summary.php';
use Paint\NovaPoshta\Registry\Summary;
$checks=0;
$check=static function($ok,$message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$ob=static fn($source,$date,$id,$data)=>['source'=>$source,'observed_at'=>$date,'id'=>$id,'payload'=>json_encode($data)];
$document=$ob('np_documents','2026-10-01 12:00:00',1,[
    'RecipientDescription'=>'Synthetic Company','RecipientContactPerson'=>'Synthetic Person',
    'CityRecipient'=>'00000000-0000-0000-0000-000000000001',
    'SettlmentAddressData'=>['RecipientSettlementDescription'=>'Synthetic City'],
    'DateTime'=>'2026-06-25 11:14:43','CostOnSite'=>'1116.09','Cost'=>'13217.00','PayerType'=>'Sender',
]);
$s=Summary::fromObservations([$document]);
$check($s['recipient']==='Synthetic Company' && $s['contact']==='Synthetic Person','Company and contact displayed separately');
$check($s['city']==='Synthetic City','City UUID never displayed as city');
$check($s['date']==='25.06.2026','Carrier calendar date kept, no UTC conversion');
$check($s['delivery']==='1116.09' && $s['declared']==='13217.00' && $s['payer']==='Sender','Delivery and declared value remain separate');
$tracking=$ob('np_tracking','2026-10-02 12:00:00',2,['RecipientFullName'=>'New Person','CostOnSite'=>'0.00','PayerType'=>'Recipient']);
$s=Summary::fromObservations([$document,$tracking]);
$check($s['recipient']==='New Person' && $s['contact']==='','No stale contact from an older recipient');
$check($s['delivery']==='0.00' && $s['payer']==='Recipient','Latest carrier response wins, zero is not missing');
$check($s['city']==='Synthetic City' && $s['declared']==='13217.00','Missing tracking fields fall back to saved document');
$csv=$ob('csv','2026-10-03 12:00:00',3,['recipient'=>'CSV Person','date'=>'2026-06-01','delivery_cost'=>'25,50','payer'=>'Sender','destination'=>'Some street']);
$s=Summary::fromObservations([$csv,$document]);
$check($s['recipient']==='Synthetic Company' && $s['delivery']==='1116.09' && !$s['csv'],'Newer manual CSV cannot overwrite carrier facts');
$s=Summary::fromObservations([$csv]);
$check($s['recipient']==='CSV Person' && $s['delivery']==='25.50' && $s['csv'],'CSV fallback labelled unverified');
$check($s['city']==='','Address is not guessed to be a city');
$s=Summary::fromObservations([$ob('np_tracking','2026-10-03',4,['DateCreated'=>'0001-01-01 00:00:00','CostOnSite'=>null])]);
$check($s['date']==='' && $s['delivery']==='','Unknown values remain empty');
$s=Summary::fromObservations([$ob('np_tracking','2026-10-03',4,['DateCreated'=>'31.02.2026','CostOnSite'=>'bad'])]);
$check($s['date']==='' && $s['delivery']==='','Invalid dates and amounts not displayed as valid');
$s=Summary::fromObservations([$ob('np_tracking','2026-10-03',4,['DateCreated'=>'25.10.2026 01:30:00'])]);
$check($s['date']==='25.10.2026','Document date in dotted format');
echo "Registry summary: $checks checks passed.\n";
