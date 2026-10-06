<?php
/** Local WordPress integration: temporary users, no mail or external HTTP. */
use PaintCore\PCOE\ManagerWorkspace as Workspace;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local paint test only.');
require_once ABSPATH . 'wp-admin/includes/user.php';
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
add_filter('pre_http_request', static fn() => new WP_Error('test_block', 'External HTTP blocked by test.'), PHP_INT_MAX);
$original = get_current_user_id(); $users = []; $checks = 0;
$check = static function ($ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$tag = 'pcoe-directory-' . strtolower(wp_generate_password(10, false));
$create = static function (string $role, string $type, string $city, bool $linked = true) use (&$users, $tag): int {
    $number = count($users);
    $id = wp_insert_user(['user_login' => $tag . '-' . $number, 'user_pass' => wp_generate_password(30),
        'user_email' => $tag . '-' . $number . '@example.invalid', 'display_name' => $tag . ' ' . str_pad((string) $number, 2, '0', STR_PAD_LEFT), 'role' => $role]);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $users[] = $id;
    // Customer registration assigns the default Internet-client link; replace it with the fixture.
    foreach (['_folio_partner_id', '_folio_partner_short_name', '_folio_partner_name', '_folio_partner_type'] as $key) delete_user_meta($id, $key);
    update_user_meta($id, 'billing_city', $city);
    if ($type !== '') update_user_meta($id, '_folio_partner_type', $type);
    if ($linked) {
        update_user_meta($id, '_folio_partner_short_name', 'TEST-' . $number);
        update_user_meta($id, '_folio_partner_name', 'Test organization ' . $number);
    }
    return $id;
};
$ids = static fn(array $result): array => array_map(static fn($user) => (int) $user->ID, $result['users']);
try {
    $manager = $create('shop_manager', 'H', 'Test Kyiv');
    $salons = [];
    for ($i = 0; $i < 27; $i++) $salons[] = $create($i === 0 ? 'partner' : ($i === 1 ? 'opt' : 'customer'), 'H', $i === 2 ? 'Test Odesa' : 'Test Kyiv');
    // EXISTS must not duplicate a customer with duplicate metadata rows.
    add_user_meta($salons[0], '_folio_partner_type', 'H');
    $partner = $create('partner', 'П', 'Test Kyiv');
    $dealer = $create('opt', 'Д', 'Test Odesa');
    $buyer = $create('customer', 'К', 'Test Kyiv');
    $unlinked = $create('customer', 'H', 'Test Kyiv', false);
    $cyrillic = $create('customer', 'Н', 'Test Kyiv');
    wp_set_current_user($manager);
    $all = Workspace::directory($tag, '', '', 1, 100);
    $check($all['total'] === 32 && !in_array($manager, $ids($all), true), 'Directory retains site customers and excludes staff');
    $filtered = Workspace::directory($tag, '', '', 1, 100, 'H');
    $check($filtered['total'] === 27 && count($filtered['users']) === 27, 'Art salons include all customer price roles without duplicate rows: ' . wp_json_encode(['total' => $filtered['total'], 'rows' => count($filtered['users']), 'unexpected' => array_map(static fn($id) => [$id, get_user_meta($id, '_folio_partner_type', true), get_user_meta($id, '_folio_partner_short_name', true)], array_values(array_diff($ids($filtered), $salons)))]));
    $check(!in_array($unlinked, $ids($filtered), true) && !in_array($cyrillic, $ids($filtered), true), 'Art salon filter requires a link and Latin H');
    $check($ids(Workspace::directory($tag, 'partner', '', 1, 100, 'H')) === [$salons[0]], 'Role and Folio type are independent intersecting filters');
    $check($ids(Workspace::directory($tag, '', 'Test Odesa', 1, 100, 'H')) === [$salons[2]], 'City combines with Folio type');
    $check(Workspace::directory($tag, 'partner', 'Test Odesa', 1, 100, 'H')['total'] === 0, 'All selected filters must match');
    foreach (['П' => $partner, 'Д' => $dealer, 'К' => $buyer] as $type => $id) $check($ids(Workspace::directory($tag, '', '', 1, 100, $type)) === [$id], 'Cyrillic organization types remain valid');
    $check(Workspace::directory($tag, '', '', 1, 100, "H' OR 1=1 --")['total'] === $all['total'], 'Unknown filter input safely falls back to all types');
    $first = Workspace::directory($tag, '', '', 1, 25, 'H'); $second = Workspace::directory($tag, '', '', 2, 25, 'H');
    $check($first['total'] === 27 && $second['total'] === 27 && count($first['users']) === 25 && count($second['users']) === 2, 'Pagination counts the filtered customers');
    $check(!array_intersect($ids($first), $ids($second)), 'Pagination has no duplicate customers');
    $check(Workspace::directory($tag, 'partner', '', 1, 100)['total'] === 2, 'Existing calls without Folio type keep their behavior');
    $render = static function (array $filters) use ($tag): string {
        $_GET = array_merge(['page' => Workspace::PAGE, 'customer_search' => $tag], $filters);
        ob_start(); Workspace::render(); return ob_get_clean();
    };
    $html = $render(['customer_folio_type' => 'H']);
    $check((bool) preg_match('/<option value="H"\s+selected=[^>]+>/', $html), 'Selected type is rendered');
    $check(str_contains($html, 'customer_folio_type=H') && str_contains($html, 'customers_page=2'), 'Pagination keeps the selected organization type');
    $check(str_contains($html, 'name="customer_role"') && str_contains($html, 'name="customer_city"'), 'Existing filters remain available');
    $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html); $xpath = new DOMXPath($dom);
    $reset = $xpath->query('//form[contains(@class,"pcoe-directory-filters")]//a')->item(0);
    $check($reset && !str_contains($reset->getAttribute('href'), 'customer_folio_type'), 'Reset clears the new filter');
    if ($qa_dir = getenv('PCOE_DIRECTORY_QA_DIR')) {
        if (!is_dir($qa_dir)) mkdir($qa_dir, 0700, true);
        foreach (['all' => [], 'salons' => ['customer_folio_type' => 'H'], 'salons-page-2' => ['customer_folio_type' => 'H', 'customers_page' => 2],
            'salons-partner' => ['customer_folio_type' => 'H', 'customer_role' => 'partner'], 'salons-odesa' => ['customer_folio_type' => 'H', 'customer_city' => 'Test Odesa']] as $name => $filters) {
            $body = $render($filters);
            $body = preg_replace('/<form method="get"/', '<form action="/" method="get"', $body);
            file_put_contents($qa_dir . '/' . $name . '.html', '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customer directory QA</title><link rel="stylesheet" href="/admin.css"><link rel="stylesheet" href="/manager.css"><body class="wp-admin"><main>' . $body . '</main></body></html>');
        }
    }
    wp_set_current_user($salons[0]);
    try { Workspace::directory($tag, '', '', 1, 25, 'H'); throw new LogicException('Customer access was allowed'); }
    catch (RuntimeException $error) { $check($error->getMessage() === 'Forbidden', 'Directory capability gate remains enforced'); }
    echo "PASS: $checks customer directory checks\n";
} finally {
    wp_set_current_user($original);
    foreach ($users as $id) wp_delete_user($id);
}
