<?php
/** Offline tests: no WordPress bootstrap, credentials, HTTP, mail or live database. */
define('ABSPATH', __DIR__ . '/isolated/');
define('ARRAY_A', 'ARRAY_A');
function wp_json_encode($value) { return json_encode($value, JSON_THROW_ON_ERROR); }
function __($value, $domain = '') { return $value; }
class WP_User {}
function get_user_by($field, $id) { return $id === 7 ? new WP_User() : false; }
function get_current_user_id() { return 7; }
function get_option($key, $default = null) { return $GLOBALS['options'][$key] ?? $default; }
function is_user_logged_in() { return $GLOBALS['logged_in'] ?? true; }
function pc_wholesale_customer_can_access() { return $GLOBALS['allowed'] ?? true; }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? false; }

final class OfflineWpdb {
    public string $prefix = 'isolated_';
    public string $last_error = '';
    private PDO $db;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE isolated_pcoe_waitlist (user_id INTEGER PRIMARY KEY, revision INTEGER, payload TEXT)');
    }
    public function prepare($sql, ...$values) {
        $index = 0;
        return preg_replace_callback('/%[ds]/', function ($match) use (&$index, $values) {
            $value = $values[$index++];
            return $match[0] === '%d' ? (string) (int) $value : $this->db->quote((string) $value);
        }, $sql);
    }
    public function query($sql) {
        // SQLite's equivalent of MySQL's unique-key INSERT IGNORE, for offline CAS tests.
        return $this->db->exec(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql));
    }
    public function get_row($sql, $format) { return $this->db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null; }
}

class WC_Product {
    public function __construct(public int $id) {}
    public function get_id() { return $this->id; }
    public function get_type() { return 'simple'; }
    public function get_status() { return 'publish'; }
    public function get_sku() { return 'SKU-' . $this->id; }
    public function get_catalog_visibility() { return 'visible'; }
    public function is_type($type) { return is_array($type) ? in_array('simple', $type, true) : $type === 'simple'; }
}
class WC_Order_Item_Product {
    public function __construct(public int $product, public float $qty) {}
    public function get_variation_id() { return 0; }
    public function get_product_id() { return $this->product; }
    public function get_product() { return wc_get_product($this->product); }
    public function get_quantity() { return $this->qty; }
}
class WC_Order {
    public function __construct(public int $id, public int $customer, public array $items, public string $status = 'pc-draft') {}
    public function get_customer_id() { return $this->customer; }
    public function get_id() { return $this->id; }
    public function has_status($status) { return $this->status === $status; }
    public function get_item($id) { return $this->items[$id] ?? false; }
    public function get_items($type) { return $this->items; }
}

require __DIR__ . '/../inc/WaitlistModel.php';
require __DIR__ . '/../inc/WaitlistStore.php';
require __DIR__ . '/../inc/Waitlist.php';
require __DIR__ . '/../inc/PriceList.php';
use PaintCore\PCOE\WaitlistModel as Model;
use PaintCore\PCOE\WaitlistStore as Store;
use PaintCore\PCOE\Waitlist;

$checks = 0;
function expect($actual, $expected, $label) {
    global $checks;
    $checks++;
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . json_encode([$actual, $expected]));
}

foreach (['0', '-1', '1.5', '2e3', '100001', '', ' 2', '<script>', [], INF] as $bad) expect(Model::quantity($bad), 0, 'Reject invalid quantity');
expect(Model::quantity('12'), 12, 'Whole quantity');
expect(Model::quantity('100000'), 100000, 'Quantity upper bound');
expect(Model::remainder_reason(true, 2, 10, 2, 2), 'stock_shortage', 'Proven shortage');
expect(Model::remainder_reason(true, 2, 10, 2, 0), 'cart_rejected', 'Technical failure is not stockout evidence');
expect(Model::remainder_reason(true, 20, 10, 10, 2), 'cart_adjusted', 'Quantity filter is not stockout evidence');
expect(Model::remainder_reason(true, null, 10, 10, 10), 'none', 'No remainder needs no reason');
expect(Model::remainder_reason(true, null, 10, 0, 0), 'stock_unknown', 'Unknown stock');
expect(Model::remainder_reason(true, 12, 10, 2, 2), 'allocation_restricted', 'Selected warehouse restriction');
expect(Model::remainder_reason(false, 12, 10, 0, 0), 'not_purchasable', 'Unsellable product');

$source = ['product_id' => 10, 'quantity' => 10, 'status' => 'active', 'created_at' => 100];
$entries = ['manual' => $source, 'draft:1' => $source, 'draft:2' => array_merge($source, ['quantity' => 6])];
expect(Model::group($entries, 100)[10]['quantity'], 10, 'Overlapping demand does not add');
$entries['manual']['quantity'] = 7;
$entries['manual']['intent'] = 'partition-1';
$entries['draft:1']['quantity'] = 3;
$entries['draft:1']['intent'] = 'partition-1';
expect(Model::group($entries, 100)[10]['quantity'], 10, 'Explicit partial transfer retains total demand');
expect(Model::group(['a' => array_merge($source, ['snooze_until' => 101])], 100), [], 'Snooze suppresses');
expect(Model::group(['a' => array_merge($source, ['snooze_until' => 100])], 100)[10]['quantity'], 10, 'Snooze expires');
expect(Model::group(['a' => array_merge($source, ['status' => 'removed'])], 100), [], 'Removed source');

$GLOBALS['wpdb'] = new OfflineWpdb();
$a = Store::read(7); $b = Store::read(7);
expect(Store::save(7, $a), true, 'Initial write');
expect(Store::save(7, $b), false, 'Competing initial write loses');
$a = Store::read(7); $b = Store::read(7);
$a['pending'] = ['target' => 'cart'];
expect(Store::save(7, $a), true, 'Claim mutation receipt');
expect(Store::save(7, $b), false, 'Stale update rejected');
expect(Store::read(7)['pending']['target'], 'cart', 'Unknown result survives request end');
expect(Store::read(8)['entries'], [], 'Customer isolation');
$a = Store::read(7); $a['entries'] = ['manual' => $source]; $a['pending'] = null;
expect(Store::save(7, $a), true, 'Resolve receipt');
expect(Store::read(7)['entries']['manual']['quantity'], 10, 'State round trip');

expect(Waitlist::allowed(), false, 'Default disabled');
$GLOBALS['options'] = ['pcoe_waitlist_schema' => 1, 'pcoe_waitlist_enabled' => 'yes'];
expect(Waitlist::allowed(), false, 'Empty pilot denies everyone');
$GLOBALS['options']['pcoe_waitlist_pilot_user_id'] = 8;
expect(Waitlist::allowed(), false, 'Other customer denied');
expect(Waitlist::customer_allowed(8), false, 'Deleted pilot denied');
$GLOBALS['options']['pcoe_waitlist_pilot_user_id'] = 7;
expect(Waitlist::allowed(), true, 'Allowed customer');
$GLOBALS['allowed'] = false;
expect(Waitlist::allowed(), false, 'Role gate');
$GLOBALS['allowed'] = true; $GLOBALS['logged_in'] = false;
expect(Waitlist::allowed(), false, 'Guest gate');
$GLOBALS['logged_in'] = true;

$GLOBALS['products'][10] = new WC_Product(10);
$GLOBALS['orders'][21] = new WC_Order(21, 7, [31 => new WC_Order_Item_Product(10, 3)]);
$GLOBALS['orders'][22] = new WC_Order(22, 8, [31 => new WC_Order_Item_Product(10, 99)]);
$effective = new ReflectionMethod(Waitlist::class, 'effective_entries');
$tracked = array_merge($source, ['draft_id' => 21, 'item_id' => 31]);
expect($effective->invoke(null, ['x' => $tracked])['x']['quantity'], 3, 'Use current remainder');
expect($tracked['quantity'], 10, 'Keep original intent unchanged');
expect($effective->invoke(null, ['x' => array_merge($tracked, ['draft_id' => 22])])['x']['quantity'], 0, 'Never use another customer draft');
expect($effective->invoke(null, ['x' => array_merge($tracked, ['item_id' => 99])])['x']['quantity'], 0, 'Removed line is no longer demand');
$GLOBALS['orders'][21]->status = 'completed';
expect($effective->invoke(null, ['x' => $tracked])['x']['quantity'], 0, 'Closed draft excluded');
$GLOBALS['orders'][21]->status = 'pc-draft';
$subscribe = new ReflectionMethod(Waitlist::class, 'subscribe_draft');
$state = $subscribe->invoke(null, ['entries' => []], $GLOBALS['orders'][21], true);
expect(count($state['entries']), 1, 'Draft opt-in');
expect(count($subscribe->invoke(null, $state, $GLOBALS['orders'][21], true)['entries']), 1, 'Tracking toggle does not duplicate lines');
expect($subscribe->invoke(null, $state, $GLOBALS['orders'][21], false)['entries'], [], 'Draft opt-out');
echo "OK: $checks offline waitlist checks (SQLite CAS adapter; no live WordPress/MariaDB).\n";
