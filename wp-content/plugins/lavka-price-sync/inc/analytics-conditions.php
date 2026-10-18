<?php
if (!defined('ABSPATH')) exit;

function lps_analytics_group_branches($branches): array {
    if (!is_array($branches) || count($branches) > 100) throw new InvalidArgumentException(__('Use at most 100 valid group branches.', 'lavka-price-sync'));
    $out = [];
    foreach ($branches as $branch) {
        $path = $branch['path'] ?? null;
        $mode = $branch['mode'] ?? '';
        if (!in_array($mode, ['INCLUDE', 'EXCLUDE'], true) || !is_array($path) || !$path || count($path) > 6
            || !array_is_list($path)) throw new InvalidArgumentException(__('Invalid group branch path.', 'lavka-price-sync'));
        foreach ($path as $part) if (!is_string($part) || mb_strlen($part) > 255) throw new InvalidArgumentException(__('Invalid group branch path.', 'lavka-price-sync'));
        if (trim(end($path)) === '') throw new InvalidArgumentException(__('Invalid group branch path.', 'lavka-price-sync'));
        $out[] = ['mode' => $mode, 'path' => $path];
    }
    return $out;
}

function lps_analytics_conditions_enqueue(): void {
    $base = dirname(__DIR__);
    wp_enqueue_script('lps-analytics-conditions', plugins_url('assets/analytics-conditions.js', $base . '/lavka-price-sync.php'), [], filemtime($base . '/assets/analytics-conditions.js'), true);
    wp_enqueue_style('lps-analytics-conditions', plugins_url('assets/analytics-conditions.css', $base . '/lavka-price-sync.php'), [], filemtime($base . '/assets/analytics-conditions.css'));
    $messages = [
        'title' => __('Selected conditions', 'lavka-price-sync'), 'empty' => __('No conditions added.', 'lavka-price-sync'),
        'tree' => __('Product group tree', 'lavka-price-sync'), 'search' => __('Find a group or subgroup', 'lavka-price-sync'),
        'include' => __('Include branch', 'lavka-price-sync'), 'exclude' => __('Exclude branch', 'lavka-price-sync'),
        'included' => __('Included condition', 'lavka-price-sync'), 'excluded' => __('Excluded condition', 'lavka-price-sync'),
        'add' => __('Add condition', 'lavka-price-sync'), 'edit' => __('Edit condition', 'lavka-price-sync'), 'remove' => __('Remove condition', 'lavka-price-sync'),
        'toggle' => __('Switch include / exclude', 'lavka-price-sync'), 'blank' => __('No group at this level', 'lavka-price-sync'),
        'rule' => __('Included branches are combined. Excluded branches are removed from the result. Other conditions apply together.', 'lavka-price-sync'),
        'unavailable' => __('Group tree requires the updated Java service. Existing conditions are preserved.', 'lavka-price-sync'),
        'legacy' => __('Saved level condition', 'lavka-price-sync'), 'pending' => __('Press Add condition to apply your selection.', 'lavka-price-sync'),
    ];
    wp_localize_script('lps-analytics-conditions', 'LPS_CONDITIONS_I18N', $messages);
}
