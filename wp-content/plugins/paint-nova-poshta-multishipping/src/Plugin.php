<?php

namespace Paint\NovaPoshta;

use Paint\NovaPoshta\Admin\OrderPanel;
use Paint\NovaPoshta\Admin\SettingsPage;
use Paint\NovaPoshta\Checkout\CheckoutIntegration;
use Paint\NovaPoshta\Domain\AllocationSnapshotBuilder;
use Paint\NovaPoshta\Domain\TtnNormalizer;
use Paint\NovaPoshta\Infrastructure\ApiClient;
use Paint\NovaPoshta\Infrastructure\SenderDirectory;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;
use Paint\NovaPoshta\Infrastructure\WarehouseDirectory;
use Paint\NovaPoshta\Shipping\NovaPoshtaShippingMethod;

defined('ABSPATH') || exit;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        add_filter('woocommerce_shipping_methods', [$this, 'shippingMethods']);
        add_action('admin_init', [$this, 'installShippingMethodInUkraineZones']);

        $repository = new ShipmentRepository();
        $api = new ApiClient();
        $settings = new SettingsPage(
            $api,
            new SenderDirectory($api),
            new WarehouseDirectory($api)
        );
        $settings->hooks();

        $order_panel = new OrderPanel(
            new AllocationSnapshotBuilder(),
            $repository,
            new TtnNormalizer()
        );
        $order_panel->hooks();

        CheckoutIntegration::create()->hooks();
    }

    /** @param array<string,class-string> $methods @return array<string,class-string> */
    public function shippingMethods(array $methods): array
    {
        $methods['pnpm_nova_poshta'] = NovaPoshtaShippingMethod::class;
        return $methods;
    }

    public function installShippingMethodInUkraineZones(): void
    {
        if (!current_user_can('manage_woocommerce') || get_option('pnpm_shipping_zone_migration_v1') === 'done') {
            return;
        }

        $installed = false;
        foreach (\WC_Shipping_Zones::get_zones() as $zone_data) {
            $is_ukraine = false;
            foreach ((array) ($zone_data['zone_locations'] ?? []) as $location) {
                if (($location->type ?? '') === 'country' && ($location->code ?? '') === 'UA') {
                    $is_ukraine = true;
                    break;
                }
            }
            if (!$is_ukraine) {
                continue;
            }

            $zone = new \WC_Shipping_Zone((int) $zone_data['zone_id']);
            $has_method = false;
            foreach ($zone->get_shipping_methods(false) as $method) {
                if ($method->id === 'pnpm_nova_poshta') {
                    $has_method = true;
                    break;
                }
            }
            if (!$has_method) {
                $zone->add_shipping_method('pnpm_nova_poshta');
            }
            $installed = true;
        }

        if ($installed) {
            update_option('pnpm_shipping_zone_migration_v1', 'done', false);
        }
    }
}
