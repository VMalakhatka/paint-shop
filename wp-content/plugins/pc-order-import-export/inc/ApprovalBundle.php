<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** One acknowledgement of explicitly selected Folio accounts; never merges accounting records. */
final class ApprovalBundle {
    public static function ids($value): array {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*){0,19}$/D', $value)) {
            throw new \RuntimeException(__('Select between 1 and 20 Folio accounts for one customer.', 'pc-order-import-export'));
        }
        $ids = array_map('intval', explode(',', $value));
        if (count(array_unique($ids)) !== count($ids) || in_array(0, $ids, true)) throw new \RuntimeException(__('Duplicate or invalid accounts selected.', 'pc-order-import-export'));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public static function source(array $input): array {
        return ['customer_id' => absint($input['customer_id'] ?? 0), 'order_id' => 0, 'type' => 'ACCOUNT', 'document_id' => 0,
            'document_ids' => implode(',', self::ids($input['document_ids'] ?? '')),
            'include_payment' => ($input['include_payment'] ?? '') === '1' ? '1' : '0'];
    }

    public static function snapshot(array $source): array {
        $documents = [];
        foreach (self::ids($source['document_ids']) as $id) $documents[] = CustomerApproval::folio($source, $id, true);
        $items = []; $total = 0;
        foreach ($documents as $doc) {
            if ($doc['currency'] !== 'UAH' || !is_finite((float)$doc['total'])) throw new \RuntimeException('Invalid account currency or amount');
            $total += (int)round((float)$doc['total'] * 100);
            foreach ($doc['items'] as $item) {
                foreach (['quantity', 'price', 'amount'] as $key) if (!is_finite((float)$item[$key])) throw new \RuntimeException('Invalid account amount');
                $item['document'] = $doc['number'].' · '.$doc['date'];
                $item['warehouse'] = $doc['warehouse'];
                $items[] = $item;
            }
        }
        $snapshot = ['bundle' => true, 'title' => __('Combined account confirmation', 'pc-order-import-export'),
            'items' => $items, 'total' => number_format($total / 100, 2, '.', ''), 'currency' => 'UAH', 'documents' => $documents];
        if (($source['include_payment'] ?? '0') === '1') {
            if (!function_exists('pc_folio_invoice_settings') || !function_exists('pc_folio_invoice_validate_settings')) throw new \RuntimeException(__('Payment details are unavailable.', 'pc-order-import-export'));
            $settings = pc_folio_invoice_settings(); $valid = pc_folio_invoice_validate_settings($settings);
            if (is_wp_error($valid)) throw new \RuntimeException($valid->get_error_message());
            $snapshot['payment_details'] = array_intersect_key($settings, array_flip(['name', 'tax_id', 'iban', 'bank', 'mfo']));
        }
        return $snapshot;
    }

    public static function payment_labels(): array {
        return ['name' => __('Payment recipient', 'pc-order-import-export'), 'tax_id' => __('Tax ID', 'pc-order-import-export'),
            'iban' => __('IBAN', 'pc-order-import-export'), 'bank' => __('Bank', 'pc-order-import-export'), 'mfo' => __('Bank code', 'pc-order-import-export')];
    }

    public static function payment_panel(array $snapshot): void {
        echo '<p>'.esc_html__('Selected account totals are combined once. Original Folio accounts and Woo orders remain separate. This is not a calculation of unpaid debt.', 'pc-order-import-export').'</p>';
        if (empty($snapshot['payment_details'])) return;
        if (!self::payment_is_current($snapshot['payment_details'])) {
            echo '<p role="alert" class="woocommerce-info pcoe-payment-details-unavailable">'.esc_html__('Payment details in this confirmation are no longer verified. Ask your manager for an updated confirmation before paying. Do not use details from an earlier file or email.', 'pc-order-import-export').'</p>';
            return;
        }
        echo '<h3>'.esc_html__('Payment details', 'pc-order-import-export').'</h3><dl>';
        foreach (self::payment_labels() as $key => $label) if (!empty($snapshot['payment_details'][$key])) echo '<dt>'.esc_html($label).'</dt><dd>'.esc_html($snapshot['payment_details'][$key]).'</dd>';
        echo '</dl><p>'.esc_html__('Before payment, agree the amount with your manager, including any payments already made.', 'pc-order-import-export').'</p>';
    }

    /** Keep the historical snapshot, but never present an obsolete recipient for payment. */
    private static function payment_is_current($saved): bool {
        if (!is_array($saved) || !function_exists('pc_folio_payment_details')) return false;
        try { $current = pc_folio_payment_details(); }
        catch (\Throwable $e) { return false; }
        if (!$current) return false;
        foreach (array_keys(self::payment_labels()) as $key) {
            if (!is_scalar($saved[$key] ?? '') || !is_scalar($current[$key] ?? '')) return false;
            $before = trim((string) ($saved[$key] ?? ''));
            $now = trim((string) ($current[$key] ?? ''));
            if ($key === 'iban') {
                $before = strtoupper(preg_replace('/\s+/', '', $before));
                $now = strtoupper(preg_replace('/\s+/', '', $now));
            }
            if ($before !== $now) return false;
        }
        return true;
    }

    public static function options(array $source): void {
        echo '<form method="get" action="'.esc_url(admin_url('admin.php')).'">';
        foreach (['page' => CustomerApproval::PAGE, 'customer_id' => $source['customer_id'], 'document_ids' => $source['document_ids']] as $key => $value) echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
        echo '<p><label><input type="checkbox" name="include_payment" value="1" '.checked($source['include_payment'], '1', false).'> '.esc_html__('Include configured payment details', 'pc-order-import-export').'</label> <button class="button">'.esc_html__('Update preview', 'pc-order-import-export').'</button></p></form>';
    }
}
