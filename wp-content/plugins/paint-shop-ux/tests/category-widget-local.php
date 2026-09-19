<?php
// Local migration test. All widget options and the current user are restored in finally.
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local') throw new RuntimeException('Local CLI only');
if (!class_exists('WPB_Accordion_Menu_Widget')) throw new RuntimeException('Run before deactivating WPB');
function psu_widget_check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$names = ['sidebars_widgets', 'widget_psu_category_menu', PSU_Category_Menu_Migration::JOURNAL];
$original = [];
foreach ($names as $name) $original[$name] = get_option($name, false);
if (get_option(PSU_Category_Menu_Migration::LOCK, false) !== false) throw new RuntimeException('Migration locked');
if (!empty($original[PSU_Category_Menu_Migration::JOURNAL]) && ($original[PSU_Category_Menu_Migration::JOURNAL]['status'] ?? '') !== 'restored') throw new RuntimeException('Existing migration: do not overwrite');
$user = get_current_user_id();
$admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
if (!$admins) throw new RuntimeException('No local administrator');
try {
    $widget = new PSU_Category_Widget();
    $settings = $widget->update(['title' => '<b>Test</b>', 'orderby' => 'invalid', 'order' => 'invalid'], []);
    psu_widget_check($settings === ['title'=>'Test','orderby'=>'name','order'=>'ASC','hide_empty'=>false,'show_count'=>false], 'Widget input sanitation and unchecked checkboxes');
    psu_widget_check(PSU_Category_Widget::settings([])['hide_empty'], 'New widgets hide empty categories by default');
    $plan = PSU_Category_Menu_Migration::plan();
    psu_widget_check(count($plan['replacements']) > 0 && !$plan['skipped'], 'Published category widgets eligible');
    $reserved = $plan['before']['widget_psu_category_menu'];
    $reserved[41] = ['title'=>'Existing native menu', 'orderby'=>'count', 'order'=>'DESC', 'show_count'=>true];
    add_filter('pre_option_widget_psu_category_menu', $reserve = static function () use ($reserved) { return $reserved; });
    $collision_plan = PSU_Category_Menu_Migration::plan();
    remove_filter('pre_option_widget_psu_category_menu', $reserve);
    psu_widget_check(reset($collision_plan['replacements'])['to'] === 'psu_category_menu-42'
        && $collision_plan['after']['widget_psu_category_menu'][41] === $reserved[41], 'Existing native instances never overwritten');
    wp_set_current_user(0);
    psu_widget_check(PSU_Category_Menu_Migration::apply(PSU_Category_Menu_Migration::fingerprint($plan))->get_error_code() === 'forbidden', 'Guests cannot migrate');
    wp_set_current_user((int) $admins[0]);
    psu_widget_check(is_wp_error(PSU_Category_Menu_Migration::apply('stale')), 'Stale preview refused');
    add_option(PSU_Category_Menu_Migration::LOCK, time(), '', false);
    psu_widget_check(is_wp_error(PSU_Category_Menu_Migration::apply(PSU_Category_Menu_Migration::fingerprint($plan))), 'Concurrent migration refused');
    delete_option(PSU_Category_Menu_Migration::LOCK);
    $source_widgets = get_option('widget_wpb_wmca_accordion_widget');
    $result = PSU_Category_Menu_Migration::apply(PSU_Category_Menu_Migration::fingerprint($plan));
    psu_widget_check(!is_wp_error($result) && $result['status'] === 'completed', 'Migration completed');
    psu_widget_check(get_option('sidebars_widgets') === $plan['after']['sidebars_widgets'], 'Sidebar order and unrelated widgets preserved');
    psu_widget_check(get_option('widget_wpb_wmca_accordion_widget') === $source_widgets, 'Original WPB settings retained');
    // WordPress caches sidebar assignments within a request; simulate the next frontend request.
    global $_wp_sidebars_widgets;
    $_wp_sidebars_widgets = null;
    $widget->_register();
    foreach ($plan['replacements'] as $row) {
        psu_widget_check(PSU_Category_Menu::config($row['to']) === array_merge($row['settings'], ['widget'=>$row['to']]), 'Native API config independent of WPB settings');
        psu_widget_check(PSU_Category_Menu::config($row['from']) === null, 'Unassigned old widget is not a public API source');
        $first = PSU_Category_Menu::branch(PSU_Category_Menu::config($row['to']), 0);
        psu_widget_check(!is_wp_error($first) && count($first['items']) > 0, 'Native branch API returns categories');
    }
    psu_widget_check(is_wp_error(PSU_Category_Menu_Migration::apply(PSU_Category_Menu_Migration::fingerprint(PSU_Category_Menu_Migration::plan()))), 'Repeat migration cannot duplicate widgets');
    $changed = get_option('widget_psu_category_menu');
    $key = (int) substr(reset($plan['replacements'])['to'], strlen('psu_category_menu-'));
    $changed[$key]['title'] = 'Later operator edit';
    update_option('widget_psu_category_menu', $changed);
    psu_widget_check(is_wp_error(PSU_Category_Menu_Migration::rollback()), 'Rollback refuses to overwrite later edits');
    update_option('widget_psu_category_menu', $plan['after']['widget_psu_category_menu']);
    $housekeeping = get_option('sidebars_widgets');
    $housekeeping['wp_inactive_widgets'] = array_values(array_diff($housekeeping['wp_inactive_widgets'], array_keys($plan['replacements'])));
    update_option('sidebars_widgets', $housekeeping);
    psu_widget_check(!is_wp_error(PSU_Category_Menu_Migration::rollback()), 'Rollback succeeds without conflicts');
    psu_widget_check(get_option('sidebars_widgets') === $plan['before']['sidebars_widgets'], 'Rollback tolerates only WordPress cleanup of migrated inactive widgets');
    psu_widget_check(get_option('sidebars_widgets') === $plan['before']['sidebars_widgets'], 'Rollback restores original placement');
    psu_widget_check(get_option('widget_psu_category_menu') === $plan['before']['widget_psu_category_menu'], 'Rollback restores original native settings');
    // Recover an interrupted apply after the first option write but before sidebar replacement.
    update_option(PSU_Category_Menu_Migration::JOURNAL, $plan + ['status'=>'prepared']);
    update_option('widget_psu_category_menu', $plan['after']['widget_psu_category_menu']);
    psu_widget_check(!is_wp_error(PSU_Category_Menu_Migration::rollback()), 'Prepared journal recovers partial apply');
    psu_widget_check(get_option('sidebars_widgets') === $plan['before']['sidebars_widgets'], 'Partial recovery preserves original sidebar');
    $_wp_sidebars_widgets = null;
    $fresh_plan = PSU_Category_Menu_Migration::plan();
    add_filter('pre_update_option_sidebars_widgets', $fail_write = static function ($new, $old) { return $old; }, 10, 2);
    $failed = PSU_Category_Menu_Migration::apply(PSU_Category_Menu_Migration::fingerprint($fresh_plan));
    remove_filter('pre_update_option_sidebars_widgets', $fail_write);
    psu_widget_check(is_wp_error($failed) && $failed->get_error_code() === 'write_failed', 'Failed sidebar write reported explicitly');
    psu_widget_check(get_option(PSU_Category_Menu_Migration::JOURNAL)['status'] === 'prepared', 'Failed write retains recovery journal');
    psu_widget_check(!is_wp_error(PSU_Category_Menu_Migration::rollback()), 'Failed write can be restored');
} finally {
    foreach ($original as $name => $value) {
        if ($value === false) delete_option($name);
        else update_option($name, $value);
    }
    delete_option(PSU_Category_Menu_Migration::LOCK);
    $_wp_sidebars_widgets = null;
    wp_set_current_user($user);
}
