<?php
/**
 * Plugin Name: Lavka Workshops
 * Description: Photo-led workshop schedule, visual articles and booking requests.
 * Version: 2.1.0
 * Requires PHP: 8.1
 * Text Domain: lavka-workshops
 * Domain Path: /languages
 */
namespace Lavka\Workshops;
defined('ABSPATH') || exit;
const VERSION = '2.1.0';
const FILE = __FILE__;
require_once __DIR__ . '/inc/visibility.php';
require_once __DIR__ . '/inc/sessions.php';
require_once __DIR__ . '/inc/block-editor.php';
require_once __DIR__ . '/inc/admin.php';
require_once __DIR__ . '/inc/frontend.php';
require_once __DIR__ . '/inc/bookings.php';

function register(): void {
    load_plugin_textdomain('lavka-workshops', false, dirname(plugin_basename(FILE)) . '/languages');
    register_post_type('lavka_workshop', [
        'labels' => ['name' => __('Workshops', 'lavka-workshops'), 'singular_name' => __('Workshop', 'lavka-workshops'),
            'add_new_item' => __('Add workshop', 'lavka-workshops'), 'edit_item' => __('Edit workshop', 'lavka-workshops'),
            'featured_image' => __('Cover photo', 'lavka-workshops'), 'set_featured_image' => __('Choose cover photo', 'lavka-workshops')],
        'public' => true, 'has_archive' => 'master-klasy', 'rewrite' => ['slug' => 'master-klas'],
        'menu_icon' => 'dashicons-art', 'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author'],
        'capability_type' => ['lavka_workshop', 'lavka_workshops'], 'map_meta_cap' => true,
        'show_in_rest' => true,
        'exclude_from_search' => section_closed(),
    ]);
    register_post_type('lavka_mk_request', [
        'labels' => ['name' => __('Booking requests', 'lavka-workshops'), 'singular_name' => __('Booking request', 'lavka-workshops'), 'edit_item' => __('Booking request', 'lavka-workshops')],
        'public' => false, 'show_ui' => true, 'show_in_menu' => 'edit.php?post_type=lavka_workshop',
        'supports' => false, 'capability_type' => ['lavka_mk_request', 'lavka_mk_requests'], 'map_meta_cap' => true,
        'capabilities' => ['create_posts' => 'do_not_allow'],
    ]);
}
add_action('init', __NAMESPACE__ . '\\register');

function activate(): void {
    register();
    $caps = ['read' => true, 'upload_files' => true];
    foreach (['lavka_workshops', 'lavka_mk_requests'] as $type) {
        foreach (['edit_', 'edit_others_', 'publish_', 'read_private_', 'delete_', 'delete_private_', 'delete_published_', 'delete_others_', 'edit_private_', 'edit_published_'] as $prefix) {
            $caps[$prefix . $type] = true;
        }
    }
    add_role('lavka_instructor', __('Workshop instructor', 'lavka-workshops'), $caps);
    foreach (['administrator', 'lavka_instructor'] as $role_name) {
        $role = get_role($role_name);
        if ($role) foreach ($caps as $cap => $enabled) $role->add_cap($cap);
    }
    flush_rewrite_rules(false);
}
register_activation_hook(FILE, __NAMESPACE__ . '\\activate');
register_deactivation_hook(FILE, static function (): void {
    unregister_post_type('lavka_workshop');
    flush_rewrite_rules(false);
});
