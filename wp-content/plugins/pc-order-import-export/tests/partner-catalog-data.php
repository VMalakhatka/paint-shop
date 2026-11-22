<?php
namespace PaintCore\PCOE {
final class BroadcastPricing {
    public static function group($user){return 'test-pricing';}
    public static function scope($u,$fn){$old=$GLOBALS['owner']??0;$GLOBALS['owner']=$u;try{return $fn();}finally{$GLOBALS['owner']=$old;}}
    public static function products($ids){foreach($ids as $id)if(isset($GLOBALS['products'][$id]))yield $GLOBALS['products'][$id];}
}
final class CommercialOffer {public static function packaging($skus){$GLOBALS['packaging_calls']=($GLOBALS['packaging_calls']??0)+1;return array_fill_keys($skus,6);}}
}
namespace {
define('ABSPATH',__DIR__);
require dirname(__DIR__).'/inc/PriceList.php';require dirname(__DIR__).'/inc/PartnerCatalogData.php';
class WC_Product {
    function __construct(public int $id,public string $sku,public array $categories=[2]){}
    function get_id(){return $this->id;}function get_sku(){return $this->sku;}function get_parent_id(){return 0;}
    function get_price(){return $GLOBALS['owner']===5?10:20;}function get_regular_price($mode='view'){return 30;}
    function get_name(){return 'Product '.$this->sku;}function is_type($s){return $s==='simple';}
    function get_description(){return '<p>Full</p>';}function get_short_description(){return 'Short';}
    function get_attributes(){return ($GLOBALS['extra_attributes'][$this->id] ?? []) + ['colour'=>new Attr(true),'supplier'=>new Attr(true),'internal'=>new Attr(false)];}
    function get_attribute($key){return $key==='colour'?'Blue':'SECRET';}
    function get_image_id(){return 0;}function get_gallery_image_ids(){return [];}function get_meta($key){return '';}
}
class Attr {function __construct(private bool $visible){}function get_visible(){return $this->visible;}}
class PSU_Category_Menu {static function index(){return ['visible'=>[1=>1,2=>1],'blocked'=>[3=>1]];}}
function __($v,$d=''){return $v;}function absint($v){return abs((int)$v);}function term_exists($v,$t){return true;}
function add_filter(...$args){}function add_action(...$args){}
require dirname(__DIR__,3).'/mu-plugins/psu-catalog-suppliers.php';
function get_option($key,$default=false){
    if($key!=='psu_catalog_suppliers')return $default;
    $settings=[];
    foreach($GLOBALS['visible_brands']??[] as $order=>$term)$settings[$term->term_id]=['visible'=>true,'order'=>$order];
    return $settings;
}
function get_term_meta($id,$key,$single){return $key==='product_count_product_brand'?($GLOBALS['storefront_counts'][$id]??1):'';}
function get_terms($args){
    if(($args['taxonomy']??'')==='product_brand'){
        $terms=[];
        foreach($GLOBALS['catalog_brands']??$GLOBALS['visible_brands']??[] as $brand){
            $term=clone $brand;
            $term->count=empty($GLOBALS['admin_request'])?get_term_meta($term->term_id,'product_count_product_brand',true):26;
            $terms[]=$term;
        }
        return $terms;
    }
    return [(object)['term_id'=>1,'name'=>'Art','parent'=>0],(object)['term_id'=>2,'name'=>'Paint','parent'=>1],(object)['term_id'=>3,'name'=>'Private','parent'=>0]];}
function get_term($id,$tax){if($tax==='location')return (object)['term_id'=>$id,'name'=>'Location '.$id,'parent'=>0];foreach(get_terms([]) as $t)if($t->term_id===$id)return $t;return false;}
function get_ancestors($id,$tax,$type){return $id===2?[1]:[];}
function get_term_children($id,$tax){return ($tax==='product_brand' && $id===22) ? [24] : ($id===1?[2]:[]);}
function is_taxonomy_hierarchical($tax){return true;}
function has_term($ids,$tax,$id){return (bool)array_intersect($ids,wp_list_pluck(get_the_terms($id,$tax),'term_id'));}
function get_the_terms($id,$tax){
    if($tax==='product_brand')return $GLOBALS['assigned_brands'][$id]??[];
    return array_values(array_filter(get_terms([]),fn($t)=>in_array($t->term_id,$GLOBALS['products'][$id]->categories)));
}

function get_post_meta($id,$key,$single){return str_starts_with($key,'_stock_at_')?2:'';}
function wp_list_pluck($a,$key){return array_map(fn($r)=>$r->$key,$a);}function is_wp_error($v){return false;}
function post_password_required($id){return $id===4;}function apply_filters($h,$value,...$args){return $value;}
function wc_get_product($id){return $GLOBALS['products'][$id]??false;}function wc_get_product_id_by_sku($sku){foreach($GLOBALS['products'] as $p)if($p->sku===$sku)return $p->id;return 0;}
function wc_get_price_to_display($p,$args){return $args['price'];}function wc_attribute_label($s){return $s;}
function get_permalink($id){return 'https://example.test/product/'.$id;}
function wp_strip_all_tags($s){return strip_tags($s);}function strip_shortcodes($s){return $s;}
function lavka_get_locations_mapping_for_java(){return $GLOBALS['locations']??[['id'=>10,'codes'=>['1']],['id'=>11,'codes'=>['5']]];}
function get_bloginfo($k){return 'Shop';}function home_url($p){return 'https://example.test/';}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_woocommerce_currency(){return 'UAH';}
$GLOBALS['products']=[1=>new WC_Product(1,'A'),2=>new WC_Product(2,'PRIVATE',[3]),4=>new WC_Product(4,'PASSWORD')];
use PaintCore\PCOE\PartnerCatalogData as D;
$n=0;function ok($v,$why){global $n;$n++;if(!$v)throw new RuntimeException($why);}
$s=['skus'=>['A','PRIVATE','PASSWORD','MISSING'],'category'=>0,'brand'=>0];$d=D::build(5,$s);
ok(count($d['rows'])===1,'hidden category and password excluded');ok($d['rows'][0]['cost']===10.0,'owner 5 cost');ok($d['rows'][0]['retail']===30.0,'retail not replaced by owner price');ok($d['rows'][0]['stock']===4.0,'selling stock reused');ok($d['rows'][0]['attributes']===['colour'=>'Blue'],'internal/supplier attrs excluded');ok($d['rows'][0]['pack']===6.0 || $d['rows'][0]['pack']===6,'pack quantity');ok(count($d['missing'])===3,'missing and excluded report');ok($d['rows'][0]['category_id']===2,'stable real category id');ok(D::build(6,$s)['rows'][0]['cost']===20.0,'different owner cost');ok(($GLOBALS['owner']??null)===0,'scope restored');
$calls=$GLOBALS['packaging_calls'];
$only=D::build_ids(5,[1],$s,[10],[],false);ok($only['rows'][0]['stock']===2.0,'CRM selected Kyiv group excludes Odesa');
ok($GLOBALS['packaging_calls']===$calls,'CRM does not request Java packaging');
require dirname(__DIR__).'/inc/CrmCatalogData.php';
$GLOBALS['visible_brands']=get_terms([]);
$sel=\PaintCore\PCOE\CrmCatalogData::selection(['brands'=>['2','1','2'],'locations'=>['10']]);
ok($sel===['brands'=>[1,2],'locations'=>[10]],'Multiple brands use a deduplicated selection');
$GLOBALS['locations']=[['id'=>10,'codes'=>['1','7']],['id'=>11,'codes'=>['5']],['id'=>12,'codes'=>['1']]];
try{\PaintCore\PCOE\CrmCatalogData::selection(['brands'=>[1],'locations'=>[10,12]]);$bad=false;}catch(\RuntimeException $e){$bad=true;}ok($bad,'Overlapping warehouse groups cannot double-count stock');
try{\PaintCore\PCOE\CrmCatalogData::selection(['brands'=>[1],'locations'=>[999]]);$bad=false;}catch(\RuntimeException $e){$bad=true;}ok($bad,'Unknown/incoming warehouse cannot be selected');
// The same brand assignment must drive selection and public export fields.
$brand=(object)['term_id'=>22,'name'=>'Clairefontaine'];
$otherBrand=(object)['term_id'=>23,'name'=>'Art & Paper'];
$privateBrand=(object)['term_id'=>24,'name'=>'Internal distributor'];
$GLOBALS['visible_brands']=[$brand,$otherBrand];
$GLOBALS['catalog_brands']=[$brand,$otherBrand,$privateBrand];
$GLOBALS['assigned_brands'][1]=[$brand,$privateBrand,$otherBrand];
$GLOBALS['extra_attributes'][1]=['manufacturer'=>new Attr(true)];
$GLOBALS['products'][6]=new class(6,'A-VARIATION') extends WC_Product {
    function get_parent_id(){return 1;}
    function is_type($type){return $type==='variation';}
    function get_permalink(){return get_permalink(1);}
};
$rows=\PaintCore\PCOE\CrmCatalogData::rows(5,[1,6],['brands'=>[22],'locations'=>[10]]);
ok(count($rows['rows'])===2,'CRM selects both a matching product and its variation');
require dirname(__DIR__).'/inc/PartnerCatalogFormats.php';
require dirname(__DIR__).'/inc/CrmCatalogFormat.php';
$xml=new DOMDocument();
$xml->loadXML('<offers>'.\PaintCore\PCOE\CrmCatalogFormat::part($rows)['xml'].'</offers>');
$xpath=new DOMXPath($xml);
foreach([1,6] as $id){
    ok($xpath->evaluate("string(/offers/offer[@id='$id']/vendor)")==='Clairefontaine, Art & Paper','XML uses assigned brands for product and variation, regardless of attribute label');
}
$xml->loadXML(\PaintCore\PCOE\PartnerCatalogFormats::yml($rows));
ok((new DOMXPath($xml))->evaluate('string(//offer/vendor)')==='Clairefontaine, Art & Paper','Prom exports the same public names');
ok(\PaintCore\PCOE\PartnerCatalogFormats::cells($rows['rows'][0])[3]==='Clairefontaine, Art & Paper','Partner spreadsheets export the same brand names');
$GLOBALS['assigned_brands'][1]=[$privateBrand];
$manual=D::build_ids(5,[1],['skus'=>['A'],'category'=>0,'brand'=>0],[10],[],false);
ok(count($manual['rows'])===1 && $manual['rows'][0]['brand']==='','SKU with a private supplier and a manufacturer attribute is included without a public brand');
$unbrandedXml=\PaintCore\PCOE\CrmCatalogFormat::part($manual)['xml'];
ok(!str_contains($unbrandedXml,'<vendor>') && !str_contains($unbrandedXml,'SECRET'),'Neither vendor nor params expose a substitute brand');
ok($manual['rows'][0]['attributes']===['colour'=>'Blue'],'Unrelated public attributes remain available without a brand');
$hidden=['brands'=>[24],'locations'=>[10]];
$mixed=['brands'=>[22,24],'locations'=>[10]];
ok(\PaintCore\PCOE\CrmCatalogData::selection($mixed,true)['brands']===[22],'Saved CRM selection retains its public brands');
ok(\PaintCore\PCOE\CrmCatalogData::page($hidden,0)===[],'Removed brand never becomes an unrestricted CRM query');
ok(\PaintCore\PCOE\CrmCatalogData::rows(5,[1,6],$mixed)['rows']===[],'Product matching only a hidden brand is excluded from CRM brand filter');
foreach ([fn()=>D::selection(['brand'=>24]),fn()=>\PaintCore\PCOE\CrmCatalogData::selection($mixed)] as $invalid){
    try{$invalid();$rejected=false;}catch(RuntimeException $e){$rejected=true;}
    ok($rejected,'New selections reject hidden brand IDs');
}
ok(iterator_to_array(D::products([1],['brand'=>22,'category'=>0]))===[],'Hidden child brand cannot match a public parent-brand filter');
try{D::build(5,['skus'=>['A'],'category'=>0,'brand'=>24]);$rejected=false;}catch(RuntimeException $e){$rejected=true;}
ok($rejected,'Previously saved hidden Prom brand cannot become an unfiltered SKU export');
$emptyBrand=(object)['term_id'=>25,'name'=>'Only hidden products'];
$GLOBALS['catalog_brands'][]=$emptyBrand;
$GLOBALS['visible_brands'][]=$emptyBrand;
$GLOBALS['storefront_counts'][25]=0;
$before=\PaintCore\PCOE\CrmCatalogData::context(5,$mixed);
$GLOBALS['admin_request']=true;
ok(array_column(psu_catalog_supplier_terms(), 'count')===[26,26,26,26], 'Admin fixture includes raw product counts');
ok(array_keys(D::brand_names())===[22,23], 'Download handler sees exactly the storefront brands even when raw counts include hidden products');
ok(\PaintCore\PCOE\CrmCatalogData::context(5,$mixed)===$before, 'The account preview and admin-post download accept the same snapshot');
$GLOBALS['visible_brands']=[$brand];
ok(\PaintCore\PCOE\CrmCatalogData::context(5,$mixed)!==$before,'Hiding a secondary brand invalidates the previous CRM snapshot');
echo "PASS $n catalogue data checks\n";
}
