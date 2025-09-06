<?php
namespace PaintCore\PCOE;

class Plugin {
    public function init(){
        // Статус чернетки
        ImporterDraft::register_status();

        // UI (кнопки експорту, імпорту в кошик і в чернетку)
        Ui::hooks();

        // Експорт
        add_action('wp_ajax_pcoe_export', [Exporter::class,'handle']);
        add_action('wp_ajax_nopriv_pcoe_export', [Exporter::class,'handle']);

        // Імпорт у кошик
        add_action('wp_ajax_pcoe_import_cart', [ImporterCart::class,'handle']);
        add_action('wp_ajax_nopriv_pcoe_import_cart', [ImporterCart::class,'handle']);

        // Імпорт у чернетку
        add_action('wp_ajax_pcoe_import_order_draft', [ImporterDraft::class,'handle']);
        add_action('wp_ajax_nopriv_pcoe_import_order_draft', [ImporterDraft::class,'handle']);

        // Вимкнути емейли для pc-draft
        ImporterDraft::mute_emails_for_drafts();
    }
}