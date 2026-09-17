<?php
if (!defined('ABSPATH')) exit;

/** Explicit migration only: never change sidebar assignments during frontend requests. */
final class PSU_Category_Menu_Migration {
    const JOURNAL = 'psu_category_menu_migration_v1';
    const LOCK = 'psu_category_menu_migration_lock';
    private static $result = null;

    public static function plan() {
        global $wp_registered_sidebars;
        $before = [
            'sidebars_widgets' => get_option('sidebars_widgets', []),
            'widget_psu_category_menu' => get_option('widget_psu_category_menu', false),
        ];
        $after = $before;
        $after['widget_psu_category_menu'] = is_array($before['widget_psu_category_menu']) ? $before['widget_psu_category_menu'] : [];
        $numbers = array_filter(array_keys($after['widget_psu_category_menu']), 'is_numeric');
        $next = $numbers ? max(2, max($numbers) + 1) : 2;
        $replacements = []; $skipped = []; $source = get_option('widget_wpb_wmca_accordion_widget', []);
        foreach ($before['sidebars_widgets'] as $sidebar => $ids) {
            if (!isset($wp_registered_sidebars[$sidebar]) || !is_array($ids)) continue;
            foreach ($ids as $position => $id) {
                if (!preg_match('/^wpb_wmca_accordion_widget-(\d+)$/D', $id, $match)) continue;
                $config = PSU_Category_Menu::legacy_config($id);
                if (!$config) { $skipped[] = $id; continue; }
                if (!isset($replacements[$id])) {
                    $number = $next++;
                    $settings = PSU_Category_Widget::settings(array_merge($config, ['title' => $source[(int) $match[1]]['title'] ?? '']));
                    $new_id = 'psu_category_menu-' . $number;
                    $after['widget_psu_category_menu'][$number] = $settings;
                    $replacements[$id] = ['from' => $id, 'to' => $new_id, 'settings' => $settings];
                }
                $after['sidebars_widgets'][$sidebar][$position] = $replacements[$id]['to'];
            }
        }
        if ($replacements) {
            $after['widget_psu_category_menu']['_multiwidget'] = 1;
            // WordPress parks unassigned instances here when the Widgets screen is opened.
            $inactive = $after['sidebars_widgets']['wp_inactive_widgets'] ?? [];
            $after['sidebars_widgets']['wp_inactive_widgets'] = array_values(array_unique(array_merge($inactive, array_keys($replacements))));
        }
        return ['before' => $before, 'after' => $after, 'replacements' => $replacements, 'skipped' => array_values(array_unique($skipped))];
    }

    public static function fingerprint($plan) { return hash('sha256', wp_json_encode($plan)); }

    private static function error($code) {
        return new WP_Error($code, __('Category menu settings changed or could not be saved. Reload this page and check the saved migration before retrying.', 'paint-shop-ux'));
    }

    private static function store($name, $value) {
        if ($value === false) delete_option($name);
        else update_option($name, $value, false);
        return get_option($name, false) === $value;
    }

    private static function matches($name, $current, $expected, $replacements) {
        if ($name === 'sidebars_widgets' && is_array($current) && is_array($expected)) {
            // The block editor removes inactive instances of disabled plugins, then restores them on activation.
            // Ignore only the parked source widgets, never active placement or unrelated inactive widgets.
            $sources = array_keys($replacements);
            $current['wp_inactive_widgets'] = array_values(array_diff($current['wp_inactive_widgets'] ?? [], $sources));
            $expected['wp_inactive_widgets'] = array_values(array_diff($expected['wp_inactive_widgets'] ?? [], $sources));
        }
        return $current === $expected;
    }

    public static function apply($expected) {
        if (!current_user_can('edit_theme_options')) return new WP_Error('forbidden', __('Access denied.', 'paint-shop-ux'));
        if (!add_option(self::LOCK, time(), '', false)) return self::error('locked');
        try {
            $saved = get_option(self::JOURNAL, []);
            if (!empty($saved) && ($saved['status'] ?? '') !== 'restored') return self::error('already_saved');
            $plan = self::plan();
            if (!hash_equals(self::fingerprint($plan), (string) $expected)) return self::error('changed');
            if (!$plan['replacements']) return self::error('nothing_to_migrate');
            $saved = $plan + ['status' => 'prepared', 'created_at' => gmdate('c')];
            if (!self::store(self::JOURNAL, $saved)) return self::error('backup_failed');
            // Save instances first, then switch sidebars. The prepared journal permits recovery after interruption.
            foreach (['widget_psu_category_menu', 'sidebars_widgets'] as $name) {
                if (get_option($name, false) !== $plan['before'][$name] || !self::store($name, $plan['after'][$name])) return self::error('write_failed');
            }
            $saved['status'] = 'completed';
            if (!self::store(self::JOURNAL, $saved)) return self::error('journal_failed');
            return $saved;
        } finally { delete_option(self::LOCK); }
    }

    public static function rollback() {
        if (!current_user_can('edit_theme_options')) return new WP_Error('forbidden', __('Access denied.', 'paint-shop-ux'));
        if (!class_exists('WPB_Accordion_Menu_Widget')) return new WP_Error('wpb_missing', __('Activate WPB before restoring its widgets.', 'paint-shop-ux'));
        if (!add_option(self::LOCK, time(), '', false)) return self::error('locked');
        try {
            $saved = get_option(self::JOURNAL, []);
            if (!in_array($saved['status'] ?? '', ['prepared', 'completed'], true)) return self::error('no_backup');
            foreach ($saved['before'] as $name => $value) {
                $current = get_option($name, false);
                if (!self::matches($name, $current, $value, $saved['replacements'])
                    && !self::matches($name, $current, $saved['after'][$name], $saved['replacements'])) return self::error('rollback_conflict');
            }
            // Restore placement before removing the native instances; never overwrite later widget edits.
            foreach (['sidebars_widgets', 'widget_psu_category_menu'] as $name) {
                if (!self::store($name, $saved['before'][$name])) return self::error('restore_failed');
            }
            $saved['status'] = 'restored';
            if (!self::store(self::JOURNAL, $saved)) return self::error('journal_failed');
            return $saved;
        } finally { delete_option(self::LOCK); }
    }

    public static function handle_post() {
        if (!current_user_can('edit_theme_options')) wp_die(esc_html__('Access denied.', 'paint-shop-ux'));
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_admin_referer('psu_category_menu_migrate');
            $action = sanitize_key(wp_unslash($_POST['menu_action'] ?? ''));
            if ($action === 'migrate') self::$result = self::apply(sanitize_text_field(wp_unslash($_POST['plan'] ?? '')));
            elseif ($action === 'restore') self::$result = self::rollback();
            if (self::$result !== null && !is_wp_error(self::$result)) {
                wp_safe_redirect(add_query_arg('saved', '1', admin_url('themes.php?page=psu-category-menu')));
                exit;
            }
        }
    }

    public static function page() {
        if (!current_user_can('edit_theme_options')) wp_die(esc_html__('Access denied.', 'paint-shop-ux'));
        $result = self::$result;
        $plan = self::plan(); $saved = get_option(self::JOURNAL, []);
        echo '<div class="wrap"><h1>' . esc_html__('Lavka categories', 'paint-shop-ux') . '</h1>';
        if ($result !== null || (!empty($_GET['saved']) && $saved)) echo '<div class="notice ' . (is_wp_error($result) ? 'notice-error' : 'notice-success') . '"><p>' . esc_html(is_wp_error($result) ? $result->get_error_message() : __('Category menu settings saved.', 'paint-shop-ux')) . '</p></div>';
        echo '<p><a href="' . esc_url(admin_url('widgets.php')) . '">' . esc_html__('Edit widgets', 'paint-shop-ux') . '</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Current widget', 'paint-shop-ux') . '</th><th>' . esc_html__('Replacement', 'paint-shop-ux') . '</th></tr></thead><tbody>';
        foreach ($plan['replacements'] as $row) echo '<tr><td>' . esc_html($row['from']) . '</td><td>' . esc_html($row['to']) . '</td></tr>';
        if (!$plan['replacements']) echo '<tr><td colspan="2">' . esc_html__('No category widgets to migrate.', 'paint-shop-ux') . '</td></tr>';
        echo '</tbody></table>';
        foreach ($plan['skipped'] as $id) echo '<p>' . esc_html__('Unsupported WPB widget left unchanged:', 'paint-shop-ux') . ' ' . esc_html($id) . '</p>';
        if ($plan['replacements'] && (!$saved || ($saved['status'] ?? '') === 'restored')) self::button('migrate', __('Replace category widgets', 'paint-shop-ux'), self::fingerprint($plan));
        if (in_array($saved['status'] ?? '', ['prepared', 'completed'], true)) {
            echo '<p>' . esc_html__('Migration backup is available.', 'paint-shop-ux') . '</p>';
            self::button('restore', __('Restore WPB widgets', 'paint-shop-ux'));
        }
        echo '<p>' . esc_html__('WPB is not disabled automatically. Check its other widgets, shortcodes and templates before deactivation. To restore the old menu, activate WPB first.', 'paint-shop-ux') . '</p></div>';
    }

    private static function button($action, $label, $plan = '') {
        echo '<form method="post">'; wp_nonce_field('psu_category_menu_migrate');
        echo '<input type="hidden" name="menu_action" value="' . esc_attr($action) . '"><input type="hidden" name="plan" value="' . esc_attr($plan) . '">';
        submit_button($label, 'secondary'); echo '</form>';
    }
}

add_action('admin_menu', static function () {
    $hook = add_theme_page(__('Lavka categories', 'paint-shop-ux'), __('Lavka categories', 'paint-shop-ux'), 'edit_theme_options', 'psu-category-menu', [PSU_Category_Menu_Migration::class, 'page']);
    add_action('load-' . $hook, [PSU_Category_Menu_Migration::class, 'handle_post']);
});
