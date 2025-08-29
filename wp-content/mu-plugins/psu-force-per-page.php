<?php
/**
 * Plugin Name: PSU Force Per Page (cols × rows)
 * Description: Ставит количество товаров на странице = (число колонок в CSS-сетке) × (ряды). Колонки меряются JS-ом на клиенте и сохраняются в cookie.
 * Author: PSU
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

/* ================== НАСТРОЙКИ ================== */
define('PSUFP_COOKIE_COLS', 'psu_cols');   // имя cookie с количеством колонок
define('PSUFP_ROWS',         3);           // СКОЛЬКО РЯДОВ показывать на странице
define('PSUFP_FALLBACK_COLS', 5);          // если cookie ещё нет — считаем столько колонок
define('PSUFP_DEBUG',        false);       // true = лог в консоль и блок в футере

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
    return max($cols, $cols * (int) PSUFP_ROWS);
}

/* ================== ПЕРЕХВАТ per_page ================== */

/** Самый ранний перехват параметров WP_Query (ещё до построения объекта). */
add_filter('request', function(array $vars){
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) return $vars;

    // Признаки витринного запроса товаров в массиве $vars
    $is_products = (isset($vars['post_type']) && $vars['post_type'] === 'product')
        || isset($vars['product_cat']) || isset($vars['product_tag'])
        || isset($vars['product_shipping_class']);

    if ($is_products) {
        $vars['posts_per_page'] = psufp_calc_per_page();
    }
    return $vars;
}, 1);

/** Классический фильтр Woo. */
add_filter('loop_shop_per_page', function($n){ return psufp_calc_per_page(); }, 9999);

/** На уровне главного запроса (страховка против тем/плагинов). */
add_action('pre_get_posts', function($q){
    if (!($q instanceof WP_Query)) return;
    if (!$q->is_main_query())      return;
    if (!psufp_is_product_archive_context()) return;
    $q->set('posts_per_page', psufp_calc_per_page());
}, 9999);

/** Доп. «якорь» от Woo. */
add_action('woocommerce_product_query', function($q){
    if (is_admin()) return;
    $q->set('posts_per_page', psufp_calc_per_page());
}, 9999);

/* ================== JS: замер колонок и cookie ================== */

add_action('wp_footer', function () {
    if (!psufp_is_product_archive_context()) return;

    $cookie = esc_js(PSUFP_COOKIE_COLS);
    $debug  = PSUFP_DEBUG ? 'true' : 'false';
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
        // Берём первый видимый элемент — его наружную ширину считаем «ячейкой»
        var it = Array.prototype.find.call(items, function(el){ return el.offsetParent !== null; }) || items[0];
        var gw = grid.clientWidth;
        var r  = it.getBoundingClientRect();
        var cs = getComputedStyle(it);
        var outer = r.width + (parseFloat(cs.marginLeft)||0) + (parseFloat(cs.marginRight)||0);
        if (!outer) return 0;
        return Math.max(1, Math.floor(gw / outer));
      }
      function apply(){
        var cols = measureCols();
        var prev = parseInt(getCookie('<?php echo $cookie; ?>') || '0', 10);
        if (DEBUG) {
          var msg = "[PSUFP] cols(measured)=" + cols + ", cookie=" + prev + ", w=" + window.innerWidth;
          console.log(msg);
          var box = document.getElementById('psufp-debug'); if (box) box.textContent = msg;
        }
        if (cols && prev !== cols){
          setCookie('<?php echo $cookie; ?>', String(cols), 7);
          // Перезагружаем один раз, чтобы сервер получил новое per_page
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
      var t; window.addEventListener('resize', function(){ clearTimeout(t); t = setTimeout(apply, 150); });
    })();
    </script>
    <?php if (PSUFP_DEBUG): ?>
      <div id="psufp-debug" style="margin:20px 0;padding:10px;background:#111;color:#0f0;border:2px solid #0f0; font-family:monospace">PSUFP …</div>
    <?php endif;
}, 999);