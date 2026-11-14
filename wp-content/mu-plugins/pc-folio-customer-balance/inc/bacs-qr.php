<?php

defined('ABSPATH') || exit;

/** NBU Resolution 97, annex 3, format 002. No payment or network operations. */
function pc_folio_bacs_qr_link(array $recipient, string $amount, string $purpose): string {
    if (is_wp_error(pc_folio_invoice_validate_settings($recipient))) return '';
    if (!preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{2}))?$/D', $amount, $parts)) return '';
    $minor = (int) $parts[1] * 100 + (int) ($parts[2] ?? 0);
    if ($minor <= 0) return '';
    $amount = $parts[1] . (($parts[2] ?? '00') === '00' ? '' : '.' . $parts[2]);
    // Reject unsupported characters and embedded field separators; never shorten a payee.
    foreach ([[$recipient['name'], 140], [$purpose, 420]] as [$value, $limit]) {
        $value = (string) $value;
        if ($value === '' || preg_match('//u', $value) !== 1 || mb_strlen($value, 'UTF-8') > $limit) return '';
        $encoded = @iconv('UTF-8', 'Windows-1251', $value);
        if ($encoded === false || preg_match('/[\x00-\x1F\x7F\x98\xA0]/', $encoded)) return '';
    }
    $fields = [
        'BCD', '002', '1', 'UCT', '', $recipient['name'],
        strtoupper(preg_replace('/\s+/', '', $recipient['iban'])),
        'UAH' . $amount, $recipient['tax_id'], '', '', $purpose, '',
    ];
    $data = rtrim(strtr(base64_encode(implode("\n", $fields)), '+/', '-_'), '=');
    if (strlen($data) > 475) return '';
    return 'https://qr.bank.gov.ua/' . $data;
}

function pc_folio_bacs_qr_order($order): array {
    // Parent orders alone collect payment. Do not offer the full total again on a
    // paid, refunded, completed, cancelled or partially refunded purchase.
    if (!$order instanceof WC_Order || $order->get_payment_method() !== 'bacs'
        || $order->get_currency() !== 'UAH' || $order->get_parent_id()
        || !$order->has_status(['pending', 'on-hold']) || $order->is_paid()
        || $order->get_date_paid() || (float) $order->get_total_refunded() !== 0.0) return [];
    $recipient = pc_folio_payment_details();
    if (!$recipient) return [];
    /* translators: %s: order number. Used as the bank transfer payment reference. */
    $purpose = sprintf(__('Payment for order #%s', 'pc-folio-customer-balance'), $order->get_order_number());
    $link = pc_folio_bacs_qr_link($recipient, (string) $order->get_total(), $purpose);
    return $link === '' ? [] : ['url' => $link, 'purpose' => $purpose];
}

function pc_folio_bacs_qr_assets(): void {
    $base = WPMU_PLUGIN_URL . '/pc-folio-customer-balance/assets/';
    wp_enqueue_style('pc-bacs-qr', $base . 'bacs-qr.css', [], '20261114');
    wp_enqueue_script('pc-bacs-qr-generator', $base . 'third-party/qrcodegen.js', [], '20261114', true);
    wp_enqueue_script('pc-bacs-qr', $base . 'bacs-qr.js', ['pc-bacs-qr-generator'], '20261114', true);
}
add_action('wp_enqueue_scripts', static function (): void {
    if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-received') || is_wc_endpoint_url('view-order'))) {
        pc_folio_bacs_qr_assets();
    }
});

function pc_folio_bacs_render_qr($order): void {
    $qr = pc_folio_bacs_qr_order($order);
    if (!$qr) return;
    pc_folio_bacs_qr_assets();
    echo '<section class="pc-bacs-qr">';
    echo '<h2>' . esc_html__('Pay by bank QR', 'pc-folio-customer-balance') . '</h2>';
    echo '<p>' . esc_html__('Scan with your banking app, or use your phone camera and tap the detected link. Check the recipient, amount and payment reference before confirming.', 'pc-folio-customer-balance') . '</p>';
    echo '<div class="pc-bacs-qr__layout"><div class="pc-bacs-qr__image" data-payment-qr="' . esc_attr($qr['url']) . '" role="img" aria-label="' . esc_attr__('Bank transfer QR code', 'pc-folio-customer-balance') . '">';
    echo '<p class="pc-bacs-qr__fallback">' . esc_html__('If the QR code is unavailable, use the bank link or the recipient details.', 'pc-folio-customer-balance') . '</p></div><div class="pc-bacs-qr__details">';
    echo '<p><strong>' . esc_html__('Amount', 'pc-folio-customer-balance') . ':</strong> ' . wp_kses_post($order->get_formatted_order_total()) . '</p>';
    echo '<p><strong>' . esc_html__('Payment reference', 'pc-folio-customer-balance') . ':</strong> <span>' . esc_html($qr['purpose']) . '</span></p>';
    echo '<p><a class="button pc-bacs-qr__open" rel="noreferrer" href="' . esc_url($qr['url']) . '">' . esc_html__('Open banking app', 'pc-folio-customer-balance') . '</a></p>';
    echo '<p>' . esc_html__('Opening an app depends on your bank and phone settings. If the link opens a browser or another bank, scan the QR inside your preferred banking app or enter the details manually.', 'pc-folio-customer-balance') . '</p>';
    echo '</div></div><p class="pc-bacs-qr__notice">' . esc_html__('A QR code does not confirm payment. Your order awaits confirmation of funds received. If you have already transferred the money, do not pay again; contact your manager.', 'pc-folio-customer-balance') . '</p></section>';
}

add_action('woocommerce_view_order', static function ($order_id): void {
    $order = wc_get_order($order_id);
    if (!$order || !get_current_user_id() || ((int) $order->get_customer_id() !== get_current_user_id() && !current_user_can('manage_woocommerce'))) return;
    if (!pc_folio_bacs_qr_order($order)) return;
    pc_folio_bacs_render_details();
    pc_folio_bacs_render_qr($order);
}, 25);

/** The email points to the order's live, key-protected page, not a stale QR. */
function pc_folio_bacs_qr_email_link($order, bool $plain_text): void {
    if (!pc_folio_bacs_qr_order($order)) return;
    $label = __('View bank QR and current payment details', 'pc-folio-customer-balance');
    $url = $order->get_checkout_order_received_url();
    echo $plain_text ? $label . ': ' . esc_url_raw($url) . "\n\n"
        : '<p><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>';
}
