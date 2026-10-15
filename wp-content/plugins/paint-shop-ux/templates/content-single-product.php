<?php
/**
 * Lavka product layout, preserving the Woo content-single-product 3.6.0 hook contract.
 * Gallery, product types, role prices, stock, notices and structured data stay with
 * their existing owners. Review this contract when upgrading WooCommerce.
 */
defined('ABSPATH') || exit;
global $product;

do_action('woocommerce_before_single_product');

if (post_password_required()) {
    echo get_the_password_form();
    return;
}
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class('psu-product', $product); ?> data-product-type="<?php echo esc_attr($product->get_type()); ?>">
    <div class="psu-product__hero">
        <div class="psu-product__gallery">
            <?php do_action('woocommerce_before_single_product_summary'); ?>
        </div>
        <div class="psu-product__purchase summary entry-summary">
            <?php do_action('woocommerce_single_product_summary'); ?>
        </div>
    </div>
    <div class="psu-product__details">
        <?php do_action('woocommerce_after_single_product_summary'); ?>
    </div>
</div>
<?php do_action('woocommerce_after_single_product'); ?>
