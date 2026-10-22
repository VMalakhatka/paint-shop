<?php
/** Read-only category media regression; image API fixtures exist only in memory. */
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local'
    || parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new RuntimeException('Local only');
require_once dirname(__DIR__) . '/inc/category-description-media.php';

$attachments = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'numberposts' => 1, 'fields' => 'ids']);
if (!$attachments) throw new RuntimeException('Existing local image required');
$id = (int) $attachments[0];
$page_id = wc_get_page_id('myaccount');
$saved_query = $GLOBALS['wp_query'];
$saved_meta = get_post_meta($id);
$saved_term = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1])[0] ?? null;
if (!($saved_term instanceof WP_Term)) throw new RuntimeException('Existing local category required');
$saved_description = $saved_term->description;
$query = new WP_Query();
$query->is_tax = true; $query->queried_object = $saved_term;
$GLOBALS['wp_query'] = $query;
$source = 'https://media.example.invalid/current/example-300x300.jpg';
$old = 'https://media.example.invalid/old/example-300x300.jpg';
$allow_source = true; $allow_srcset = true;
$meta = ['width' => 600, 'height' => 600, 'file' => 'current/example.jpg', 'sizes' => [
    'medium' => ['file' => 'example-300x300.jpg', 'width' => 300, 'height' => 300, 'mime-type' => 'image/jpeg'],
    'archive_custom' => ['file' => 'example-300x300.jpg', 'width' => 300, 'height' => 300, 'mime-type' => 'image/jpeg'],
]];
$metadata_filter = static fn($value, $attachment_id) => (int) $attachment_id === $id ? $meta : $value;
$source_filter = static function ($value, $attachment_id, $size) use ($id, &$allow_source, $source) {
    if ((int) $attachment_id !== $id) return $value;
    return $allow_source ? [$source, 300, 300, true] : false;
};
$srcset_filter = static function ($sources, $size, $image_src, $image_meta, $attachment_id) use ($id, &$allow_srcset) {
    if ((int) $attachment_id !== $id) return $sources;
    return $allow_srcset ? [
        300 => ['url' => 'https://media.example.invalid/current/example-300x300.jpg', 'descriptor' => 'w', 'value' => 300],
        600 => ['url' => 'https://media.example.invalid/current/example.jpg', 'descriptor' => 'w', 'value' => 600],
    ] : false;
};
$http = 0;
$http_filter = static function () use (&$http) { $http++; return new WP_Error('test_no_http', 'No network in this test'); };
add_filter('wp_get_attachment_metadata', $metadata_filter, PHP_INT_MAX, 2);
add_filter('wp_get_attachment_image_src', $source_filter, PHP_INT_MAX, 3);
add_filter('wp_calculate_image_srcset', $srcset_filter, PHP_INT_MAX, 5);
add_filter('pre_http_request', $http_filter, PHP_INT_MAX);
$checks = 0;
$check = static function ($condition, $label) use (&$checks) {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
};
$render = static fn($html) => apply_filters('woocommerce_taxonomy_archive_description_raw', $html, $saved_term);
$image = '<img class="alignnone size-medium wp-image-' . $id . '" src="' . $old . '" alt="Blue &amp; white" width="300" height="300" style="max-width:100%">';
$parse = static function ($value) { $p = new WP_HTML_Tag_Processor($value); $p->next_tag('IMG'); return $p; };

try {
    $input = '<p>Before <strong>artwork</strong></p><a href="' . $old . '">' . $image . '</a><p>After</p>';
    $output = $render($input);
    $check($output === str_replace('src="' . $old . '"', 'src="' . $source . '"', $input), 'Only image src changes; text, link, alt, class, style and dimensions survive');
    $check($render($output) === $output, 'Rendering is idempotent');
    $check($render(str_replace($old, home_url('/old/example-300x300.jpg'), $image)) === str_replace($old, $source, $image), 'Own-site URL can move to the attachment media host');

    $responsive = str_replace(' alt=', ' srcset="' . $old . ' 300w, https://media.example.invalid/old/example.jpg 600w" sizes="(max-width: 300px) 100vw, 300px" alt=', $image);
    $responsive_output = $parse($render($responsive));
    $check($responsive_output->get_attribute('src') === $source, 'Responsive image uses current src');
    $check($responsive_output->get_attribute('srcset') === $source . ' 300w, https://media.example.invalid/current/example.jpg 600w', 'Stale srcset is replaced through the image API');
    $check($responsive_output->get_attribute('sizes') === '(max-width: 300px) 100vw, 300px', 'Authored responsive layout preserved');
    $allow_srcset = false;
    $check($parse($render($responsive))->get_attribute('srcset') === null, 'Unavailable responsive set removes stale candidates');
    $allow_srcset = true;
    $check($parse($render($image))->get_attribute('srcset') === null, 'Does not add responsive attributes to an authored fixed image');

    foreach ([
        'foreign host' => str_replace($old, 'https://foreign.example.invalid/old/example-300x300.jpg', $image),
        'different illustration' => str_replace($old, 'https://media.example.invalid/old/another-300x300.jpg', $image),
        'missing ID' => str_replace('wp-image-' . $id, '', $image),
        'unknown ID' => str_replace('wp-image-' . $id, 'wp-image-' . PHP_INT_MAX, $image),
        'non-image post' => str_replace('wp-image-' . $id, 'wp-image-' . $page_id, $image),
        'missing size' => str_replace('size-medium', '', $image),
        'unknown size' => str_replace('size-medium', 'size-no_such_size', $image),
        'ambiguous IDs' => str_replace('wp-image-' . $id, 'wp-image-' . $id . ' wp-image-999', $image),
        'ambiguous sizes' => str_replace('size-medium', 'size-medium size-large', $image),
        'data URL' => str_replace($old, 'data:image/png;base64,eA==', $image),
        'missing src' => str_replace('src="' . $old . '"', '', $image),
    ] as $label => $unchanged) $check($render($unchanged) === $unchanged, $label . ' stays untouched');

    $allow_source = false;
    $check($render($image) === $image, 'Unavailable attachment image API leaves source intact');
    $allow_source = true;
    $check($parse($render(str_replace('size-medium', 'size-archive_custom', $image)))->get_attribute('src') === $source, 'Stored metadata size is supported');
    $check($parse($render(str_replace('size-medium', 'size-full', $image)))->get_attribute('src') === $source, 'Explicit full size is supported');
    $other_term = clone $saved_term; $other_term->taxonomy = 'product_brand';
    $check(apply_filters('woocommerce_taxonomy_archive_description_raw', $image, $other_term) === $image, 'Other product taxonomies are outside scope');
    $GLOBALS['wp_query'] = new WP_Query();
    $check($render($image) === $image, 'Non-category routes are outside scope');
    $GLOBALS['wp_query'] = $query;
    $check($http === 0, 'Rendering makes no HTTP requests');
    $check(get_post_meta($id) === $saved_meta && get_term($saved_term->term_id)->description === $saved_description, 'Stored attachment metadata and category description are unchanged');
    echo wp_json_encode(['passed' => $checks, 'http_requests' => $http, 'writes' => 0]), "\n";
} finally {
    remove_filter('wp_get_attachment_metadata', $metadata_filter, PHP_INT_MAX);
    remove_filter('wp_get_attachment_image_src', $source_filter, PHP_INT_MAX);
    remove_filter('wp_calculate_image_srcset', $srcset_filter, PHP_INT_MAX);
    remove_filter('pre_http_request', $http_filter, PHP_INT_MAX);
    $GLOBALS['wp_query'] = $saved_query;
}
