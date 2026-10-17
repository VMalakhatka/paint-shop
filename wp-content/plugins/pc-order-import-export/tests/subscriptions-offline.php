<?php
namespace PaintCore\PCOE {
    class ManagerWorkspace {
        static function customer_roles(){return ['customer'];}
        static function directory(...$args){return ['total'=>3,'users'=>array_values($GLOBALS['users'])];}
    }
    class BroadcastSources {static function products($kind,$input){return ['ids'=>$kind==='text'?[]:[1,2,3],'source'=>[],'missing'=>[]];}}
    class CommercialOffer {static function options($input){return [];}}
    class BroadcastPricing {static function group($user){return 'same-prices';}}
    class BroadcastStore {
        const TYPE='test';static array $data=[];
        static function lock($fn){return $fn();}
        static function save($id,$data){self::$data[$id]=$data;}
    }
}
namespace {
    define('ABSPATH',__DIR__);
    function __($s,$d=''){return $s;}
    function esc_html__($s,$d=''){return htmlspecialchars($s);}
    function esc_html($s){return htmlspecialchars((string)$s);}
    function esc_attr($s){return htmlspecialchars((string)$s);}
    function selected($a,$b,$echo=true){$s=$a==$b?'selected':'';if($echo)echo $s;return $s;}
    function checked($a,$b,$echo=true){$s=$a==$b?'checked':'';if($echo)echo $s;return $s;}
    function wp_nonce_field(...$args){}
    function wp_verify_nonce($nonce,$action){return $nonce==='valid';}
    function wp_list_pluck($rows,$key){return array_map(fn($r)=>$r->$key,$rows);}
    function wp_json_encode($v){return json_encode($v);}
    function get_user_meta($u,$k,$single=true){return $GLOBALS['meta'][$u][$k]??'';}
    function update_user_meta($u,$k,$v){$GLOBALS['meta'][$u][$k]=$v;}
    function get_current_user_id(){return 99;}
    function current_user_can($cap){return true;}
    function user_can($u,$cap){return false;}
    function get_userdata($u){return $GLOBALS['users'][$u]??null;}
    function get_posts($args){return [];}
    function wp_insert_post($data,$error){return 456;}
    function update_post_meta(...$args){}
    function get_user_locale($u){return 'uk';}
    function wp_slash($v){return $v;}
    function is_email($v){return str_contains($v,'@');}
    function is_wp_error($v){return false;}
    function wp_next_scheduled(...$args){return false;}
    function wp_schedule_single_event(...$args){}
    function sanitize_key($v){return $v;}
    function sanitize_text_field($v){return $v;}
    function sanitize_textarea_field($v){return $v;}
    function absint($v){return abs((int)$v);}
    function wc_add_notice($s,$type){$GLOBALS['errors'][]=$s;}
    function wc_get_product($id){return new class {function get_parent_id(){return 0;}};}
    function wp_get_object_terms($id,$taxonomy,$args){return $GLOBALS['tags'][$id][$taxonomy]??[];}
    function get_ancestors($id,...$args){return $id===101?[100]:[];}
    function term($id,$name,$taxonomy){return (object)['term_id'=>$id,'name'=>$name,'taxonomy'=>$taxonomy];}
    function psu_catalog_supplier_terms($visible){return [term(10,'Cernit','product_brand'),term(20,'Darwi','product_brand')];}
    class PSU_Category_Menu {static function index(){return ['visible'=>[100=>true,101=>true,200=>true],'blocked'=>[]];}}
    function get_terms($args){return [term(100,'Clay','product_cat'),term(101,'Polymer clay','product_cat'),term(200,'Paint','product_cat')];}
    function get_term($id,$taxonomy){return term($id,'Parent '.$id,$taxonomy);}
    require __DIR__.'/../inc/CustomerSubscriptions.php';
    require __DIR__.'/../inc/Broadcasts.php';
    use PaintCore\PCOE\CustomerSubscriptions as S;
    use PaintCore\PCOE\Broadcasts as B;
    use PaintCore\PCOE\BroadcastStore as Store;
    $count=0;function check($ok,$why){global $count;if(!$ok)throw new \RuntimeException($why);$count++;}
    $p=S::normalize(['topics'=>['arrival','promotion'],'scope'=>'selected','rules'=>[
        ['enabled'=>1,'topic'=>'arrival','brand'=>10,'category'=>100],
        ['enabled'=>1,'topic'=>'arrival','brand'=>20,'category'=>0],
        ['enabled'=>1,'topic'=>'promotion','brand'=>0,'category'=>0],
    ]],[10,20],[100,101,200]);
    check(S::matches($p,'arrival',[10],[101,100]),'Descendant category matches with Cernit');
    check(!S::matches($p,'arrival',[10],[200]),'Cernit paint must not match clay');
    check(S::matches($p,'arrival',[20],[200]),'All Darwi products match');
    check(!S::matches($p,'arrival',[30],[100]),'Other brand clay is excluded');
    check(S::matches($p,'promotion',[30],[200]),'Promotions may be unrestricted independently');
    check(!S::matches($p,'news',[10],[100]),'Unselected topic excluded');
    check(!S::matches($p,'arrival',[],[]),'Untagged text does not bypass selected interests');
    try{S::normalize(['topics'=>['arrival'],'scope'=>'selected','rules'=>[['enabled'=>1,'brand'=>999]]],[10],[100]);throw new \LogicException('accepted invalid brand');}catch(\RuntimeException $e){$count++;}
    $GLOBALS['tags']=[1=>['product_brand'=>[10],'product_cat'=>[101]],2=>['product_brand'=>[20],'product_cat'=>[200]],3=>['product_brand'=>[10],'product_cat'=>[200]]];
    check(S::products($p,'arrival',[1,2,3])===[1,2],'File contains only interest intersection, with descendant matching');
    check(S::products($p,'price',[1,2,3])===[],'Disabled price subscription has no attachment');
    for($i=1;$i<=3;$i++)$GLOBALS['users'][$i]=(object)['ID'=>$i,'display_name'=>'Fixture '.$i,'roles'=>['customer'],'user_email'=>'fixture'.$i.'@example.invalid'];
    $GLOBALS['meta'][1][S::META]=$p;$GLOBALS['meta'][2][S::META]=$p;
    // Same prices but a different product selection must not share a preliminary file group.
    $all=['version'=>1,'topics'=>['arrival'],'scope'=>'all','rules'=>[]];$GLOBALS['meta'][3][S::META]=$all;
    $input=['request_key'=>'12345678-1234-1234-1234-123456789abc','kind'=>'arrival','subject'=>'Fixture','message'=>'Fixture','all_filtered'=>'1'];
    B::create($input);$d=Store::$data[456];
    check(count($d['recipients'])===3 && count($d['groups'])===2,'Identical interests share one group; different subset gets another');
    check($d['recipients'][1]['group']===$d['recipients'][2]['group'],'Same prices and products reuse group');
    check($d['groups'][$d['recipients'][1]['group']]['ids']===[1,2],'Group stores filtered product IDs');
    check($d['recipients'][1]['pricing_context']==='same-prices','Original pricing context preserved for delivery');
    check(B::subscription_current(1,$d['recipients'][1]),'Unchanged preferences permit delivery');
    $GLOBALS['meta'][1][S::META]['topics']=[];
    check(!B::subscription_current(1,$d['recipients'][1]),'Preference change after preparation blocks delivery');
    check(!B::subscription_current(2,[]),'Legacy prepared mailing cannot bypass new subscriptions');
    check(B::subscription_current(77,[]),'Legacy unsaved customer behavior preserved');
    $GLOBALS['meta'][1][S::META]=$p;$GLOBALS['meta'][3][S::META]=$p;
    $input['kind']='text';$input['mail_topic']='arrival';$input['mail_brand']=10;$input['mail_category']=101;
    B::create($input);check(count(Store::$data[456]['recipients'])===3 && Store::$data[456]['groups']===[],'Targeted text uses the same interest rules without generating files');
    $GLOBALS['meta'][99][S::META]=$p;
    S::save(99,['pcoe_subscriptions_nonce'=>'invalid','subscriptions'=>[]]);check(S::profile(99)===$p,'Invalid nonce cannot erase subscriptions');
    S::save(2,['pcoe_subscriptions_nonce'=>'valid','subscriptions'=>[]]);check(S::profile(2)===$p,'Customer cannot update another customer');
    S::save(99,['pcoe_subscriptions_nonce'=>'valid','subscriptions'=>['topics'=>[],'scope'=>'all']]);check(S::profile(99)['topics']===[],'Saving zero topics opts out of all marketing topics');
    ob_start();S::select('fixture',psu_catalog_supplier_terms(true),999,'All brands');$unavailable=ob_get_clean();
    check(str_contains($unavailable,'value="999" selected'), 'Removed or hidden interest cannot silently become All brands on account save');
    if(in_array('--html',$argv,true)){
        $GLOBALS['meta'][99][S::META]=$p;
        echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px sans-serif;max-width:900px;margin:20px auto;padding:12px}select{font-size:16px}label{line-height:2}</style></head><body><form>';S::fields(99);echo '<button>Save changes</button></form></body></html>';
    }else echo "PASS: $count subscription, audience, grouping and consent checks (no HTTP/mail/database)\n";
}
