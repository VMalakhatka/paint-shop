<?php
/** Synthetic local DB fixtures; no actual carrier, Java, mail, orders or Folio writes. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='local'
    || !defined('PNPM_NOVA_POSHTA_API_KEY') || PNPM_NOVA_POSHTA_API_KEY!=='synthetic-registry-test-key') throw new RuntimeException('Intercepted local CLI only');

use Paint\NovaPoshta\Registry\{Schema,Record,Store,Service,Folio,Carrier};
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
$http=[]; $documentReads=[]; $mail=0; $mode='good'; $status='1'; $partner='TEST';
add_filter('pre_wp_mail', static function() use (&$mail) { $mail++; return false; },PHP_INT_MAX);
$mock=static function($pre,$args,$url) use (&$http,&$documentReads,&$mode,&$status,&$partner,$ttn,$ttn2) {
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
    if (str_contains($url,'/admin/folio/customer-documents/')) {
        $type=str_contains($url,'/EXPENSE/')?'EXPENSE':'ACCOUNT';
        return $response(['ok'=>true,'partner'=>['shortName'=>$partner],'document'=>['documentType'=>$type,'documentId'=>123,
            'documentNumber'=>'50','documentNumberSuffix'=>'a','documentDate'=>'2026-06-01T12:00:00.000','returnDocument'=>false,'warehouseId'=>7,
            'items'=>[['sku'=>'not-needed']],'linkedPayments'=>[['private'=>'not-needed']]]]);
    }
    if (str_contains($url,'/admin/folio/receipt-catalogue')) return $response(['ok'=>true,'warehouseId'=>7,'date'=>'2026-06-01',
        'documents'=>[['id'=>123,'number'=>'50a','date'=>[2026,6,1],'warehouseId'=>7,'type'=>'receipt','accounted'=>true]],'hasMore'=>false]);
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
        'CityRecipientDescription'=>'Київ','DateTime'=>'2026-10-20 10:00:00','Cost'=>'13217.00','CostOnSite'=>'1116.09','PayerType'=>'Sender',
    ],'2026-10-21 10:00:00'));
    $store->transaction(fn()=>$store->observe($summaryTtn,'np_tracking',['CostOnSite'=>'0','PayerType'=>'ThirdPerson'],'2026-10-22 10:00:00'));
    // A late-arriving old snapshot must not override newer evidence or supply its missing city.
    $store->transaction(fn()=>$store->observe($summaryTtn,'np_tracking',['CostOnSite'=>'999','CityRecipient'=>'Wrong old city'],'2026-10-20 10:00:00'));
    $service->run('classify',['id'=>$summaryId,'revision'=>1,'direction'=>'outgoing','purpose'=>'sale','partner_code'=>'TEST']);
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
    if ($dir=getenv('PNPM_REGISTRY_FIXTURE_DIR')) {
        foreach (['detail','index'] as $view) {
            switch_to_locale('uk'); $_GET=$view==='detail'?['page'=>'pnpm-registry','id'=>$id]:['page'=>'pnpm-registry'];
            ob_start(); (new RegistryPage())->render(); $body=ob_get_clean();
            $config=['url'=>'/ajax','nonce'=>'synthetic','page'=>'/registry-index.html','loading'=>RegistryPage::t('Checking…'),
                'failure'=>RegistryPage::t('The request failed. Reload and check the saved result before repeating.'),
                'preview'=>RegistryPage::t('Preview only. Check the data and confirm below.'),'saved'=>RegistryPage::t('Saved to the site registry.'),
                'empty'=>'Synthetic empty page','more'=>'Synthetic next page','labels'=>RegistryPage::labels()];
            restore_previous_locale();
            file_put_contents($dir.'/registry-'.$view.'.html','<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px/1.5 system-ui;background:#f0f0f1;margin:24px}input,button,select,textarea{font:inherit}table{width:100%;text-align:left}button{padding:6px 12px}td{padding:8px}h1{font-size:28px}</style><style>'.file_get_contents(PNPM_DIR.'assets/registry.css').'</style>'.$body.'<script>window.pnpmRegistry='.wp_json_encode($config).';</script><script>'.file_get_contents(PNPM_DIR.'assets/registry.js').'</script></html>');
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
