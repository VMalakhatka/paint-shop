<?php

namespace Paint\NovaPoshta\Admin;

use Paint\NovaPoshta\Domain\AllocationSnapshotBuilder;
use Paint\NovaPoshta\Domain\TtnNormalizer;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;
use WC_Order;

defined('ABSPATH') || exit;

final class OrderPanel
{
    public function __construct(
        private readonly AllocationSnapshotBuilder $allocation,
        private readonly ShipmentRepository $repository,
        private readonly TtnNormalizer $normalizer
    ) {
    }

    public function hooks(): void
    {
        add_action('add_meta_boxes', [$this, 'registerMetaBox']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id = $screen ? (string) $screen->id : '';
        if (!in_array($screen_id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }
        wp_enqueue_style('pnpm-admin', PNPM_URL . 'assets/admin.css', [], PNPM_VERSION);
    }

    public function registerMetaBox(): void
    {
        $screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        foreach (array_unique($screens) as $screen) {
            add_meta_box(
                'pnpm-order-shipments',
                __('Nova Poshta shipments', 'paint-nova-poshta-multishipping'),
                [$this, 'render'],
                $screen,
                'normal',
                'default'
            );
        }
    }

    public function render(object $object): void
    {
        $order = $object instanceof WC_Order ? $object : wc_get_order((int) ($object->ID ?? 0));
        if (!$order) {
            return;
        }

        $snapshot = $this->allocation->build($order);
        $stored = $this->repository->findByOrder($order->get_id());
        ?>
        <div class="pnpm-order-panel">
            <p><strong><?php esc_html_e('Safety mode:', 'paint-nova-poshta-multishipping'); ?></strong>
                <?php esc_html_e('diagnostics only; no real Nova Poshta shipment can be created.', 'paint-nova-poshta-multishipping'); ?>
            </p>

            <?php if ($snapshot['errors']) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html(implode(' ', $snapshot['errors'])); ?></p></div>
            <?php endif; ?>

            <h4><?php esc_html_e('Actual warehouse allocation', 'paint-nova-poshta-multishipping'); ?></h4>
            <?php if (!$snapshot['shipments']) : ?>
                <p><?php esc_html_e('No complete warehouse allocation was found.', 'paint-nova-poshta-multishipping'); ?></p>
            <?php else : ?>
                <?php foreach ($snapshot['shipments'] as $shipment) : ?>
                    <details>
                        <summary>
                            <?php echo esc_html(sprintf(
                                /* translators: 1: warehouse name, 2: quantity */
                                __('Shipment from %1$s: %2$s item units', 'paint-nova-poshta-multishipping'),
                                $shipment['location_name'],
                                wc_format_decimal($shipment['quantity'])
                            )); ?>
                        </summary>
                        <ul>
                            <?php foreach ($shipment['items'] as $item) : ?>
                                <li><?php echo esc_html(sprintf('%s × %s [%s]', $item['name'], wc_format_decimal($item['quantity']), $item['source'])); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>

            <h4><?php esc_html_e('Stored shipments', 'paint-nova-poshta-multishipping'); ?></h4>
            <?php if (!$stored) : ?>
                <p><?php esc_html_e('No shipment records have been created yet.', 'paint-nova-poshta-multishipping'); ?></p>
            <?php else : ?>
                <ul>
                    <?php foreach ($stored as $shipment) : ?>
                        <li><?php echo esc_html(sprintf('#%s · %s · %s', $shipment['id'], $shipment['source'], $shipment['status'])); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="description">
                <?php esc_html_e('External customer TTN submission, private labels and manager approval will be enabled after the private-file and tracking layers are completed.', 'paint-nova-poshta-multishipping'); ?>
            </p>
        </div>
        <?php
    }
}
