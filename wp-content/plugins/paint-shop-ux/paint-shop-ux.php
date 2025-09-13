<?php
/*
Plugin Name: Paint Shop UX
Description: Компактные названия в каталоге, единый бокс для изображений, мобильные размеры заголовков.
Author: Volodymyr
Version: 1.0.0
Text Domain: paint-shop-ux
Domain Path: /languages
*/
if (!defined('ABSPATH')) exit;

// Загрузка текст-домена (на будущее, если появятся строки)
add_action('init', function () {
    load_plugin_textdomain('paint-shop-ux', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/** =======================
 *  Настройки по умолчанию
 *  ======================= */
// через фильтры — так локали/темы смогут подменять
const PSU_COLS_DESKTOP = 0;
const PSU_IMG_H_DESKTOP = 210;
const PSU_IMG_H_TABLET  = 190;
const PSU_IMG_H_MOBILE  = 180;
const PSU_TITLE_RESERVE = 35;

/** =======================
 *  1) Компактные названия
 *  ======================= */
add_action('init', function () {
    remove_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10);
    add_action('woocommerce_shop_loop_item_title', 'psu_loop_title', 10);
});
function psu_loop_title() {
    if (is_product()) return;
    $raw = get_the_title();
    $reserve = (int) apply_filters('psu_title_reserve', PSU_TITLE_RESERVE);
    $display = psu_compact_title_after_pipe($raw, $reserve);
    echo '<h2 class="woocommerce-loop-product__title compact-title" title="' . esc_attr($raw) . '">' . esc_html($display) . '</h2>';
}
function psu_compact_title_after_pipe($title, $reserve = 25) {
    $t = trim(wp_strip_all_tags((string)$title));
    $pos = strpos($t, '|');
    if ($pos !== false) {
        $after = ltrim(substr($t, $pos + 1));
        if ($after !== '') return $after;
    }
    $chars = preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) return $t;
    $len = count($chars);
    return ($len <= $reserve) ? $t : implode('', array_slice($chars, $len - $reserve));
}

/** =======================
 *  2) CSS
 *  ======================= */
add_action('wp_enqueue_scripts', function () {
    $img_h_desktop = (int) apply_filters('psu_img_h_desktop', PSU_IMG_H_DESKTOP);
    $img_h_tablet  = (int) apply_filters('psu_img_h_tablet',  PSU_IMG_H_TABLET);
    $img_h_mobile  = (int) apply_filters('psu_img_h_mobile',  PSU_IMG_H_MOBILE);

    $css = '
.woocommerce ul.products li.product{display:flex;flex-direction:column;height:100%}
.woocommerce ul.products li.product a img{
  width:100%;
  height: '.$img_h_desktop.'px;
  object-fit:contain;object-position:center;display:block;
  background:#fff;padding:6px;box-sizing:border-box;
}
@media (max-width:1024px){
  .woocommerce ul.products li.product a img{height: '.$img_h_tablet.'px;}
}
@media (max-width:600px){
  .woocommerce ul.products li.product a img{height: '.$img_h_mobile.'px;}
}
.woocommerce ul.products li.product .woocommerce-loop-product__title.compact-title{
  --lines:2;
  display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:var(--lines);
  overflow:hidden;line-height:1.2;min-height:calc(1.2em * var(--lines));
  margin:0.4rem 0 0.6rem !important;
  font-size: clamp(0.85rem, 2.5vw, 1rem);
}
.woocommerce ul.products li.product .price,
.woocommerce ul.products li.product .quantity,
.woocommerce ul.products li.product .add_to_cart_button,
.woocommerce ul.products li.product .button,
.woocommerce ul.products li.product .loop-buy-row{margin-top:auto}
.woocommerce-products-header__title.page-title{font-size:1.8rem}
@media (max-width:768px){ .woocommerce-products-header__title.page-title{font-size:1.4rem} }
';
    wp_register_style('psu-inline', false);
    wp_enqueue_style('psu-inline');
    wp_add_inline_style('psu-inline', $css);
}, 20);