<?php
/** Offline catalogue/XLSX regression. No WordPress bootstrap, network or DB. */
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
require getenv('PCOE_AUTOLOAD') ?: dirname(__DIR__, 3) . '/vendor/autoload.php';
function add_action(...$args) {}
function add_filter(...$args) {}
function __($text, $domain = '') { return $text; }
function absint($value) { return abs((int)$value); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function get_transient($key) { return false; }
function set_transient(...$args) {}
function wp_json_encode($value) { return json_encode($value); }
function get_current_blog_id() { return 1; }
function get_locale() { return 'en_US'; }
function home_url($path = '') { return 'https://fixture.invalid' . $path; }
function wc_get_product_visibility_term_ids() { return []; }
class WP_Error { function __construct(...$args) {} }
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_terms($args) { return $GLOBALS['terms']; }
function get_the_terms($id, $taxonomy) {
    return $taxonomy === 'product_cat' ? array_values(array_filter($GLOBALS['terms'], static fn($term) => in_array($term->term_id, $GLOBALS['assigned'][$id] ?? [], true))) : [];
}
function wp_list_pluck($items, $key) { return array_map(static fn($item) => $item->$key, $items); }
function get_post_meta($id, $key, $single) { return $key === '_yoast_wpseo_primary_product_cat' ? ($GLOBALS['primary'][$id] ?? '') : '4'; }
function wc_get_price_to_display($product, $args) { return $args['price']; }
function get_permalink($id) { return home_url('/product/' . $id); }
function psu_product_display_barcode($product) { return '012345678905'; }
function wp_get_current_user() { return (object)['roles' => ['partner']]; }
function wp_roles() { return (object)['roles' => ['partner' => ['name' => 'Partner']]]; }
function translate_user_role($role) { return $role; }
function get_woocommerce_currency() { return 'UAH'; }
function get_bloginfo($key) { return 'Lavka test'; }
function wp_date($format) { return date($format); }
class WC_Product {
    function __construct(private int $id, private int $parent = 0) {}
    function get_id() { return $this->id; }
    function get_parent_id() { return $this->parent; }
    function get_sku() { return '000-SKU-' . $this->id; }
    function get_name() { return 'Product ' . $this->id; }
    function get_price() { return '73.12'; }
    function is_type($type) { return $type === 'variation' && $this->parent > 0; }
    function get_permalink() { return get_permalink($this->parent) . '?variation=' . $this->id; }
}
$wpdb = new class {
    public $term_taxonomy = 'fixture_terms', $term_relationships = 'fixture_links', $posts = 'fixture_posts', $last_error = '';
    function get_results($sql) { return $GLOBALS['terms']; }
};
require __DIR__ . '/../../paint-shop-ux/inc/category-menu.php';
require __DIR__ . '/../inc/PriceList.php';
use PaintCore\PCOE\PriceList;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$terms = [];
foreach ([[1,0,'A Hidden',1],[2,1,'Hidden child',1],[3,0,'B Visible',0],[4,3,'Visible child',1],[5,4,'Visible leaf',1],[6,0,'C Empty',0],[7,0,'D Visible',1]] as [$id,$parent,$name,$count]) {
    $terms[] = (object)['term_id'=>$id, 'parent'=>$parent, 'name'=>$name, 'count'=>$count];
}
usort($terms, static fn($a, $b) => strcmp($a->name, $b->name));
$options = ['psu_category_menu_excluded'=>[1]];
$assigned = [10=>[2], 11=>[2,5], 12=>[4], 13=>[6], 14=>[], 15=>[7]];
$primary = [11=>2];
$products = array_map(static fn($id) => new WC_Product($id), [10,11,12,13,14,15]);
$products[] = new WC_Product(16, 10); // Hidden-only variation.
$products[] = new WC_Product(17, 11); // Visible variation uses parent categories.
$products[] = $products[1];
$rows = iterator_to_array(PriceList::catalogue_rows($products, [1,2]), false);
$headings = array_column($rows, 'heading');
$values = array_values(array_filter(array_column($rows, 'values')));
check($headings === ['B Visible','Visible child','Visible leaf','D Visible'], 'Only visible, used branches and their ancestors');
check(count($values) === 4, 'Hidden-only, empty-category, unassigned and duplicate rows excluded');
check(!in_array('000-SKU-10', array_column($values, 0), true) && !in_array('000-SKU-16', array_column($values, 0), true), 'Hidden descendants cannot leak through Other products');
foreach ($values as $row) check(!str_contains($row[4], 'Hidden'), 'Hidden primary category must fall back to visible path');
$options['psu_category_menu_excluded'] = [1,4];
check(array_column(iterator_to_array(PriceList::catalogue_rows($products, []), false), 'heading') === ['D Visible'], 'Changed exclusions take effect on next export, including empty parents');
$options['psu_category_menu_excluded'] = [1];
$book = PriceList::workbook($products, [1,2]);
$path = tempnam(sys_get_temp_dir(), 'pcoe-visibility-');
try {
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
    $saved = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $saved->getActiveSheet();
    check($sheet->getStyle('A1')->getFill()->getStartColor()->getRGB() === '800000', 'Burgundy table header');
    check($sheet->getStyle('C2')->getFill()->getStartColor()->getRGB() === '800000', 'Burgundy root heading');
    check($sheet->getStyle('C3')->getFill()->getStartColor()->getRGB() === 'D9B3B3', 'Muted burgundy subgroup');
    $found = 0;
    for ($row=2; $row<=$sheet->getHighestRow(); $row++) {
        if (!$sheet->getCell('A'.$row)->getValue()) continue;
        $found++;
        check($sheet->getCell('B'.$row)->getValue() === '012345678905', 'Barcode leading zero');
        check($sheet->getCell('F'.$row)->getValue() === 73.12, 'Customer price unchanged');
        check($sheet->getCell('G'.$row)->getValue() == 8, 'Combined stock unchanged');
        check($sheet->getStyle('H'.$row)->getFill()->getStartColor()->getRGB() === 'FFF2B3', 'Order column stays yellow');
        check($sheet->getCell('H'.$row)->getValue() === null, 'Order quantity remains blank');
        check(str_starts_with($sheet->getCell('I'.$row)->getHyperlink()->getUrl(), home_url('/product/')), 'Product link preserved');
    }
    check($found === 4 && !$sheet->getShowSummaryBelow() && $sheet->getFreezePane() === 'D2', 'XLSX rows, grouping and freeze pane preserved');
    if ($preview = getenv('PCOE_TEST_PREVIEW')) (new \PhpOffice\PhpSpreadsheet\Writer\Html($saved))->save($preview);
    $saved->disconnectWorksheets();
} finally { unlink($path); $book->disconnectWorksheets(); }
$wpdb->last_error = 'fixture failure';
$failed = false;
try { iterator_to_array(PriceList::catalogue_rows($products, [])); } catch (RuntimeException $e) { $failed = $e->getMessage() === 'Category visibility unavailable'; }
check($failed, 'Visibility failure must not expose the unrestricted catalogue');
echo "PASS: storefront visibility, excluded descendants, primary fallback, variations, fresh settings, XLSX roundtrip and burgundy styling\n";
