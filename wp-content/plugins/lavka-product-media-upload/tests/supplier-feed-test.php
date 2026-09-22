<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Isolated parser/matcher contract tests; no WordPress/product writes.
define('ABSPATH', __DIR__ . '/');
function __($s, $domain = '') { return $s; }
require dirname(__DIR__) . '/inc/class-supplier-feed.php';
require dirname(__DIR__) . '/inc/class-supplier-catalog.php';
use Lavka\ProductMediaUpload\SupplierFeed;
use Lavka\ProductMediaUpload\SupplierCatalog;
function expect($ok, $why) { if (!$ok) { throw new RuntimeException($why); } }
function read_xml($xml, $map = []) {
    $p = tempnam(sys_get_temp_dir(), 'supplier-test'); file_put_contents($p, $xml);
    try { return iterator_to_array(SupplierFeed::read($p, $map)); } finally { unlink($p); }
}
function rejects($xml, $map = []) { try { read_xml($xml, $map); } catch (Throwable $e) { return true; } return false; }
$x = read_xml('<yml_catalog><shop><categories><category id="12">Paint</category></categories><offers><offer id="001"><model>00042</model><barcode>04820001230001</barcode><name><![CDATA[Paint & colour]]></name><categoryId>12</categoryId><picture>https://example.com/a.jpg</picture><picture>https://example.com/b.png</picture><price>1.20</price></offer></offers></shop></yml_catalog>');
expect(count($x)===1 && $x[0]['id']==='001' && $x[0]['sku']==='00042' && $x[0]['barcode']==='04820001230001', 'Identifiers must remain text');
expect($x[0]['category']==='Paint' && count($x[0]['images'])===2 && $x[0]['name']==='Paint & colour', 'YML categories and CDATA');
$x=read_xml('<rss xmlns:g="http://base.google.com/ns/1.0"><channel><item><g:id>abc</g:id><g:gtin>012345</g:gtin><g:title>Title</g:title><g:image_link>https://example.com/a.jpg</g:image_link><g:additional_image_link>https://example.com/b.jpg</g:additional_image_link></item></channel></rss>', ['item'=>'item','id'=>'id']);
expect($x[0]['id']==='abc' && count($x[0]['images'])===2 && $x[0]['barcode']==='012345','Namespaced RSS');
$x=read_xml('<products><product><sku>A</sku><media><image>https://example.com/a.jpg</image></media></product></products>', ['item'=>'product','images'=>'media/image']);
expect(count($x[0]['images'])===1 && $x[0]['id']==='A','Nested custom mapping and fallback ID');
expect(rejects('<!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><x><offer id="a"><name>&xxe;</name></offer></x>'),'Reject XXE');
expect(rejects('<!DOCTYPE x SYSTEM "https://example.com/evil.dtd"><x/>'),'Reject external DTD');
expect(rejects('<root><offer id="a"/><offer id="a"/></root>'),'Reject duplicate identifiers');
expect(rejects('<root><offer id="a"/></root>garbage'),'Reject malformed trailing data');
expect(rejects('<root><offer id="a"><name>broken'),'Reject truncated feed');
expect(rejects('<html><body>challenge</body></html>'),'Reject challenge HTML');
expect(rejects('<root><offer/></root>'),'Reject missing identifiers');
expect(rejects('<root/>', ['images'=>"//image[@src] | document('x')"]),'Reject XPath injection');
$lookup=['sku'=>['A'=>[1=>true],'B'=>[2=>true]],'barcode'=>['0001'=>[1=>true],'0002'=>[2=>true],'duplicate'=>[1=>true,2=>true]]];
expect(SupplierCatalog::match(['sku'=>'B','barcode'=>'0001'],$lookup,false)['id']===1,'Default ignores supplier SKU');
expect(SupplierCatalog::match(['sku'=>'B','barcode'=>'0001'],$lookup,true)['state']==='ambiguous','Conflicting identifiers block');
expect(SupplierCatalog::match(['sku'=>'A','barcode'=>'duplicate'],$lookup,true)['id']===0,'Duplicate barcode never falls back to SKU');
expect(SupplierCatalog::match(['sku'=>'A','barcode'=>''],$lookup,false)['id']===0,'SKU opt-in required');
expect(SupplierCatalog::match(['sku'=>'B','barcode'=>'duplicate'],$lookup,false,'A')['id']===1,'Explicit reviewed manual mapping');
expect(SupplierCatalog::match(['sku'=>'B','barcode'=>'0001'],$lookup,true,'MISSING')['id']===0,'Stale manual SKU does not fall back');
expect(count(read_xml('<!DOCTYPE yml_catalog SYSTEM "shops.dtd"><yml_catalog><offer id="safe"/></yml_catalog>')) === 1, 'Inert standard YML declaration');
echo "Supplier parser and matching contracts: passed (18 scenarios)\n";
if (isset($argv[1])) {
    $n=0; $photos=0;
    foreach (SupplierFeed::read($argv[1], []) as $row) { $n++;$photos+=count($row['images']); }
    echo "Real feed: $n products, $photos image references\n";
}
