<?php
require __DIR__ . '/router.php';
$map = array('version' => 1, 'home' => 'https://kreul.com.ua/',
    'products' => array('123' => 'https://kreul.com.ua/p/example/'),
    'categories' => array('9' => 'https://kreul.com.ua/category/paints/'),
    'aliases' => array('paints' => 'category_id=9', 'example' => 'product_id=123',
        'about' => 'information_id=4', 'article' => 'article_id=1'));
$cases = array(
    array('/', 'GET', '1lavka.com', 'https://kreul.com.ua/'),
    array('/index.php?route=common/home', 'HEAD', 'www.1lavka.com', 'https://kreul.com.ua/'),
    array('/index.php?route=product/product&product_id=123&path=1_9', 'GET', '1lavka.com', 'https://kreul.com.ua/p/example/'),
    array('/index.php?route=product%2Fproduct&product_id=123', 'GET', '1lavka.com:443', 'https://kreul.com.ua/p/example/'),
    array('/index.php?route=product/category&path=1_9&page=1', 'GET', '1lavka.com', 'https://kreul.com.ua/category/paints/'),
    array('/index.php?route=product/category&path=1_9&page=2', 'GET', '1lavka.com', null),
    array('/paints/example', 'GET', '1lavka.com', 'https://kreul.com.ua/p/example/'),
    array('/paints/', 'GET', '1lavka.com', 'https://kreul.com.ua/category/paints/'),
    array('/index.php?_route_=paints%2Fexample', 'GET', '1lavka.com', 'https://kreul.com.ua/p/example/'),
    array('/example/paints', 'GET', '1lavka.com', null),
    array('/about', 'GET', '1lavka.com', null),
    array('/article', 'GET', '1lavka.com', null),
    array('/unknown', 'GET', '1lavka.com', null),
    array('/index.php?route=product/product&product_id=999', 'GET', '1lavka.com', null),
    array('/index.php?route=product/product&product_id[]=123', 'GET', '1lavka.com', null),
    array('/index.php?route=product/product&product_id=123&filter=9', 'GET', '1lavka.com', null),
    array('/index.php?route=checkout/cart', 'GET', '1lavka.com', null),
    array('/index.php?route=checkout/success', 'GET', '1lavka.com', null),
    array('/index.php?route=account/order', 'GET', '1lavka.com', null),
    array('/index.php?route=account/login', 'GET', '1lavka.com', null),
    array('/index.php?route=extension/payment/callback&order_id=1', 'GET', '1lavka.com', null),
    array('/index.php?route=product/search&search=paint', 'GET', '1lavka.com', null),
    array('/index.php?route=extension/feed/google_sitemap', 'GET', '1lavka.com', null),
    array('/admin/index.php', 'GET', '1lavka.com', null),
    array('/image/test.jpg', 'GET', '1lavka.com', null),
    array('/index.php?route=product/product&product_id=123', 'POST', '1lavka.com', null),
    array('/', 'GET', 'paint.dn.ua', null),
    array('/', 'GET', '1lavka.com.attacker.test', null),
    array('/?utm_source=Google&utm_campaign=artist%20tools', 'GET', '1lavka.com', 'https://kreul.com.ua/?utm_source=Google&utm_campaign=artist%20tools'),
    array('/?email=user%40example.com', 'GET', '1lavka.com', null),
    array('/?utm_source=x%0d%0aLocation%3Aevil', 'GET', '1lavka.com', null),
    array('/paints/example?route=checkout/cart', 'GET', '1lavka.com', null),
    array('/example?product_id=123', 'GET', '1lavka.com', null),
    array('/?route=product/category&path=1_999', 'GET', '1lavka.com', null),
    array('/?route=product/product&product_id=123x', 'GET', '1lavka.com', null),
);
$checks = 0;
foreach ($cases as $case) {
    list($uri, $method, $host, $expected) = $case;
    $query = array();
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
    $actual = lavka_legacy_target(array('REQUEST_METHOD' => $method, 'HTTP_HOST' => $host, 'REQUEST_URI' => $uri), $query, $map);
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL: ' . $uri . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n");
        exit(1);
    }
    $checks++;
}
foreach (array('https://attacker.test/', 'https://kreul.com.ua@attacker.test/',
    'https://kreul.com.ua/%0d%0aX:test', 'https://kreul.com.ua/?redirect=evil',
    "https://kreul.com.ua/\r\nX: test") as $bad) {
    $badMap = $map;
    $badMap['home'] = $bad;
    if (lavka_legacy_target(array('REQUEST_METHOD' => 'GET', 'HTTP_HOST' => '1lavka.com'), array(), $badMap) !== null) {
        throw new RuntimeException('Unsafe map URL accepted');
    }
    $checks++;
}
if (isset($argv[1])) {
    $live = json_decode(file_get_contents($argv[1]), true);
    foreach (array('products' => array('product/product', 'product_id'), 'categories' => array('product/category', 'path')) as $kind => $keys) {
        foreach ($live[$kind] as $id => $expected) {
            $actual = lavka_legacy_target(array('REQUEST_METHOD' => 'GET', 'HTTP_HOST' => '1lavka.com', 'REQUEST_URI' => '/index.php'),
                array('route' => $keys[0], $keys[1] => (string) $id), $live);
            if ($actual !== $expected) {
                throw new RuntimeException('Generated mapping failed: ' . $kind . ':' . $id);
            }
            $checks++;
        }
    }
}
foreach (array('PHPSESSID', 'default') as $cookieName) {
    if (lavka_legacy_target(array('REQUEST_METHOD' => 'GET', 'HTTP_HOST' => '1lavka.com'), array(), $map,
        array($cookieName => 'existing-session')) !== null) {
        throw new RuntimeException('Existing legacy session was redirected');
    }
    $checks++;
}
$badMap = $map;
$badMap['aliases']['example'] = array('unexpected');
if (lavka_legacy_target(array('REQUEST_METHOD' => 'GET', 'HTTP_HOST' => '1lavka.com', 'REQUEST_URI' => '/example'), array(), $badMap) !== null) {
    throw new RuntimeException('Malformed alias accepted');
}
$checks++;
echo $checks . " routing checks passed.\n";
