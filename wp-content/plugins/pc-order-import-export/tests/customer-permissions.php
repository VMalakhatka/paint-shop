<?php
/** Isolated policy regression; no DB, email or HTTP. */
define('ABSPATH', __DIR__);
class WP_User {
    public $ID; public $roles; public $allcaps;
    public function __construct($id, $roles, $caps = []) {
        $this->ID = $id; $this->roles = $roles;
        $this->allcaps = array_merge(array_fill_keys($roles, true), ['read' => true], $caps);
    }
    public function exists() { return $this->ID > 0; }
}
function is_multisite() { return $GLOBALS['multi'] ?? false; }
function get_userdata($id) { return $GLOBALS['users'][$id] ?? false; }
function wp_get_current_user() { return get_userdata($GLOBALS['actor']); }
function add_filter(...$args) { $GLOBALS['hooks'][] = $args; }
require dirname(__DIR__) . '/inc/CustomerPermissions.php';
use PaintCore\PCOE\CustomerPermissions as Policy;
$users = [1 => new WP_User(1, ['shop_manager'], ['manage_woocommerce' => true]),
    2 => new WP_User(2, ['administrator'], ['manage_options' => true]),
    3 => new WP_User(3, ['customer']), 4 => new WP_User(4, ['opt']),
    5 => new WP_User(5, ['partner']), 6 => new WP_User(6, ['customer', 'editor']),
    7 => new WP_User(7, ['opt'], ['edit_posts' => true]),
    8 => new WP_User(8, []), 9 => new WP_User(9, ['schule']),
    10 => new WP_User(10, ['opt_osn']), 11 => new WP_User(11, ['customer', 'administrator'])];
$actor = 1; $checks = 0;
function check($ok, $message) { global $checks; if (!$ok) throw new RuntimeException($message); ++$checks; }
Policy::hooks(); check(count($GLOBALS['hooks']) === 4, 'hooks registered');
foreach ([3,4,5,9,10] as $id) {
    foreach (['edit_user', 'promote_user'] as $cap) check(Policy::target_caps(['edit_users'], $cap, 1, [$id]) === ['edit_users'], 'customer allowed');
    check(Policy::target_caps(['do_not_allow'], 'edit_user', 1, [$id]) === ['do_not_allow'], 'external denial preserved');
}
foreach ([2,6,7,8,11,999] as $id) foreach (['edit_user','promote_user','delete_user','remove_user'] as $cap) {
    check(Policy::target_caps(['edit_users'], $cap, 1, [$id]) === ['do_not_allow'], 'unsafe target blocked');
}
check(Policy::target_caps(['read'], 'edit_user', 1, [1]) === ['read'], 'self edit unchanged');
check(Policy::target_caps(['promote_users'], 'promote_user', 1, [1]) === ['do_not_allow'], 'self promote blocked');
check(Policy::target_caps(['edit_users'], 'edit_user', 2, [1]) === ['edit_users'], 'admin unchanged');
$caps = Policy::capabilities([], [], [], $users[1]);
foreach (['edit_users','promote_users','list_users'] as $cap) check($caps[$cap], 'grant');
foreach (['create_users','delete_users','remove_users'] as $cap) check(!$caps[$cap], 'destructive denied');
check(Policy::capabilities([], [], [], $users[3]) === [], 'customer not granted');
$roles = []; foreach (['customer','opt','partner','opt_osn','schule','administrator','shop_manager','editor'] as $r) $roles[$r] = ['capabilities' => ['read'=>true]];
check(count(Policy::editable_roles($roles)) === 5, 'customer role allowlist');
$roles['opt']['capabilities']['manage_options'] = true;
check(!isset(Policy::editable_roles($roles)['opt']), 'elevated category blocked');
$multi = true; check(Policy::capabilities([], [], [], $users[1]) === [], 'no multisite grants');
echo "PASS: $checks customer permission checks\n";
