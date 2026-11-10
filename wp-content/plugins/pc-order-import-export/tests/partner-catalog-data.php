<?php
namespace PaintCore\PCOE {
final class BroadcastPricing {
    public static function scope($u,$fn){$old=$GLOBALS['owner']??0;$GLOBALS['owner']=$u;try{return $fn();}finally{$GLOBALS['owner']=$old;}}
    public static function products($ids){foreach($ids as $id)if(isset($GLOBALS['products'][$id]))yield $GLOBALS['products'][$id];}
}
final class CommercialOffer {public static function packaging($skus){return array_fill_keys($skus,6);}}
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
    function get_attributes(){return ['colour'=>new Attr(true),'supplier'=>new Attr(true),'internal'=>new Attr(false)];}
    function get_attribute($key){return $key==='colour'?'Blue':'SECRET';}
    function get_image_id(){return 0;}function get_gallery_image_ids(){return [];}function get_meta($key){return '';}
}
class Attr {function __construct(private bool $visible){}function get_visible(){return $this->visible;}}
class PSU_Category_Menu {static function index(){return ['visible'=>[1=>1,2=>1],'blocked'=>[3=>1]];}}
function __($v,$d=''){return $v;}function absint($v){return abs((int)$v);}function term_exists($v,$t){return true;}
function get_terms($args){return [(object)['term_id'=>1,'name'=>'Art','parent'=>0],(object)['term_id'=>2,'name'=>'Paint','parent'=>1],(object)['term_id'=>3,'name'=>'Private','parent'=>0]];}
function get_term($id,$tax){foreach(get_terms([]) as $t)if($t->term_id===$id)return $t;return false;}
function get_ancestors($id,$tax,$type){return $id===2?[1]:[];}
function get_term_children($id,$tax){return $id===1?[2]:[];}
function is_taxonomy_hierarchical($tax){return true;}function has_term($ids,$tax,$id){return (bool)array_intersect($ids,$GLOBALS['products'][$id]->categories);}
function get_the_terms($id,$tax){if($tax!=='product_cat')return [];return array_values(array_filter(get_terms([]),fn($t)=>in_array($t->term_id,$GLOBALS['products'][$id]->categories)));}
function get_post_meta($id,$key,$single){return str_starts_with($key,'_stock_at_')?2:'';}
function wp_list_pluck($a,$key){return array_map(fn($r)=>$r->$key,$a);}function is_wp_error($v){return false;}
function post_password_required($id){return $id===4;}function apply_filters($h,$value,...$args){return $value;}
function wc_get_product($id){return $GLOBALS['products'][$id]??false;}function wc_get_product_id_by_sku($sku){foreach($GLOBALS['products'] as $p)if($p->sku===$sku)return $p->id;return 0;}
function wc_get_price_to_display($p,$args){return $args['price'];}function wc_attribute_label($s){return $s;}
function get_permalink($id){return 'https://example.test/product/'.$id;}
function wp_strip_all_tags($s){return strip_tags($s);}function strip_shortcodes($s){return $s;}
function lavka_get_locations_mapping_for_java(){return [['id'=>10,'codes'=>['1']],['id'=>11,'codes'=>['5']]];}
function get_woocommerce_currency(){return 'UAH';}
$GLOBALS['products']=[1=>new WC_Product(1,'A'),2=>new WC_Product(2,'PRIVATE',[3]),4=>new WC_Product(4,'PASSWORD')];
use PaintCore\PCOE\PartnerCatalogData as D;
$n=0;function ok($v,$why){global $n;$n++;if(!$v)throw new RuntimeException($why);}
$s=['skus'=>['A','PRIVATE','PASSWORD','MISSING'],'category'=>0,'brand'=>0];$d=D::build(5,$s);
ok(count($d['rows'])===1,'hidden category and password excluded');ok($d['rows'][0]['cost']===10.0,'owner 5 cost');ok($d['rows'][0]['retail']===30.0,'retail not replaced by owner price');ok($d['rows'][0]['stock']===4.0,'selling stock reused');ok($d['rows'][0]['attributes']===['colour'=>'Blue'],'internal/supplier attrs excluded');ok($d['rows'][0]['pack']===6.0 || $d['rows'][0]['pack']===6,'pack quantity');ok(count($d['missing'])===3,'missing and excluded report');ok($d['rows'][0]['category_id']===2,'stable real category id');ok(D::build(6,$s)['rows'][0]['cost']===20.0,'different owner cost');ok(($GLOBALS['owner']??null)===0,'scope restored');
echo "PASS $n catalogue data checks\n";
}
