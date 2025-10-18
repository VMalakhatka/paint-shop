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

    $raw     = get_the_title();
    $product = wc_get_product(get_the_ID());

    $display = psu_get_compact_title($raw, $product ? $product->get_id() : 0);
    // даём перехватить на всякий случай
    $display = apply_filters('psu_compact_title_display', $display, $raw, $product);

    echo '<h2 class="woocommerce-loop-product__title compact-title" title="' . esc_attr($raw) . '">' . esc_html($display) . '</h2>';
}

/**
 * Правило выбора компактного названия:
 * 1) кастомное поле _psu_compact_title (если непустое)
 * 2) если в title есть две | — берём между ними; если одна | — берём после неё
 * 3) иначе — полный title
 */
function psu_get_compact_title(string $title, int $product_id = 0): string {
    $clean = trim(wp_strip_all_tags($title));

    
    // 1) спец-поле
    $meta_key = apply_filters('psu_compact_title_meta_key', '_psu_compact_title');
    if ($product_id) {
        $custom = get_post_meta($product_id, $meta_key, true);
        $custom = is_string($custom) ? trim(wp_strip_all_tags($custom)) : '';
        if ($custom !== '') {
            return $custom;
        }
    }

    // 2) маркеры |…| либо |…конец
    $first = strpos($clean, '|');
    if ($first !== false) {
        $second = strpos($clean, '|', $first + 1);
        if ($second !== false) {
            $mid = trim(substr($clean, $first + 1, $second - $first - 1));
            if ($mid !== '') return $mid;
        } else {
            $after = ltrim(substr($clean, $first + 1));
            if ($after !== '') return $after;
        }
    }

    // 3) полный
    return $clean;
}


// UI: вкладка «Общие» → текстовое поле «Compact catalog title»
add_action('woocommerce_product_options_general_product_data', function () {
    woocommerce_wp_text_input([
        'id'          => '_psu_compact_title',
        'label'       => __('Compact catalog title', 'paint-shop-ux'),
        'desc_tip'    => true,
        'description' => __('Shown in product grid. If empty, the plugin will use |…| or text after |, otherwise full title.', 'paint-shop-ux'),
    ]);
});

// Save
add_action('woocommerce_admin_process_product_object', function (WC_Product $product) {
    if (isset($_POST['_psu_compact_title'])) {
        $product->update_meta_data('_psu_compact_title', sanitize_text_field(wp_unslash($_POST['_psu_compact_title'])));
    }
});

/** =======================
 *  3) Category thumbnails fallback
 *  ======================= */

/**
 * Build up-to-2-letter initials from a term name (multibyte safe).
 */
function psu_term_initials( $name ) {
    $name = trim( wp_strip_all_tags( (string) $name ) );
    if ( $name === '' ) return '';

    // Split by whitespace and punctuation commonly used in names.
    $parts = preg_split( '/[\s\-\._]+/u', $name, -1, PREG_SPLIT_NO_EMPTY );
    $letters = [];

    foreach ( $parts as $part ) {
        // take first character (multibyte safe)
        if ( function_exists( 'mb_substr' ) ) {
            $first = mb_substr( $part, 0, 1, 'UTF-8' );
        } else {
            $first = substr( $part, 0, 1 );
        }
        if ( $first !== '' ) {
            $letters[] = $first;
        }
        if ( count( $letters ) >= 2 ) break;
    }

    $initials = implode( '', $letters );

    // Uppercase (multibyte safe)
    if ( function_exists( 'mb_strtoupper' ) ) {
        $initials = mb_strtoupper( $initials, 'UTF-8' );
    } else {
        $initials = strtoupper( $initials );
    }
    return $initials;
}

// Replace WooCommerce default category thumbnail output to show a text box when no image.
add_action('init', function () {
    // Remove default renderer.
    remove_action('woocommerce_before_subcategory_title', 'woocommerce_subcategory_thumbnail', 10);
    // Add our custom renderer.
    add_action('woocommerce_before_subcategory_title', 'psu_subcategory_thumbnail', 10, 1);
});

/**
 * Output category thumbnail or a text fallback that fills the image area.
 *
 * @param WP_Term $category
 */
function psu_subcategory_thumbnail( $category ) {
    $thumb_id = get_term_meta( $category->term_id, 'thumbnail_id', true );
    $size     = apply_filters( 'subcategory_archive_thumbnail_size', 'woocommerce_thumbnail' );

    if ( $thumb_id ) {
        // Normal image when available.
        $image = wp_get_attachment_image( $thumb_id, $size, false, array( 'loading' => 'lazy' ) );
        if ( ! $image && function_exists( 'wc_placeholder_img' ) ) {
            $image = wc_placeholder_img( $size );
        }
        echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        return;
    }

    // Text fallback — full clickable area is preserved because this runs inside the link.
    $name = isset( $category->name ) ? $category->name : '';
    $initials = psu_term_initials( $name );

    echo '<div class="psu-cat-faux-thumb" role="img" aria-label="' . esc_attr( $name ) . '">'
        . '<span class="psu-cat-faux-title">' . esc_html( $name ) . '</span>'
        . '</div>';
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
.woocommerce ul.products li.product-category a img{
  width:100%;
  height: '.$img_h_desktop.'px;
  object-fit:contain;object-position:center;display:block;
  background:#fff;padding:6px;box-sizing:border-box;
}
@media (max-width:1024px){
  .woocommerce ul.products li.product a img{height: '.$img_h_tablet.'px;}
  .woocommerce ul.products li.product-category a img{height: '.$img_h_tablet.'px;}
}
@media (max-width:600px){
  .woocommerce ul.products li.product a img{height: '.$img_h_mobile.'px;}
  .woocommerce ul.products li.product-category a img{height: '.$img_h_mobile.'px;}
}
.woocommerce ul.products li.product-category .psu-cat-faux-thumb{
  position:relative;
  width:100%;
  height: '.$img_h_desktop.'px;
  /* gradient + subtle paper-like texture overlay */
  --psu-grad-1:#eae4dd;
  --psu-grad-2:#e8e5e2;
  background-image:
    linear-gradient(135deg,var(--psu-grad-1),var(--psu-grad-2)),
    repeating-linear-gradient(45deg,rgba(0,0,0,.007) 0 10px, rgba(0,0,0,.005) 10px 20px);
  background-blend-mode: multiply;
  padding:6px;
  box-sizing:border-box;
  display:flex;align-items:center;justify-content:center;
  text-align:center;
  color:#8a4b2a;
  font-weight:600;
  line-height:1.2;
  border:1px solid #eee;
  word-break:break-word;
  font-size: clamp(0.95rem, 2.2vw, 1.25rem);
  border-radius:6px;
  overflow:hidden;
}

.woocommerce ul.products li.product-category .psu-cat-faux-title{
  transition:transform .25s ease;
  will-change:transform;
  padding:0 .25rem;
}

.woocommerce ul.products li.product-category a:hover .psu-cat-faux-title,
.woocommerce ul.products li.product-category .psu-cat-faux-thumb:hover .psu-cat-faux-title{
  transform:scale(1.08);
}
@media (max-width:1024px){
  .woocommerce ul.products li.product-category .psu-cat-faux-thumb{height: '.$img_h_tablet.'px;}
}
@media (max-width:600px){
  .woocommerce ul.products li.product-category .psu-cat-faux-thumb{height: '.$img_h_mobile.'px;}
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