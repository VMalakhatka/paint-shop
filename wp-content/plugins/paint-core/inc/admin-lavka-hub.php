<?php
if (!defined('ABSPATH')) exit;

const PAINT_CORE_LAVKA_ADMIN_SLUG = 'lavka-hub';

/**
 * Shared parent slug for Lavka administration pages.
 */
function paint_core_lavka_admin_parent_slug(): string {
    return PAINT_CORE_LAVKA_ADMIN_SLUG;
}

function paint_core_lavka_admin_capability(): string {
    return 'manage_woocommerce';
}

add_action('admin_menu', function (): void {
    add_menu_page(
        __('Lavka', 'paint-core'),
        __('Lavka', 'paint-core'),
        paint_core_lavka_admin_capability(),
        PAINT_CORE_LAVKA_ADMIN_SLUG,
        'paint_core_render_lavka_admin_hub',
        'dashicons-store',
        58
    );

    add_submenu_page(
        PAINT_CORE_LAVKA_ADMIN_SLUG,
        __('Lavka overview', 'paint-core'),
        __('Lavka overview', 'paint-core'),
        paint_core_lavka_admin_capability(),
        PAINT_CORE_LAVKA_ADMIN_SLUG,
        'paint_core_render_lavka_admin_hub'
    );
}, 5);

function paint_core_lavka_admin_links(): array {
    return [
        ['page' => 'lavka-warehouses', 'cap' => 'manage_lavka_sync', 'label' => __('Lavka settings', 'paint-core')],
        ['page' => 'lavka-sync', 'cap' => 'manage_lavka_sync', 'label' => __('Lavka stock sync', 'paint-core')],
        ['page' => 'lts-main', 'cap' => 'manage_lavka_sync', 'label' => __('Lavka full sync', 'paint-core')],
        ['page' => 'lps-main', 'cap' => 'manage_lavka_prices', 'label' => __('Lavka price sync', 'paint-core')],
        ['page' => 'lps-accounting-prices', 'cap' => 'manage_lavka_prices', 'label' => __('Lavka Folio accounting prices', 'paint-core')],
        ['page' => 'lps-analytics-scenarios', 'cap' => 'manage_lavka_prices', 'label' => __('Lavka analytics scenarios', 'paint-core')],
        ['page' => 'lps-product-analytics', 'cap' => 'manage_lavka_prices', 'label' => __('Lavka product analytics', 'paint-core')],
        ['page' => 'lavka-reports', 'cap' => 'manage_woocommerce', 'label' => __('Lavka reports', 'paint-core')],
        ['page' => 'lavka-ecosystem-events', 'cap' => 'manage_options', 'label' => __('Lavka events', 'paint-core')],
    ];
}

function paint_core_render_lavka_admin_hub(): void {
    if (!current_user_can(paint_core_lavka_admin_capability())) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'paint-core'));
    }

    $links = array_values(array_filter(
        paint_core_lavka_admin_links(),
        static fn(array $item): bool => current_user_can($item['cap'])
    ));
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Lavka', 'paint-core'); ?></h1>
        <p><?php echo esc_html__('Synchronization, Folio operations, analytics and service tools are collected in this section.', 'paint-core'); ?></p>
        <div class="card" style="max-width:960px">
            <h2><?php echo esc_html__('Main sections', 'paint-core'); ?></h2>
            <ul>
                <?php foreach ($links as $item): ?>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . $item['page'])); ?>"><?php echo esc_html($item['label']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
}

/**
 * Keep custom pages together and give their visible labels one consistent,
 * translated vocabulary without changing stable page slugs.
 */
add_action('admin_menu', function (): void {
    global $submenu;
    if (empty($submenu) || !is_array($submenu)) return;

    $labels = [
        'lavka-hub' => __('Lavka overview', 'paint-core'),
        'lavka-warehouses' => __('Lavka settings', 'paint-core'),
        'lavka-sync' => __('Lavka stock sync', 'paint-core'),
        'lavka-stock-report' => __('Lavka stock report', 'paint-core'),
        'lavka-logs' => __('Lavka stock sync logs', 'paint-core'),
        'lts-main' => __('Lavka full sync settings', 'paint-core'),
        'lts-run' => __('Lavka run full sync', 'paint-core'),
        'lts-logs' => __('Lavka full sync logs', 'paint-core'),
        'lts-media' => __('Lavka media sync', 'paint-core'),
        'lts-cron-summary' => __('Lavka sync schedule', 'paint-core'),
        'lps-main' => __('Lavka price sync settings', 'paint-core'),
        'lps-mapping' => __('Lavka price mapping', 'paint-core'),
        'lps-run' => __('Lavka run price sync', 'paint-core'),
        'lps-logs' => __('Lavka price sync logs', 'paint-core'),
        'lps-accounting-prices' => __('Lavka Folio accounting prices', 'paint-core'),
        'lps-analytics-scenarios' => __('Lavka analytics scenarios', 'paint-core'),
        'lps-product-analytics' => __('Lavka product analytics', 'paint-core'),
        'lavka-reports' => __('Lavka reports', 'paint-core'),
        'lavka-profit-report' => __('Lavka Folio profit', 'paint-core'),
        'lavka-ecosystem-events' => __('Lavka events', 'paint-core'),
        'role-price-import-lite' => __('Lavka role price import', 'paint-core'),
        'stock-import-csv-lite' => __('Lavka stock import', 'paint-core'),
        'pc-stock-sync-woo' => __('Lavka manual stock sync', 'paint-core'),
        'lavka-product-media-upload' => __('Lavka product image batches', 'paint-core'),
        'pnpm-settings' => __('Lavka Nova Poshta', 'paint-core'),
        'pc-checkbox-fiscalization' => __('Lavka Checkbox fiscalization', 'paint-core'),
        'pc-wayforpay-test-access' => __('Lavka WayForPay test access', 'paint-core'),
        'pc-folio-customer-debtors' => __('Lavka Folio debtors', 'paint-core'),
    ];
    $positions = array_flip(array_keys($labels));

    foreach ($submenu as $parent_slug => &$items) {
        if (!is_array($items)) continue;
        foreach ($items as &$item) {
            $slug = isset($item[2]) ? (string)$item[2] : '';
            if (isset($labels[$slug])) $item[0] = $labels[$slug];
        }
        unset($item);

        if ($parent_slug !== PAINT_CORE_LAVKA_ADMIN_SLUG) continue;
        usort($items, static function (array $a, array $b) use ($positions): int {
            $a_slug = isset($a[2]) ? (string)$a[2] : '';
            $b_slug = isset($b[2]) ? (string)$b[2] : '';
            $a_pos = $positions[$a_slug] ?? PHP_INT_MAX;
            $b_pos = $positions[$b_slug] ?? PHP_INT_MAX;
            return $a_pos <=> $b_pos;
        });
    }
    unset($items);
}, 999);
