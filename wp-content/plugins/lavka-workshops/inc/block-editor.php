<?php
namespace Lavka\Workshops;
defined('ABSPATH') || exit;
require_once __DIR__ . '/patterns.php';

function workshop_editor_context($context): bool { return isset($context->post) && $context->post->post_type === 'lavka_workshop'; }
add_filter('use_block_editor_for_post_type', static fn($use, $type) => $type === 'lavka_workshop' ? true : $use, 100, 2);
add_filter('allowed_block_types_all', static function ($allowed, $context) {
    if (!workshop_editor_context($context)) return $allowed;
    return array_map(static fn($name) => 'core/' . $name, ['paragraph','heading','list','list-item','image','gallery','columns','column','media-text','group','cover','quote','pullquote','buttons','button','separator','spacer','video','audio','embed','table','details','freeform']);
}, 10, 2);
add_filter('block_editor_settings_all', static function ($settings, $context) {
    if (!workshop_editor_context($context)) return $settings;
    $settings['codeEditingEnabled'] = false;
    $settings['enableOpenverseMediaCategory'] = false;
    $settings['__experimentalBlockDirectory'] = false;
    $settings['styles'][] = ['css' => file_get_contents(dirname(FILE) . '/assets/publication.css')];
    return $settings;
}, 20, 2);
add_action('enqueue_block_editor_assets', static function () {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'lavka_workshop') return;
    wp_enqueue_script('lw-block-editor', plugins_url('assets/block-editor.js', FILE), ['wp-plugins','wp-editor','wp-blocks','wp-data','wp-element','wp-components','wp-block-editor'], VERSION, true);
    wp_enqueue_style('lw-block-editor', plugins_url('assets/block-editor.css', FILE), [], VERSION);
    wp_localize_script('lw-block-editor', 'lwStudio', [
        'patterns' => studio_patterns(),
        'previewLink' => get_preview_post_link(get_post()),
        'labels' => [
            'title' => __('Lavka · Publication studio', 'lavka-workshops'),
            'choose' => __('Choose a starting layout', 'lavka-workshops'),
            'intro' => __('Start with a finished composition, replace the text and add your photos. Every block can be moved, duplicated or removed.', 'lavka-workshops'),
            'blank' => __('Start with a blank page', 'lavka-workshops'),
            'patterns' => __('Add a composition', 'lavka-workshops'),
            'help' => __('Click text to write. Use the plus button for blocks and List View to move sections. Add the cover, excerpt and dates before publishing.', 'lavka-workshops'),
            'legacy' => __('Convert existing text to blocks', 'lavka-workshops'),
            'legacyHelp' => __('Your earlier article is preserved. Convert it to movable blocks, review the result, then save. Undo is available before saving.', 'lavka-workshops'),
            'saved' => __('View saved article', 'lavka-workshops'),
            'saveHelp' => __('Save first to view the latest article. Use Preview for unsaved changes.', 'lavka-workshops'),
        ],
    ]);
});
add_action('wp_enqueue_scripts', static function () {
    if (!is_singular('lavka_workshop')) return;
    wp_enqueue_style('lw-publication', plugins_url('assets/publication.css', FILE), ['lw-public','wp-block-library'], VERSION);
});

// Hide the unrelated cloud-template importer on workshop screens only.
add_action('enqueue_block_editor_assets', static function () {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'lavka_workshop') return;
    wp_dequeue_script('templately-gutenberg');
    wp_dequeue_script('templately');
    wp_dequeue_style('templately');
    wp_dequeue_style('templately-gutenberg');
    wp_dequeue_style('templately-tailwind');
}, 100);
