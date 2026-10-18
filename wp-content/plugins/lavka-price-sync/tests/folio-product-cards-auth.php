<?php
// Both entry points must reject capability/nonce failures before contacting Java.
define('ABSPATH',__DIR__.'/');define('LPS_CAP','manage_lavka_prices');
function add_action($n,$f){$GLOBALS['hooks'][$n]=$f;}function __($s,$d=''){return $s;}
function esc_html__($s,$d=''){return $s;}function current_user_can($c){return $GLOBALS['allowed'];}
function wp_send_json_error($d,$status=400){throw new RuntimeException((string)$status);}
function wp_die($s,$title='',$args=[]){throw new RuntimeException((string)($args['response']??500));}
function check_admin_referer($n){throw new RuntimeException('nonce-rejected');}
function check_ajax_referer($n,$k){throw new RuntimeException('nonce-rejected');}
require __DIR__.'/../inc/folio-product-cards.php';
require __DIR__.'/../inc/folio-product-cards-import.php';
foreach(['wp_ajax_lps_folio_product_cards_import','wp_ajax_lps_folio_product_cards','admin_post_lps_folio_product_cards'] as $hook){
 foreach([false,true] as $allow){$GLOBALS['allowed']=$allow;
  try{($GLOBALS['hooks'][$hook])();throw new LogicException('Unauthorized call continued');}
  catch(RuntimeException $e){if($e->getMessage()!==($allow?'nonce-rejected':'403'))throw $e;}
 }
}
echo "PASS: preview and download reject missing capability or nonce before Java reads\n";
