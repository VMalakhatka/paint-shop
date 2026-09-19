<?php
// Standalone read-only HTML regression test. No WordPress bootstrap, login or writes.
$base = 'http://paint.local';
$path = '/category/farbi-akrilovi/farbi-akrilovi-kreul-solo-goya-80-ml/';
$checks = [];
$check = static function ($pass, $name) use (&$checks) { $checks[] = ['test'=>$name,'pass'=>(bool)$pass]; };
$load = static function ($url) use ($base) {
    if (parse_url($url, PHP_URL_HOST) !== parse_url($base, PHP_URL_HOST)) throw new RuntimeException('Local only');
    $html = file_get_contents($url, false, stream_context_create(['http'=>['timeout'=>60,'header'=>"Cookie: psu_cols=2; psu_rows=2\r\n"]]));
    if ($html === false) throw new RuntimeException('HTTP failed');
    $doc = new DOMDocument(); @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    return [$html, new DOMXPath($doc)];
};
$products = static function ($xp) {
    $ids = [];
    foreach ($xp->query('//li[contains(concat(" ",@class," ")," type-product ")]') as $node) {
        if (preg_match('/\bpost-(\d+)\b/', $node->getAttribute('class'), $match)) $ids[] = $match[1];
    }
    return $ids;
};
foreach (['pp=12'=>12,'pp=24'=>24,'pp=48'=>48,'per_page=20'=>20] as $query=>$size) {
    [$html,$xp] = $load($base.$path.'?'.$query);
    $first = $products($xp);
    $check(count($first) === $size, $query.' exact size despite old cookies');
    $check(!str_contains($html,'psufp_applied') && !str_contains($html,'function measureCols()'), $query.' no forced reload script');
    $next = $xp->query('//div[@class="psu-catalog-pagination"]//a[contains(@class,"next")]')->item(0);
    $check((bool)$next, $query.' has next page');
    if (!$next) continue;
    $href = $next->getAttribute('href');
    $check(str_contains($href,$path.'page/2/') && str_contains($href,$query), $query.' category and size preserved');
    [, $second] = $load($href);
    $check(!array_intersect($first,$products($second)) && count($products($second)) > 0, $query.' page two disjoint');
    foreach ($second->query('//div[contains(@class,"psu-per-page")]//a') as $link) {
        $link_url = $link->getAttribute('href');
        $check(!str_contains($link_url,'page/2') && !str_contains($link_url,'per_page='), $query.' size switch resets page and alias');
    }
    $key = explode('=', $query)[0];
    $check($second->query('//form[@class="psu-catalog-filters"]//input[@name="'.$key.'" and @value="'.$size.'"]')->length === 1, $query.' filter form preserves explicit selection');
}
[$html,$xp] = $load($base.$path.'?pp=12&in_stock=1&orderby=price');
$next = $xp->query('//div[@class="psu-catalog-pagination"]//a[contains(@class,"next")]')->item(0);
$check(count($products($xp)) === 12 && (bool)$next, 'filtered category size and pages');
if ($next) {
    parse_str(parse_url($next->getAttribute('href'),PHP_URL_QUERY),$params);
    $check(($params['in_stock']??null)==='1' && ($params['orderby']??null)==='price' && ($params['pp']??null)==='12', 'pagination preserves stock, sort and size');
    [, $second] = $load($next->getAttribute('href'));
    $check(!array_intersect($products($xp),$products($second)), 'filtered pages have no duplicate products');
}
$result = ['passed'=>count(array_filter($checks,fn($c)=>$c['pass'])),'total'=>count($checks),'failed'=>array_values(array_filter($checks,fn($c)=>!$c['pass']))];
file_put_contents('/tmp/psu-page-size-http.json',json_encode($result,JSON_PRETTY_PRINT));
echo json_encode($result,JSON_PRETTY_PRINT),"\n";
exit($result['failed'] ? 1 : 0);
