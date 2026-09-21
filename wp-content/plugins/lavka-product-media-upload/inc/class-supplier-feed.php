<?php
namespace Lavka\ProductMediaUpload;

if (!defined('ABSPATH')) { exit; }

/** Bounded, entity-free XML reader. Paths are element names, never executable XPath. */
final class SupplierFeed
{
    public const MAX_BYTES = 62914560;
    public const MAX_ITEMS = 100000;

    public static function defaults(): array
    {
        return ['item' => 'offer', 'id' => '@id', 'sku' => 'model|vendorCode|sku',
            'barcode' => 'barcode|gtin|ean', 'name' => 'name|title', 'brand' => 'vendor|brand',
            'category' => 'categoryId|product_type', 'description' => 'description',
            'images' => 'picture|image_link|additional_image_link', 'price' => 'price'];
    }

    public static function mapping(array $input): array
    {
        $map = array_intersect_key($input, self::defaults()) + self::defaults();
        foreach ($map as $value) {
            if (!is_string($value) || strlen($value) > 250 || !preg_match('/^@?[\w:-]+(?:[\/|]@?[\w:-]+)*$/D', $value)) {
                throw new \RuntimeException(__('Use element names separated by / or | for field mapping.', 'lavka-product-media-upload'));
            }
        }
        if (!preg_match('/^[\w:-]+$/D', $map['item'])) {
            throw new \RuntimeException(__('The product element must be a single XML element name.', 'lavka-product-media-upload'));
        }
        return $map;
    }

    public static function values(\DOMElement $node, string $paths): array
    {
        $out = [];
        foreach (explode('|', $paths) as $path) {
            $nodes = [$node];
            foreach (explode('/', $path) as $part) {
                $next = [];
                foreach ($nodes as $current) {
                    if ($part[0] === '@') {
                        if ($current->hasAttribute(substr($part, 1))) { $next[] = $current->getAttributeNode(substr($part, 1)); }
                    } else {
                        foreach ($current->childNodes as $child) {
                            if ($child instanceof \DOMElement && ($child->localName === $part || $child->nodeName === $part)) { $next[] = $child; }
                        }
                    }
                }
                $nodes = $next;
            }
            foreach ($nodes as $value) { $text = trim($value->textContent); if ($text !== '') { $out[] = $text; } }
        }
        return array_values(array_unique($out));
    }

    public static function read(string $path, array $mapping): \Generator
    {
        if (!class_exists('XMLReader') || !class_exists('DOMDocument')) {
            throw new \RuntimeException(__('The server needs XMLReader and DOM support.', 'lavka-product-media-upload'));
        }
        if (!is_file($path) || filesize($path) > self::MAX_BYTES || filesize($path) < 1) {
            throw new \RuntimeException(__('The XML file is empty or exceeds 60 MiB.', 'lavka-product-media-upload'));
        }
        $map = self::mapping($mapping);
        $prior = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader();
        $categories = [];
        $seen = [];
        $count = 0;
        try {
            if (!$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) { throw new \RuntimeException('XML_OPEN'); }
            $reader->setParserProperty(\XMLReader::LOADDTD, false);
            $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
            while ($reader->read()) {
                // YML commonly declares shops.dtd. Accept that exact inert declaration only; never load it.
                if ($reader->nodeType === \XMLReader::DOC_TYPE && preg_match('/^<!DOCTYPE\s+yml_catalog\s+SYSTEM\s+[\"\']shops\.dtd[\"\']\s*>$/D', trim($reader->readOuterXml()))) { continue; }
                if ($reader->nodeType === \XMLReader::DOC_TYPE || $reader->nodeType === \XMLReader::ENTITY_REF) {
                    throw new \RuntimeException(__('XML with DTD or entities is not supported.', 'lavka-product-media-upload'));
                }
                if ($reader->nodeType !== \XMLReader::ELEMENT) { continue; }
                if ($reader->localName === 'category' && $reader->getAttribute('id') !== null) {
                    $categories[$reader->getAttribute('id')] = mb_substr($reader->readString(), 0, 190);
                }
                if ($reader->localName !== $map['item'] && $reader->name !== $map['item']) { continue; }
                if (++$count > self::MAX_ITEMS) { throw new \RuntimeException(__('The catalogue exceeds 100,000 products.', 'lavka-product-media-upload')); }
                $node = @$reader->expand();
                if (!$node instanceof \DOMElement || strlen($node->textContent) > 1048576) { throw new \RuntimeException('XML_ITEM_SIZE'); }
                $row = [];
                foreach ($map as $field => $selector) {
                    if ($field === 'item') { continue; }
                    $values = self::values($node, $selector);
                    $row[$field] = $field === 'images' ? array_slice($values, 0, 40) : (string) ($values[0] ?? '');
                }
                $row['id'] = $row['id'] ?: ($row['sku'] ?: $row['barcode']);
                if ($row['id'] === '' || isset($seen[$row['id']])) {
                    throw new \RuntimeException(__('Every product needs a unique supplier ID, SKU or barcode. Check the field mapping.', 'lavka-product-media-upload'));
                }
                $seen[$row['id']] = true;
                $row['category'] = $categories[$row['category']] ?? $row['category'];
                $row['kind'] = 'image';
                $row['attributes'] = [];
                foreach ($node->childNodes as $child) {
                    if (!$child instanceof \DOMElement || $child->childElementCount > 0) { continue; }
                    $label = $child->localName === 'param' ? $child->getAttribute('name') : $child->localName;
                    if ($label && !in_array($label, ['picture','description'], true)) {
                        $row['attributes'][] = ['name' => mb_substr($label, 0, 190), 'value' => mb_substr(trim($child->textContent), 0, 4000)];
                    }
                }
                $row['attributes'] = array_slice($row['attributes'], 0, 100);
                yield $row;
            }
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) { throw new \RuntimeException(__('The XML is incomplete or malformed. The previous catalogue has been retained.', 'lavka-product-media-upload')); }
            }
            if (!$count) { throw new \RuntimeException(__('No products found. Check the product element and field mapping.', 'lavka-product-media-upload')); }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
    }

    /** No credentials, private networks, nonstandard ports or unvalidated redirects. */
    public static function url(string $url): string
    {
        $parts = wp_parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $resolved = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (defined('FILTER_FLAG_GLOBAL_RANGE')) { $flags |= FILTER_FLAG_GLOBAL_RANGE; }
        $public = filter_var($resolved, FILTER_VALIDATE_IP, $flags);
        // PHP 8.1 fallback: exclude shared, benchmarking, documentation and multicast IPv4 ranges too.
        if ($public && filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = ip2long($resolved);
            foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4]] as [$network, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($ip & $mask) === (ip2long($network) & $mask)) { $public = false; }
            }
        }
        if (!$public || !$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true))
            || !wp_http_validate_url($url)) {
            throw new \RuntimeException(__('Use a public HTTP or HTTPS URL without embedded credentials.', 'lavka-product-media-upload'));
        }
        return $url;
    }

    public static function download(string $url, int $max, array $headers = []): string
    {
        $url = self::url($url);
        $path = wp_tempnam('lavka-supplier');
        if (!$path) { throw new \RuntimeException('TEMP_FILE'); }
        try {
            for ($redirect = 0; $redirect <= 3; $redirect++) {
                self::url($url);
                $r = wp_safe_remote_get($url, ['timeout' => 90, 'redirection' => 0, 'stream' => true,
                    'filename' => $path, 'limit_response_size' => $max + 1, 'headers' => $headers]);
                if (is_wp_error($r)) { throw new \RuntimeException(__('The supplier could not be reached. Try again later or upload a saved XML file.', 'lavka-product-media-upload')); }
                $status = wp_remote_retrieve_response_code($r);
                if ($status === 200) { break; }
                if (in_array($status, [301,302,303,307,308], true) && $redirect < 3 && !$headers) {
                    $location = (string) wp_remote_retrieve_header($r, 'location');
                    if ($location !== '') { $url = \WP_Http::make_absolute_url($location, $url); continue; }
                }
                throw new \RuntimeException(__('The supplier refused the download. Open the source in your browser or upload a saved XML file.', 'lavka-product-media-upload'));
            }
            clearstatcache(true, $path);
            if (!filesize($path) || filesize($path) > $max) { throw new \RuntimeException(__('The downloaded file is empty or too large.', 'lavka-product-media-upload')); }
            return $path;
        } catch (\Throwable $e) { @unlink($path); throw $e; }
    }
}
