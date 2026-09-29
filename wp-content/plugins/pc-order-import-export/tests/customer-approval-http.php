<?php
/** wp eval-file: real local HTTP routing with disposable users, order and approval.
 * A loopback PHP server blocks outbound HTTP/mail; only GET and this fixture's confirm POST are accepted.
 * Optional PCOE_APPROVAL_HTTP_HTML exports the owner's rendered page for offline visual inspection.
 */
use PaintCore\PCOE\CustomerApproval as Approval;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='paint.local') throw new RuntimeException('Local paint test only');
add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
$users=[];$order=null;$approval=0;$server=null;$files=[];$checks=0;$base='';
$check=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
add_filter('pre_http_request',static function($pre,$args,$url)use(&$base){return $base && str_starts_with($url,$base.'/') && in_array($args['method']??'GET',['GET','POST'],true)?$pre:new WP_Error('test_block','External HTTP blocked');},PHP_INT_MAX,3);
try {
    foreach(['owner','other','manager'] as $name){
        $tag=wp_generate_uuid4();$id=wp_insert_user(['user_login'=>'approval-route-'.$name.'-'.$tag,'user_pass'=>wp_generate_password(32),'user_email'=>$tag.'@example.invalid','role'=>$name==='manager'?'shop_manager':'opt']);
        if(is_wp_error($id))throw new RuntimeException($id->get_error_message());$users[]=$id;
    }
    $contact=new WC_Customer($users[0]);$contact->set_billing_first_name('Saved customer');$contact->set_billing_phone('+380501112233');$contact->set_billing_city('Saved city');$contact->set_billing_address_1('Saved billing street');$contact->set_shipping_first_name('Saved recipient');$contact->set_shipping_phone('+380509998877');$contact->set_shipping_city('Shipping city');$contact->set_shipping_address_1('Saved shipping street');$contact->save();
    $order=wc_create_order(['customer_id'=>$users[0],'status'=>'pc-draft']);
    $item=new WC_Order_Item_Product();$item->set_name('Approval route synthetic product');$item->set_quantity(2);$item->set_subtotal(20);$item->set_total(20);
    $order->add_item($item);$order->calculate_totals();$order->save();
    $source=['customer_id'=>$users[0],'order_id'=>$order->get_id(),'type'=>'','document_id'=>0];
    $snapshot=Approval::snapshot($source);
    $approval=(new ReflectionMethod(Approval::class,'save'))->invoke(null,['source'=>$source,'snapshot'=>$snapshot,'revision'=>Approval::revision($snapshot),'status'=>'pending','history'=>[],'requested_by'=>$users[2]]);
    $listener=stream_socket_server('tcp://127.0.0.1:0');if(!$listener)throw new RuntimeException('Loopback socket unavailable');
    $address=stream_socket_get_name($listener,false);fclose($listener);$base='http://'.$address;
    $secret=wp_generate_password(40,false);
    $router=tempnam(sys_get_temp_dir(),'approval-router-');$log=tempnam(sys_get_temp_dir(),'approval-server-');$mail_log=tempnam(sys_get_temp_dir(),'approval-mail-');$files=[$router,$log,$mail_log];
    file_put_contents($router,"<?php\nif (PHP_SAPI !== 'cli-server' || !hash_equals(".var_export($secret,true).", \$_SERVER['HTTP_X_PCOE_TEST']??'')) { http_response_code(403); exit; }\n".
        "\$posting=(\$_SERVER['REQUEST_METHOD']??'')==='POST'; if (\$posting && ((\$_POST['action']??'')!=='pcoe_approval_confirm' || (int)(\$_POST['approval_id']??0)!==".$approval.")) { http_response_code(403); exit; } if(\$posting)define('WP_ADMIN',true);\n".
        "define('DISABLE_WP_CRON',true); define('WP_HTTP_BLOCK_EXTERNAL',true); define('WP_USE_THEMES',true);\n".
        "\$_SERVER['HTTP_HOST']='paint.local'; \$_SERVER['SERVER_PORT']=80; \$_SERVER['SCRIPT_NAME']='/index.php';\n".
        "require ".var_export(ABSPATH.'wp-load.php',true).";\n".
        "add_filter('pre_wp_mail',static function(){file_put_contents(".var_export($mail_log,true).",\"mail\\n\",FILE_APPEND);return true;},PHP_INT_MAX); add_filter('pre_http_request',static fn()=>new WP_Error('offline','External HTTP blocked'),PHP_INT_MAX);\n".
        "if(\$posting){require ".var_export(ABSPATH.'wp-admin/admin-post.php',true).";exit;}\n".
        "require ".var_export(ABSPATH.'wp-blog-header.php',true).";\n");
    $server=proc_open([PHP_BINARY,'-S',$address,'-t',ABSPATH,$router],[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,ABSPATH);
    if(!is_resource($server))throw new RuntimeException('Local HTTP server unavailable');
    for($i=0;$i<50;$i++){ $ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1);if($ready){fclose($ready);break;}usleep(100000); }
    $cookies=[];foreach($users as $uid){$expiration=time()+600;$token=WP_Session_Tokens::get_instance($uid)->create($expiration);$cookies[$uid]=LOGGED_IN_COOKIE.'='.wp_generate_auth_cookie($uid,$expiration,'logged_in',$token).'; '.AUTH_COOKIE.'='.wp_generate_auth_cookie($uid,$expiration,'auth',$token);}
    $request=static function(string $path,int $uid=0,array $body=[])use($base,$secret,$cookies){
        $headers=['Host'=>'paint.local','X-PCOE-Test'=>$secret];
        if($uid)$headers['Cookie']=$cookies[$uid];
        $r=wp_remote_request($base.$path,['timeout'=>30,'redirection'=>0,'headers'=>$headers,'method'=>$body?'POST':'GET','body'=>$body]);
        if(is_wp_error($r))throw new RuntimeException($r->get_error_message());
        return [wp_remote_retrieve_response_code($r),(string)wp_remote_retrieve_header($r,'location'),wp_remote_retrieve_body($r)];
    };
    $path=wp_parse_url(Approval::customer_url($approval),PHP_URL_PATH).'?pcoe_approval='.$approval;
    [$status,$location,$html]=$request($path,$users[0]);
    $check($status===200 && !$location,'Owner deep link must render without redirect; got '.$status.' '.$location);
    $check(str_contains($html,'Approval route synthetic product') && str_contains($html,'name="consent"') && str_contains($html,'name="payment"') && str_contains($html,'name="delivery"'),'Owner sees products, delivery, payment and confirmation');
    $check(str_contains($html,'name="approval_id" value="'.$approval.'"'),'Form targets the approval ID, independently of the Woo order ID');
    if($file=getenv('PCOE_APPROVAL_HTTP_HTML'))file_put_contents($file,$html);
    [$status,$location,$other]=$request($path,$users[1]);
    $check($status===200 && !$location && !str_contains($other,'Approval route synthetic product') && !str_contains($other,'name="consent"'),'Other customer cannot read or confirm the request');
    [$status,$location,$guest]=$request($path);
    $check($status===200 && !$location && str_contains($guest,'woocommerce-login-nonce') && !str_contains($guest,'Approval route synthetic product'),'Guest sees login without leaking approval details');
    [$status,$location,$missing]=$request('/my-account/?pcoe_approval=999999999',$users[0]);
    $check($status===200 && !$location && !str_contains($missing,'name="consent"'),'Missing request stays on confirmation route without an actionable form');
    [$status,$location,$list]=$request('/my-account/?approval_page=1',$users[0]);
    $check($status===200 && !$location && str_contains($list,'pcoe_approval='.$approval),'Approval list pagination remains accessible');
    [$status,$location]=$request('/my-account/',$users[0]);
    $check($status===302 && str_ends_with($location,'/my-account/orders/'),'Ordinary account root still redirects to orders');
    [$status,$location]=$request('/my-account/?pcoe_chat=1',$users[0]);
    $check($status===200 && !$location,'Chat query route remains accessible');
    $saved=json_decode(get_post($approval)->post_content,true);
    $check($saved['status']==='pending' && wc_get_order($order->get_id())->has_status('pc-draft'),'Opening links does not confirm or change an order');
    preg_match('/name="_wpnonce" value="([^"]+)"/',$html,$nonce);
    $choices=Approval::choices();$body=['action'=>'pcoe_approval_confirm','approval_id'=>$approval,'_wpnonce'=>$nonce[1]??'','revision'=>Approval::revision($snapshot),
        'consent'=>'1','payment'=>array_key_first($choices['payment']),'delivery'=>array_key_first($choices['delivery']),'recipient'=>'Chosen customer','phone'=>'+380500001122','destination'=>'Chosen shipping address'];
    [$status]=$request('/wp-admin/admin-post.php',$users[0],array_replace($body,['payment'=>'invalid']));
    clean_post_cache($approval);$check($status===409 && json_decode(get_post($approval)->post_content,true)['status']==='pending','Invalid selection does not confirm or send mail');
    [$status,$location]=$request('/wp-admin/admin-post.php',$users[0],$body);
    clean_post_cache($approval);$saved=json_decode(get_post($approval)->post_content,true);
    $check($status===302 && str_contains($location,'pcoe_approval='.$approval) && $saved['status']==='confirmed' && $saved['preferences']['destination']==='Chosen shipping address','Actual customer POST records reviewed preferences');
    $request('/wp-admin/admin-post.php',$users[0],$body);
    $check(count(file($mail_log,FILE_IGNORE_NEW_LINES))===1,'Actual confirmation and replay notify the manager exactly once');
    echo "PASS: $checks real HTTP approval route checks; external HTTP/mail blocked, disposable records removed.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    foreach($files as $file)if(is_file($file))unlink($file);
    if($approval)wp_delete_post($approval,true);
    if($order)$order->delete(true);
    require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $uid)wp_delete_user($uid);
}
