<?php
/** WP-CLI only, in a disposable database. Never run against paint.local or production. */
if (!defined('WP_CLI') || !WP_CLI || !defined('PCOE_ISOLATED_WAITLIST_TEST') || !PCOE_ISOLATED_WAITLIST_TEST
    || DB_NAME !== 'pcoe_isolated' || strpos(ABSPATH, '/private/tmp/pcoe-waitlist-') !== 0
    || !str_starts_with(DB_HOST, '127.0.0.1:')) throw new RuntimeException('Disposable waitlist test database required.');
add_filter('pre_http_request', static fn() => new WP_Error('offline', 'HTTP blocked'), PHP_INT_MAX);
add_filter('pre_wp_mail', static fn() => true, PHP_INT_MAX);

use PaintCore\PCOE\Waitlist;
use PaintCore\PCOE\WaitlistStore as Store;
use PaintCore\PCOE\WaitlistModel as Model;
use PaintCore\PCOE\DraftFolioWorkflow;

function check_waitlist($condition, $label) { if (!$condition) throw new RuntimeException($label); }
class WaitlistTestRedirect extends Exception {}
remove_all_filters('wp_redirect');
add_filter('wp_redirect', static function ($url) { throw new WaitlistTestRedirect($url); });

Store::install();
update_option('pcoe_waitlist_enabled', 'yes', false);
add_role('partner', 'Partner', ['read' => true]);
$user = wp_insert_user(['user_login' => 'waitlist_' . wp_generate_password(8, false), 'user_pass' => wp_generate_password(), 'role' => 'partner']);
check_waitlist(!is_wp_error($user), 'Create isolated customer');
update_option('pcoe_waitlist_pilot_user_id', $user, false);
wp_set_current_user($user);
WC()->initialize_session();
WC()->session->set('pc_alloc_pref', ['mode' => 'auto', 'term_id' => 0]);
WC()->customer = new WC_Customer($user);
WC()->cart = new WC_Cart();
check_waitlist(Waitlist::allowed(), 'Role gate');

$product = new WC_Product_Simple();
$product->set_name('KREUL — тестовий товар');
$product->set_sku('WAITLIST-' . $user);
$product->set_status('publish');
$product->set_regular_price('12');
$product->set_manage_stock(true);
$product->set_stock_quantity(20);
$product->save();
$product->update_meta_data('_stock_at_101', 20);
$product->update_meta_data('_stock_at_102', 0);
$product->update_meta_data('_wc_gtin_code', '04000798123456');
$product->save();
$source = ['product_id' => $product->get_id(), 'quantity' => 10, 'requested' => 10, 'created_at' => time(), 'status' => 'active', 'reason' => 'explicit_waitlist', 'intent' => wp_generate_uuid4()];
$state = Store::read($user);
$state['entries']['manual:' . $product->get_id()] = $source;
check_waitlist(Store::save($user, $state), 'MariaDB insert');
check_waitlist(!Store::save($user, $state), 'MariaDB rejects duplicate initial revision');
$state = Store::read($user);
$stale = $state;
check_waitlist(Store::save($user, $state), 'MariaDB CAS');
check_waitlist(!Store::save($user, $stale), 'MariaDB rejects stale revision');

$transfer = new ReflectionMethod(Waitlist::class, 'transfer');
$_POST['quantity'] = '3';
$state = Store::read($user);
$group = Model::group($state['entries'], time())[$product->get_id()];
try { $transfer->invoke(null, $user, $state, $product->get_id(), $group, 'draft'); } catch (WaitlistTestRedirect $done) {}
$state = Store::read($user);
check_waitlist(empty($state['pending']), 'Draft receipt completed');
check_waitlist(Model::group($state['entries'], time())[$product->get_id()]['quantity'] === 10, 'Partial draft transfer preserves 10 units of intent');
$draft_source = current(array_filter($state['entries'], static fn($entry) => !empty($entry['draft_id'])));
$draft = wc_get_order($draft_source['draft_id']);
check_waitlist($draft instanceof WC_Order && (int) $draft->get_customer_id() === $user && $draft->has_status('pc-draft'), 'Owned draft created');
check_waitlist((int) $draft->get_item_count() === 3, 'Draft selected quantity');
check_waitlist(WC()->cart->is_empty(), 'Draft transfer preserves cart');

$before = Store::read($user);
ob_start(); Waitlist::render(); $html = ob_get_clean();
check_waitlist(str_contains($html, 'WAITLIST-' . $user) && str_contains($html, '04000798123456'), 'Rendered SKU and confirmed barcode');
check_waitlist(Store::read($user) === $before, 'Rendering is read-only');
check_waitlist($draft->get_meta('_pcoe_track_waitlist') === 'yes', 'Explicit transfer records draft tracking');

// The fixture supplies location stock, while the actual allocator and Woo cart run.
$_POST['quantity'] = '4';
$state = Store::read($user);
$group = Model::group($state['entries'], time())[$product->get_id()];
try { $transfer->invoke(null, $user, $state, $product->get_id(), $group, 'cart'); } catch (WaitlistTestRedirect $done) {}
check_waitlist((int) WC()->cart->get_cart_contents_count() === 4, 'Real Woo cart addition');
check_waitlist(empty(Store::read($user)['pending']), 'Cart receipt completed');
$_POST['quantity'] = '7';
$rejected = false;
try { $transfer->invoke(null, $user, Store::read($user), $product->get_id(), $group, 'cart'); } catch (RuntimeException $e) { $rejected = true; }
check_waitlist($rejected && (int) WC()->cart->get_cart_contents_count() === 4, 'Existing cart prevents excess addition');

$analyse = new ReflectionMethod(DraftFolioWorkflow::class, 'analyse');
$prepare = new ReflectionMethod(DraftFolioWorkflow::class, 'prepare_cart_and_remainder');
$analysis = $analyse->invoke(null, $draft, 'partial_to_cart');
$prepare->invoke(null, $draft, $analysis);
$draft = wc_get_order($draft->get_id());
$events = array_values($draft->get_meta('_pcoe_demand_event', false));
check_waitlist(count($events) === 2, 'Durable before/after evidence');
$started = $events[0]->get_data()['value'];
$finished = $events[1]->get_data()['value'];
check_waitlist($started['phase'] === 'started' && $finished['phase'] === 'cart_prepared', 'Evidence phases');
check_waitlist($started['attempt'] === $finished['attempt'], 'Evidence attempt identity');
check_waitlist((float) current($finished['rows'])['requested'] === 3.0, 'Original quantity preserved after line deletion');
check_waitlist((float) current($finished['rows'])['cart_added'] === 3.0, 'Actual cart quantity preserved');

$partial = wc_create_order(['status' => 'wc-pc-draft', 'customer_id' => $user]);
$partial->add_product($product, 10);
$partial->save();
$_POST['track_waitlist'] = '1';
Waitlist::track_created($partial);
check_waitlist($partial->get_meta('_pcoe_track_waitlist') === 'yes', 'Draft creation consent recorded');
$quantity_filter = static fn($quantity) => 2;
add_filter('woocommerce_add_to_cart_quantity', $quantity_filter, 100);
$partial_analysis = $analyse->invoke(null, $partial, 'partial_to_cart');
$actual = $prepare->invoke(null, $partial, $partial_analysis);
remove_filter('woocommerce_add_to_cart_quantity', $quantity_filter, 100);
check_waitlist((float) $actual['loadable_total'] === 2.0 && (float) $actual['unavailable_total'] === 8.0, 'Actual hook-adjusted quantity leaves correct remainder');
$partial = wc_get_order($partial->get_id());
check_waitlist((int) $partial->get_item_count() === 8, 'Draft retains the unadded 8 units');
$events = array_values($partial->get_meta('_pcoe_demand_event', false));
check_waitlist(current($events[1]->get_data()['value']['rows'])['reason'] === 'cart_adjusted', 'Adjusted quantity is not classified as shortage');

// Fail one real Woo add: all source quantity must stay in the draft.
$reject = static fn() => false;
add_filter('woocommerce_add_to_cart_validation', $reject, 100);
$actual = $prepare->invoke(null, $partial, $analyse->invoke(null, $partial, 'partial_to_cart'));
remove_filter('woocommerce_add_to_cart_validation', $reject, 100);
check_waitlist((float) $actual['loadable_total'] === 0.0 && (float) $actual['unavailable_total'] === 8.0, 'Rejected cart addition does not consume demand');

// Actual request gates, before any mutation.
class WaitlistTestDenied extends Exception {}
add_filter('wp_die_handler', static fn() => static function () { throw new WaitlistTestDenied(); });
$_SERVER['REQUEST_METHOD'] = 'GET';
$denied = false;
try { Waitlist::handle(); } catch (WaitlistTestDenied $e) { $denied = true; }
check_waitlist($denied, 'Mutation rejects GET');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST['_wpnonce'] = 'invalid';
$denied = false;
try { Waitlist::handle(); } catch (WaitlistTestDenied $e) { $denied = true; }
check_waitlist($denied, 'Mutation rejects invalid nonce');

// Render actual PHP output for layout inspection; no customer data in the fixture.
$state = Store::read($user);
$state['entries']['manual:' . $product->get_id()]['quantity'] = 7;
Store::save($user, $state);
load_textdomain('pc-order-import-export', PCOE_DIR . '/languages/pc-order-import-export-uk.mo');
ob_start(); Waitlist::render(); $html = ob_get_clean();
$css = file_get_contents(PCOE_DIR . '/assets/waitlist.css');
$page = '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Список очікування — ізольована перевірка</title><style>body{font:16px/1.5 system-ui;background:#f5f7f5;color:#203126;margin:0;padding:24px}main{max-width:980px;margin:auto;background:white;padding:24px}input,button{font:inherit;padding:10px;border:1px solid #bcc9be;border-radius:4px}button{background:#eef4ef}a{color:#24673f}*{box-sizing:border-box}' . $css . '</style><main>' . $html . '</main></html>';
file_put_contents(dirname(ABSPATH) . '/waitlist-preview.html', $page);
WP_CLI::success('Isolated WordPress/MariaDB: setup, CAS, partial draft, actual cart, read-only render and durable evidence passed.');
