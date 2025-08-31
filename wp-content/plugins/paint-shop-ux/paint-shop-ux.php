<?php
/*
Plugin Name: Paint Shop UX
Description: Компактные названия в каталоге, единый бокс для изображений, мобильные размеры заголовков.
Author: Volodymyr
Version: 1.0.0
*/
if (!defined('ABSPATH')) exit;

/** =======================
 *  Настройки по умолчанию
 *  (можешь менять цифры здесь)
 *  ======================= */
const PSU_COLS_DESKTOP = 0; // 0 = не трогаем сетку (сетка у тебя уже в теме)
const PSU_IMG_H_DESKTOP = 210; // px
const PSU_IMG_H_TABLET  = 190; // px
const PSU_IMG_H_MOBILE  = 180; // px
const PSU_TITLE_RESERVE    = 30;  // сколько символов показывать, если нет "|"

/** =======================
 *  1) Компактные названия
 *  ======================= */
add_action('init', function () {
    // если тема выводит стандартным хуком — подменим
    remove_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10);
    add_action('woocommerce_shop_loop_item_title', 'psu_loop_title', 10);
});
function psu_loop_title() {
    if (is_product()) return; // на странице товара — полное
    $raw = get_the_title();
    $display = psu_compact_title_after_pipe($raw, PSU_TITLE_RESERVE);
    echo '<h2 class="woocommerce-loop-product__title compact-title" title="' . esc_attr($raw) . '">' . esc_html($display) . '</h2>';
}
function psu_compact_title_after_pipe($title, $reserve = 25) {
    $t = trim(wp_strip_all_tags((string)$title));
    $pos = strpos($t, '|');
    if ($pos !== false) {
        $after = ltrim(substr($t, $pos + 1));
        if ($after !== '') return $after;
    }
    // фолбек — последние N символов (юникод-безопасно)
    $chars = preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) return $t;
    $len = count($chars);
    return ($len <= $reserve) ? $t : implode('', array_slice($chars, $len - $reserve));
}

/** =======================
 *  2) CSS: единый бокс для картинок, фикс высоты title, мобильные размеры
 *     (сетку **не трогаем**, она уже у тебя в child theme)
 *  ======================= */
add_action('wp_enqueue_scripts', function () {
    $css = '
/* Карточка как колонка: подвал прижат вниз */
.woocommerce ul.products li.product{display:flex;flex-direction:column;height:100%}

/* ЕДИНАЯ ВЫСОТА КАРТИНКИ */
.woocommerce ul.products li.product a img{
  width:100%;
  height: '.intval(PSU_IMG_H_DESKTOP).'px;
  object-fit:contain;object-position:center;display:block;
  background:#fff;padding:6px;box-sizing:border-box;
}
@media (max-width:1024px){
  .woocommerce ul.products li.product a img{height: '.intval(PSU_IMG_H_TABLET).'px;}
}
@media (max-width:600px){
  .woocommerce ul.products li.product a img{height: '.intval(PSU_IMG_H_MOBILE).'px;}
}

/* КОМПАКТНЫЙ TITLE: ровно 2 строки, одинаковая высота */
.woocommerce ul.products li.product .woocommerce-loop-product__title.compact-title{
  --lines:2;
  display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:var(--lines);
  overflow:hidden;line-height:1.2;min-height:calc(1.2em * var(--lines));
  margin:0.4rem 0 0.6rem !important;
  font-size: clamp(0.85rem, 2.5vw, 1rem);
}

/* Подвал карточки всегда вниз */
.woocommerce ul.products li.product .price,
.woocommerce ul.products li.product .quantity,
.woocommerce ul.products li.product .add_to_cart_button,
.woocommerce ul.products li.product .button,
.woocommerce ul.products li.product .loop-buy-row{margin-top:auto}

/* H1 подгруппы поменьше */
.woocommerce-products-header__title.page-title{font-size:1.8rem}
@media (max-width:768px){ .woocommerce-products-header__title.page-title{font-size:1.4rem} }
';
    wp_register_style('psu-inline', false);
    wp_enqueue_style('psu-inline');
    wp_add_inline_style('psu-inline', $css);
}, 20);

/** =======================
 *  3) (опционально) Колонки каталога PHP-ом
 *      — ОТКЛЮЧЕНО, т.к. сетка уже в child theme.
 *      Включишь — поставь нужные цифры и раскомментируй.
 *  ======================= */
// add_filter('loop_shop_columns', function($cols){
//     if (wp_is_mobile()) return 3; // маленькие телефоны
//     return 4; // большие экраны
// }, 20);