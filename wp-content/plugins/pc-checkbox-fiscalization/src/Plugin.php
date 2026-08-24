<?php

namespace Paint\CheckboxFiscalization;

use Paint\CheckboxFiscalization\Admin\SettingsPage;
use Paint\CheckboxFiscalization\Domain\CommandValidator;
use Paint\CheckboxFiscalization\Domain\FiscalizationService;
use Paint\CheckboxFiscalization\Http\RestController;
use Paint\CheckboxFiscalization\Infrastructure\ApiClient;
use Paint\CheckboxFiscalization\Infrastructure\OperationRepository;
use Paint\CheckboxFiscalization\Integration\JavaCommandProvider;

defined('ABSPATH') || exit;

final class Plugin
{
    private static ?self $instance = null;
    private ?FiscalizationService $service = null;
    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        Activator::maybeMigrate();
        $config = new Config();
        $operations = new OperationRepository();
        $api = new ApiClient($config);
        $this->service = new FiscalizationService($config, new CommandValidator(), $operations, $api);
        $rest = new RestController($config, $this->service, new JavaCommandProvider($config));
        add_action('rest_api_init', [$rest, 'register']);

        if (is_admin()) {
            (new SettingsPage($config, $this->service, $operations))->register();
        }
    }

    public function service(): FiscalizationService
    {
        if (!$this->service) {
            $this->boot();
        }
        return $this->service;
    }
}
