<?php
/** Local integration fixtures only; no external HTTP, mail or accounting writes. */
use PaintCore\PCOE\CustomerApproval as Approval;
use PaintCore\PCOE\ApprovalBundle as Bundle;
use PaintCore\PCOE\ApprovalWorkbook as Workbook;
use PaintCore\PCOE\ApprovalNotifications as Notices;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
$checks=0; $users=[]; $id=0; $mode='ok'; $mails=[]; $order=null; $original=get_current_user_id();
$check=static function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
$deny=static function($fn)use($check){try{$fn();}catch(Throwable $e){$check(true,'Rejected');return;}throw new RuntimeException('Expected rejection');};
add_filter('pre_wp_mail',static function($pre,$args)use(&$mails){$mails[]=$args;return true;},PHP_INT_MAX,2);
add_filter('pre_http_request',static function($pre,$args,$url)use(&$mode){
    if(($args['method']??'GET')!=='GET' || !preg_match('~/customer-documents/ACCOUNT/(41|42)~',$url,$m))return new WP_Error('blocked','Unexpected HTTP');
    $id=(int)$m[1];
    if($mode==='offline')return new WP_Error('offline','Test outage');
    return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['ok'=>true,'partner'=>['shortName'=>$mode==='foreign'?'OTHER':'TEST'],
        'document'=>['documentType'=>'ACCOUNT','documentId'=>$mode==='wrong-id'?99:$id,'documentNumber'=>'TEST-'.$id,'documentDate'=>[2026,10,11],
            'accounted'=>$mode!=='unaccounted','returnDocument'=>$mode==='return','totalAmount'=>$id===41?10.01:($mode==='changed'?25:20.02),
            'warehouseId'=>$id,'warehouseName'=>$id===41?'Київ':'Одеса','items'=>[['sku'=>'0000123','name'=>'=1+1','quantity'=>1,'price'=>$id===41?10.01:20.02,'amount'=>$id===41?10.01:20.02]]]])];
},PHP_INT_MAX,3);
$settings=['name'=>'Test recipient','tax_id'=>'0000000000','iban'=>'UA89'.str_repeat('0',25),'bank'=>'Test bank','mfo'=>'','confirmed'=>true];
add_filter('pre_option_pc_folio_invoice_settings',static function()use(&$settings){return $settings;});
try {
    foreach(['opt','shop_manager'] as $role){$uid=wp_insert_user(['user_login'=>'bundle-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(30),'user_email'=>wp_generate_uuid4().'@example.invalid','role'=>$role]);if(is_wp_error($uid))throw new RuntimeException('Fixture');$users[]=$uid;}
    [$owner,$manager]=$users;update_user_meta($owner,'_folio_partner_short_name','TEST');update_user_meta($owner,'_folio_partner_id','TEST');wp_set_current_user($manager);
    $source=Bundle::source(['customer_id'=>$owner,'document_ids'=>'42,41','include_payment'=>'1','order_id'=>999]);
    $check($source['document_ids']==='41,42' && $source['order_id']===0,'Explicit normalized source ignores unrelated order ID');
    $check(Approval::key($source)===Approval::key(array_replace($source,['document_ids'=>'42,41'])),'Selection order does not duplicate requests');
    foreach(['','1,1','0','-1','41,a',implode(',',range(1,21)),['41']] as $bad)$deny(fn()=>Bundle::ids($bad));
    $snapshot=Approval::snapshot($source);
    $check($snapshot['total']==='30.03' && count($snapshot['items'])===2 && count($snapshot['documents'])===2,'Two warehouses combined exactly once in kopecks');
    $check($snapshot['items'][1]['document']==='TEST-42 · 2026-10-11' && $snapshot['items'][1]['warehouse']==='Одеса','Each row retains original account/date/warehouse');
    $check($snapshot['payment_details']['name']==='Test recipient','Confirmed site settings supply payment details');
    $plain=Approval::snapshot(array_replace($source,['include_payment'=>'0']));$check(!isset($plain['payment_details']),'Payment details optional');
    $check(Approval::revision($plain)!==Approval::revision($snapshot),'Payment selection requires review');
    $data=['source'=>$source,'snapshot'=>$snapshot,'revision'=>Approval::revision($snapshot),'status'=>'pending','history'=>[],'requested_by'=>$manager];
    $id=(new ReflectionMethod(Approval::class,'save'))->invoke(null,$data);
    $order=wc_create_order(['customer_id'=>$owner,'status'=>'on-hold']);$order->update_meta_data('_folio_document_id','41');$order->save();
    $check(Approval::snapshot($source)['total']==='30.03','Linked Woo order is not added to selected Folio totals');
    $book=Workbook::workbook($id,$data);$sheet=$book->getActiveSheet();$rows=$sheet->toArray();
    $lines=array_values(array_filter($rows,static fn($r)=>$r[0]==='0000123'));
    $check($book->getSheetCount()===1 && count($lines)===2 && $lines[1][5]==='TEST-42 · 2026-10-11','One worksheet with source columns and no duplicate detail sheets');
    $check(str_contains(wp_json_encode($rows),'Test recipient'),'Payment details in workbook');
    foreach($sheet->getCellCollection()->getCoordinates() as $cell)$check($sheet->getCell($cell)->getDataType()!=='f','Formula-like title stored as text');
    if($dir=getenv('PCOE_BUNDLE_PREVIEW')){(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($dir.'/bundle.xlsx');(new PhpOffice\PhpSpreadsheet\Writer\Html($book))->save($dir.'/bundle.html');}
    $book->disconnectWorksheets();
    $mails=[];
    $check(Notices::send_customer($id,$data['revision'],wp_generate_uuid4(),'xlsx')==='accepted' && count($mails)===1,'One combined file sent only through intercepted explicit send');
    $check(count($mails[0]['attachments'])===1 && !is_file(reset($mails[0]['attachments'])),'One private attachment cleaned up');
    $mode='changed';$deny(fn()=>Notices::send_customer($id,$data['revision'],wp_generate_uuid4(),'xlsx'));
    foreach(['offline','foreign','unaccounted','return','wrong-id'] as $mode)$deny(fn()=>Approval::snapshot($source));$mode='ok';
    $settings['confirmed']=false;$deny(fn()=>Approval::snapshot($source));$settings['confirmed']=true;
    $settings['name']='Changed recipient';$check(Approval::revision(Approval::snapshot($source))!==$data['revision'],'Changed bank recipient invalidates saved confirmation');$settings['name']='Test recipient';
    $payment_html=static function(array $saved):string {ob_start();Bundle::payment_panel($saved);return ob_get_clean();};
    $original_snapshot=$snapshot;$original_settings=$settings;
    $check(str_contains($payment_html($snapshot),'Test recipient'),'Verified matching saved recipient remains visible');
    $formatted=$snapshot;$formatted['payment_details']['iban']=strtolower(chunk_split($settings['iban'],4,' '));
    $check(str_contains($payment_html($formatted),'Test recipient'),'Harmless IBAN formatting does not hide a matching recipient');
    foreach(['name'=>'Changed recipient','tax_id'=>'1111111111','bank'=>'Changed bank','mfo'=>'123456','confirmed'=>false,'iban'=>['malformed']] as $field=>$value){
        $settings=array_replace($original_settings,[$field=>$value]);$html=$payment_html($snapshot);
        $check(str_contains($html,'pcoe-payment-details-unavailable')&&!str_contains($html,'Test recipient')&&!str_contains($html,'Test bank')&&!str_contains($html,'Changed recipient')&&!str_contains($html,'Changed bank'),'Changed or invalid canonical payment details are hidden without silently substituting another recipient');
    }
    $settings=array_replace($original_settings,['name'=>'Changed recipient']);
    ob_start();(new ReflectionMethod(Approval::class,'details'))->invoke(null,get_post($id),true);$stale_html=ob_get_clean();
    $check(str_contains($stale_html,'pcoe-payment-details-unavailable')&&!str_contains($stale_html,'Test recipient')&&!str_contains($stale_html,'Changed recipient'),'Existing stale confirmation page suppresses obsolete bank details');
    $settings=$original_settings;
    foreach([['iban'=>['malformed']],['name'=>'Historical recipient'],['iban'=>'UA00'.str_repeat('0',25)]] as $override){
        $broken=$snapshot;$broken['payment_details']=array_replace($broken['payment_details'],$override);
        $check(str_contains($payment_html($broken),'pcoe-payment-details-unavailable'),'Malformed or mismatched saved recipient is hidden');
    }
    $check(!str_contains($payment_html($plain),'pcoe-payment-details-unavailable'),'Confirmation without selected payment details has no payment warning');
    $stored_after=(new ReflectionMethod(Approval::class,'data'))->invoke(null,get_post($id));
    $check($snapshot===$original_snapshot&&$stored_after['snapshot']===$original_snapshot,'Rendering never mutates the saved confirmation snapshot');
    $choices=['payment'=>['bacs'=>'Bank'],'delivery'=>['pickup:1'=>'Pickup']];$input=['revision'=>$data['revision'],'consent'=>'1','payment'=>'bacs','delivery'=>'pickup:1','recipient'=>'Test','phone'=>'000','destination'=>'Test'];
    $deny(fn()=>Approval::confirmed($data,$manager,$data['revision'],$input,$choices));
    $confirmed=Approval::confirmed($data,$owner,$data['revision'],$input,$choices);$check($confirmed['status']==='confirmed','Owning customer confirms the whole bundle');
    $check(Approval::confirmed($confirmed,$owner,$data['revision'],$input,$choices)===$confirmed,'Repeat acknowledgement is idempotent');
    $check(wc_get_order($order->get_id())->has_status('on-hold') && count($mails)===1,'No order status changes or unintended email');
    $_GET=['document_ids'=>'41,42','customer_id'=>$owner];$_REQUEST=$_GET;
    ob_start();Approval::admin();$html=ob_get_clean();
    $check(str_contains($html,'name="document_ids" value="41,42"') && str_contains($html,'name="include_payment"') && str_contains($html,'name="revision"'),'Preview preserves bundle selection and review revision');
    if($dir)file_put_contents($dir.'/preview.html','<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px system-ui;margin:20px}table{border-collapse:collapse;width:100%}td,th{padding:10px;border:1px solid #ccc}button{padding:10px}dt{font-weight:bold}</style>'.$html);
    echo "PASS: $checks combined approval checks; HTTP and email intercepted.\n";
} finally {
    if($id)wp_delete_post($id,true);if($order)$order->delete(true);wp_set_current_user($original);require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $uid)wp_delete_user($uid);
}
