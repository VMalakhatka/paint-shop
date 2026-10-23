<?php
namespace PaintCore\PCOE;

class Plugin {
    public function init(){
        CustomerPermissions::hooks();
        FolioCustomerImport::hooks();
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('pcoe customers-import', static function ($args,$options) {
                if (empty($args[0])) \WP_CLI::error('Provide the prepared Customers XLSX path.');
                try { \WP_CLI::log(wp_json_encode(OpenCartCustomerImport::run($args[0],isset($options['apply']),isset($options['send'])),JSON_UNESCAPED_UNICODE)); }
                catch (\Throwable $e) { \WP_CLI::error($e->getMessage()); }
            });
        }
        CustomerInternalNotes::hooks();
        // Статус чернетки
        ImporterDraft::register_status();

        // UI (кнопки експорту, імпорту в кошик і в чернетку)
        Ui::init();
        CatalogDraft::hooks();
        Waitlist::hooks();
        add_action('wp_ajax_pcoe_price_list', [PriceList::class, 'handle']);

        // Експорт
        add_action('wp_ajax_pcoe_export', [Exporter::class,'handle']);
        add_action('wp_ajax_nopriv_pcoe_export', [Exporter::class,'handle']);

        // Імпорт у кошик
        add_action('wp_ajax_pcoe_import_cart', [ImporterCart::class,'handle']);
        add_action('wp_ajax_nopriv_pcoe_import_cart', [ImporterCart::class,'handle']);

        // Імпорт у чернетку
        add_action('wp_ajax_pcoe_import_order_draft', [ImporterDraft::class,'handle']);
        add_action('wp_ajax_nopriv_pcoe_import_order_draft', [ImporterDraft::class,'handle']);

        // З кошика → у чернетку
        add_action('wp_ajax_pcoe_cart_to_draft',        [CartToDraft::class, 'handle']);
        add_action('wp_ajax_nopriv_pcoe_cart_to_draft', [CartToDraft::class, 'handle']);

        // З чернетки → у кошик
        add_action('wp_ajax_pcoe_draft_to_cart',        [DraftToCart::class, 'handle']);
        add_action('wp_ajax_nopriv_pcoe_draft_to_cart', [DraftToCart::class, 'handle']);

        DraftFolioWorkflow::hooks();
        ManagerWorkspace::hooks();
        Conversations::hooks();
        TelegramBridge::hooks();
        ManagerNotifications::hooks();
        CustomerApproval::hooks();
        Broadcasts::hooks();

        // Вимкнути емейли для pc-draft
        ImporterDraft::mute_emails_for_drafts();
    }
}
