<?php
/** Synthetic local DB fixtures; no actual carrier, Java, mail, orders or Folio writes. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local'
    || !defined('PNPM_NOVA_POSHTA_API_KEY') || PNPM_NOVA_POSHTA_API_KEY!=='synthetic-registry-test-key') throw new RuntimeException('Intercepted local CLI only');

use Paint\NovaPoshta\Registry\{Schema,Record,Store,Service,Folio,Carrier,WooOrders,DocumentValues,PackageFacts};
use Paint\NovaPoshta\Infrastructure\ApiClient;
use Paint\NovaPoshta\Admin\RegistryPage;

$checks=0;
$check=static function($ok,$message) use (&$checks) { if (!$ok) throw new RuntimeException($message); $checks++; };
$reject=static function(callable $work,$message) use ($check) { try { $work(); } catch (RuntimeException|InvalidArgumentException $e) { $check(true,$message); return; } throw new RuntimeException('Expected rejection: '.$message); };
global $wpdb;
$prefix=$wpdb->prefix; $old_user=$GLOBALS['current_user'];
$manager=new WP_User(); $manager->ID=PHP_INT_MAX-10; $manager->allcaps=['manage_woocommerce'=>true,'manage_pnpm_shipments'=>true]; $GLOBALS['current_user']=$manager;
$store=new Store(); $service=new Service(); $ttn='99999999000001'; $ttn2='99999999000002'; $ids=[];
$key='pnpm_registry_schema'; $old_schema=get_option($key,null);
// Exercise real migration and transactions against isolated disposable tables, not local business data.
$wpdb->prefix=$prefix.'pnpmtest_';
$http=[]; $documentReads=[]; $mail=0; $mode='good'; $status='1'; $partner='TEST'; $catalogMode='good'; $basisMode='NOT_FOUND'; $valuesMode='good';
add_filter('pre_wp_mail', static function() use (&$mail) { $mail++; return false; },PHP_INT_MAX);
$mock=static function($pre,$args,$url) use (&$http,&$documentReads,&$mode,&$status,&$partner,&$catalogMode,&$basisMode,&$valuesMode,$ttn,$ttn2) {
    $http[]=$url;
    $response=static fn($body)=>['headers'=>[],'body'=>wp_json_encode($body),'response'=>['code'=>200,'message'=>'OK']];
    if (str_starts_with($url,'https://api.novaposhta.ua/')) {
        $request=json_decode($args['body'],true);
        if (!in_array($request['calledMethod'],['getDocumentList','getStatusDocuments'],true)) throw new RuntimeException('Unexpected carrier mutation!');
        if ($mode==='fail') return $response(['success'=>false,'data'=>[],'errors'=>['synthetic failure']]);
        if ($request['calledMethod']==='getDocumentList') {
            $p=$request['methodProperties'];
            $documentReads[]=$p;
            $start=DateTimeImmutable::createFromFormat('!d.m.Y',$p['DateTimeFrom']);
            $finish=DateTimeImmutable::createFromFormat('!d.m.Y',$p['DateTimeTo']);
            if ($p['GetFullList']!=='0' || !$start || !$finish || $start->diff($finish)->days>30) throw new RuntimeException('Wrong monthly pagination contract');
            if ($mode==='period_error') return $response(['success'=>false,'data'=>[],'errors'=>['The difference between DateTimeFrom and DateTimeTo cannot be more than three months.']]);
            $rows=$p['Page']==='1' || $mode==='repeat' ? [['IntDocNumber'=>$ttn,'DateTime'=>'01.06.2026','StateId'=>'1','PayerType'=>'Recipient','CostOnSite'=>'70','Cost'=>'149','LinkedDocumentNumber'=>$ttn2]] : [];
            return $response(['success'=>true,'data'=>$rows]);
        }
        return $response(['success'=>true,'data'=>[['Number'=>$mode==='wrong'?$ttn2:$ttn,'StatusCode'=>$status,'Status'=>'Synthetic status',
            'PayerType'=>'Recipient','CostOnSite'=>'70','DocumentCost'=>'149','TrackingUpdateDate'=>'2026-06-01 12:30:00','ApiKey'=>'never-store','TrackingURL'=>'https://example.invalid/secret']]]);
    }
    parse_str(parse_url($url,PHP_URL_QUERY)?:'',$query);
    if (str_contains($url,'/ref/warehouses')) return $response([['code'=>7,'name'=>'Тестовий склад']]);
    if (str_contains($url,'/admin/folio/partners')) {
        if (($query['types']??'')!=='all' || ($query['limit']??'')!=='20') throw new RuntimeException('Bounded partner search including suppliers');
        return $response(['items'=>[['id'=>'TEST','shortName'=>'TEST','name'=>'Тестовий клієнт']]]);
    }
    if (str_contains($url,'/admin/folio/customer-documents?')) {
        if (($query['partnerShortName']??'')!=='TEST' || ($query['limit']??'')!=='50') throw new RuntimeException('Use saved shipment partner and bounded page');
        $row=['documentType'=>$query['types'],'documentId'=>123,'documentNumber'=>'50','documentNumberSuffix'=>'a','documentDate'=>'2026-06-01T12:00:00','warehouseId'=>7,'returnDocument'=>false,'totalAmount'=>'149.00','operationKind'=>'*ПЕРЕМЕЩЕНИЕ','sourceInfo'=>'Тестовий маршрут','additionalInfo'=>'Тестова примітка'];
        return $response(['ok'=>true,'partner'=>['shortName'=>$catalogMode==='wrong_partner'?'OTHER':'TEST'],
            'documents'=>[$row,array_merge($row,['documentId'=>124,'documentNumber'=>'51','warehouseId'=>8]),array_merge($row,['documentId'=>125,'returnDocument'=>true])],
            'hasMore'=>!isset($query['cursor']),'nextCursor'=>$catalogMode==='bad_cursor'?'':'next-page']);
    }
    if (str_ends_with(parse_url($url,PHP_URL_PATH),'/values')) {
        if($valuesMode==='offline') return new WP_Error('offline','Synthetic outage');
        $second=str_contains($url,'/124/');
        return $response(['ok'=>true,'values'=>['documentType'=>str_contains($url,'/EXPENSE/')?'EXPENSE':'ACCOUNT',
            'documentId'=>$second?124:123,'partnerShortName'=>$valuesMode==='wrong'?'OTHER':'TEST',
            'documentNumber'=>$second?'51':'50','documentNumberSuffix'=>'a','documentDate'=>match($valuesMode) {'array_date'=>[2026,6,1], 'bad_date'=>[2026,2,31], 'wrong_date'=>[2026,6,2], default=>'2026-06-01'},'warehouseId'=>$second?8:7,
            'documentAmount'=>'149.00','shipmentAmount'=>'149.00','accountingAmount'=>$valuesMode==='missing'?null:'100.25',
            'lineCount'=>2,'missingAmountLines'=>0,'missingAccountingLines'=>$valuesMode==='missing'?1:0,'currency'=>'UAH','source'=>'ACTIVE_LEDGER']]);
    }
    if (str_contains($url,'/basis-account')) {
        if ($basisMode==='offline') return new WP_Error('unavailable','Synthetic outage');
        return $response(['ok'=>true,'partnerShortName'=>'TEST','expenseId'=>123,'basis'=>'123456Woo о 18.06.2026','status'=>$basisMode,
            'account'=>$basisMode==='MATCHED'?['documentType'=>'ACCOUNT','documentId'=>321,'documentNumber'=>'123456','documentNumberSuffix'=>'Woo','documentDate'=>'2026-06-18','warehouseId'=>7,'partnerShortName'=>'TEST','source'=>'ARCHIVE_LEDGER']:null]);
    }
    if (str_contains($url,'/admin/folio/customer-documents/')) {
        $type=str_contains($url,'/EXPENSE/')?'EXPENSE':'ACCOUNT'; $second=str_ends_with(parse_url($url,PHP_URL_PATH),'/124');
        return $response(['ok'=>true,'partner'=>['shortName'=>$partner],'document'=>['documentType'=>$type,'documentId'=>$second?124:123,
            'documentNumber'=>$second?'51':'50','documentNumberSuffix'=>'a','documentDate'=>'2026-06-01T12:00:00.000','returnDocument'=>false,'warehouseId'=>$second?8:7,
            'basis'=>'123456Woo о 18.06.2026','totalAmount'=>'149.00','items'=>[['sku'=>'not-needed']],'linkedPayments'=>[['private'=>'not-needed']]]]);
    }
    if (str_contains($url,'/admin/folio/receipt-catalogue')) return $response(['ok'=>true,'warehouseId'=>(int)$query['warehouseId'],'date'=>$query['date'],
        'documents'=>[['id'=>123,'number'=>'50a','date'=>array_map('intval',explode('-',$query['date'])),'warehouseId'=>(int)$query['warehouseId'],'type'=>'receipt','accounted'=>true]],'hasMore'=>false]);
    return new WP_Error('blocked','Unexpected HTTP blocked');
};
add_filter('pre_http_request',$mock,PHP_INT_MAX,3);
try {
    Schema::install(); $check(Schema::ready(),'Schema installed'); Schema::install();
    $check(count($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.'pnpm_registry').'%')))===5,'Five dedicated InnoDB tables, migration repeatable');
    $check(Record::ttn(' '.$ttn.' ')===$ttn,'TTN normalized'); $reject(fn()=>Record::ttn('123'),'Invalid TTN');
    $reject(fn()=>Record::date('2026-02-30'),'Real dates only');
    $check(Record::clean(['ApiKey'=>'secret','url'=>'https://secret','LinkedDocumentNumber'=>$ttn,'x'=>'https://secret'])===['LinkedDocumentNumber'=>$ttn],'Secrets removed, related number retained');
    $csv="ttn;date;sender;recipient;origin;destination;payer;delivery_cost\n$ttn;2026-06-01;Sender;Recipient;Origin;Destination;Recipient;\n";
    $check(Record::csv($csv)[0]['data']['delivery_cost']===null,'Unknown cost remains null');
    $reject(fn()=>Record::csv($csv."$ttn;2026-06-01;S;R;O;D;R;0\n"),'Duplicate CSV rows rejected');
    $reject(fn()=>Record::csv(str_replace('2026-06-01','2026-05-31',$csv)),'History lower bound');
    $reject(fn()=>Record::metadata(['direction'=>'outgoing','purpose'=>'purchase']),'Incoming purpose consistency');
    $p=$service->run('history',['from'=>'2026-06-01','to'=>'2026-06-30']);
    $check($store->listing([])===[],'Preview does not create registry record');
    $reject(fn()=>$service->run('confirm',['token'=>$p['token']]),'Explicit confirmation required');
    $confirm=fn($token)=>$service->run('confirm',['token'=>$token,'confirmed'=>'1']);
    $saved=$confirm($p['token']); $id=$saved['id']; $ids[]=$id;
    $check($id>0 && count($store->detail($id)['observations'])===1,'History imported');
    $check($confirm($p['token'])===$saved,'Repeated confirmation returns durable result');
    $mode='repeat'; $reject(fn()=>$service->run('history',['from'=>'2026-06-01','to'=>'2026-06-30','previous'=>$p['token']]),'Server ignoring pagination rejected'); $mode='good';
    $page2=$service->run('history',['from'=>'2026-06-01','to'=>'2026-06-30','previous'=>$p['token']]);
    $end=$confirm($page2['token']); $check($end['scope']['empty_page'] && !$end['scope']['next_page'],'Empty-page evidence is scoped');
    $check($end['scope']['coverage']==='account_document_list_only','No false all incoming coverage');
    $reject(fn()=>(new Carrier())->documents('2026-06-01','2026-10-25',1),'Transport rejects long period before HTTP');
    $long=['from'=>'2026-06-15','to'=>'2026-08-05'];
    $cursor=$service->run('history',$long);
    $check($cursor['scope']['window_from']==='2026-06-15' && $cursor['scope']['window_to']==='2026-06-30','Partial first month');
    $confirm($cursor['token']);
    $cursor=$service->run('history',$long+['previous'=>$cursor['token']]);
    $check($cursor['scope']['page']===2 && $cursor['scope']['window_from']==='2026-06-15','Pages stay in the same month until empty');
    $result=$confirm($cursor['token']);
    $check($result['scope']['next_page']===1 && $result['scope']['next_from']==='2026-07-01','Empty page advances to next month, not completion');
    $check($confirm($cursor['token'])===$result,'Repeated boundary confirmation does not skip a month');
    $cursor=$service->run('history',$long+['previous'=>$cursor['token']]);
    $check($cursor['scope']['page']===1 && $cursor['scope']['window_from']==='2026-07-01' && $cursor['scope']['window_to']==='2026-07-31','31-day month included');
    $confirm($cursor['token']); $cursor=$service->run('history',$long+['previous'=>$cursor['token']]); $confirm($cursor['token']);
    $cursor=$service->run('history',$long+['previous'=>$cursor['token']]);
    $check($cursor['scope']['window_from']==='2026-08-01' && $cursor['scope']['window_to']==='2026-08-05','Last month clipped to selected end');
    $confirm($cursor['token']); $cursor=$service->run('history',$long+['previous'=>$cursor['token']]); $result=$confirm($cursor['token']);
    $check($result['scope']['empty_page'] && $result['scope']['next_page']===null && $result['scope']['next_from']===null,'Only last empty page ends full selected period');
    $reject(fn()=>$service->run('history',$long+['previous'=>$cursor['token']]),'Finished period cannot continue');
    $mode='period_error';
    try { (new Carrier())->documents('2026-06-01','2026-06-30',1); throw new Exception('Expected period error'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'three months'),'Known provider error becomes actionable'); }
    $mode='good';
    $legacy=$store->preview('import',['source'=>'np_documents','rows'=>[],'scope'=>['from'=>'2026-06-01','to'=>'2026-06-30','next_page'=>2],
        'observed_at'=>gmdate('Y-m-d H:i:s'),'account'=>(new Carrier())->fingerprint()]);
    $confirm($legacy);
    $reject(fn()=>$service->run('history',['from'=>'2026-06-01','to'=>'2026-06-30','previous'=>$legacy]),'Legacy cursor requires explicit restart');
    $service->run('classify',['id'=>$id,'revision'=>1,'direction'=>'outgoing','purpose'=>'sale','partner_code'=>'TEST']);
    $reject(fn()=>$service->run('classify',['id'=>$id,'revision'=>1]),'Stale classification blocked');
    $first=$service->run('tracking',['ttn'=>$ttn]); $confirm($first['token']);
    $again=$service->run('tracking',['ttn'=>$ttn]); $confirm($again['token']);
    $check(count($store->detail($id)['observations'])===2,'Same response deduplicated per source');
    $status='9'; $updated=$service->run('tracking',['ttn'=>$ttn]); $confirm($updated['token']);
    $check(count($store->detail($id)['observations'])===3,'Changed response retained');
    $check($store->find($id)['partner_code']==='TEST','Refresh preserves manager metadata');
    $before=$store->detail($id); $mode='fail'; $reject(fn()=>$service->run('tracking',['ttn'=>$ttn]),'Carrier error blocks import');
    $check($store->detail($id)===$before,'Carrier error does not erase evidence');
    $mode='wrong'; $reject(fn()=>$service->run('tracking',['ttn'=>$ttn]),'Wrong TTN rejected'); $mode='good';
    $d=['id'=>$id,'revision'=>2,'type'=>'ACCOUNT','role'=>'sale_account','verification'=>'api','document_id'=>'123','number'=>'50a','date'=>'2026-06-01','partner'=>'TEST','warehouse'=>'7','evidence'=>'Synthetic document checked'];
    $doc=$service->run('link_preview',$d);
    $reject(fn()=>$service->run('link_preview',array_merge($d,['warehouse'=>'8'])),'Wrong document warehouse rejected');
    $check(!isset($doc['document']['api_snapshot']['items']) && !isset($doc['document']['api_snapshot']['linkedPayments']),'Only header evidence retained');
    $partner='OTHER'; $reject(fn()=>$service->run('link_preview',$d),'Wrong Folio partner rejected'); $partner='TEST';
    $confirm($doc['token']); $check(count($store->detail($id)['links'])===1,'Confirmed account linked');
    $account=(int)$store->detail($id)['links'][0]['id'];
    $expense=array_merge($d,['revision'=>3,'type'=>'EXPENSE','role'=>'sale_expense','account_link_id'=>$account]);
    $exp=$service->run('link_preview',$expense); $confirm($exp['token']);
    $check(count($store->detail($id)['links'])===2,'Account retained alongside expense');
    $expenseId=(int)$store->detail($id)['links'][0]['id'];
    $reject(fn()=>$service->run('classify',['id'=>$id,'revision'=>4,'direction'=>'incoming','purpose'=>'purchase','partner_code'=>'TEST']),'Direction cannot invalidate active links');
    $reject(fn()=>$service->run('unlink',['id'=>$id,'revision'=>4,'link'=>$account,'reason'=>'Synthetic correction']),'Account cannot be revoked before its expense');
    $service->run('unlink',['id'=>$id,'revision'=>4,'link'=>$expenseId,'reason'=>'Synthetic correction']);
    $check($store->detail($id)['links'][0]['active']==='0','Link revoked without deletion');
    $service->run('unlink',['id'=>$id,'revision'=>5,'link'=>$account,'reason'=>'Synthetic correction']);
    $service->run('classify',['id'=>$id,'revision'=>6,'direction'=>'incoming','purpose'=>'purchase','partner_code'=>'TEST']);
    $receipt=array_merge($d,['revision'=>7,'type'=>'RECEIPT','role'=>'purchase']);
    $r=$service->run('link_preview',$receipt); $check($r['document']['verification']==='api_header_manual_party','Receipt supplier not falsely API verified'); $confirm($r['token']);
    $manual=$service->run('manual',['ttn'=>$ttn2,'evidence'=>'Synthetic paper return']); $new=$confirm($manual['token']); $ids[]=$new['id'];
    $service->run('classify',['id'=>$new['id'],'revision'=>1,'direction'=>'incoming','purpose'=>'customer_return','partner_code'=>'TEST','related_ttn'=>$ttn]);
    $return=array_merge($d,['id'=>$new['id'],'revision'=>2,'type'=>'RETURN','role'=>'customer_return','verification'=>'manual','document_id'=>'','evidence'=>'Checked paper return document']);
    $rp=$service->run('link_preview',$return); $confirm($rp['token']);
    $check($store->detail($new['id'])['links'][0]['verification']==='manual','Return manual evidence marked explicitly');
    $bad=$service->run('csv',['csv'=>str_replace($ttn,'99999999000003',$csv)]);
    $old_suppress=$wpdb->suppress_errors(true);
    $break=static fn($sql)=>str_starts_with($sql,'INSERT INTO `'.$wpdb->prefix.'pnpm_registry_observations`') ? 'INVALID SYNTHETIC QUERY' : $sql;
    add_filter('query',$break);
    try { $reject(fn()=>$confirm($bad['token']),'Failed insert rolls back import and consumption'); }
    finally { remove_filter('query',$break); $wpdb->suppress_errors($old_suppress); }
    $check($store->listing(['ttn'=>'99999999000003'])===[],'No partial import after failure');
    $ok=$confirm($bad['token']); $ids[]=$ok['id']; $check($ok['count']===1,'Same confirmation can recover after rollback');
    $expired=$service->run('csv',['csv'=>$csv]);
    $wpdb->update($wpdb->prefix.'pnpm_registry_batches',['expires_at'=>'2000-01-01 00:00:00'],['token_hash'=>hash('sha256',$expired['token'])]);
    $reject(fn()=>$confirm($expired['token']),'Expired preview rejected');
    $owner=$service->run('csv',['csv'=>$csv]);
    $manager->ID--;
    $reject(fn()=>$confirm($owner['token']),'Preview belongs to its manager');
    $manager->ID++;
    $changedAccount=$store->preview('import',['account'=>'changed','rows'=>[]]);
    $reject(fn()=>$confirm($changedAccount),'Account changed between read and confirm');
    $manualCheck=$service->run('link_preview',array_merge($receipt,['revision'=>8,'verification'=>'manual']));
    $service->run('classify',['id'=>$id,'revision'=>8,'direction'=>'incoming','purpose'=>'purchase','partner_code'=>'TEST','note'=>'Changed after preview']);
    $reject(fn()=>$confirm($manualCheck['token']),'Document preview invalidated by manager edit');
    $beforeAudit=$store->detail($id);
    $breakAudit=static fn($sql)=>str_starts_with($sql,'INSERT INTO `'.$wpdb->prefix.'pnpm_registry_audit`') ? 'INVALID SYNTHETIC QUERY' : $sql;
    $old_suppress=$wpdb->suppress_errors(true); add_filter('query',$breakAudit);
    try { $reject(fn()=>$service->run('classify',['id'=>$id,'revision'=>9,'direction'=>'incoming','purpose'=>'purchase','partner_code'=>'TEST','note'=>'Must roll back']),'Audit failure rolls back metadata'); }
    finally { remove_filter('query',$breakAudit); $wpdb->suppress_errors($old_suppress); }
    $check($store->detail($id)===$beforeAudit,'Metadata and audit are atomic');
    $manager->allcaps=[]; $reject(fn()=>$service->run('tracking',['ttn'=>$ttn]),'Unauthorized user rejected');
    $manager->allcaps=['manage_woocommerce'=>true]; $reject(fn()=>$service->run('tracking',['ttn'=>$ttn]),'Shipment permission also required');
    $manager->allcaps=['manage_woocommerce'=>true,'manage_pnpm_shipments'=>true];
    $_GET=['page'=>'pnpm-registry','id'=>$id];
    ob_start(); (new RegistryPage())->render(); $html=ob_get_clean();
    $check(str_contains($html,'data-command="link_preview"') && str_contains($html,$ttn),'Registry detail renders');
    $check(!str_contains($html,'never-store') && !str_contains($html,'synthetic-registry-test-key'),'Secrets absent from rendered page');
    $summaryTtn='99999999000004';
    $summaryId=$store->transaction(fn()=>$store->observe($summaryTtn,'np_documents',[
        'RecipientDescription'=>'Тестова компанія & партнери <script>alert(1)</script>','RecipientContactPerson'=>'Тестовий одержувач',
        'CityRecipientDescription'=>'Київ','DateTime'=>'2026-10-20 10:00:00','Cost'=>'13217.00','CostOnSite'=>'1116.09','PayerType'=>'Sender','Weight'=>'3.5','SeatsAmount'=>'2','OptionsSeat'=>[['weight'=>'1.5','volumetricLength'=>'25','volumetricWidth'=>'16','volumetricHeight'=>'12'],['weight'=>'2','volumetricLength'=>'30','volumetricWidth'=>'20','volumetricHeight'=>'15']],
    ],'2026-10-21 10:00:00'));
    $store->transaction(fn()=>$store->observe($summaryTtn,'np_tracking',['CostOnSite'=>'0','PayerType'=>'ThirdPerson'],'2026-10-22 10:00:00'));
    // A late-arriving old snapshot must not override newer evidence or supply its missing city.
    $store->transaction(fn()=>$store->observe($summaryTtn,'np_tracking',['CostOnSite'=>'999','CityRecipient'=>'Wrong old city'],'2026-10-20 10:00:00'));
    $classified=$service->run('classify',['id'=>$summaryId,'revision'=>1,'direction'=>'outgoing','purpose'=>'sale','partner_code'=>'TEST']);
    $check($classified['classification']['revision']===2 && $classified['classification']['partner_code']==='TEST'
        && $classified['classification']['direction']==='outgoing' && $classified['classification']['note']==='',
        'Classification returns accepted snapshot and revision for all forms without a page reload');
    $queries=[]; $capture=static function($sql) use (&$queries) { $queries[]=$sql; return $sql; };
    $httpBefore=count($http); add_filter('query',$capture);
    try { $list=$store->listing(['ttn'=>$summaryTtn]); } finally { remove_filter('query',$capture); }
    $check(count($list)===1 && $list[0]['summary']['city']==='Київ' && $list[0]['summary']['date']==='20.10.2026','Listing projects existing source fields; skips stale tracking city');
    $check($list[0]['summary']['delivery']==='0' && $list[0]['summary']['declared']==='13217.00' && $list[0]['summary']['payer']==='ThirdPerson','Latest observation time wins over insertion order; amounts separate');
    $check($list[0]['partner_code']==='TEST' && $list[0]['summary']['contact']==='Тестовий одержувач','Manager Folio code and recipient contact retained');
    $check(count($http)===$httpBefore && count($queries)===4 && count(array_filter($queries,static fn($sql)=>str_starts_with($sql,'SELECT ')))===4,'Listing uses four bounded reads and no HTTP or writes');
    $_GET=['page'=>'pnpm-registry']; ob_start(); (new RegistryPage())->render(); $index=ob_get_clean();
    $check(str_contains($index,'Київ') && str_contains($index,'20.10.2026') && str_contains($index,'TEST') && str_contains($index,number_format_i18n(13217,2)),'Requested summary fields render');
    $check(!str_contains($index,'<script>alert(1)</script>') && str_contains($index,'компанія &amp; партнери'),'Carrier text sanitized and escaped in list');
    $beforeCatalog=$store->detail($summaryId);
    $check($service->run('partners',['q'=>'те'])['items'][0]['name']==='Тестовий клієнт','Partner selector provides short code and full name');
    $countHttp=count($http); $check($service->run('partners',['q'=>'т'])['items']===[] && count($http)===$countHttp,'Short query never calls Java');
    $check($service->run('warehouses',[])['items'][0]['code']==='7','Shared warehouse directory normalized');
    $query=['id'=>$summaryId,'revision'=>2,'type'=>'ACCOUNT','warehouse'=>'7','date_from'=>'2026-06-01','date_to'=>'2026-06-02','partner'=>'UNTRUSTED'];
    $documents=$service->run('documents',$query);
    $check(count($documents['items'])===1 && $documents['items'][0]['number']==='50a' && $documents['cursor']==='next-page','Documents scoped to saved partner, warehouse and non-return type');
    $check($service->run('documents',$query+['cursor'=>'next-page'])['cursor']==='','Customer pagination terminates');
    $catalogMode='wrong_partner'; $reject(fn()=>$service->run('documents',$query),'Different partner response rejected');
    $catalogMode='bad_cursor'; $reject(fn()=>$service->run('documents',$query),'Broken continuation rejected'); $catalogMode='good';
    $reject(fn()=>$service->run('documents',array_merge($query,['revision'=>1])),'Stale registry selection rejected');
    $reject(fn()=>$service->run('documents',array_merge($query,['warehouse'=>'0'])),'Warehouse required');
    $reject(fn()=>$service->run('documents',array_merge($query,['date_to'=>'2026-05-31'])),'Reversed dates rejected');
    $reject(fn()=>$service->run('documents',array_merge($query,['type'=>'RETURN'])),'Returns keep manual verification');
    $receipts=$service->run('documents',array_merge($query,['type'=>'RECEIPT']));
    $check($receipts['receipt'] && $receipts['cursor']==='2026-06-02|0','Receipt catalogue advances one day without supplier claim');
    $check($service->run('documents',array_merge($query,['type'=>'RECEIPT','cursor'=>$receipts['cursor']]))['cursor']==='','Receipt period completes');
    $reject(fn()=>$service->run('documents',array_merge($query,['type'=>'RECEIPT','cursor'=>'2026-06-03|0'])),'Receipt cursor cannot leave selected period');
    $check($store->detail($summaryId)===$beforeCatalog,'Selectors do not change registry records, observations or links');
    $multiQuery=array_merge($query,['warehouses'=>'7,8','type'=>'EXPENSE','operation'=>'ПЕРЕМЕЩЕНИЕ']);
    $multi=$service->run('documents',$multiQuery);
    $check(count($multi['items'])===2 && $multi['items'][1]['warehouse']==='8' && $multi['items'][0]['source_info']==='Тестовий маршрут' && $multi['items'][0]['additional_info']==='Тестова примітка','Multiple warehouses and all selection details returned');
    $check($service->run('documents',array_merge($multiQuery,['operation'=>'НЕМАЄ']))['items']===[],'Operation text is filtered, even with an older API');
    $reject(fn()=>$service->run('documents',array_merge($multiQuery,['warehouses'=>'7,0'])),'Invalid warehouse list rejected');
    $r=$service->run('documents',array_merge($multiQuery,['type'=>'RECEIPT','operation'=>'','date_to'=>'2026-06-01']));
    $check($r['cursor']==='1!2026-06-01|0','Receipt cursor advances to the next warehouse');
    $r=$service->run('documents',array_merge($multiQuery,['type'=>'RECEIPT','operation'=>'','date_to'=>'2026-06-01','cursor'=>$r['cursor']]));
    $check($r['cursor']==='' && $r['items'][0]['warehouse']==='8','Receipt paging completes across warehouses');
    $transferId=$store->transaction(fn()=>$store->observe('99999999000009','manual',['evidence'=>'Synthetic transfer'],'2026-06-01 12:00:00'));
    $store->classify($transferId,1,['partner_code'=>'TEST','direction'=>'internal','purpose'=>'other']);
    $batch=array_merge($expense,['id'=>$transferId,'revision'=>2,'role'=>'other','account_link_id'=>0,'selected_documents'=>wp_json_encode($multi['items'])]);
    $beforeTransfer=$store->detail($transferId); $p=$service->run('link_preview',$batch);
    $check(count($p['documents'])===2 && empty($p['documents'][0]['related_account']) && $store->detail($transferId)===$beforeTransfer,'Transfer batch is read-only preview, no account or Woo required');
    $wrong=$multi['items']; $wrong[1]['warehouse']='7';
    $reject(fn()=>$service->run('link_preview',array_merge($batch,['selected_documents'=>wp_json_encode($wrong)])),'Every selected header checked against API');
    $reject(fn()=>$service->run('link_preview',array_merge($batch,['selected_documents'=>wp_json_encode([$multi['items'][0],$multi['items'][0]])])),'Duplicate document IDs rejected');
    $reject(fn()=>$service->run('link_preview',array_merge($batch,['selected_documents'=>wp_json_encode(array_fill(0,21,$multi['items'][0]))])),'Batch limited to twenty documents');
    $breakSecond=static fn($sql)=>str_starts_with($sql,'INSERT INTO `'.$wpdb->prefix.'pnpm_registry_links`') && str_contains($sql,"'124'") ? 'INVALID SYNTHETIC QUERY' : $sql;
    $old_suppress=$wpdb->suppress_errors(true); add_filter('query',$breakSecond);
    try { $reject(fn()=>$confirm($p['token']),'Second link failure rejects batch'); }
    finally { remove_filter('query',$breakSecond); $wpdb->suppress_errors($old_suppress); }
    $check($store->detail($transferId)===$beforeTransfer,'Batch failure rolls back first link and audit');
    $savedBatch=$confirm($p['token']); $check($confirm($p['token'])===$savedBatch,'Batch confirmation replay is idempotent');
    $check(count($store->detail($transferId)['links'])===2 && (int)$store->find($transferId)['revision']===4,'Two warehouses linked in one confirmation');

    // Expenses stand alone. Basis accounts are optional, read-only proposals in the same confirmation.
    $directId=$store->transaction(fn()=>$store->observe('99999999000006','manual',['evidence'=>'Synthetic direct expense'],'2026-06-01 12:00:00'));
    $store->classify($directId,1,['partner_code'=>'TEST','direction'=>'outgoing','purpose'=>'sale']);
    $direct=array_merge($expense,['id'=>$directId,'revision'=>2,'account_link_id'=>0]);
    $p=$service->run('link_preview',$direct);
    $check($p['document']['basis_lookup_status']==='NOT_FOUND' && empty($p['document']['related_account']),'Missing basis account does not block expense preview');
    $confirm($p['token']);
    $check(count($store->detail($directId)['links'])===1 && $store->detail($directId)['links'][0]['document_type']==='EXPENSE','Expense confirms without account or Woo order');
    $basisMode='MATCHED'; $direct['revision']=3; $p=$service->run('link_preview',$direct);
    $check($p['document']['related_account']['source']==='ARCHIVE_LEDGER' && count($store->detail($directId)['links'])===1,'Archived account proposed without saving on preview');
    $result=$confirm($p['token']); $check($confirm($p['token'])===$result,'Account and expense confirmation replay is idempotent');
    $linked=$store->detail($directId)['links'];
    $check(count($linked)===2 && $linked[0]['document_type']==='ACCOUNT' && (int)$store->find($directId)['revision']===5,'Account and expense saved together');
    $expenseSaved=json_decode($linked[1]['snapshot'],true);
    $check($expenseSaved['account_link_id']===(int)$linked[0]['id'],'Expense points to the confirmed archived account');
    $direct['revision']=5; $basisMode='AMBIGUOUS'; $p=$service->run('link_preview',$direct);
    $check($p['document']['basis_lookup_status']==='AMBIGUOUS' && empty($p['document']['related_account']),'Ambiguous basis never selects an account');
    $basisMode='offline'; $p=$service->run('link_preview',$direct);
    $check($p['document']['basis_lookup_status']==='UNAVAILABLE' && empty($p['document']['related_account']),'Old or unavailable API still allows expense alone');
    $p=$service->run('link_preview',array_merge($direct,['account_link_id'=>999999]));
    $reject(fn()=>$confirm($p['token']),'An explicitly selected unrelated account is rejected');
    $basisMode='MATCHED'; $p=$service->run('link_preview',$direct); $beforeBundle=$store->detail($directId);
    $breakExpense=static fn($sql)=>str_starts_with($sql,'UPDATE `'.$wpdb->prefix.'pnpm_registry_links`') && str_contains($sql,"'EXPENSE'") ? 'INVALID SYNTHETIC QUERY' : $sql;
    $old_suppress=$wpdb->suppress_errors(true); add_filter('query',$breakExpense);
    try { $reject(fn()=>$confirm($p['token']),'Expense failure rolls back whole bundle'); }
    finally { remove_filter('query',$breakExpense); $wpdb->suppress_errors($old_suppress); }
    $check($store->detail($directId)===$beforeBundle,'No partial account update after bundle failure');
    $basisMode='NOT_FOUND';

    $v=$store->detail($transferId)['links'][0]; $values=json_decode($v['snapshot'],true)['values'];
    $check($values['document_amount']==='149.00' && $values['shipment_amount']==='149.00' && $values['accounting_amount']==='100.25' && $values['currency']==='UAH','Document amounts captured as decimal strings, independently of carrier costs');
    $valuesMode='array_date'; $p=$service->run('link_preview',$direct);
    $check($p['document']['values']['accounting_amount']==='100.25','Production Java LocalDate arrays preserve verified document amounts');
    foreach (['bad_date','wrong_date'] as $valuesMode) {
        $p=$service->run('link_preview',$direct);
        $check($p['document']['values']['status']==='values_unavailable','Invalid or different Java date cannot match the selected document');
    }
    $valuesMode='missing'; $p=$service->run('link_preview',$direct);
    $check($p['document']['values']['accounting_amount']===null && $p['document']['values']['missing_accounting_lines']===1,'Missing accounting line is not zero or a partial sum');
    $valuesMode='wrong'; $p=$service->run('link_preview',$direct);
    $check($p['document']['values']['accounting_amount']===null && $p['document']['values']['status']==='values_unavailable','Different partner values cannot enter snapshot');
    $valuesMode='offline'; $p=$service->run('link_preview',$direct);
    $check($p['document']['values']['document_amount']==='149.00' && $p['document']['values']['accounting_amount']===null,'Old API retains verified header total without inventing cost');
    $valuesMode='good';
    $link=$store->detail($transferId)['links'][0]; $before=$store->detail($transferId);
    $valuesMode='offline';
    $reject(fn()=>$service->run('refresh_link_preview',['id'=>$transferId,'revision'=>4,'link'=>$link['id']]),'Unavailable amounts cannot replace a saved snapshot');
    $check($store->detail($transferId)===$before,'Failed values refresh preserves existing link and amounts');
    $valuesMode='array_date';
    $refresh=$service->run('refresh_link_preview',['id'=>$transferId,'revision'=>4,'link'=>$link['id']]);
    $check($store->detail($transferId)===$before,'Refreshing values is preview-only');
    $confirm($refresh['token']); $check(count($store->detail($transferId)['links'])===2 && (int)$store->find($transferId)['revision']===5,'Refresh updates the existing link without duplicate');
    $valuesMode='good';
    $reject(fn()=>$service->run('refresh_link_preview',['id'=>$transferId,'revision'=>5,'link'=>999999]),'Refresh cannot access unrelated link');
    $check(DocumentValues::money('0')==='0.00' && DocumentValues::money('-12.34')==='-12.34' && DocumentValues::money(null)===null && DocumentValues::money('1e4')===null,'Money retains zero, signed values and unknown without floating arithmetic');
    $packages=$store->detail($summaryId)['carrier_packages'];
    $check(count($packages['places'])===2 && $packages['places'][0]['length_cm']==='25' && $packages['places'][1]['weight_kg']==='2','Carrier place sizes and weights survive observation storage');
    $check($packages['captured_at_utc']==='2026-10-21 10:00:00' && $packages['total_weight_kg']==='3.5','Dimensions retain their own snapshot date, not the latest tracking date');
    $local=PackageFacts::local(['id'=>1,'order_id'=>2,'request_snapshot'=>wp_json_encode(['boxes'=>[['weight'=>1.5,'length'=>25,'width'=>16,'height'=>12]],'carrier'=>['OptionsSeat'=>[['weight'=>2,'volumetricLength'=>30,'volumetricWidth'=>20,'volumetricHeight'=>15]],'Weight'=>2,'SeatsAmount'=>1]])]);
    $check($local['source']==='site_package_entry' && $local['places'][0]['length_cm']==='30' && $local['places'][0]['weight_kg']==='2','Exact durable creation payload takes precedence over earlier preparation');
    $noPlaces=PackageFacts::carrier([['source'=>'np_tracking','observed_at'=>'2026-06-01 00:00:00','payload'=>wp_json_encode(['Weight'=>10,'SeatsAmount'=>3])]]);
    $check($noPlaces['places']===[] && $noPlaces['place_count']===3,'Total mass never becomes guessed per-place mass');
    $badPlaces=PackageFacts::local(['id'=>1,'order_id'=>2,'request_snapshot'=>wp_json_encode(['boxes'=>[['weight'=>0,'length'=>'secret','width'=>null,'height'=>-1]]])]);
    $check($badPlaces['places'][0]['weight_kg']===null && $badPlaces['places'][0]['length_cm']===null,'Invalid or missing package fields stay unknown');

    // In-memory CRUD objects only: never create/change actual Woo orders in this DB.
    $wooA=new WC_Order(); $wooA->set_date_created('2026-06-01 12:00:00'); $wooA->set_status('processing');
    $wooA->set_currency('UAH'); $wooA->set_total('149.00'); $wooA->set_billing_first_name('Synthetic'); $wooA->set_billing_last_name('Customer'); $wooA->set_id(999999001);
    $wooB=clone $wooA; $wooB->set_id(999999002);
    $savedFolio=['documents'=>[['document_id'=>'321','document_number'=>'TEST-ACCOUNT','document_type'=>'account','document_date'=>'2026-06-01','folio_warehouse_id'=>'7','items'=>[['private'=>'not-needed']]]]];
    $wooA->update_meta_data('_folio_documents_result',$savedFolio);
    $wooB->update_meta_data('_folio_document_id','654'); $wooB->update_meta_data('_folio_document_number','LEGACY'); $wooB->update_meta_data('_folio_document_type','account');
    $wooFixture=[999999001=>$wooA,999999002=>$wooB]; $wooQueries=[];
    $woo=new WooOrders(static function($id) use (&$wooFixture) { return $wooFixture[$id]??false; },
        static function($args) use (&$wooQueries,$wooA) { $wooQueries[]=$args; return (object)['orders'=>[$wooA],'max_num_pages'=>2]; });
    $wooService=new Service($store,new Carrier(),new Folio(),$woo);
    $wooRegistry=$store->transaction(fn()=>$store->observe('99999999000005','manual',['evidence'=>'Synthetic Woo registry'],'2026-06-01 12:00:00'));
    $httpBefore=count($http);
    $orderList=$wooService->run('woo_orders',['woo_from'=>'2026-06-01','woo_to'=>'2026-06-02','woo_email'=>'test@example.invalid']);
    $check($orderList['next_page']===2 && $wooQueries[0]['limit']===20 && $wooQueries[0]['type']==='shop_order'
        && $wooQueries[0]['billing_email']==='test@example.invalid' && $wooQueries[0]['date_created']==='2026-06-01...2026-06-02','Woo search uses bounded CRUD query and explicit filters');
    $check($wooService->run('woo_orders',['woo_id'=>'999999001'])['items'][0]['order_id']===999999001 && count($wooQueries)===1,'Exact Woo ID uses CRUD read independently of dates');
    $reject(fn()=>$wooService->run('woo_orders',['woo_id'=>'invalid']),'Invalid Woo ID rejected');
    $reject(fn()=>$wooService->run('woo_orders',['woo_id'=>'999999999']),'Unavailable Woo order rejected');
    $reject(fn()=>$wooService->run('woo_orders',['woo_from'=>'2026-06-02','woo_to'=>'2026-06-01']),'Woo reversed date period rejected');
    $draft=['id'=>$wooRegistry,'revision'=>1,'woo_order_id'=>'999999001','evidence'=>''];
    $preview=$wooService->run('woo_preview',$draft);
    $check(!isset($preview['document']['order_fingerprint']) && count($store->detail($wooRegistry)['links'])===0,'Woo preview creates no association and hides fingerprint');
    $check($preview['document']['evidence']==='' && $preview['document']['verification']==='manager_confirmation','Woo comment is optional; manager confirmation remains explicit');
    $check($preview['document']['order_snapshot']['folio_documents']===[['id'=>'321','number'=>'TEST-ACCOUNT','type'=>'ACCOUNT','date'=>'2026-06-01','warehouse'=>'7']],'Only saved Folio headers shown from Woo, not raw payload');
    $check($preview['document']['registry_context']===['partner'=>'','documents'=>[]],'Unclassified TTN explicitly has no saved Folio context');
    $folioPreview=$wooService->run('woo_preview',['id'=>$id,'revision'=>9,'woo_order_id'=>'999999001','evidence'=>'OK']);
    $context=$folioPreview['document']['registry_context'];
    $check($context['partner']==='TEST' && count($context['documents'])===1 && $context['documents'][0]['type']==='RECEIPT','Folio context includes saved partner and active documents, not revoked links');
    $wooA->update_meta_data('_folio_documents_result',['documents'=>[]]);
    $reject(fn()=>$wooService->run('confirm',['token'=>$preview['token'],'confirmed'=>'1']),'Changed saved Folio metadata invalidates Woo preview');
    $wooA->update_meta_data('_folio_documents_result',$savedFolio);
    $wooA->set_total('150.00');
    $reject(fn()=>$wooService->run('confirm',['token'=>$preview['token'],'confirmed'=>'1']),'Changed Woo snapshot blocks confirmation');
    $wooA->set_total('149.00');
    $confirmed=$wooService->run('confirm',['token'=>$preview['token'],'confirmed'=>'1']);
    $check($wooService->run('confirm',['token'=>$preview['token'],'confirmed'=>'1'])===$confirmed && count($store->detail($wooRegistry)['links'])===1,'Woo confirmation replay is idempotent');
    $second=$wooService->run('woo_preview',array_merge($draft,['revision'=>2,'woo_order_id'=>'999999002']));
    $check($second['document']['order_snapshot']['folio_documents'][0]['number']==='LEGACY','Legacy single document metadata stays visible');
    $wooService->run('confirm',['token'=>$second['token'],'confirmed'=>'1']);
    $check(count($store->detail($wooRegistry)['links'])===2,'Several Woo orders can belong to one TTN');
    $other=$wooService->run('woo_preview',['id'=>$summaryId,'revision'=>2,'woo_order_id'=>'999999001','evidence'=>'Synthetic split shipment']);
    $wooService->run('confirm',['token'=>$other['token'],'confirmed'=>'1']);
    $check($store->detail($summaryId)['links'][0]['document_type']==='WOO_ORDER','One Woo order can belong to several TTNs');
    $missing=$wooService->run('woo_preview',array_merge($draft,['revision'=>3]));
    unset($wooFixture[999999001]);
    $reject(fn()=>$wooService->run('confirm',['token'=>$missing['token'],'confirmed'=>'1']),'Order deleted after preview blocks confirmation');
    $wooFixture[999999001]=$wooA;
    $store->classify($wooRegistry,3,['partner_code'=>'OTHER','direction'=>'incoming','purpose'=>'customer_return']);
    $check(count($store->detail($wooRegistry)['links'])===2,'Woo association does not require or freeze a Folio partner');
    $stale=$wooService->run('woo_preview',array_merge($draft,['revision'=>4]));
    $store->classify($wooRegistry,4,['partner_code'=>'OTHER','direction'=>'incoming','purpose'=>'customer_return','note'=>'Synthetic revision']);
    $reject(fn()=>$wooService->run('confirm',['token'=>$stale['token'],'confirmed'=>'1']),'Registry revision guards Woo confirmation');
    $links=$store->detail($wooRegistry)['links'];
    $store->unlink($wooRegistry,5,(int)$links[0]['id'],'Synthetic correction of association');
    $check(count(array_filter($store->detail($wooRegistry)['links'],static fn($link)=>$link['active']))===1,'Woo link revoked independently with audit');
    $savedWoo=json_decode($store->detail($summaryId)['links'][0]['snapshot'],true);
    $check($savedWoo['registry_context']['partner']==='TEST' && $savedWoo['order_snapshot']['folio_documents'][0]['number']==='TEST-ACCOUNT','Saved link retains source-separated Folio context');
    $check(count($http)===$httpBefore && $wooA->get_total()==='149.00','Woo linking performs no external HTTP or order mutation');
    $manager->allcaps=[]; $reject(fn()=>$wooService->run('woo_orders',['woo_id'=>'999999001']),'Woo search requires registry manager permissions');
    $manager->allcaps=['manage_woocommerce'=>true,'manage_pnpm_shipments'=>true];
    $store->transaction(fn()=>$store->observe('99999999000009','np_documents',['Weight'=>'3.5','SeatsAmount'=>'2','OptionsSeat'=>[['weight'=>'1.5','volumetricLength'=>'25','volumetricWidth'=>'16','volumetricHeight'=>'12'],['weight'=>'2','volumetricLength'=>'30','volumetricWidth'=>'20','volumetricHeight'=>'15']]],'2026-06-01 12:00:00'));
    if ($dir=getenv('PNPM_REGISTRY_FIXTURE_DIR')) {
        foreach (['detail','index'] as $view) {
            switch_to_locale('uk'); $_GET=$view==='detail'?['page'=>'pnpm-registry','id'=>$transferId]:['page'=>'pnpm-registry'];
            ob_start(); (new RegistryPage())->render(); $body=ob_get_clean();
            $config=['url'=>'/ajax','nonce'=>'synthetic','page'=>'/registry-index.html','loading'=>RegistryPage::t('Checking…'),
                'failure'=>RegistryPage::t('The request failed. Reload and check the saved result before repeating.'),
                'preview'=>RegistryPage::t('Preview only. Check the data and confirm below.'),'saved'=>RegistryPage::t('Saved to the site registry.'),
                'empty'=>'Synthetic empty page','more'=>'Synthetic next page','labels'=>RegistryPage::labels(),'picker'=>RegistryPage::pickerText()];
            restore_previous_locale();
            file_put_contents($dir.'/registry-'.$view.'.html','<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px/1.5 system-ui;background:#f0f0f1;margin:24px}input,button,select,textarea{font:inherit}table{width:100%;text-align:left}button{padding:6px 12px}td{padding:8px}h1{font-size:28px}</style><style>'.file_get_contents(PNPM_DIR.'assets/registry.css').'</style>'.$body.'<script>window.pnpmRegistry='.wp_json_encode($config).';</script><script>'.file_get_contents(PNPM_DIR.'assets/registry.js').'</script><script>'.file_get_contents(PNPM_DIR.'assets/registry-picker.js').'</script><script>'.file_get_contents(PNPM_DIR.'assets/registry-woo.js').'</script></html>');
        }
    }
    $check($mail===0,'Registry sent no email');
    echo "Registry: $checks checks passed; all external HTTP mocked, isolated tables removed.\n";
} finally {
    foreach (['_observations','_links','_audit','_batches',''] as $suffix) $wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'pnpm_registry'.$suffix);
    $wpdb->prefix=$prefix;
    if ($old_schema===null) delete_option($key); else update_option($key,$old_schema,false);
    $GLOBALS['current_user']=$old_user;
    remove_filter('pre_http_request',$mock,PHP_INT_MAX);
}
