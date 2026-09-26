<?php
/** Render only. No checkout, order, mail or real directory request. Run via local wp eval-file. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') { throw new RuntimeException('Local CLI only.'); }
$dir = getenv('PNPM_POINT_FIXTURE_DIR');
if (!$dir || !is_dir($dir)) { throw new RuntimeException('Provide an existing private fixture directory.'); }
add_filter('pre_http_request', static fn() => new WP_Error('blocked', 'Fixture blocks HTTP'), PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
switch_to_locale('uk');
// In-memory wholesale identity, never persisted to the users table.
$old_user = $GLOBALS['current_user'];
$user = new WP_User(); $user->ID = PHP_INT_MAX; $user->roles = ['opt']; $GLOBALS['current_user'] = $user;
try {
    $config = [
        'ajaxUrl' => 'https://pnpm-test.invalid/ajax', 'nonce' => 'synthetic',
        'searching' => __('Searching Nova Poshta...', 'paint-nova-poshta-multishipping'),
        'nothingFound' => __('Nothing was found. Refine the search.', 'paint-nova-poshta-multishipping'),
        'requestFailed' => __('Nova Poshta directory could not be loaded.', 'paint-nova-poshta-multishipping'),
        'branchLabel' => __('Nova Poshta branch', 'paint-nova-poshta-multishipping'),
        'parcelLockerLabel' => __('Nova Poshta parcel locker', 'paint-nova-poshta-multishipping'),
        'point' => Paint\NovaPoshta\Checkout\PointCard::labels(),
    ];
    ob_start(); Paint\NovaPoshta\Checkout\CheckoutIntegration::create()->renderFields(null); $fields = ob_get_clean();
    $html = '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>НП — тестова картка точки</title><style>body{font:16px/1.5 system-ui;margin:0;background:#f0f2f4;color:#20252b}main{max-width:720px;margin:auto;padding:12px}*{box-sizing:border-box}input,select{width:100%;padding:12px;font:inherit}label{display:block}button{cursor:pointer;font:inherit}a{color:#9d201a}h1{font-size:24px}</style>'
        . '<style>' . file_get_contents(PNPM_DIR . 'assets/checkout.css') . '</style><main><h1>Доставка Новою поштою</h1><p>Тестові дані — замовлення не створюється</p>'
        . '<select name="shipping_method[0]"><option value="pnpm_nova_poshta:1">Нова пошта</option><option value="pnpm_customer_ttn">Своя ТТН</option></select>'
        . $fields . '</main><script>' . file_get_contents(ABSPATH . 'wp-includes/js/jquery/jquery.min.js') . '</script>'
        . '<script>var pnpmCheckout=' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
        . '<script>' . file_get_contents(PNPM_DIR . 'assets/checkout.js') . '</script></html>';
    file_put_contents($dir . '/point-card.html', $html);
    echo "Point card fixture rendered; no order or email.\n";
} finally { $GLOBALS['current_user'] = $old_user; restore_previous_locale(); }
