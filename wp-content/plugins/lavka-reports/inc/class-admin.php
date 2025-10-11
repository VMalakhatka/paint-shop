<?php
if (!defined('ABSPATH')) exit;

class Lavka_Reports_Admin {
    const PAGE_SLUG = 'lavka-reports';
    const OPT = 'lavka_reports_opts';

    public function __construct(){
        add_action('admin_menu', [$this,'menu']);
        add_action('admin_enqueue_scripts', [$this,'assets']);
        add_action('admin_init', [$this,'register_settings']);
    }

    public function menu(){
       add_menu_page(
            __('Звіти Lavka', 'lavka-reports'),
            __('Звіти Lavka', 'lavka-reports'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this,'render_page'],
            'dashicons-analytics',
            59
        );
    }

    public function register_settings(){
            register_setting(self::PAGE_SLUG, self::OPT, [
                'type'    => 'array',
                'default' => [],
                'sanitize_callback' => function($v){
                    // базовая защита
                    if (!is_array($v)) $v = [];

                    // гарантируем массивы
                    if (!isset($v['warehouses']) || !is_array($v['warehouses'])) $v['warehouses'] = [];
                    if (!isset($v['ops_exclude']) || !is_array($v['ops_exclude'])) $v['ops_exclude'] = [];

                    // --- ЧИНИМ "ОБРЕЗАННЫЕ" КЛЮЧИ ---
                    // warehouses[0  → переносим в warehouses[0]
                    foreach ($v as $k => $val) {
                        if (preg_match('/^warehouses\[(\d+)$/', (string)$k, $m)) {
                            $idx = (int)$m[1];
                            if (is_array($val)) $v['warehouses'][$idx] = $val;
                            unset($v[$k]);
                        }
                    }
                    // ops_exclude[0 → переносим в ops_exclude[0]
                    foreach ($v as $k => $val) {
                        if (preg_match('/^ops_exclude\[(\d+)$/', (string)$k, $m)) {
                            $idx = (int)$m[1];
                            if (is_array($val)) $v['ops_exclude'][$idx] = $val;
                            unset($v[$k]);
                        }
                    }

                    // фильтруем пустые элементы
                    $v['warehouses'] = array_values(array_filter($v['warehouses'], function($w){
                        return is_array($w) && ($w['id'] ?? '') !== '';
                    }));
                    $v['ops_exclude'] = array_values(array_filter($v['ops_exclude'], function($o){
                        return is_array($o) && ($o['code'] ?? '') !== '';
                    }));

                    return $v;
                }
            ]);
        }

    public function assets($hook){
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) return;
        wp_enqueue_style('lavr-reports', LAVR_URL.'reports.css', [], LAVR_VER);
        wp_enqueue_script('lavr-reports', LAVR_URL.'reports.js', ['jquery'], LAVR_VER, true);
        wp_localize_script('lavr-reports', 'LavkaReports', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lavka_reports_nonce'),
            'i18n'  => [
                'loadRef' => __('Load from MS SQL', 'lavka-reports'),
                'clear'   => __('Clear', 'lavka-reports'),
                'add'     => __('Add', 'lavka-reports'),
                'error'   => __('Error', 'lavka-reports'),
            ],
        ]);
    }

    public function render_page(){
        echo '<div class="wrap lavka-reports">';
        echo '<h1>'.esc_html__('Lavka report: No movement by warehouses', 'lavka-reports').'</h1>';
        echo '<p class="description">'.esc_html__('Step 1 — choose the analysis period, warehouses, and operation types to exclude. These settings will be used to build the report query.', 'lavka-reports').'</p>';
        echo '<div id="lavr-no-movement-settings">';
        do_action('lavr/no_movement/settings');
        echo '</div></div>';
    }
}