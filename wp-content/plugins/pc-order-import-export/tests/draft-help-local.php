<?php
/** Local-only help assertions and inert HTML captures; never calls Folio. */
use PaintCore\PCOE\CatalogDraft;
use PaintCore\PCOE\DraftHelp;
use PaintCore\PCOE\DraftFolioWorkflow;

if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
add_filter('pre_http_request', static fn() => new WP_Error('blocked', 'No external HTTP in guide capture'), PHP_INT_MAX);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
$fixture = json_decode(file_get_contents(getenv('PCOE_CATALOG_FIXTURE')), true);
$user = get_user_by('id', $fixture['user']);
if (!$user || strpos($user->user_login, 'catalog-draft-test-') !== 0) throw new RuntimeException('Synthetic fixture required');
$user->set_role('customer');
$capture = static function (callable $render): string { ob_start(); $render(); return ob_get_clean(); };
$check = static function ($ok, $label): void { if (!$ok) throw new RuntimeException($label); };
wp_set_current_user(0);
$check($capture(fn() => DraftHelp::render()) === '', 'Guest has no draft help');
wp_set_current_user($user->ID);
$html = $capture(fn() => DraftHelp::render('processing'));
$check(substr_count($html, 'pcoe-draft-help__topic') === 5, 'Retail customer gets five topics');
$check(strpos($html, 'folio.png') < strpos($html, 'collect.png'), 'Processing context starts with Folio');
$check(strpos($html, '<form') === false && strpos($html, '<script') === false, 'Help is read-only');
$check(!pc_wholesale_customer_can_access(), 'Retail help does not grant wholesale permissions');
wp_get_current_user()->add_role('opt');
try {
    $check($capture(fn() => DraftHelp::render()) !== '', 'Wholesale gets draft help');
    $guide = $capture(fn() => pc_wholesale_help_render_endpoint());
    $check(strpos($guide, 'pcoe-draft-help__topic') !== false, 'Published wholesale guide embeds same help');
} finally {
    wp_get_current_user()->remove_role('opt');
}
$check(is_file(PCOE_DIR . '/assets/draft-guide/drafts-and-preorders-uk.pdf') || getenv('PCOE_GUIDE_CAPTURE'), 'PDF is shipped');

if (getenv('PCOE_GUIDE_CAPTURE')) {
    switch_to_locale('uk');
    $order = wc_get_order($fixture['order_id']);
    $check((int) $order->get_customer_id() === $user->ID && $order->has_status('pc-draft'), 'Only own synthetic draft');
    $order->update_meta_data('_pc_draft_title', 'Фарби для майстерні');
    foreach ($order->get_items() as $item) {
        $colour = $item->get_meta('colour') === 'Red' ? 'червона' : 'синя';
        $item->set_name('Олійна фарба, ' . $colour . ', 40 мл');
        $item->set_quantity($colour === 'червона' ? 12 : 6);
        $item->set_subtotal($item->get_quantity() * 15);
        $item->set_total($item->get_quantity() * 15);
        $item->save();
    }
    $order->calculate_totals(); $order->save();
    $css = file_get_contents(PCOE_DIR . '/assets/catalog-draft.css');
    $write = static function ($name, $body) use ($css): void {
        // The saved examples cannot submit: strip credentials and disable every control.
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?><div>' . $body . '</div>');
        foreach ($dom->getElementsByTagName('form') as $form) { $form->removeAttribute('action'); $form->setAttribute('onsubmit', 'return false'); }
        foreach (['input', 'button', 'select'] as $tag) foreach ($dom->getElementsByTagName($tag) as $node) {
            if ($node->getAttribute('type') === 'hidden') $node->setAttribute('value', '');
            $node->setAttribute('disabled', 'disabled');
        }
        foreach (iterator_to_array($dom->getElementsByTagName('script')) as $script) $script->parentNode->removeChild($script);
        $body = $dom->saveHTML();
        file_put_contents('/tmp/draft-guide-' . $name . '.html', '<!doctype html><html lang="uk"><meta charset="utf-8"><style>
            body{font:17px Arial,sans-serif;color:#303735;background:white;margin:0;padding:24px;line-height:1.5}
            *{box-sizing:border-box} h2{font-size:24px;margin:0 0 16px} h3{font-size:21px}
            p{margin:12px 0} label{display:block} select,input{font:17px Arial;padding:10px;border:1px solid #b5bfba;background:white;color:#303735;max-width:100%}
            button,.button{font:16px Arial;padding:12px 16px;background:#28634c;color:white;border:0;border-radius:3px;line-height:1.45}
            :disabled{opacity:1} table{border-collapse:collapse;width:100%;margin-bottom:20px}td,th{border:1px solid #dde2de;padding:14px;text-align:left;font-size:16px}
            .pcoe-draft-folio__actions{display:grid;gap:24px}.pcoe-draft-folio__action{border-top:1px solid #d6dada;padding-top:16px}
            .pcoe-draft-help{display:none}.pcoe-draft-folio__confirm a{background:white;color:#28634c}
            ' . $css . '</style><main>' . $body . '</main></html>');
    };
    $editor = $capture(fn() => CatalogDraft::editor($order->get_id()));
    $write('edit', str_replace('<details ', '<details open ', $editor));
    add_filter('pcoe_folio_non_accounting_warehouse_id', static fn() => 7);
    $order->update_meta_data('_pcoe_draft_purpose', 'preorder'); $order->save();
    $write('folio', $capture(fn() => DraftFolioWorkflow::render($order)));
    // In-memory preview only. No transient or payload is written to the database.
    $preview = ['owner_id' => $user->ID, 'order_id' => $order->get_id(), 'mode' => 'whole_draft', 'purpose' => 'preorder',
        'analysis' => ['rows' => [
            ['sku' => 'DEMO-RED', 'name' => 'Олійна фарба, червона, 40 мл', 'requested' => 12, 'loadable' => 0, 'unavailable' => 12],
            ['sku' => 'DEMO-BLUE', 'name' => 'Олійна фарба, синя, 40 мл', 'requested' => 6, 'loadable' => 0, 'unavailable' => 6],
        ]]];
    add_filter('pre_transient_pcoe_draft_folio_preview_guide', static fn() => $preview);
    $render = new ReflectionMethod(DraftFolioWorkflow::class, 'render_preview');
    $write('preview', $capture(fn() => $render->invoke(null, $order, 'guide')));
    $check(!pc_folio_order_has_saved_documents(wc_get_order($order->get_id())), 'No Folio documents created');
    echo "Inert guide captures prepared.\n";
}
echo "Draft help local checks passed.\n";
