<?php
/**
 * Plugin Name: PSU Force Per Page (cols × rows)
 * Description: Кол-во товаров на странице = колонки × ряды. Колонки меряются JS-ом, ряды задаются константами.
 * Author: PSU
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) exit;

/* ================== НАСТРОЙКИ ================== */
define('PSUFP_COOKIE_COLS',   'psu_cols');  // имя cookie с количеством колонок
define('PSUFP_COOKIE_ROWS',   'psu_rows');  // имя cookie с количеством рядов

define('PSUFP_ROWS_DESKTOP',  3);  // ряды по умолчанию (десктоп > 480px)
define('PSUFP_ROWS_MOBILE',   3);  // ряды для обычных телефонов (321–480px)
define('PSUFP_ROWS_XSMALL',   2);  // ряды для самых маленьких телефонов (<=320px)

define('PSUFP_FALLBACK_COLS', 5);  // если cookie нет — считаем 5 колонок
define('PSUFP_DEBUG', false);      // true = лог в консоль и блок в футере

/* ================ ВСПОМОГАТЕЛЬНЫЕ ================ */

/** Наш ли это витринный запрос Woo (архив/категория/тег товаров)? */
function psufp_is_product_archive_context(): bool {
    if (!function_exists('is_woocommerce')) return false;
    return is_shop() || is_product_taxonomy() || is_product_category() || is_product_tag();
}

/** Считать per_page из cookie (или из fallback). */
function psufp_calc_per_page(): int {
    $cols = isset($_COOKIE[PSUFP_COOKIE_COLS]) ? (int) $_COOKIE[PSUFP_COOKIE_COLS] : 0;
    if ($cols < 1 || $cols > 12) $cols = (int) PSUFP_FALLBACK_COLS;

    $rows = isset($_COOKIE[PSUFP_COOKIE_ROWS]) ? (int) $_COOKIE[PSUFP_COOKIE_ROWS] : (int) PSUFP_ROWS_DESKTOP;
    if ($rows < 1 || $rows > 10) $rows = (int) PSUFP_ROWS_DESKTOP;

    return max($cols, $cols * $rows);
}

/* ================== ПЕРЕХВАТ per_page ================== */

add_filter('loop_shop_per_page', function($n){ return psufp_calc_per_page(); }, 9999);

add_action('pre_get_posts', function($q){
    if (!($q instanceof WP_Query)) return;
    if (!$q->is_main_query()) return;
    if (!psufp_is_product_archive_context()) return;
    $q->set('posts_per_page', psufp_calc_per_page());
}, 9999);

add_action('woocommerce_product_query', function($q){
    if (is_admin()) return;
    $q->set('posts_per_page', psufp_calc_per_page());
}, 9999);

/* ================== JS: замер колонок и рядов ================== */

add_action('wp_footer', function () {
    if (!psufp_is_product_archive_context()) return;

    $cookieCols = esc_js(PSUFP_COOKIE_COLS);
    $cookieRows = esc_js(PSUFP_COOKIE_ROWS);
    $debug      = PSUFP_DEBUG ? 'true' : 'false';
    ?>
    <script>
    (function(){
      var DEBUG = <?php echo $debug; ?>;

      function setCookie(name, value, days){
        var d = new Date(); d.setTime(d.getTime() + days*864e5);
        document.cookie = name + "=" + encodeURIComponent(value) + "; expires=" + d.toUTCString() + "; path=/";
      }
      function getCookie(name){
        var m = document.cookie.match(new RegExp('(?:^|; )'+name.replace(/([.$?*|{}()\[\]\\/+^])/g,'\\$1')+'=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : null;
      }
      function measureCols(){
        var grid = document.querySelector('.woocommerce ul.products');
        if (!grid) return 0;
        var items = grid.querySelectorAll(':scope > li.product');
        if (!items.length) return 0;
        var it = Array.prototype.find.call(items, function(el){ return el.offsetParent !== null; }) || items[0];
        var gw = grid.clientWidth;
        var r  = it.getBoundingClientRect();
        var cs = getComputedStyle(it);
        var outer = r.width + (parseFloat(cs.marginLeft)||0) + (parseFloat(cs.marginRight)||0);
        if (!outer) return 0;
        return Math.max(1, Math.floor(gw / outer));
      }
      function decideRows(){
        var w = window.innerWidth || document.documentElement.clientWidth;
        if (w <= 320) return <?php echo (int) PSUFP_ROWS_XSMALL; ?>;
        if (w <= 480) return <?php echo (int) PSUFP_ROWS_MOBILE; ?>;
        return <?php echo (int) PSUFP_ROWS_DESKTOP; ?>;
      }
      function apply(){
        var cols = measureCols();
        var rows = decideRows();

        var prevCols = parseInt(getCookie('<?php echo $cookieCols; ?>') || '0', 10);
        var prevRows = parseInt(getCookie('<?php echo $cookieRows; ?>') || '0', 10);

        if (DEBUG) {
          var msg = "[PSUFP] cols=" + cols + " (cookie " + prevCols + "), rows=" + rows + " (cookie " + prevRows + "), w=" + window.innerWidth;
          console.log(msg);
          var box = document.getElementById('psufp-debug'); if (box) box.textContent = msg;
        }

        var changed = false;
        if (cols && prevCols !== cols){ setCookie('<?php echo $cookieCols; ?>', String(cols), 7); changed = true; }
        if (rows && prevRows !== rows){ setCookie('<?php echo $cookieRows; ?>', String(rows), 7); changed = true; }

        if (changed){
          if (!sessionStorage.getItem('psufp_applied')){
            sessionStorage.setItem('psufp_applied','1');
            location.reload();
          } else {
            sessionStorage.removeItem('psufp_applied');
          }
        }
      }

      if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', apply); } else { apply(); }
      window.addEventListener('load', function(){ setTimeout(apply, 0); });
      var t; window.addEventListener('resize', function(){ clearTimeout(t); t=setTimeout(apply, 150); });
    })();
    </script>
    <?php if (PSUFP_DEBUG): ?>
      <div id="psufp-debug" style="margin:20px 0;padding:10px;background:#111;color:#0f0;border:2px solid #0f0; font-family:monospace">PSUFP …</div>
    <?php endif;
}, 999);