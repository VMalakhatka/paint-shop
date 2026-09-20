<?php

namespace Paint\NovaPoshta\Email;

use Paint\NovaPoshta\Domain\ExternalShipmentPolicy;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;
use WC_Order;

defined('ABSPATH') || exit;

/** Enrich the original Woo template without copying or replacing it. No API calls or writes. */
final class DeliverySummary
{
    private array $buffers = [];

    public function hooks(): void
    {
        add_action('woocommerce_before_template_part', [$this, 'before'], 999, 4);
        add_action('woocommerce_after_template_part', [$this, 'after'], -999, 4);
    }

    public function before(string $name, string $path, string $located, array $args): void
    {
        if (!$this->supported($name) || !(($args['order'] ?? null) instanceof WC_Order)) { return; }
        $this->buffers[] = ['name' => $name, 'level' => ob_get_level()];
        ob_start();
    }

    public function after(string $name, string $path, string $located, array $args): void
    {
        if (!$this->supported($name) || !(($args['order'] ?? null) instanceof WC_Order)) { return; }
        $frame = end($this->buffers);
        if (!$frame || $frame['name'] !== $name || ob_get_level() !== $frame['level'] + 1) { return; }
        array_pop($this->buffers);
        $body = (string) ob_get_clean();
        $plain = str_contains($name, '/plain/');
        $summary = $this->render($args['order'], !empty($args['sent_to_admin']), $plain);
        if ($summary === '') { echo $body; return; }
        // Both legacy and improved Woo HTML templates place their order heading in h2.
        $pattern = $plain
            ? '~(^[^\r\n]*\#' . preg_quote((string) $args['order']->get_order_number(), '~') . '(?!\d)[^\r\n]*(?:\r?\n)?)~m'
            : '~(<h2\b[^>]*>.*?</h2>)~is';
        $body = preg_replace_callback($pattern, static fn($m) => $m[0] . $summary, $body, 1, $count);
        echo $count ? $body : $summary . $body;
    }

    private function supported(string $name): bool
    {
        return in_array($name, ['emails/email-order-details.php', 'emails/plain/email-order-details.php'], true);
    }

    public function render(WC_Order $order, bool $admin, bool $plain): string
    {
        $source = $order;
        $parent_id = (int) ($order->get_meta('_folio_parent_order_id') ?: $order->get_meta('_folio_split_from_order_id'));
        // Split creation can send mail before _folio_parent_order_id is set.
        if ($parent_id && !$order->get_items('shipping')) {
            $parent = wc_get_order($parent_id);
            if ($parent instanceof WC_Order && (int) $parent->get_customer_id() === (int) $order->get_customer_id()
                && ($order->get_customer_id() || $parent->get_billing_email() === $order->get_billing_email())) {
                $source = $parent;
            }
        }
        $methods = [];
        foreach ($source->get_items('shipping') as $shipping) {
            $methods[] = $shipping->get_method_id() === ExternalShipmentPolicy::RATE
                ? ExternalShipmentPolicy::label() : wp_strip_all_tags($shipping->get_method_title());
        }
        $shipments = array_values(array_filter((new ShipmentRepository())->findByOrder($source->get_id()),
            static fn(array $row): bool => $row['source'] === 'customer_external'));
        $pending = $source->get_meta('_pnpm_external_plan', true);
        $pending = is_array($pending) ? $pending : [];
        if (!$methods && ($shipments || $pending)) { $methods[] = ExternalShipmentPolicy::label(); }
        $methods = array_values(array_unique(array_filter($methods)));
        if (!$methods) { return ''; }
        $lines = [[__('Delivery', 'paint-nova-poshta-multishipping'), implode(', ', $methods)]];
        if ($source->get_id() !== $order->get_id()) {
            $lines[] = [__('Shipping details', 'paint-nova-poshta-multishipping'), sprintf(
                /* translators: %s: original order number. */
                __('From original order #%s (all warehouses).', 'paint-nova-poshta-multishipping'), $source->get_order_number())];
        }
        foreach ($shipments as $shipment) {
            $sender = json_decode((string) $shipment['sender_snapshot'], true);
            $label = __('Customer TTN', 'paint-nova-poshta-multishipping');
            if (!empty($sender['label'])) { $label .= ' — ' . $sender['label']; }
            $status = $shipment['status'] === 'approved'
                ? __('Approved by the warehouse', 'paint-nova-poshta-multishipping')
                : __('Submitted by customer; awaiting warehouse review', 'paint-nova-poshta-multishipping');
            $lines[] = [$label, $shipment['ttn_number'] . ' — ' . $status];
        }
        if (!$shipments && ($pending || in_array(ExternalShipmentPolicy::label(), $methods, true))) {
            // Never present a plan as a successfully saved shipment after a persistence failure.
            $lines[] = [__('Customer TTN', 'paint-nova-poshta-multishipping'),
                __('Shipment registration needs manager review.', 'paint-nova-poshta-multishipping')];
        }
        if ($shipments) {
            $lines[] = ['', __('Nova Poshta services are paid under the customer TTN. This does not confirm dispatch or payment for our goods.', 'paint-nova-poshta-multishipping')];
        }
        $link = $source->get_id() !== $order->get_id()
            ? ($admin ? $source->get_edit_order_url() : $source->get_view_order_url()) : '';
        if ($plain) {
            $text = "\n";
            foreach ($lines as [$label, $value]) { $text .= ($label ? wp_strip_all_tags($label) . ': ' : '') . wp_strip_all_tags($value) . "\n"; }
            if ($link) { $text .= __('Original order', 'paint-nova-poshta-multishipping') . ': ' . esc_url_raw($link) . "\n"; }
            return $text . "\n";
        }
        $html = '<div class="pnpm-email-delivery" style="margin:12px 0 24px;padding:14px 16px;border:1px solid #d7dce2;border-left:4px solid #e2231a;overflow-wrap:anywhere;">';
        foreach ($lines as [$label, $value]) {
            $html .= '<p style="margin:0 0 8px;">' . ($label ? '<strong>' . esc_html($label) . ':</strong> ' : '') . esc_html($value) . '</p>';
        }
        if ($link) { $html .= '<p style="margin:0;"><a href="' . esc_url($link) . '">' . esc_html__('Original order', 'paint-nova-poshta-multishipping') . '</a></p>'; }
        return $html . '</div>';
    }
}
