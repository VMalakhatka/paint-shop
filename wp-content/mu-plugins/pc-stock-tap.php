<?php
/*
Plugin Name: PC Stock Tap
Description: Selective stock writes barrier + trace log.
Author: Volodymyr Malakhatka
Text Domain: pc-stock-tap
Domain Path: /languages
*/

if (!defined('ABSPATH')) exit;

function pcstk_trace_paths($limit = 20){
  $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
  $list = [];
  foreach ($t as $fr){
    $file = $fr['file'] ?? '';
    if (!$file) continue;
    $list[] = $file;
    if (count($list) >= $limit) break;
  }
  return $list;
}
function pcstk_trace_str(){
  $paths = pcstk_trace_paths(12);
  $nice = [];
  foreach ($paths as $p){
    if (strpos($p, '/wp-includes/')!==false || strpos($p,'/wp-admin/')!==false) continue;
    $nice[] = basename($p);
    if (count($nice)>=10) break;
  }
  return implode(' ← ', $nice);
}

/**
 * Selective barrier for writes of _stock/_stock_status/_stock_at_* on frontend.
 * Block ONLY if call came from SLW helpers/classes (frontend/order-item),
 * and it's not our intentional write-off.
 */
add_filter('update_post_metadata', function($check,$post_id,$key,$val){
  // интересуют только ключи стока
  if ($key!=='_stock' && $key!=='_stock_status' && strpos($key,'_stock_at_')!==0) return $check;

  // всегда пропускаем админку и cron
  if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) return $check;

  // пропускаем наши целевые редукции (или когда мы явно разрешили)
  if (!empty($GLOBALS['PC_ALLOW_STOCK_WRITE'])) return $check;

  // если Woo сменил статус на processing/completed — это тоже «наше окно»
  if (did_action('woocommerce_order_status_processing') || did_action('woocommerce_order_status_completed')) {
    return $check;
  }

  // анализ стека: если источником является SLW frontend/order-item — блокируем
  $paths = pcstk_trace_paths();
  $is_slw_writer = false;
  foreach ($paths as $p){
    // названия взяты из твоих логов:
    if (strpos($p, 'helper-slw-frontend.php')!==false)    { $is_slw_writer = true; break; }
    if (strpos($p, 'class-slw-frontend-cart.php')!==false){ $is_slw_writer = true; break; }
    if (strpos($p, 'helper-slw-order-item.php')!==false)  { $is_slw_writer = true; break; }
    if (strpos($p, 'class-slw-order-item.php')!==false)   { $is_slw_writer = true; break; }
  }

  if ($is_slw_writer){
    $old = get_post_meta($post_id,$key,true);
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            // через __() для возможности перевода сообщений логов
            __('[STOCK-BARRIER] blocked (SLW): post=%d key=%s old=%s new=%s url=%s trace=%s', 'pc-stock-tap'),
            (int)$post_id,
            $key,
            is_scalar($old)?$old:json_encode($old),
            is_scalar($val)?$val:json_encode($val),
            $_SERVER['REQUEST_URI']??'',
            pcstk_trace_str()
        ));
    }
    return false; // ← блок
  }

  // по умолчанию пропускаем (другие плагины/наши вещи)
  return $check;
}, 9999, 4);  