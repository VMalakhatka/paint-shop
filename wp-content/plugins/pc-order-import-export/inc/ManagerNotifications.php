<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Admin notifications only: keep the authenticated shop sender. */
final class ManagerNotifications {
    public static function hooks(): void {
        add_filter('woocommerce_email_subject_new_order', [self::class, 'subject'], 20, 2);
        add_filter('woocommerce_email_headers', [self::class, 'headers'], 20, 3);
        add_action('woocommerce_email_before_order_table', [self::class, 'contact'], 20, 4);
    }
    public static function address($order): string {
        $email = $order instanceof \WC_Order ? (string) $order->get_billing_email() : '';
        return !preg_match('/[\r\n]/', $email) && is_email($email) ? $email : '';
    }
    public static function subject($subject, $order): string {
        $email = self::address($order);
        return $email ? $email . ' | ' . $subject : $subject;
    }
    public static function headers($headers, $id, $order) {
        $email = self::address($order);
        if ($id !== 'new_order' || !$email) return $headers;
        $headers = preg_replace('/^Reply-To:[^\r\n]*(?:\r?\n|$)/mi', '', $headers);
        return rtrim($headers, "\r\n") . "\r\nReply-To: " . $email . "\r\n";
    }
    public static function reply_url($order): string {
        $email = self::address($order);
        return $email ? 'mailto:' . $email . '?subject=' . rawurlencode(sprintf(__('Order #%s', 'pc-order-import-export'), $order->get_order_number())) : '';
    }
    public static function contact($order, $admin, $plain, $email): void {
        if (!$admin || !is_object($email) || $email->id !== 'new_order' || !self::address($order)) return;
        $label = __('Reply to customer', 'pc-order-import-export');
        if ($plain) echo "\n" . $label . ': ' . self::address($order) . "\n" . self::reply_url($order) . "\n\n";
        else echo '<p><a href="' . esc_url(self::reply_url($order)) . '">' . esc_html($label) . '</a> — ' . esc_html(self::address($order)) . '</p>';
    }
}
