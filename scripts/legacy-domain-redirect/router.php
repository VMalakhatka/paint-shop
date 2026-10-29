<?php
/** Standalone OpenCart catalogue bridge. No database, sessions or WordPress load. */
function lavka_legacy_target($server, $query, $map, $cookies = array())
{
    if (!is_array($map) || !isset($map['version']) || $map['version'] !== 1) {
        return null;
    }
    // Stage one: keep existing OpenCart visits/carts on their original application.
    foreach (array('PHPSESSID', 'default') as $cookieName) {
        if (isset($cookies[$cookieName]) && $cookies[$cookieName] !== '') {
            return null;
        }
    }
    foreach (array('products', 'categories', 'aliases') as $section) {
        if (!isset($map[$section]) || !is_array($map[$section])) {
            return null;
        }
    }
    if (!isset($server['REQUEST_METHOD'], $server['HTTP_HOST']) ||
        !in_array($server['REQUEST_METHOD'], array('GET', 'HEAD'), true) ||
        !preg_match('/^(?:www\.)?1lavka\.com(?::(?:80|443))?$/iD', $server['HTTP_HOST'])) {
        return null;
    }
    // Never turn a checkout/callback/search/filter request into a catalogue redirect.
    $allowed = array('route', 'product_id', 'path', '_route_', 'page', 'sort', 'order', 'limit',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'gclid', 'dclid', 'fbclid', 'gbraid', 'wbraid');
    foreach ($query as $key => $value) {
        if (!in_array($key, $allowed, true) || !is_string($value) || strlen($value) > 2048 ||
            preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return null;
        }
    }
    $uriPath = parse_url(isset($server['REQUEST_URI']) ? $server['REQUEST_URI'] : '/', PHP_URL_PATH);
    if (!is_string($uriPath) || preg_match('/[\\\\\x00-\x1f\x7f]/', $uriPath)) {
        return null;
    }
    $route = isset($query['route']) ? $query['route'] : '';
    $product = isset($query['product_id']) ? $query['product_id'] : '';
    $categoryPath = isset($query['path']) ? $query['path'] : '';
    $seoPath = isset($query['_route_']) ? $query['_route_'] : '';
    if ($seoPath === '' && $uriPath !== '/' && $uriPath !== '/index.php') {
        $seoPath = rawurldecode(ltrim($uriPath, '/'));
    }
    if ($seoPath !== '') {
        // Conflicting explicit route/IDs must continue through the original application.
        if ($route !== '' || $product !== '' || $categoryPath !== '') {
            return null;
        }
        $parts = explode('/', trim($seoPath, '/'));
        $ids = array();
        foreach ($parts as $part) {
            if ($part === '' || !isset($map['aliases'][$part])) {
                return null;
            }
            $alias = $map['aliases'][$part];
            if (!is_string($alias)) {
                return null;
            }
            if (preg_match('/^category_id=([1-9][0-9]*)$/D', $alias, $match) && $product === '') {
                $ids[] = $match[1];
                $route = 'product/category';
            } elseif (preg_match('/^product_id=([1-9][0-9]*)$/D', $alias, $match) && $product === '') {
                $product = $match[1];
                $route = 'product/product';
            } else {
                return null;
            }
        }
        $categoryPath = implode('_', $ids);
    }
    $target = null;
    if (($route === '' || $route === 'common/home') && $product === '' && $categoryPath === '' &&
        ($uriPath === '/' || $uriPath === '/index.php') && $seoPath === '') {
        $target = isset($map['home']) ? $map['home'] : null;
    } elseif ($route === 'product/product' && preg_match('/^[1-9][0-9]*$/D', $product)) {
        $target = isset($map['products'][$product]) ? $map['products'][$product] : null;
    } elseif ($route === 'product/category' && $product === '' &&
        preg_match('/^[1-9][0-9]*(?:_[1-9][0-9]*)*$/D', $categoryPath)) {
        // Different page sizes mean old page N cannot safely become new page N.
        if (isset($query['page']) && $query['page'] !== '1') {
            return null;
        }
        $parts = explode('_', $categoryPath);
        $category = end($parts);
        $target = isset($map['categories'][$category]) ? $map['categories'][$category] : null;
    }
    // A malformed or modified map must not permit an external redirect.
    if (!is_string($target) || strlen($target) > 4096 ||
        !preg_match('~^https://kreul\.com\.ua/[^\s?#\\\\]*$~D', $target) ||
        preg_match('/%0[ad]/i', $target)) {
        return null;
    }
    $tracking = array();
    foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_id', 'gclid', 'dclid', 'fbclid', 'gbraid', 'wbraid') as $key) {
        if (isset($query[$key]) && $query[$key] !== '') {
            $tracking[$key] = $query[$key];
        }
    }
    return $target . ($tracking ? '?' . http_build_query($tracking, '', '&', PHP_QUERY_RFC3986) : '');
}
