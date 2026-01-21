<?php
/*
Plugin Name: Paint Shop UX
Description: UX improvements for WooCommerce catalog: compact titles, square thumbnails, per-page switcher, graceful fallbacks.
Author: Volodymyr
Version: 1.1.0
Text Domain: paint-shop-ux
Domain Path: /languages
*/
if (!defined('ABSPATH')) exit;

/** =======================
 *  i18n
 *  ======================= */
add_action('init', function () {
    load_plugin_textdomain('paint-shop-ux', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/** =======================
 *  Products per page (?pp=)
 *  ======================= */
add_filter('loop_shop_per_page', function ($per_page) {
    $pp = isset($_GET['pp']) ? (int)$_GET['pp'] : 0;
    if ($pp >= 6 && $pp <= 120) return $pp;

    $pp_filter = (int)apply_filters('psu_products_per_page', 0);
    if ($pp_filter >= 6 && $pp_filter <= 120) return $pp_filter;

    return $per_page;
}, 20);

add_action('pre_get_posts', function ($q) {
    if (is_admin() || !$q->is_main_query()) return;
    if (function_exists('is_shop') && (is_shop() || is_product_taxonomy())) {
        $pp = isset($_GET['pp']) ? (int)$_GET['pp'] : 0;
        if ($pp >= 6 && $pp <= 120) {
            $q->set('posts_per_page', $pp);
            $q->set('posts_per_archive_page', $pp);
        }
    }
}, 20);

/** =======================
 *  Per-page UI (12 / 24 / 48)
 *  ======================= */
add_action('woocommerce_before_shop_loop', function () {
    if (!function_exists('is_shop') || (!is_shop() && !is_product_taxonomy())) return;

    $cur = isset($_GET['pp']) ? (int)$_GET['pp'] : 0;
    if ($cur < 6 || $cur > 120) $cur = 0;

    $base = remove_query_arg('pp');
    $mk = fn($v) => esc_url(add_query_arg('pp', (int)$v, $base));

    echo '<div class="psu-per-page" role="group" aria-label="Products per page">';
    echo '<span class="psu-per-page__label">' . esc_html__('Show:', 'paint-shop-ux') . '</span>';
    foreach ([12, 24, 48] as $v) {
        echo '<a class="psu-per-page__btn' . ($cur === $v ? ' is-active' : '') . '" href="' . $mk($v) . '">' . $v . '</a>';
    }
    echo '</div>';
}, 15);

/** =======================
 *  Compact catalog title
 *  ======================= */
add_action('init', function () {
    remove_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10);
    add_action('woocommerce_shop_loop_item_title', 'psu_loop_title', 10);
});

function psu_loop_title() {
    if (is_product()) return;

    $raw = get_the_title();
    $product = wc_get_product(get_the_ID());
    $display = psu_get_compact_title($raw, $product ? $product->get_id() : 0);

    echo '<h2 class="woocommerce-loop-product__title compact-title" title="' . esc_attr($raw) . '">' . esc_html($display) . '</h2>';
}

function psu_get_compact_title(string $title, int $product_id = 0): string {
    $clean = trim(wp_strip_all_tags($title));

    $meta_key = '_psu_compact_title';
    if ($product_id) {
        $custom = trim((string)get_post_meta($product_id, $meta_key, true));
        if ($custom !== '') return $custom;
    }

    $p1 = strpos($clean, '|');
    if ($p1 !== false) {
        $p2 = strpos($clean, '|', $p1 + 1);
        if ($p2 !== false) {
            $mid = trim(substr($clean, $p1 + 1, $p2 - $p1 - 1));
            if ($mid !== '') return $mid;
        } else {
            $after = trim(substr($clean, $p1 + 1));
            if ($after !== '') return $after;
        }
    }

    return $clean;
}

/** Admin field */
add_action('woocommerce_product_options_general_product_data', function () {
    woocommerce_wp_text_input([
        'id' => '_psu_compact_title',
        'label' => __('Compact catalog title', 'paint-shop-ux'),
        'description' => __('Shown in product grid only.', 'paint-shop-ux'),
    ]);
});

add_action('woocommerce_admin_process_product_object', function (WC_Product $product) {
    if (isset($_POST['_psu_compact_title'])) {
        $product->update_meta_data('_psu_compact_title', sanitize_text_field($_POST['_psu_compact_title']));
    }
});

/** =======================
 *  Category thumbnail fallback
 *  ======================= */
add_action('init', function () {
    remove_action('woocommerce_before_subcategory_title', 'woocommerce_subcategory_thumbnail', 10);
    add_action('woocommerce_before_subcategory_title', 'psu_subcategory_thumbnail', 10);
});

function psu_subcategory_thumbnail( $category ) {
    $size = apply_filters('subcategory_archive_thumbnail_size', 'woocommerce_thumbnail');

    /** =========================
     *  1) Если у категории есть своя картинка — показываем её
     *  ========================= */
    $thumb_id = get_term_meta( $category->term_id, 'thumbnail_id', true );
    if ( $thumb_id ) {
        echo wp_get_attachment_image( $thumb_id, $size, false, ['loading' => 'lazy'] );
        return;
    }

    /** =========================
     *  2) Пробуем взять картинку любого товара
     *     в этой категории ИЛИ в дочерних
     *  ========================= */
    $q = new WC_Product_Query([
        'status'    => 'publish',
        'limit'     => 20,              // ⬅ важно: больше 1
        'orderby'   => 'date',
        'order'     => 'DESC',
        'tax_query' => [[
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => [$category->term_id],
            'include_children' => true,
        ]],
    ]);

    $products = $q->get_products();

    if (!empty($products)) {
        foreach ($products as $product) {
            if ($product instanceof WC_Product && $product->get_image_id()) {
                echo wp_get_attachment_image(
                    $product->get_image_id(),
                    $size,
                    false,
                    ['loading' => 'lazy']
                );
                return;
            }
        }
    }


    
    /** =========================
     *  3) Fallback — текстовый бокс
     *  ========================= */
    $name = $category->name ?? '';
    echo '<div class="psu-cat-faux-thumb" role="img" aria-label="' . esc_attr( $name ) . '">';
    echo '<span class="psu-cat-faux-title">' . esc_html( $name ) . '</span>';
    echo '</div>';
}

add_filter('woocommerce_product_subcategories_args', function ($args) {
    unset($args['menu_order']);   
    $args['order']   = 'ASC';
    return $args;
}, 20);

/** =======================
 *  Product thumbnail fallback
 *  ======================= */
add_action('init', function () {
    remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10);
    add_action('woocommerce_before_shop_loop_item_title', 'psu_loop_product_thumbnail', 10);
});

function psu_loop_product_thumbnail() {
    global $product;
    if (!$product) $product = wc_get_product(get_the_ID());

    if ($product && has_post_thumbnail($product->get_id())) {
        echo woocommerce_get_product_thumbnail();
        return;
    }

    $title = psu_get_compact_title(get_the_title(), $product ? $product->get_id() : 0);
    echo '<div class="psu-prod-faux-thumb" role="img"><span class="psu-prod-faux-title">' . esc_html($title) . '</span></div>';
}
/** =======================
 *  CSS + JS
 *  ======================= */
add_action('wp_enqueue_scripts', function () {

$css = <<<CSS
/* === Square thumbnails === */
.woocommerce ul.products li.product a img,
.woocommerce ul.products li.product-category a img{
  width:100%;
  aspect-ratio:1/1;
  height:auto;
  object-fit:contain;
  background:#fff;
  padding:6px;
  box-sizing:border-box;
}

/* Category fallback */
.psu-cat-faux-thumb{
  width:100%;
  aspect-ratio:1/1;
  display:flex;
  align-items:center;
  justify-content:center;
  text-align:center;
  padding:10px;
  border:1px solid #eee;
  border-radius:6px;
  background:linear-gradient(135deg,#eae4dd,#e8e5e2);
  color:#8a4b2a;
  font-weight:600;
}

/* === Limit oversized category fallback when it's the only item === */
@media (min-width: 768px) {
  .woocommerce ul.products li.product-category:only-child .psu-cat-faux-thumb {
    max-width: 360px;          /* ⬅ оптимально, можно 360–480 */
    margin-left: auto;
    margin-right: auto;
  }
}

/* Product fallback */
.psu-prod-faux-thumb{
  width:100%;
  aspect-ratio:1/1;
  display:flex;
  align-items:center;
  justify-content:center;
  text-align:center;
  padding:10px;
  border:1px solid #eee;
  border-radius:6px;
  background:rgba(138,75,42,.04);
  color:#8a4b2a;
  font-weight:600;
}

.psu-prod-faux-title,
.psu-cat-faux-title{
  display:-webkit-box;
  -webkit-box-orient:vertical;
  -webkit-line-clamp:3;
  overflow:hidden;
}

/* Compact title */
.woocommerce-loop-product__title.compact-title{
  --lines:3;
  display:-webkit-box;
  -webkit-line-clamp:var(--lines);
  -webkit-box-orient:vertical;
  overflow:hidden;
  min-height:calc(1.3em * var(--lines));
}

/* Per-page UI */
.psu-per-page{display:flex;gap:.35rem;margin:.5rem 0 1rem}
.psu-per-page__btn{padding:.25rem .5rem;border:1px solid #ddd;border-radius:4px;text-decoration:none}
.psu-per-page__btn.is-active{background:#f2f2f2}

/* Category & subcategory title color */
.woocommerce ul.products li.product-category h2,
.woocommerce ul.products li.product-category h2 a {
  color: #8a4b2a;
  font-weight: 600;
  text-decoration: none;
}

.woocommerce ul.products li.product-category h2 a:hover {
  color: #6f3c22;
}
CSS;

    wp_register_style('psu-inline', false);
    wp_enqueue_style('psu-inline');
    wp_add_inline_style('psu-inline', $css);

    // Grid nudge (safety)
    wp_register_script('psu-nudge', '', [], false, true);
    wp_enqueue_script('psu-nudge');
    wp_add_inline_script('psu-nudge',
        "window.addEventListener('load',()=>{setTimeout(()=>window.dispatchEvent(new Event('resize')),120);});"
    );
});