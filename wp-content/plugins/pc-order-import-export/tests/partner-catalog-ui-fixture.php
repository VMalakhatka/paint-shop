<?php
namespace PaintCore\PCOE {final class BroadcastPricing {static function group($id){return 'fixture';}}}
namespace {
define('ABSPATH',__DIR__);define('PCOE_DIR',dirname(__DIR__));define('PCOE_URL','file://'.PCOE_DIR.'/');
require PCOE_DIR.'/inc/PartnerCatalog.php';require PCOE_DIR.'/inc/PartnerCatalogUi.php';
function get_current_user_id(){return 5;}function get_userdata($u){return (object)['ID'=>$u];}function pc_wholesale_customer_can_access($u){return true;}
function get_user_meta($id,$k,$single){return ['selection'=>['skus'=>['TEST-001'],'category'=>0,'brand'=>0],'enabled'=>true,'seed'=>'fixture'];}
function get_transient($key){if(str_contains($key,'notice'))return false;return ['signature'=>hash('sha256',wp_json_encode([get_user_meta(5,'',true)['selection'],'fixture'])),'data'=>['rows'=>[['sku'=>'TEST-001','name'=>'Тестова фарба для творчості, синя, 50 мл','cost'=>40,'retail'=>60,'stock'=>5,'images'=>['image']]],'missing'=>['TEST-MISSING'],'at'=>'2026-11-10T12:00:00Z']];}
function wp_json_encode($v){return json_encode($v);}function get_terms($a){return [(object)['term_id'=>3,'name'=>'Матеріали для творчості']];}function is_wp_error($v){return false;}
function selected($a,$b,$echo=false){return $a===$b?'selected':'';}function wp_nonce_field($a){echo '<input type="hidden" name="_wpnonce" value="fixture">';}
function wp_enqueue_style(...$args){}function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function esc_attr($v){return esc_html($v);}function esc_textarea($v){return esc_html($v);}function esc_url($v){return esc_html($v);}
function __($s,$domain=''){static $t;if($t===null)$t=getenv('PCOE_TRANSLATIONS')?json_decode(file_get_contents(getenv('PCOE_TRANSLATIONS')),true):[];return $t[$s]??$s;}function esc_html__($s,$d=''){return esc_html(__($s,$d));}
function wp_salt($s){return 'fixture-secret';}function admin_url($s){return 'https://example.test/'.$s;}function add_query_arg($a,$b=null,$c=null){return ($c??$b).'?'.http_build_query(is_array($a)?$a:[$a=>$b]);}
function wc_get_page_permalink($s){return 'https://example.test/my-account/';}
function wc_get_account_endpoint_url($s){return 'https://example.test/my-account/'.$s.'/';}
echo '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{font:16px/1.5 Arial;margin:20px auto;padding:0 12px;max-width:1000px}*{box-sizing:border-box}button,input,textarea,select{font:inherit}textarea,select{padding:8px}button{padding:10px;background:#245f4c;color:white;border:0;border-radius:4px}td,th{padding:8px;text-align:left}table{border-collapse:collapse}tr{border-bottom:1px solid #ddd}.woocommerce-info{background:#edf4fa;padding:12px}</style><style>'.file_get_contents(PCOE_DIR.'/assets/partner-catalog.css').'</style>';
\PaintCore\PCOE\PartnerCatalogUi::render();echo '</html>';
}
