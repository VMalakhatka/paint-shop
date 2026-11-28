<?php
/**
 * Plugin Name: Lavka Temporary AJAX JSON Compatibility
 * Description: Read WordPress AJAX data on the legacy PHP host pending migration.
 */
defined('ABSPATH') || exit;

function lavka_enqueue_ajax_json_compat() {
    if (defined('LAVKA_AJAX_JSON_COMPAT') && !LAVKA_AJAX_JSON_COMPAT) {
        return;
    }
    $handle = 'lavka-ajax-json-compat';
    $asset = 'lavka-ajax-json-compat/client.js';
    wp_enqueue_script($handle, plugins_url($asset, __FILE__), [], '1.0.2', false);
    // jQuery can be printed before or after our script, or not requested at all.
    wp_add_inline_script('jquery-core', 'if (window.lavkaAttachAjaxJson) window.lavkaAttachAjaxJson(window.jQuery);', 'after');
}
add_action('admin_enqueue_scripts', 'lavka_enqueue_ajax_json_compat', 0);
add_action('wp_enqueue_scripts', 'lavka_enqueue_ajax_json_compat', 0);
