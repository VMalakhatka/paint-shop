<?php
/**
 * Plugin Name: PC Cart Guard
 * Description: Автоматично обрізає/видаляє позиції кошика відповідно до доступу на поточному складі/режимі.
 * Author: PaintCore
 */

if (!defined('ABSPATH')) exit;

/**
 * Отримати максимально дозволену кількість у кошику для товару
 * відповідно до поточного складу/режиму.
 *
 * Повертає ціле число >= 0.
 */
function pc_cartguard_get_allowed_qty_for_cart(WC_Product $product, int $current_cart_qty): int {
    // 1) Наш пріоритетний спосіб — агрегований вигляд складів
    if (function_exists('pc_build_stock_view')) {
        $view   = pc_build_stock_view($product);            // очікується ['sum'=>...]
        $sum    = isset($view['sum']) ? (int) $view['sum'] : 0;
        $maxQty = max(0, $sum);
    }
    // 2) Якщо є API Stock Locations UI: воно повертає "скільки ще можна додати".
    elseif (function_exists('slu_available_for_add')) {
        $addable = (int) slu_available_for_add($product);   // "ще можна додати"
        // Щоб отримати "максимум у кошику", додаємо поточну кількість:
        $maxQty  = max(0, $current_cart_qty + $addable);
    }
    // 3) Фолбек — стандартний Woo stock для simple продуктів
    else {
        $stock  = (int) wc_stock_amount(get_post_meta($product->get_id(), '_stock', true));
        $maxQty = max(0, $stock);
    }

    // Обмеження продукту (якщо задано)
    $product_max = (int) $product->get_max_purchase_quantity();
    if ($product_max > 0) {
        $maxQty = min($maxQty, $product_max);
    }

    return max(0, (int) $maxQty);
}

/**
 * Основна перевірка і обрізання позицій кошика.
 * Працює завжди: при завантаженні із сесії та перед перерахунком тоталів.
 */
function pc_cartguard_enforce_limits() {
    if (!function_exists('WC')) return;
    $cart = WC()->cart;
    if (!$cart || empty($cart->get_cart())) return;

    $changed_any = false;

    foreach ($cart->get_cart() as $key => $item) {
        if (empty($item['product_id'])) continue;

        $product = wc_get_product($item['product_id']);
        if (!$product || !$product->is_purchasable()) continue;

        $current_qty = (int) $item['quantity'];
        $allowed_max = pc_cartguard_get_allowed_qty_for_cart($product, $current_qty);

        if ($allowed_max <= 0) {
            // Видаляємо позицію повністю
            $cart->remove_cart_item($key);
            wc_add_notice(
                sprintf(
                    /* translators: 1: product title */
                    __('%s було видалено з кошика, бо більше не може бути придбаним на обраному складі.', 'woocommerce'),
                    $product->get_name()
                ),
                'error'
            );
            $changed_any = true;
            continue;
        }

        if ($current_qty > $allowed_max) {
            // Обрізаємо до дозволеної межі
            $cart->set_quantity($key, $allowed_max, true);
            wc_add_notice(
                sprintf(
                    /* translators: 1: product title, 2: qty */
                    __('Кількість для «%1$s» зменшено до %2$d згідно доступу на складі.', 'woocommerce'),
                    $product->get_name(),
                    $allowed_max
                ),
                'notice'
            );
            $changed_any = true;
        }
    }

    // Якщо змінювали — перерахувати підсумки (на випадок ранніх хуків)
    if ($changed_any) {
        $cart->calculate_totals();
    }
}

// Коли кошик зчитано із сесії.
add_action('woocommerce_cart_loaded_from_session', 'pc_cartguard_enforce_limits', 20);
// Перед розрахунком тоталів (перехоплює зміни складу навіть без перезавантаження кошика).
add_action('woocommerce_before_calculate_totals', 'pc_cartguard_enforce_limits', 20);

/**
 * Додатково: якщо твій селектор складу ставить cookie/GET,
 * і ти хочеш запускати перевірку одразу після зміни складу,
 * можна примусово перерахувати кошик:
 *
 * (залишив як приклад; не обов'язково)
 */
// add_action('init', function() {
//     if (!is_admin() && !wp_doing_ajax() && WC()->cart) {
//         // Наприклад, якщо присутній $_GET['location'] — користувач тільки-но змінив склад
//         if (!empty($_GET['location'])) {
//             pc_cartguard_enforce_limits();
//         }
//     }
// }, 20);

/**
 * AJAX: скоригувати кількість товару у кошику на delta (може бути від'ємним).
 * Виклик: action=pc_cart_adjust, nonce=pc_cart_adj, product_id, qty (signed int)
 */
add_action('wp_ajax_pc_cart_adjust',    'pc_cart_adjust_qty');
add_action('wp_ajax_nopriv_pc_cart_adjust', 'pc_cart_adjust_qty');

function pc_cart_adjust_qty() {
    check_ajax_referer('pc_cart_adj', 'nonce');

    $pid   = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $delta = isset($_POST['qty'])        ? (int) $_POST['qty']        : 0;

    if (!$pid || $delta === 0 || !function_exists('WC')) {
        wp_send_json_error(['msg' => 'bad params']);
    }

    $cart = WC()->cart;
    if (!$cart) {
        wp_send_json_error(['msg' => 'no cart']);
    }

    $product = wc_get_product($pid);
    if (!$product || !$product->is_purchasable()) {
        wp_send_json_error(['msg' => 'no product']);
    }

    // шукаємо позицію у кошику
    $current_qty = 0;
    $item_key    = null;
    foreach ($cart->get_cart() as $key => $item) {
        if ((int) $item['product_id'] === $pid) {
            $current_qty = (int) $item['quantity'];
            $item_key    = $key;
            break;
        }
    }

    // максимум, дозволений на поточному складі/режимі
    $allowed_max = pc_cartguard_get_allowed_qty_for_cart($product, $current_qty);

    // нова цільова кількість (додаємо/віднімаємо дельту)
    $target = $current_qty + $delta;
    if ($target < 0)              $target = 0;
    if ($target > $allowed_max)   $target = $allowed_max;

    if ($target <= 0) {
        // видалити
        if ($item_key) $cart->remove_cart_item($item_key);
    } else {
        if ($item_key) $cart->set_quantity($item_key, $target, true);
        else           $cart->add_to_cart($pid, $target);
    }

    $cart->calculate_totals();

    wp_send_json_success([
        'new_qty'     => $target,
        'allowed_max' => $allowed_max,
    ]);
}