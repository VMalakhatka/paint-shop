<?php
/** The status control must navigate to a new account document even on repeated clicks. */
use PaintCore\PCOE\CrmCatalogUi;
use PaintCore\PCOE\PartnerCatalog;

putenv('PCOE_FIXTURE_PREPARING=1');
ob_start();
require __DIR__.'/partner-catalog-ui-fixture.php';
ob_end_clean();

$previous = PartnerCatalog::url();
foreach ([true, true, false] as $preparing) {
    putenv('PCOE_FIXTURE_PREPARING='.($preparing ? '1' : ''));
    ob_start();
    CrmCatalogUi::render();
    $document = new DOMDocument();
    $document->loadHTML(ob_get_clean(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $target = $xpath->evaluate('string(//a[normalize-space()="Refresh CRM status"]/@href)');
    $parts = parse_url($target);
    parse_str($parts['query'] ?? '', $query);
    $resource = explode('#', $target, 2)[0];
    if ($resource === $previous || ($query['pcoe_catalog'] ?? '') !== '1'
        || ($parts['path'] ?? '') !== '/my-account/' || ($parts['fragment'] ?? '') !== 'pcoe-crm-catalog') {
        throw new RuntimeException('Status refresh must request a different URL for the same account section');
    }
    $waiting = $xpath->evaluate('boolean(//*[@role="status"][contains(., "Products prepared: 0")])');
    $download = $xpath->evaluate('boolean(//button[@value="download"])');
    if ($waiting !== $preparing || $download === $preparing) {
        throw new RuntimeException('The visible status and download must match the current snapshot');
    }
    $previous = $resource;
}
echo "PASS repeated CRM status refresh and ready download\n";
