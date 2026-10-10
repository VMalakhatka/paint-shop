<?php
/** Local disposable fixtures; mail/HTTP/cron delivery fully intercepted. */
use PaintCore\PCOE\Broadcasts as Mailings;
use PaintCore\PCOE\BroadcastStore as Store;
use PaintCore\PCOE\BroadcastPricing as Pricing;
use PaintCore\PCOE\BroadcastSources as Sources;
use PaintCore\PCOE\BroadcastUi as Ui;
use PaintCore\PCOE\PriceList;
use PaintCore\PCOE\CommercialOffer;
use PhpOffice\PhpSpreadsheet\IOFactory;
if(!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='paint.local')throw new RuntimeException('Local only');
$users=[];$products=[];$campaigns=[];$mails=[];$mode='ok';$checks=0;$original=get_current_user_id();$quota=get_option('_pcoe_broadcast_quota',null);$prefix='Mailing-test-'.wp_generate_uuid4();$skus=[];$sourceMode='ok';
$check=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$deny=static function($fn)use($check){try{$fn();}catch(Throwable $e){$check(true,'Rejected');return;}throw new RuntimeException('Expected rejection');};
add_filter('pre_schedule_event',static fn($pre,$event)=>$event->hook===Mailings::HOOK?false:$pre,PHP_INT_MAX,2);
add_filter('pre_http_request',static function($pre,$args,$url)use(&$skus,&$sourceMode){
    if($url==='https://example.invalid/offer-test.png'){
        $image=imagecreatetruecolor(120,80);imagefill($image,0,0,imagecolorallocate($image,173,89,67));ob_start();imagepng($image);$body=ob_get_clean();imagedestroy($image);return ['response'=>['code'=>200],'body'=>$body];
    }
    if(str_contains($url,'/admin/folio/product-packaging') && $sourceMode==='packing-failed')return new WP_Error('fixture','Unavailable');
    if(str_contains($url,'/admin/folio/product-packaging'))return ['response'=>['code'=>200],'body'=>wp_json_encode(array_map(static fn($sku)=>['sku'=>$sku,'unitsPerPack'=>5],json_decode($args['body'],true)['skus']))];
    if(str_contains($url,'/ref/warehouses'))return ['response'=>['code'=>200],'body'=>wp_json_encode([['code'=>'7','name'=>'Fixture warehouse']])];
    parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$query);
    if(str_contains($url,'/admin/folio/receipt-catalogue/9001/skus'))return ['response'=>['code'=>200],'body'=>wp_json_encode(['ok'=>true,'documentId'=>9001,'warehouseId'=>7,'date'=>'2026-10-05','documentType'=>$sourceMode==='mismatch'?'receipt':($query['documentType']??'receipt'),'documentTypes'=>$sourceMode==='old'?['receipt']:['receipt','invoice'],'skus'=>[$skus[0],$skus[0],'MISSING-TEST-SKU']])];
    return new WP_Error('blocked','External HTTP blocked');
},PHP_INT_MAX,3);
add_filter('pre_wp_mail',static function($pre,$args)use(&$mails,&$mode,$check){
    $check(is_string($args['to']) && !str_contains($args['to'],','),'One private recipient per email');
    $file=[];
    foreach($args['attachments'] as $name=>$path){
        $check(str_ends_with($name,'.xlsx') && is_file($path),'Named XLSX attachment exists during delivery');
        $book=IOFactory::load($path);$rows=$book->getActiveSheet()->toArray();$file=['sha'=>hash_file('sha256',$path),'rows'=>$rows,'path'=>$path];$book->disconnectWorksheets();
    }
    $mails[]=array_merge($args,['file'=>$file]);
    if($mode==='unknown')throw new RuntimeException('Interrupted transport');
    return $mode!=='failed';
},PHP_INT_MAX,2);
$create=static function($input)use(&$campaigns){$id=Mailings::create($input);$campaigns[]=$id;return $id;};
try{
    foreach(['shop_manager','opt','opt','partner','opt','opt','opt'] as $i=>$role){
        $id=wp_insert_user(['user_login'=>$prefix.'-'.$i,'display_name'=>$prefix.' '.$i,'user_email'=>'mailing-'.wp_generate_uuid4().'@example.invalid','user_pass'=>wp_generate_password(30),'role'=>$role]);
        if(is_wp_error($id))throw new RuntimeException('Fixture');$users[]=$id;update_user_meta($id,'locale','uk');update_user_meta($id,'billing_city','Test mailing city');
    }
    [$manager,$a,$b,$partner]=$users;wp_set_current_user($manager);
    $locations=PriceList::location_ids();$check(count($locations)===2,'Selling warehouses mapped');
    for($i=0;$i<2;$i++){
        $p=new WC_Product_Simple();$p->set_name($i?'Not in receipt':'=TEST receipt product');$p->set_sku('000-BROADCAST-'.wp_generate_uuid4());$p->set_status('publish');$p->set_regular_price('100');$p->update_meta_data('_wpc_price_role_opt','80');$p->update_meta_data('_wpc_price_role_partner','70');
        foreach($locations as $loc)$p->update_meta_data('_stock_at_'.$loc,4);$p->save();$products[]=$p;$skus[]=$p->get_sku();
    }
    $check(Pricing::group($a)===Pricing::group($b) && Pricing::group($a)!==Pricing::group($partner),'Role price conditions share one group, other prices separate');
    $custom=static fn($price,$product)=>get_current_user_id()===$a?'63':$price;
    add_filter('woocommerce_product_get_price',$custom,1200,2);
    $check(Pricing::group($a)!==Pricing::group($b),'Unknown individual pricing provider isolates each customer');
    remove_filter('woocommerce_product_get_price',$custom,1200);
    add_filter('woocommerce_calc_tax',$custom,1200,2);
    $check(Pricing::group($a)!==Pricing::group($b),'Unknown tax adjustment cannot share a price file');
    remove_filter('woocommerce_calc_tax',$custom,1200);
    $check(Sources::request('/warehouses')['warehouses'][0]['code']==='7','Warehouse picker uses established reference service');
    $deny(fn()=>Sources::document_type(['document_type'=>'payment']));
    $invoiceInput=['warehouse'=>7,'date'=>'2026-10-05','document'=>9001,'document_type'=>'invoice'];
    $invoice=Sources::products('arrival',$invoiceInput);
    $check($invoice['ids']===[$products[0]->get_id()] && $invoice['source']['document_type']==='invoice','Invoice products use the same site catalogue and persist the selected type');
    $sourceMode='old';$deny(fn()=>Sources::products('arrival',$invoiceInput));
    $sourceMode='mismatch';$deny(fn()=>Sources::products('arrival',$invoiceInput));$sourceMode='ok';
    $base=['request_key'=>wp_generate_uuid4(),'search'=>$prefix,'role'=>'','city'=>'','users'=>[$a,$b,$partner],'kind'=>'arrival','subject'=>'Fixture arrival','message'=>"First line\nSecond line",'warehouse'=>7,'date'=>'2026-10-05','document'=>9001,'document_type'=>'all'];
    $id=$create($base);$check(Mailings::create($base)===$id,'Repeated preparation request is idempotent');
    $data=Store::get($id);$check($data['ids']===[$products[0]->get_id()] && count($data['missing'])===1,'Only receipt SKUs selected; missing item is shown, duplicate removed');
    $check(count($data['groups'])===2 && count($mails)===0,'Two files for three recipients; preview sends no emails');
    Mailings::tick($id);Mailings::tick($id);$data=Store::get($id);
    $check($data['status']==='ready' && count($mails)===0,'Files prepared without delivery');
    foreach($data['groups'] as $group=>$g){$check($g['products']===1,'Mini price contains only one product');$check(Store::bytes(Store::file($id,$group))!=='','Private file checksum valid');}
    $check(get_current_user_id()===$manager,'Price scope restores manager identity');
    global $wpdb;
    foreach(array_keys($data['groups']) as $group){
        $autoload=$wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name=%s",'_pcoe_mail_file_'.$id.'_'.$group));
        $check(in_array($autoload,['no','off','auto-off'],true),'Prepared files never autoload on unrelated page requests');
    }
    if($dir=getenv('PCOE_BROADCAST_PREVIEW')){
        $_GET=['mailing'=>$id];ob_start();Ui::render();file_put_contents($dir.'/review.html','<meta charset="utf-8">'.ob_get_clean());
        $_GET=['search'=>$prefix];ob_start();Ui::render();file_put_contents($dir.'/compose.html','<meta charset="utf-8">'.ob_get_clean());
    }
    delete_option('_pcoe_broadcast_quota');Mailings::control($id,'start');Mailings::tick($id);Mailings::tick($id);
    $check(count($mails)===3 && Store::get($id)['status']==='finished','Exactly one email per recipient across repeated worker calls');
    $by=[];foreach($mails as $mail)$by[$mail['to']]=$mail;
    $check($by[get_userdata($a)->user_email]['file']['sha']===$by[get_userdata($b)->user_email]['file']['sha'],'Same-role recipients get byte-identical copies of one generated file');
    foreach([$a=>80,$b=>80,$partner=>70] as $user=>$price){
        $mail=$by[get_userdata($user)->user_email];$rows=array_values(array_filter($mail['file']['rows'],static fn($r)=>$r[0]===$skus[0]));
        $expected=Pricing::scope($user,fn()=>wc_get_price_to_display(wc_get_product($products[0]->get_id())));
        $check(count($rows)===1 && (float)$rows[0][5]===(float)$expected,'Each recipient gets their site price: '.json_encode([$rows,$expected]));
        $check(Pricing::scope($user,fn()=>(float)wc_get_product($products[0]->get_id())->get_price())===(float)$price,'Role price correct');
        $check(!str_contains(json_encode($mail['file']['rows']),$skus[1]),'Unrelated product absent from mini price');
        $check(!is_file($mail['file']['path']),'Temporary email copy deleted');
        $check(in_array('Reply-To: '.get_userdata($manager)->user_email,$mail['headers'],true),'Replies return to sending manager');
    }
    $base['kind']='text';$base['users']=[$a];$base['request_key']=wp_generate_uuid4();$text=$create($base);$mode='failed';delete_option('_pcoe_broadcast_quota');
    Mailings::control($text,'start');Mailings::tick($text);$check(Store::get($text)['recipients'][$a]['status']==='failed','Known failure recorded');
    $count=count($mails);Mailings::tick($text);$check(count($mails)===$count,'Known failure is not retried automatically');
    $mode='ok';Mailings::control($text,'retry');Mailings::tick($text);$check(count($mails)===$count+1 && end($mails)['attachments']===[],'Explicit retry only, text-only mail has no file');
    $base['request_key']=wp_generate_uuid4();$unknown=$create($base);$mode='unknown';Mailings::control($unknown,'start');Mailings::tick($unknown);$mode='ok';
    $count=count($mails);Mailings::control($unknown,'retry');Mailings::tick($unknown);$check(count($mails)===$count && Store::get($unknown)['recipients'][$a]['status']==='unknown','Unknown outcome cannot be resent through failed-email retry');
    $base['request_key']=wp_generate_uuid4();$stop=$create($base);Mailings::control($stop,'start');Mailings::control($stop,'pause');Mailings::tick($stop);$check(count($mails)===$count,'Pause stops delivery');
    update_user_meta($a,Mailings::OPT_OUT,'1');Mailings::control($stop,'resume');Mailings::tick($stop);$check(Store::get($stop)['recipients'][$a]['status']==='skipped','Unsubscribe rechecked immediately before sending');delete_user_meta($a,Mailings::OPT_OUT);
    $base['users']=array_slice($users,1);$base['request_key']=wp_generate_uuid4();$batch=$create($base);delete_option('_pcoe_broadcast_quota');Mailings::control($batch,'start');$count=count($mails);Mailings::tick($batch);
    $check(count($mails)===$count+5 && Store::get($batch)['status']==='running','Global queue sends at most five emails per minute');Mailings::tick($batch);$check(count($mails)===$count+5,'Repeated tick cannot exceed quota');delete_option('_pcoe_broadcast_quota');Mailings::tick($batch);$check(count($mails)===$count+6,'Next batch completes remaining recipients');
    $base['users']=[$a];$base['request_key']=wp_generate_uuid4();$stuck=$create($base);$d=Store::get($stuck);$d['status']='running';$d['recipients'][$a]['status']='sending';Store::save($stuck,$d);$count=count($mails);Mailings::tick($stuck);$check(Store::get($stuck)['recipients'][$a]['status']==='unknown' && count($mails)===$count,'Interrupted sending claim becomes unknown, never resent');
    $base['request_key']=wp_generate_uuid4();$busy=$create($base);Mailings::control($busy,'start');$otherDb=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);global $wpdb;$lock='pcoe-mailings:'.substr(hash('sha256',DB_NAME.$wpdb->prefix),0,35);
    try{
        $check((string)$otherDb->get_var($otherDb->prepare('SELECT GET_LOCK(%s,0)',$lock))==='1','Second worker owns mutex');
        $count=count($mails);Mailings::tick($busy);
        $check(Store::get($busy)['status']==='running' && count($mails)===$count,'Concurrent worker leaves running state untouched and sends nothing');
    }finally{$otherDb->get_var($otherDb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$otherDb->close();}
    Mailings::control($busy,'cancel');
    $base['request_key']=wp_generate_uuid4();$expired=$create($base);$d=Store::get($expired);$d['ready_at']=time()-DAY_IN_SECONDS-1;Store::save($expired,$d);$deny(fn()=>Mailings::control($expired,'start'));
    $base['request_key']=wp_generate_uuid4();$changed=$create($base);$d=Store::get($changed);$d['kind']='price';$d['recipients'][$a]['group']=Pricing::group($a);Store::save($changed,$d);Mailings::control($changed,'start');
    $customer=new WP_User($a);$customer->set_role('partner');$count=count($mails);delete_option('_pcoe_broadcast_quota');Mailings::tick($changed);
    $check(Store::get($changed)['recipients'][$a]['status']==='skipped' && count($mails)===$count,'Changed price role is skipped before attachment access');$customer->set_role('opt');
    $base['kind']='arrival';$base['request_key']=wp_generate_uuid4();$broken=$create($base);$d=Store::get($broken);$key=array_key_first($d['groups']);$d['groups'][$key]['building']=true;Store::save($broken,$d);Mailings::tick($broken);
    $check(Store::get($broken)['status']==='error' && count($mails)===$count,'Interrupted preparation stops with a visible error and sends nothing');
    $base['kind']='text';$base['request_key']=wp_generate_uuid4();$cancelled=$create($base);Mailings::control($cancelled,'cancel');Mailings::tick($cancelled);$deny(fn()=>Mailings::control($cancelled,'start'));
    $check(count($mails)===$count,'Cancelled mailing cannot send');
    $deny(fn()=>CommercialOffer::options(['format'=>'bad']));$deny(fn()=>CommercialOffer::options(['quantity'=>'document']));
    $check(CommercialOffer::options([])===['format'=>'price','quantity'=>'one'],'Old campaigns retain standard price format');
    $p=$products[0];$p->update_meta_data('_edin_izmer','50 мл');$p->set_description('<p>Product description</p><p>Second line</p>');$p->update_meta_data('_wc_gtin_code','012345678905');$p->save();
    $photoFilter=static fn($image,$attachment)=>$attachment===$products[1]->get_id()?['https://example.invalid/offer-test.png',1,1,false]:$image;
    add_filter('wp_get_attachment_image_src',$photoFilter,10,2);
    $imageIdFilter=static fn($image,$product)=>$product->get_id()===$products[0]->get_id()?$products[1]->get_id():$image;
    add_filter('woocommerce_product_get_image_id',$imageIdFilter,10,2);
    foreach(['one'=>1,'stock'=>8] as $quantity=>$expectedQuantity){
        $base['kind']='arrival';$base['users']=[$a,$b,$partner];$base['format']='offer';$base['quantity']=$quantity;$base['request_key']=wp_generate_uuid4();$offer=$create($base);
        Mailings::tick($offer);Mailings::tick($offer);$d=Store::get($offer);
        $check($d['status']==='ready' && $d['quantity']===$quantity && count($d['groups'])===2,'Offer format/options persisted and same-price customers share files');
        foreach($d['groups'] as $key=>$g){
            $path=Store::temporary_file();file_put_contents($path,Store::bytes(Store::file($offer,$key)));$book=IOFactory::load($path);$sheet=$book->getActiveSheet();
            $check($sheet->getHighestRow()===2 && $sheet->getCell('A2')->getValue()===$skus[0],'Flat offer contains one product, no category rows');
            $check($sheet->getCell('C2')->getDataType()==='s' && str_starts_with($sheet->getCell('C2')->getValue(),'=TEST'),'Product text cannot become a formula');
            $check($sheet->getCell('B2')->getValue()==='012345678905' && $sheet->getCell('B2')->getDataType()==='s','Barcode leading zero preserved as text');
            [$map,$start]=\PaintCore\PCOE\Helpers::detect_colmap_and_start($sheet->toArray());
            $check($start===1 && $map['sku']===0 && $map['qty']===5 && $map['price']===null && $map['price_list'],'Offer re-import uses quantities and site prices, not attachment prices');
            $check(str_contains($sheet->getCell('D2')->getValue(),'Product description') && !str_contains($sheet->getCell('D2')->getValue(),'<p>'),'Plain description included');
            $expected=Pricing::scope($g['user'],fn()=>wc_get_price_to_display(wc_get_product($p->get_id())));
            $check((float)$sheet->getCell('E2')->getValue()===(float)$expected && $sheet->getCell('F2')->getValue()===null && (float)$sheet->getCell('K2')->getValue()===8.0 && (float)$sheet->getCell('H2')->getValue()===100.0 && $sheet->getCell('I2')->getValue()==='50 мл' && (float)$sheet->getCell('J2')->getValue()===5.0,'Offer separates retail/customer prices, units, stock and blank order quantity');
            $check(count($sheet->getDrawingCollection())===1 && $g['photos']===1,'Photo embedded in XLSX and counted in preview');
            $check($sheet->getRowDimension(2)->getOutlineLevel()===0 && $sheet->getAutoFilter()->getRange()==='A1:K2','Offer has filters without row grouping');
            if($dir=getenv('PCOE_BROADCAST_PREVIEW'))copy($path,$dir.'/offer-'.$quantity.'.xlsx');
            $book->disconnectWorksheets();unlink($path);
        }
        delete_option('_pcoe_broadcast_quota');$before=count($mails);Mailings::control($offer,'start');Mailings::tick($offer);
        $check(count($mails)===$before+3 && str_starts_with(array_key_first(end($mails)['attachments']),'commercial-offer-'),'Offer delivered privately with correct filename');
        $check($mails[$before]['file']['sha']===$mails[$before+1]['file']['sha'],'Same-price offer copies are byte-identical');
    }
    remove_filter('wp_get_attachment_image_src',$photoFilter,10);
    remove_filter('woocommerce_product_get_image_id',$imageIdFilter,10);
    $p->set_image_id(0);$p->update_meta_data('_razm_izmer','=20 x 30 см');$p->save();foreach($locations as $loc)delete_post_meta($p->get_id(),'_stock_at_'.$loc);
    $fallback=Pricing::file($a,[$p->get_id()],['format'=>'offer','quantity'=>'stock']);$path=Store::temporary_file();file_put_contents($path,Store::bytes($fallback));$book=IOFactory::load($path);$sheet=$book->getActiveSheet();
    $check($sheet->getCell('K2')->getValue()===null && $sheet->getCell('F2')->getValue()===null && $sheet->getCell('G2')->getHyperlink()->getUrl()!=='','Unknown stock remains blank and missing photo links to product');
    $check((float)$sheet->getCell('J2')->getValue()===5.0,'Pack quantity is independent of dimensions and unit volume');
    $book->disconnectWorksheets();unlink($path);
    $sourceMode='packing-failed';$deny(fn()=>Pricing::file($a,[$p->get_id()],['format'=>'offer']));$sourceMode='ok';
    wp_set_current_user($a);$deny(fn()=>Mailings::create($base));$deny(fn()=>Mailings::control($id,'retry'));$deny(fn()=>Sources::request('/warehouses'));wp_set_current_user($manager);
    $check(!wp_next_scheduled(Mailings::HOOK,[$id]),'Test fixtures never schedule a real delivery');
    echo "PASS: $checks mailing checks; separate role prices, grouped files, receipt SKU isolation, queue/idempotence/privacy, HTTP and mail mocked.\n";
}finally{
    foreach($campaigns as $id){wp_clear_scheduled_hook(Mailings::HOOK,[$id]);wp_delete_post($id,true);}
    foreach($products as $p)$p->delete(true);
    wp_set_current_user($original);require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $u)wp_delete_user($u);
    if($quota===null)delete_option('_pcoe_broadcast_quota');else update_option('_pcoe_broadcast_quota',$quota,false);
}
