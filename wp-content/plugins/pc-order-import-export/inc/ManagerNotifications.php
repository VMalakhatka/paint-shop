<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Admin notifications only: keep the authenticated shop sender. */
final class ManagerNotifications {
    public static function hooks(): void {
        add_filter('woocommerce_email_subject_new_order', [self::class, 'subject'], 20, 2);
        add_filter('woocommerce_email_headers', [self::class, 'headers'], 20, 3);
        add_action('woocommerce_email_before_order_table', [self::class, 'contact'], 20, 4);
        add_action('woocommerce_email_before_order_table', [self::class, 'folio'], 21, 4);
    }
    public static function address($order): string {
        $email = $order instanceof \WC_Order ? (string) $order->get_billing_email() : '';
        return !preg_match('/[\r\n]/', $email) && is_email($email) ? $email : '';
    }
    public static function subject($subject, $order): string {
        $email = self::address($order);
        return $email ? $email . ' | ' . $subject : $subject;
    }
    /** Saved document dates only; created-at timestamps are not accounting dates. */
    public static function documents($order): array {
        if (!$order instanceof \WC_Order) return [];
        $result = $order->get_meta('_folio_documents_result');
        if (is_string($result)) $result = json_decode($result, true);
        $source = is_array($result) && empty($result['preview_only']) ? ($result['documents'] ?? []) : [];
        $source = is_array($source) ? $source : [];
        $direct = (string) $order->get_meta('_folio_document_id');
        // Older split children retain their document in the parent's saved response.
        $parent_id = (int) ($order->get_meta('_folio_split_from_order_id') ?: $order->get_parent_id());
        if ($direct && $parent_id && ($parent = wc_get_order($parent_id))) {
            $parent_result = $parent->get_meta('_folio_documents_result');
            if (is_string($parent_result)) $parent_result = json_decode($parent_result, true);
            foreach ((is_array($parent_result) && empty($parent_result['preview_only']) ? ($parent_result['documents'] ?? []) : []) as $doc) {
                if (is_array($doc) && (string) ($doc['document_id'] ?? $doc['documentId'] ?? '') === $direct) $source[] = $doc;
            }
        }
        $rows = [];
        if ($direct) $source[] = ['document_id' => $direct, 'document_number' => $order->get_meta('_folio_document_number'), 'document_date' => $order->get_meta('_folio_document_date')];
        foreach ($source as $doc) {
            if (!is_array($doc)) continue;
            $id = (string) ($doc['document_id'] ?? $doc['documentId'] ?? '');
            $number = sanitize_text_field((string) ($doc['document_number'] ?? $doc['documentNumber'] ?? ''));
            if (!$id || !$number) continue;
            $raw = $doc['document_date'] ?? $doc['documentDate'] ?? '';
            if (is_array($raw) && count($raw) >= 3) $raw = sprintf('%04d-%02d-%02d', $raw[0], $raw[1], $raw[2]);
            $date = '';
            if (is_string($raw) && preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:$|[T -])/', $raw, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) $date = sprintf('%02d.%02d.%04d', $m[3], $m[2], $m[1]);
            if (!isset($rows[$id]) || (!$rows[$id]['date'] && $date)) $rows[$id] = ['number' => $number, 'date' => $date];
        }
        return array_values($rows);
    }
    public static function folio($order, $admin, $plain, $email): void {
        if (!$admin || !($order instanceof \WC_Order) || !is_object($email) || $email->id !== 'new_order') return;
        $lines = [__('Folio documents', 'pc-order-import-export')];
        foreach (self::documents($order) as $doc) $lines[] = '#' . $doc['number'] . ' — ' . ($doc['date'] ?: __('Document date unavailable', 'pc-order-import-export'));
        if (count($lines) === 1) $lines[] = __('No Folio documents are linked yet. Check the order card after processing.', 'pc-order-import-export');
        $label = __('Full order details, delivery and notes', 'pc-order-import-export');
        if ($plain) echo "\n" . implode("\n", $lines) . "\n" . $label . ': ' . $order->get_edit_order_url() . "\n\n";
        else echo '<p>' . implode('<br>', array_map('esc_html', $lines)) . '</p><p><a href="' . esc_url($order->get_edit_order_url()) . '">' . esc_html($label) . '</a></p>';
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
