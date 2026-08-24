<?php

namespace Paint\NovaPoshta;

use Paint\NovaPoshta\Admin\OrderPanel;
use Paint\NovaPoshta\Admin\SettingsPage;
use Paint\NovaPoshta\Domain\AllocationSnapshotBuilder;
use Paint\NovaPoshta\Domain\TtnNormalizer;
use Paint\NovaPoshta\Infrastructure\ApiClient;
use Paint\NovaPoshta\Infrastructure\SenderDirectory;
use Paint\NovaPoshta\Infrastructure\ShipmentRepository;

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
        $repository = new ShipmentRepository();
        $api = new ApiClient();
        $settings = new SettingsPage($api, new SenderDirectory($api));
        $settings->hooks();

        $order_panel = new OrderPanel(
            new AllocationSnapshotBuilder(),
            $repository,
            new TtnNormalizer()
        );
        $order_panel->hooks();
    }
}
