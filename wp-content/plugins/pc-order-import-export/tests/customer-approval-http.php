<?php
/** wp eval-file: real local HTTP routing with disposable users, order and approval.
 * A loopback PHP server blocks outbound HTTP/mail and accepts GET only with a random test header.
 * Optional PCOE_APPROVAL_HTTP_HTML exports the owner's rendered page for offline visual inspection.
 */
use PaintCore\PCOE\CustomerApproval as Approval;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(),PHP_URL_HOST)!=='paint.local') throw new RuntimeException('Local paint test only');
add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
$users=[];$order=null;$approval=0;$server=null;$files=[];$checks=0;$base='';
$check=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
add_filter('pre_http_request',static function($pre,$args,$url)use(&$base){return $base && str_starts_with($url,$base.'/') && ($args['method']??'GET')==='GET'?$pre:new WP_Error('test_block','External HTTP blocked');},PHP_INT_MAX,3);
try {
    foreach(['owner','other'] as $name){
        $id=wp_insert_user(['user_login'=>'approval-route-'.$name.'-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password(32),'role'=>'opt']);
        if(is_wp_error($id))throw new RuntimeException($id->get_error_message());$users[]=$id;
    }
    $order=wc_create_order(['customer_id'=>$users[0],'status'=>'pc-draft']);
    $item=new WC_Order_Item_Product();$item->set_name('Approval route synthetic product');$item->set_quantity(2);$item->set_subtotal(20);$item->set_total(20);
    $order->add_item($item);$order->calculate_totals();$order->save();
    $source=['customer_id'=>$users[0],'order_id'=>$order->get_id(),'type'=>'','document_id'=>0];
    $snapshot=Approval::snapshot($source);
    $approval=(new ReflectionMethod(Approval::class,'save'))->invoke(null,['source'=>$source,'snapshot'=>$snapshot,'revision'=>Approval::revision($snapshot),'status'=>'pending','history'=>[]]);
    $listener=stream_socket_server('tcp://127.0.0.1:0');if(!$listener)throw new RuntimeException('Loopback socket unavailable');
    $address=stream_socket_get_name($listener,false);fclose($listener);$base='http://'.$address;
    $secret=wp_generate_password(40,false);
    $router=tempnam(sys_get_temp_dir(),'approval-router-');$log=tempnam(sys_get_temp_dir(),'approval-server-');$files=[$router,$log];
    file_put_contents($router,"<?php\nif (PHP_SAPI !== 'cli-server' || (\$_SERVER['REQUEST_METHOD']??'') !== 'GET' || !hash_equals(".var_export($secret,true).", \$_SERVER['HTTP_X_PCOE_TEST']??'')) { http_response_code(403); exit; }\n".
        "define('DISABLE_WP_CRON',true); define('WP_HTTP_BLOCK_EXTERNAL',true); define('WP_USE_THEMES',true);\n".
        "\$_SERVER['HTTP_HOST']='paint.local'; \$_SERVER['SERVER_PORT']=80; \$_SERVER['SCRIPT_NAME']='/index.php';\n".
        "require ".var_export(ABSPATH.'wp-load.php',true).";\n".
        "add_filter('pre_wp_mail','__return_true',PHP_INT_MAX); add_filter('pre_http_request',static fn()=>new WP_Error('offline','External HTTP blocked'),PHP_INT_MAX);\n".
        "require ".var_export(ABSPATH.'wp-blog-header.php',true).";\n");
    $server=proc_open([PHP_BINARY,'-S',$address,'-t',ABSPATH,$router],[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,ABSPATH);
    if(!is_resource($server))throw new RuntimeException('Local HTTP server unavailable');
    for($i=0;$i<50;$i++){ $ready=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1);if($ready){fclose($ready);break;}usleep(100000); }
    $request=static function(string $path,int $uid=0)use($base,$secret){
        $headers=['Host'=>'paint.local','X-PCOE-Test'=>$secret];
        if($uid)$headers['Cookie']=LOGGED_IN_COOKIE.'='.wp_generate_auth_cookie($uid,time()+600,'logged_in');
        $r=wp_remote_get($base.$path,['timeout'=>30,'redirection'=>0,'headers'=>$headers]);
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
    echo "PASS: $checks real HTTP approval route checks; external HTTP/mail blocked, disposable records removed.\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    foreach($files as $file)if(is_file($file))unlink($file);
    if($approval)wp_delete_post($approval,true);
    if($order)$order->delete(true);
    require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $uid)wp_delete_user($uid);
}
