<?php
namespace PaintCore\PCOE;

use WC;

defined('ABSPATH') || exit;

class ImporterCart
{
    /**
     * AJAX: імпорт у кошик з CSV/XLS(X)
     * action: pcoe_import_cart
     * nonce:  pcoe_import_cart
     */
    public static function handle()
    {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'pcoe_import_cart')) {
            wp_send_json_error(['msg' => 'Bad nonce'], 403);
        }
        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(['msg' => 'Cart not available'], 400);
        }
        if (empty($_FILES['file']['tmp_name'])) {
            wp_send_json_error(['msg' => 'Файл не передано'], 400);
        }

        // Прогреваем сессию/корзину, чтобы первый AJAX не терял позиции
        if ( function_exists('WC') && WC()->session ) {
            // устанавливаем cookie клиентской сессии (если еще нет)
            WC()->session->set_customer_session_cookie( true );
        }
        if ( function_exists('WC') && WC()->cart ) {
            // лениво загрузить содержимое (инициализирует внутренние структуры)
            WC()->cart->get_cart();
        }

        $tmp  = (string) $_FILES['file']['tmp_name'];
        $name = strtolower((string)($_FILES['file']['name'] ?? ''));

        [$rows, $err] = Helpers::read_rows($tmp, $name);
        if ($err)  wp_send_json_error(['msg' => $err], 400);
        if (!$rows) wp_send_json_error(['msg' => 'Порожній файл або невірний формат'], 400);

        [$map, $start] = Helpers::detect_colmap_and_start($rows);

        // adder для кошика: довіряємо Woo — нехай він сам вирішує min/max, доступність, тощо
        $adder = function(\WC_Product $product, float $qty, ?float $price, array &$errExtra): bool {
            $pid = (int)$product->get_id();
            $ok  = \WC()->cart->add_to_cart($pid, $qty);
            if (!$ok) { $errExtra['pid'] = $pid; }
            return (bool)$ok;
        };

        $res = Helpers::process_rows_with_adder($rows, $map, $start, [
            'allow_price' => false,            // ціну з файлу НЕ враховуємо в кошику
            'ok_label'    => 'Додано',         // текст у звіті
            'adder'       => $adder,
        ]);

        wp_send_json_success([
            'added'       => $res['ok'],
            'skipped'     => $res['skipped'],
            'report_html' => Helpers::render_report($res['report']),
        ]);
    }
}