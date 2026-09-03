<?php
/**
 * Plugin Name: PC Wholesale Customer Help
 * Description: Role-gated customer guide, My Account endpoint and contextual help links for wholesale ordering.
 * Version: 1.2.0
 * Author: PaintCore
 * Text Domain: pc-wholesale-help
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

const PC_WHOLESALE_HELP_ENDPOINT = 'yak-zamovyty';
const PC_WHOLESALE_HELP_VERSION = '1.2.0';

/**
 * One allow-list shared by the quick-order page and the customer guide.
 * The filter is intentionally server-side; do not replace it with CSS-only hiding.
 */
function pc_wholesale_customer_roles(): array {
    $roles = apply_filters('pc_wholesale_customer_roles', ['partner', 'opt', 'opt_osn', 'schule']);
    $roles = array_map('sanitize_key', is_array($roles) ? $roles : []);
    return array_values(array_unique(array_filter($roles)));
}

function pc_wholesale_customer_can_access(?WP_User $user = null): bool {
    $user = $user ?: wp_get_current_user();
    if (!$user instanceof WP_User || $user->ID <= 0) {
        return false;
    }

    return (bool) array_intersect(pc_wholesale_customer_roles(), (array) $user->roles);
}

/** Local-only visual preview for an administrator; never adds customer links or menu items. */
function pc_wholesale_help_can_preview(): bool {
    return defined('WP_DEBUG')
        && WP_DEBUG
        && current_user_can('manage_woocommerce')
        && isset($_GET['pc_wholesale_help_preview']);
}

function pc_wholesale_help_register_endpoint(): void {
    add_rewrite_endpoint(PC_WHOLESALE_HELP_ENDPOINT, EP_ROOT | EP_PAGES);
}
add_action('init', 'pc_wholesale_help_register_endpoint');

function pc_wholesale_help_query_vars(array $query_vars): array {
    $query_vars[PC_WHOLESALE_HELP_ENDPOINT] = PC_WHOLESALE_HELP_ENDPOINT;
    return $query_vars;
}
add_filter('woocommerce_get_query_vars', 'pc_wholesale_help_query_vars');

function pc_wholesale_help_endpoint_title(string $title): string {
    return __('How to order', 'pc-wholesale-help');
}
add_filter('woocommerce_endpoint_' . PC_WHOLESALE_HELP_ENDPOINT . '_title', 'pc_wholesale_help_endpoint_title');

function pc_wholesale_help_flush_rewrite_once(): void {
    if ((string) get_option('pc_wholesale_help_rewrite_version', '') === PC_WHOLESALE_HELP_VERSION) {
        return;
    }

    pc_wholesale_help_register_endpoint();
    flush_rewrite_rules(false);
    update_option('pc_wholesale_help_rewrite_version', PC_WHOLESALE_HELP_VERSION, false);
}
add_action('wp_loaded', 'pc_wholesale_help_flush_rewrite_once');

function pc_wholesale_help_account_menu(array $items): array {
    if (!pc_wholesale_customer_can_access()) {
        return $items;
    }

    $result = [];
    $inserted = false;
    foreach ($items as $key => $label) {
        if ('customer-logout' === $key) {
            $result[PC_WHOLESALE_HELP_ENDPOINT] = __('How to order', 'pc-wholesale-help');
            $inserted = true;
        }
        $result[$key] = $label;
    }

    if (!$inserted) {
        $result[PC_WHOLESALE_HELP_ENDPOINT] = __('How to order', 'pc-wholesale-help');
    }
    return $result;
}
add_filter('woocommerce_account_menu_items', 'pc_wholesale_help_account_menu', 100);

function pc_wholesale_help_url(string $anchor = ''): string {
    $base = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url(PC_WHOLESALE_HELP_ENDPOINT)
        : home_url('/my-account/' . PC_WHOLESALE_HELP_ENDPOINT . '/');

    return $anchor !== '' ? $base . '#' . sanitize_html_class($anchor) : $base;
}

function pc_wholesale_help_quick_order_url(): string {
    $page = get_page_by_path('shvydke-zamovlennia');
    return $page instanceof WP_Post ? get_permalink($page) : home_url('/shvydke-zamovlennia/');
}

function pc_wholesale_help_asset_url(string $path): string {
    return content_url('/mu-plugins/pc-wholesale-help/assets/' . ltrim($path, '/'));
}

function pc_wholesale_help_protect_endpoint(): void {
    if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url(PC_WHOLESALE_HELP_ENDPOINT)) {
        return;
    }
    if (pc_wholesale_customer_can_access() || pc_wholesale_help_can_preview()) {
        return;
    }

    if (function_exists('wc_add_notice')) {
        wc_add_notice(__('This guide is available to wholesale customers after signing in.', 'pc-wholesale-help'), 'notice');
    }
    $target = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('orders')
        : home_url('/my-account/');
    wp_safe_redirect($target);
    exit;
}
add_action('template_redirect', 'pc_wholesale_help_protect_endpoint', 8);

/** Remove the quick-order menu entry when the shared role gate denies access. */
function pc_wholesale_help_filter_quick_order_menu(array $items): array {
    if (pc_wholesale_customer_can_access()) {
        return $items;
    }

    $quick_page = get_page_by_path('shvydke-zamovlennia');
    $quick_id = $quick_page instanceof WP_Post ? (int) $quick_page->ID : 0;
    foreach ($items as $index => $item) {
        $is_page = $quick_id > 0 && 'page' === $item->object && (int) $item->object_id === $quick_id;
        $is_url = false !== strpos((string) $item->url, '/shvydke-zamovlennia/');
        if ($is_page || $is_url) {
            unset($items[$index]);
        }
    }
    return $items;
}
add_filter('wp_nav_menu_objects', 'pc_wholesale_help_filter_quick_order_menu', 20);

function pc_wholesale_help_render_context_links(string $label, array $links): void {
    if (!pc_wholesale_customer_can_access()) {
        return;
    }
    ?>
    <aside class="pc-wholesale-help-link" aria-label="<?php esc_attr_e('Help for this page', 'pc-wholesale-help'); ?>">
        <span class="pc-wholesale-help-link__icon" aria-hidden="true">?</span>
        <span><?php echo esc_html($label); ?></span>
        <span class="pc-wholesale-help-link__links">
            <?php foreach ($links as $anchor => $link_label): ?>
                <a href="<?php echo esc_url(pc_wholesale_help_url((string) $anchor)); ?>">
                    <?php echo esc_html((string) $link_label); ?>
                </a>
            <?php endforeach; ?>
        </span>
    </aside>
    <?php
}

function pc_wholesale_help_render_context_link(string $anchor, string $label): void {
    pc_wholesale_help_render_context_links($label, [
        $anchor => __('Open instructions', 'pc-wholesale-help'),
    ]);
}

function pc_wholesale_help_catalog_link(): void {
    pc_wholesale_help_render_context_link('katalog', __('How to choose products in the catalogue', 'pc-wholesale-help'));
}
add_action('woocommerce_before_shop_loop', 'pc_wholesale_help_catalog_link', 4);
add_action('woocommerce_before_single_product', 'pc_wholesale_help_catalog_link', 4);

function pc_wholesale_help_cart_link(): void {
    pc_wholesale_help_render_context_links(__('Cart, import, export and drafts', 'pc-wholesale-help'), [
        'koshyk'    => __('Cart', 'pc-wholesale-help'),
        'import'    => __('Import', 'pc-wholesale-help'),
        'eksport'   => __('Export', 'pc-wholesale-help'),
        'chernetky' => __('Drafts', 'pc-wholesale-help'),
    ]);
}
add_action('woocommerce_before_cart', 'pc_wholesale_help_cart_link', 4);

function pc_wholesale_help_checkout_link(): void {
    pc_wholesale_help_render_context_links(__('Checkout and splitting an order by warehouses', 'pc-wholesale-help'), [
        'oformlennia' => __('Checkout', 'pc-wholesale-help'),
        'rozpodil'    => __('Warehouse split', 'pc-wholesale-help'),
    ]);
}
add_action('woocommerce_before_checkout_form', 'pc_wholesale_help_checkout_link', 4);

function pc_wholesale_help_orders_link(): void {
    pc_wholesale_help_render_context_links(__('Drafts and repeating previous orders', 'pc-wholesale-help'), [
        'chernetky'        => __('Save a draft', 'pc-wholesale-help'),
        'stari-zamovlennia'=> __('Process a draft', 'pc-wholesale-help'),
    ]);
}
add_action('woocommerce_before_account_orders', 'pc_wholesale_help_orders_link', 2);

function pc_wholesale_help_order_details_link(): void {
    pc_wholesale_help_render_context_link('stari-zamovlennia', __('Export or repeat this order', 'pc-wholesale-help'));
}
add_action('woocommerce_order_details_after_order_table', 'pc_wholesale_help_order_details_link', 3);

function pc_wholesale_help_balance_link(): void {
    pc_wholesale_help_render_context_link('balans', __('How to read the customer balance', 'pc-wholesale-help'));
}
add_action('woocommerce_account_folio-balance_endpoint', 'pc_wholesale_help_balance_link', 1);

function pc_wholesale_help_documents_link(): void {
    pc_wholesale_help_render_context_link('folio', __('How to view and repeat Folio documents', 'pc-wholesale-help'));
}
add_action('woocommerce_account_folio-documents_endpoint', 'pc_wholesale_help_documents_link', 1);

function pc_wholesale_help_enqueue_assets(): void {
    if (is_admin() || (!pc_wholesale_customer_can_access() && !pc_wholesale_help_can_preview())) {
        return;
    }

    $path = WPMU_PLUGIN_DIR . '/pc-wholesale-help/assets/wholesale-help.css';
    wp_enqueue_style(
        'pc-wholesale-help',
        pc_wholesale_help_asset_url('wholesale-help.css'),
        [],
        file_exists($path) ? (string) filemtime($path) : PC_WHOLESALE_HELP_VERSION
    );
}
add_action('wp_enqueue_scripts', 'pc_wholesale_help_enqueue_assets');

function pc_wholesale_help_step(string $number, string $title, string $text): void {
    ?>
    <li class="pc-help-step">
        <span class="pc-help-step__number"><?php echo esc_html($number); ?></span>
        <div><strong><?php echo esc_html($title); ?></strong><span><?php echo esc_html($text); ?></span></div>
    </li>
    <?php
}

function pc_wholesale_help_image(string $file, string $alt, string $caption): void {
    ?>
    <figure class="pc-help-figure">
        <a href="<?php echo esc_url(pc_wholesale_help_asset_url('images/' . $file)); ?>" target="_blank" rel="noopener">
            <img src="<?php echo esc_url(pc_wholesale_help_asset_url('images/' . $file)); ?>"
                alt="<?php echo esc_attr($alt); ?>" loading="lazy" decoding="async">
        </a>
        <figcaption><?php echo esc_html($caption); ?></figcaption>
    </figure>
    <?php
}

function pc_wholesale_help_render_endpoint(): void {
    if (!pc_wholesale_customer_can_access() && !pc_wholesale_help_can_preview()) {
        return;
    }

    $shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
    $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
    $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');
    $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
    $orders_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('orders') : $account_url;
    $balance_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('folio-balance') : $account_url;
    $documents_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('folio-documents') : $account_url;
    $folio_available = function_exists('pc_folio_balance_user_context') && (bool) pc_folio_balance_user_context();
    ?>
    <article class="pc-wholesale-help" id="pochatok">
        <header class="pc-help-hero">
            <span class="pc-help-eyebrow"><?php esc_html_e('Wholesale customer guide', 'pc-wholesale-help'); ?></span>
            <h1><?php esc_html_e('How to order on the KREUL website', 'pc-wholesale-help'); ?></h1>
            <p><?php esc_html_e('Choose products in the catalogue, as a list or from a file; check warehouses; save drafts; repeat previous purchases and view Folio documents.', 'pc-wholesale-help'); ?></p>
            <div class="pc-help-actions">
                <a class="button" href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Open catalogue', 'pc-wholesale-help'); ?></a>
                <a class="button" href="<?php echo esc_url(pc_wholesale_help_quick_order_url()); ?>"><?php esc_html_e('Order from a list', 'pc-wholesale-help'); ?></a>
                <a class="button" href="<?php echo esc_url($cart_url); ?>"><?php esc_html_e('Open cart', 'pc-wholesale-help'); ?></a>
                <a class="button" href="<?php echo esc_url($orders_url); ?>"><?php esc_html_e('My orders', 'pc-wholesale-help'); ?></a>
            </div>
        </header>

        <nav class="pc-help-toc" aria-label="<?php esc_attr_e('Guide contents', 'pc-wholesale-help'); ?>">
            <a href="#sklad"><span>1</span><?php esc_html_e('Warehouse mode', 'pc-wholesale-help'); ?></a>
            <a href="#katalog"><span>2</span><?php esc_html_e('Catalogue', 'pc-wholesale-help'); ?></a>
            <a href="#spyskom"><span>3</span><?php esc_html_e('Product list', 'pc-wholesale-help'); ?></a>
            <a href="#koshyk"><span>4</span><?php esc_html_e('Cart', 'pc-wholesale-help'); ?></a>
            <a href="#import"><span>5</span><?php esc_html_e('Import', 'pc-wholesale-help'); ?></a>
            <a href="#eksport"><span>6</span><?php esc_html_e('Export', 'pc-wholesale-help'); ?></a>
            <a href="#chernetky"><span>7</span><?php esc_html_e('Drafts', 'pc-wholesale-help'); ?></a>
            <a href="#stari-zamovlennia"><span>8</span><?php esc_html_e('Draft processing', 'pc-wholesale-help'); ?></a>
            <a href="#oformlennia"><span>9</span><?php esc_html_e('Checkout', 'pc-wholesale-help'); ?></a>
            <a href="#rozpodil"><span>10</span><?php esc_html_e('Warehouse split', 'pc-wholesale-help'); ?></a>
            <a href="#balans"><span>11</span><?php esc_html_e('Balance', 'pc-wholesale-help'); ?></a>
            <a href="#folio"><span>12</span><?php esc_html_e('Folio documents', 'pc-wholesale-help'); ?></a>
        </nav>

        <section class="pc-help-section" id="sklad">
            <div class="pc-help-section__heading"><span>01</span><div><h2><?php esc_html_e('Choose how products are written off from warehouses', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('Select the mode before adding products. If you change it later, review the entire cart again.', 'pc-wholesale-help'); ?></p></div></div>
            <div class="pc-help-grid pc-help-grid--three">
                <div class="pc-help-card pc-help-card--accent"><h3><?php esc_html_e('The system decides', 'pc-wholesale-help'); ?></h3><p><?php esc_html_e('Recommended: the site automatically distributes the required quantity among available warehouses.', 'pc-wholesale-help'); ?></p></div>
                <div class="pc-help-card"><h3><?php esc_html_e('Selected warehouse first', 'pc-wholesale-help'); ?></h3><p><?php esc_html_e('The site takes products from the selected warehouse first and covers any shortage from other warehouses.', 'pc-wholesale-help'); ?></p></div>
                <div class="pc-help-card"><h3><?php esc_html_e('Selected warehouse only', 'pc-wholesale-help'); ?></h3><p><?php esc_html_e('Only stock from one selected warehouse may be ordered. Other warehouses are not used.', 'pc-wholesale-help'); ?></p></div>
            </div>
            <div class="pc-help-note"><strong><?php esc_html_e('Look for “Allocation”.', 'pc-wholesale-help'); ?></strong> <?php esc_html_e('It shows the exact warehouse and quantity that will be used for each product.', 'pc-wholesale-help'); ?></div>
        </section>

        <section class="pc-help-section" id="katalog">
            <div class="pc-help-section__heading"><span>02</span><div><h2><?php esc_html_e('Order from catalogue cards', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('Best when you want to browse photos, categories and product variants.', 'pc-wholesale-help'); ?></p></div></div>
            <ol class="pc-help-steps">
                <?php pc_wholesale_help_step('1', __('Open the catalogue', 'pc-wholesale-help'), __('Choose a category or use search.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_step('2', __('Check the product', 'pc-wholesale-help'), __('Review the SKU, your price and stock by warehouse.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_step('3', __('Enter the quantity', 'pc-wholesale-help'), __('Use the minus, plus or quantity field, then press the cart button.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_step('4', __('Review the cart', 'pc-wholesale-help'), __('Check every item and its warehouse allocation before checkout.', 'pc-wholesale-help')); ?>
            </ol>
            <a class="pc-help-inline-action" href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('Go to catalogue', 'pc-wholesale-help'); ?> →</a>
            <?php pc_wholesale_help_image('catalog-product-cards.jpg', __('Catalogue cards with quantity and stock by warehouse', 'pc-wholesale-help'), __('Click the image to enlarge it.', 'pc-wholesale-help')); ?>
        </section>

        <section class="pc-help-section" id="spyskom">
            <div class="pc-help-section__heading"><span>03</span><div><h2><?php esc_html_e('Order products from a list', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('Best for a large order when you already know product names or SKUs.', 'pc-wholesale-help'); ?></p></div></div>
            <ol class="pc-help-steps">
                <?php pc_wholesale_help_step('1', __('Open “Product list”', 'pc-wholesale-help'), __('Sort by title, SKU or price; hide products with zero availability if needed.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_step('2', __('Enter quantities', 'pc-wholesale-help'), __('Fill in the quantity only for products you need.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_step('3', __('Add all', 'pc-wholesale-help'), __('Press “Add all to cart” before moving to another page of the list.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_step('4', __('Check the result', 'pc-wholesale-help'), __('Read the message with selected and added items, then open the cart.', 'pc-wholesale-help')); ?>
            </ol>
            <a class="pc-help-inline-action" href="<?php echo esc_url(pc_wholesale_help_quick_order_url()); ?>"><?php esc_html_e('Go to product list', 'pc-wholesale-help'); ?> →</a>
            <div class="pc-help-gallery">
                <?php pc_wholesale_help_image('quick-order-controls.jpg', __('Controls on the product list page', 'pc-wholesale-help'), __('Sorting, hiding zero stock and adding selected quantities.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_image('quick-order-table.jpg', __('Wholesale quick-order table', 'pc-wholesale-help'), __('Products, SKU, price, cart quantity, availability and new quantity.', 'pc-wholesale-help')); ?>
            </div>
        </section>

        <section class="pc-help-section" id="koshyk">
            <div class="pc-help-section__heading"><span>04</span><div><h2><?php esc_html_e('Check the cart', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('For every item, check the product, SKU, price, quantity, subtotal and the “Allocation” line.', 'pc-wholesale-help'); ?></p></div></div>
            <div class="pc-help-note pc-help-note--example"><strong><?php esc_html_e('Example:', 'pc-wholesale-help'); ?></strong> <?php esc_html_e('“Allocation: Kyiv — 2, Odesa — 3” means that five units will be collected from two warehouses.', 'pc-wholesale-help'); ?></div>
            <?php pc_wholesale_help_image('cart-warehouse-split.jpg', __('Cart with one item split between two warehouses', 'pc-wholesale-help'), __('A split is normal in automatic warehouse mode.', 'pc-wholesale-help')); ?>
        </section>

        <section class="pc-help-section" id="import">
            <div class="pc-help-section__heading"><span>05</span><div><h2><?php esc_html_e('Import your order from a file', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('Supported files: CSV, XLSX and XLS. The first row must contain column names.', 'pc-wholesale-help'); ?></p></div></div>
            <p><?php esc_html_e('Column names can be Ukrainian, Russian, or English, and columns can appear in any order.', 'pc-wholesale-help'); ?></p>
            <div class="pc-help-grid pc-help-grid--three pc-help-aliases">
                <div class="pc-help-card"><h3><?php esc_html_e('SKU / article', 'pc-wholesale-help'); ?></h3><p><code>sku</code>, <code>SKU</code>, <code>Артикул</code>, <code>Код</code>, <code>Код товару</code>, <code>article</code>, <code>mpn</code>, <code>model sku</code></p></div>
                <div class="pc-help-card"><h3><?php esc_html_e('GTIN / barcode', 'pc-wholesale-help'); ?></h3><p><code>gtin</code>, <code>GTIN</code>, <code>ean</code>, <code>ean13</code>, <code>upc</code>, <code>barcode</code>, <code>Штрих код</code>, <code>Штрих-код</code>, <code>Штрихкод</code></p></div>
                <div class="pc-help-card"><h3><?php esc_html_e('Quantity', 'pc-wholesale-help'); ?></h3><p><code>qty</code>, <code>q-ty</code>, <code>quantity</code>, <code>qnt</code>, <code>count</code>, <code>Кількість</code>, <code>К-сть</code>, <code>Количество</code>, <code>К-во</code>, <code>шт</code>, <code>pcs</code>, <code>pieces</code></p></div>
            </div>
            <div class="pc-help-note"><strong><?php esc_html_e('Search order:', 'pc-wholesale-help'); ?></strong> <?php esc_html_e('If both SKU and GTIN are present in a row, the site checks GTIN first. If no product is found, it then searches by SKU.', 'pc-wholesale-help'); ?></div>
            <div class="pc-help-sheet-example">
                <div class="pc-help-sheet-example__title"><strong><?php esc_html_e('Excel example', 'pc-wholesale-help'); ?></strong><span><?php esc_html_e('One row may contain only SKU, another only GTIN, and a row may contain both.', 'pc-wholesale-help'); ?></span></div>
                <div class="pc-help-sheet-example__scroll">
                    <table>
                        <thead><tr><th>sku</th><th>gtin</th><th>q-ty</th></tr></thead>
                        <tbody>
                            <tr><td>SKU-PRYKLAD-001</td><td></td><td>2</td></tr>
                            <tr><td></td><td>4820000000001</td><td>5</td></tr>
                            <tr><td>SKU-PRYKLAD-003</td><td>4820000000002</td><td>1</td></tr>
                        </tbody>
                    </table>
                </div>
                <p><?php
                    /* translators: %1$s is the GTIN column name; %2$s is the quantity column name. */
                    printf(esc_html__('The combination %1$s + %2$s is supported. The rows in the Excel file are examples; replace or delete them before import.', 'pc-wholesale-help'), '<code>gtin</code>', '<code>q-ty</code>');
                ?></p>
            </div>
            <div class="pc-help-downloads">
                <a href="<?php echo esc_url(pc_wholesale_help_asset_url('wholesale-order-template.csv')); ?>" download><?php esc_html_e('Download CSV template', 'pc-wholesale-help'); ?></a>
                <a href="<?php echo esc_url(pc_wholesale_help_asset_url('wholesale-order-template.xlsx')); ?>" download><?php esc_html_e('Download Excel example', 'pc-wholesale-help'); ?></a>
            </div>
            <div class="pc-help-grid pc-help-grid--two">
                <div class="pc-help-card"><h3><?php esc_html_e('Import to cart', 'pc-wholesale-help'); ?></h3><p><?php esc_html_e('Open the import block in the cart, choose a file and import it. New products are added to the products already in the cart.', 'pc-wholesale-help'); ?></p></div>
                <div class="pc-help-card"><h3><?php esc_html_e('Import to draft', 'pc-wholesale-help'); ?></h3><p><?php esc_html_e('Open “My orders”, choose a file and optionally enter a draft title. The current cart is not changed.', 'pc-wholesale-help'); ?></p></div>
            </div>
            <p><?php esc_html_e('Use SKU/article or GTIN/barcode for the product and a supported quantity column such as qty, q-ty or Кількість. Empty, zero and negative quantities are skipped. Always review the row-by-row import report.', 'pc-wholesale-help'); ?></p>
            <div class="pc-help-warning"><strong><?php esc_html_e('Current data wins.', 'pc-wholesale-help'); ?></strong> <?php esc_html_e('The cart uses your current WooCommerce price, current availability and current warehouse mode, not the price from the file.', 'pc-wholesale-help'); ?></div>
            <?php pc_wholesale_help_image('import-to-cart-and-draft.jpg', __('Cart controls for export, import and saving a draft', 'pc-wholesale-help'), __('CSV/XLSX export, import and draft controls are located below the cart totals.', 'pc-wholesale-help')); ?>
        </section>

        <section class="pc-help-section" id="eksport">
            <div class="pc-help-section__heading"><span>06</span><div><h2><?php esc_html_e('Export the cart or an order', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('Export is available in the cart and on the details page of each previous order.', 'pc-wholesale-help'); ?></p></div></div>
            <ol class="pc-help-steps">
                <?php pc_wholesale_help_step('1', __('Choose columns', 'pc-wholesale-help'), __('SKU, barcode, title, quantity, price, total and notes are available.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_step('2', __('Choose warehouse format', 'pc-wholesale-help'), __('Use one product row with allocation in notes, or separate rows for each warehouse.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_step('3', __('Download the file', 'pc-wholesale-help'), __('Choose CSV or XLSX. Export does not change or clear the cart.', 'pc-wholesale-help')); ?>
            </ol>
        </section>

        <section class="pc-help-section" id="chernetky">
            <div class="pc-help-section__heading"><span>07</span><div><h2><?php esc_html_e('Save the cart as a draft', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('Enter an optional title in the cart and press “Save as draft”. The draft will appear in “My orders”.', 'pc-wholesale-help'); ?></p></div></div>
            <div class="pc-help-warning"><strong><?php esc_html_e('Important:', 'pc-wholesale-help'); ?></strong> <?php esc_html_e('After a successful save, the current cart is cleared. A draft does not reserve stock.', 'pc-wholesale-help'); ?></div>
            <a class="pc-help-inline-action" href="<?php echo esc_url($orders_url); ?>"><?php esc_html_e('Open my orders', 'pc-wholesale-help'); ?> →</a>
        </section>

        <section class="pc-help-section" id="stari-zamovlennia">
            <div class="pc-help-section__heading"><span>08</span><div><h2><?php esc_html_e('How to process a draft', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('Open “My orders”, press “Process draft” next to the required draft, and choose one of two scenarios.', 'pc-wholesale-help'); ?></p></div></div>
            <div class="pc-help-note"><strong><?php esc_html_e('Preview first, apply second.', 'pc-wholesale-help'); ?></strong> <?php esc_html_e('The preview shows the result without changing the cart or recording a Folio document. The operation runs only after a separate confirmation (apply).', 'pc-wholesale-help'); ?></div>
            <div class="pc-help-grid pc-help-grid--two pc-help-draft-scenarios">
                <div class="pc-help-card pc-help-card--accent">
                    <h3><?php esc_html_e('Available goods to cart; remainder stays in the draft', 'pc-wholesale-help'); ?></h3>
                    <p><?php esc_html_e('Choose “Load available quantities into the cart”. The preview table shows the requested quantity, the quantity available for the cart, and the unavailable remainder.', 'pc-wholesale-help'); ?></p>
                    <ul>
                        <li><?php esc_html_e('After apply, the current cart is cleared and replaced with the currently available quantities at current prices.', 'pc-wholesale-help'); ?></li>
                        <li><?php esc_html_e('The unavailable quantity remains in the same draft.', 'pc-wholesale-help'); ?></li>
                        <li><?php esc_html_e('The unavailable remainder is recorded as one non-accounting Folio document.', 'pc-wholesale-help'); ?></li>
                    </ul>
                </div>
                <div class="pc-help-card">
                    <h3><?php esc_html_e('Entire draft to a non-accounting Folio document', 'pc-wholesale-help'); ?></h3>
                    <p><?php esc_html_e('Choose “Send the entire draft to a non-accounting Folio document”, for example for a prepaid order that is currently out of stock.', 'pc-wholesale-help'); ?></p>
                    <ul>
                        <li><?php esc_html_e('Review the preview and then confirm the separate apply action.', 'pc-wholesale-help'); ?></li>
                        <li><?php esc_html_e('The entire valid draft is recorded in one non-accounting Folio document.', 'pc-wholesale-help'); ?></li>
                        <li><?php esc_html_e('The current cart remains unchanged.', 'pc-wholesale-help'); ?></li>
                    </ul>
                </div>
            </div>
            <div class="pc-help-warning"><strong><?php esc_html_e('Protect the current cart.', 'pc-wholesale-help'); ?></strong> <?php esc_html_e('Only the first scenario clears and replaces the current cart. Save the cart as a separate draft or export it before apply if you need its current contents.', 'pc-wholesale-help'); ?></div>
            <div class="pc-help-note"><strong><?php esc_html_e('No warehouse movement.', 'pc-wholesale-help'); ?></strong> <?php
                /* translators: %s is the exact note stored in the Folio document. */
                printf(esc_html__('The non-accounting document is created on Folio warehouse 7 with the note %s. It does not write off, reserve, or otherwise move warehouse stock.', 'pc-wholesale-help'), '<code>нет на складе</code>');
            ?></div>
            <p><?php esc_html_e('If Folio returns an error or the result is unknown, the site keeps the draft and does not retry automatically. Review the message and deliberately repeat apply using the same preview. If draft lines or availability changed, run a new preview.', 'pc-wholesale-help'); ?></p>
            <h3><?php esc_html_e('Repeat a previous completed order', 'pc-wholesale-help'); ?></h3>
            <p><?php esc_html_e('Open the order details and use the available repeat action. Current prices, availability and warehouse allocation are recalculated. To add only selected products without clearing the cart, repeat them from a Folio document.', 'pc-wholesale-help'); ?></p>
        </section>

        <section class="pc-help-section" id="oformlennia">
            <div class="pc-help-section__heading"><span>09</span><div><h2><?php esc_html_e('Complete checkout', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('Check contact details, recipient, delivery, payment, products, quantities and totals before confirming.', 'pc-wholesale-help'); ?></p></div></div>
            <ol class="pc-help-steps">
                <?php pc_wholesale_help_step('1', __('Open checkout', 'pc-wholesale-help'), __('Go from the reviewed cart to checkout.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_step('2', __('Complete delivery', 'pc-wholesale-help'), __('For Nova Poshta, choose the displayed branch, parcel locker or address fields.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_step('3', __('Choose payment', 'pc-wholesale-help'), __('Select one of the methods available for your account.', 'pc-wholesale-help')); ?>
                <?php pc_wholesale_help_step('4', __('Confirm once', 'pc-wholesale-help'), __('Wait for Folio processing to finish; do not submit a duplicate order.', 'pc-wholesale-help')); ?>
            </ol>
            <a class="pc-help-inline-action" href="<?php echo esc_url($checkout_url); ?>"><?php esc_html_e('Go to checkout', 'pc-wholesale-help'); ?> →</a>
        </section>

        <section class="pc-help-section" id="rozpodil">
            <div class="pc-help-section__heading"><span>10</span><div><h2><?php esc_html_e('Why an order may be split', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('Folio creates warehouse documents separately. One WooCommerce cart may therefore become several linked orders, invoices or shipments.', 'pc-wholesale-help'); ?></p></div></div>
            <div class="pc-help-grid pc-help-grid--three">
                <div class="pc-help-card"><h3><?php esc_html_e('One warehouse', 'pc-wholesale-help'); ?></h3><p><?php esc_html_e('One order and one Folio document remain.', 'pc-wholesale-help'); ?></p></div>
                <div class="pc-help-card"><h3><?php esc_html_e('Several warehouses', 'pc-wholesale-help'); ?></h3><p><?php esc_html_e('Linked child orders appear for individual warehouses and may be shipped separately.', 'pc-wholesale-help'); ?></p></div>
                <div class="pc-help-card"><h3><?php esc_html_e('Stock shortage', 'pc-wholesale-help'); ?></h3><p><?php esc_html_e('The available part may be processed while the missing part waits for a manager review.', 'pc-wholesale-help'); ?></p></div>
            </div>
            <div class="pc-help-note"><strong><?php esc_html_e('Do not pay linked child orders separately', 'pc-wholesale-help'); ?></strong> <?php esc_html_e('unless a manager clearly asks you to do so.', 'pc-wholesale-help'); ?></div>
        </section>

        <section class="pc-help-section" id="balans">
            <div class="pc-help-section__heading"><span>11</span><div><h2><?php esc_html_e('View the customer balance', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('Choose a start date or all time, generate the report, then export XLSX or print it if needed.', 'pc-wholesale-help'); ?></p></div></div>
            <p><?php esc_html_e('The report may show opening balance, invoices, payments, total debt, overdue amount, prepayment and the amount due now. The table below explains each operation and the balance before and after it.', 'pc-wholesale-help'); ?></p>
            <?php if ($folio_available): ?>
                <a class="pc-help-inline-action" href="<?php echo esc_url($balance_url); ?>"><?php esc_html_e('Open customer balance', 'pc-wholesale-help'); ?> →</a>
            <?php endif; ?>
        </section>

        <section class="pc-help-section" id="folio">
            <div class="pc-help-section__heading"><span>12</span><div><h2><?php esc_html_e('View and repeat Folio documents', 'pc-wholesale-help'); ?></h2><p><?php esc_html_e('Choose a period and document types, then open an invoice, expense invoice or payment for details.', 'pc-wholesale-help'); ?></p></div></div>
            <p><?php esc_html_e('From an invoice or expense invoice, select the products you need and add them to the current cart or save them as a new draft. The current cart is not cleared in this workflow.', 'pc-wholesale-help'); ?></p>
            <div class="pc-help-warning"><strong><?php esc_html_e('Historical prices are for reference only.', 'pc-wholesale-help'); ?></strong> <?php esc_html_e('The new cart always uses current prices, availability and allocation. Payments and returns cannot be repeated as an order.', 'pc-wholesale-help'); ?></div>
            <?php if ($folio_available): ?>
                <a class="pc-help-inline-action" href="<?php echo esc_url($documents_url); ?>"><?php esc_html_e('Open Folio documents', 'pc-wholesale-help'); ?> →</a>
            <?php endif; ?>
        </section>

        <footer class="pc-help-footer">
            <div><strong><?php esc_html_e('Need help?', 'pc-wholesale-help'); ?></strong><p><?php esc_html_e('Tell the wholesale manager your WooCommerce order number and what you expected to see. Never send your password or bank card details.', 'pc-wholesale-help'); ?></p></div>
            <a href="#pochatok"><?php esc_html_e('Back to top', 'pc-wholesale-help'); ?> ↑</a>
        </footer>
    </article>
    <?php
}
add_action('woocommerce_account_' . PC_WHOLESALE_HELP_ENDPOINT . '_endpoint', 'pc_wholesale_help_render_endpoint');
