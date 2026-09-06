<?php
if (!defined('ABSPATH')) exit;

function lps_availability_statuses(): array {
    return ['MEASURED', 'NOT_APPLICABLE', 'POLICY_NOT_CONFIRMED', 'DATA_INCOMPLETE', 'PERIOD_OUTSIDE_HORIZON', 'SNAPSHOT_NOT_READY', 'CONTEXT_REQUIRED'];
}

// Scenario settings contain context and filters, never a copied global group membership.
function lps_availability_profile(array $input): array {
    $enabled = !empty($input['enabled']);
    $filter = [];
    foreach (['availabilityPercent', 'stockoutPercent'] as $metric) {
        foreach (['From', 'To'] as $suffix) {
            $key = $metric . $suffix;
            $value = $input['filter'][$key] ?? null;
            if ($value === null || $value === '') continue;
            if (!is_numeric($value) || !is_finite((float)$value) || $value < 0 || $value > 100) {
                wp_send_json_error(['message' => __('Invalid availability context or filter.', 'lavka-price-sync')], 400);
            }
            $filter[$key] = (float)$value;
        }
        if (isset($filter[$metric . 'From'], $filter[$metric . 'To']) && $filter[$metric . 'From'] > $filter[$metric . 'To']) {
            wp_send_json_error(['message' => __('Invalid availability context or filter.', 'lavka-price-sync')], 400);
        }
    }
    $statuses = array_values(array_unique(array_map('strval', (array)($input['filter']['availabilityStatus'] ?? []))));
    if (array_diff($statuses, lps_availability_statuses())) wp_send_json_error(['message' => __('Invalid availability context or filter.', 'lavka-price-sync')], 400);
    if ($statuses) { sort($statuses); $filter['availabilityStatus'] = $statuses; }
    $warehouse = absint($input['warehouseId'] ?? 0);
    $group = (string)($input['groupCode'] ?? '');
    if (($warehouse && $group !== '') || ($group !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $group)) || (!$enabled && $filter)) {
        wp_send_json_error(['message' => __('Invalid availability context or filter.', 'lavka-price-sync')], 400);
    }
    $result = ['enabled' => $enabled];
    if ($warehouse) $result['warehouseId'] = $warehouse;
    if ($group !== '') $result['groupCode'] = $group;
    if ($filter) $result['filter'] = $filter;
    return $result;
}

function lps_availability_enqueue(): void {
    $file = dirname(__DIR__) . '/assets/product-availability.js';
    wp_enqueue_script('lps-product-availability', plugins_url('assets/product-availability.js', dirname(__DIR__) . '/lavka-price-sync.php'), [], filemtime($file), true);
    $css = dirname(__DIR__) . '/assets/product-availability.css';
    wp_enqueue_style('lps-product-availability', plugins_url('assets/product-availability.css', dirname(__DIR__) . '/lavka-price-sync.php'), [], filemtime($css));
}

function lps_availability_fields(): void {
    ?>
    <details class="lps-pa-filter-editor lps-as-section" data-availability-editor>
        <summary><?php echo esc_html__('Physical stock availability', 'lavka-price-sync'); ?></summary>
        <p><label><input type="checkbox" data-av="enabled"> <?php echo esc_html__('Include daily stock availability', 'lavka-price-sync'); ?></label></p>
        <div class="lps-pa-v4-filter-grid lps-as-grid">
            <label><span><?php echo esc_html__('Availability context', 'lavka-price-sync'); ?></span><select data-av="context"><option value=""><?php echo esc_html__('Details only / single warehouse', 'lavka-price-sync'); ?></option></select></label>
            <?php foreach ([
                'availabilityPercentFrom' => __('Availability %, from', 'lavka-price-sync'),
                'availabilityPercentTo' => __('Availability %, through', 'lavka-price-sync'),
                'stockoutPercentFrom' => __('Stockout %, from', 'lavka-price-sync'),
                'stockoutPercentTo' => __('Stockout %, through', 'lavka-price-sync'),
            ] as $key => $title): ?>
                <label><span><?php echo esc_html($title); ?></span><input type="number" min="0" max="100" step="0.01" data-av="<?php echo esc_attr($key); ?>"></label>
            <?php endforeach; ?>
            <label><span><?php echo esc_html__('Availability status', 'lavka-price-sync'); ?></span><select data-av="availabilityStatus" multiple size="4">
                <?php $labels = lps_product_analytics_i18n()['statusLabels']; foreach (lps_availability_statuses() as $status): ?>
                    <option value="<?php echo esc_attr($status); ?>"><?php echo esc_html($labels[$status] ?? $status); ?></option>
                <?php endforeach; ?>
            </select></label>
        </div>
        <p class="description"><?php echo esc_html__('End-of-day physical stock only. The current assortment policy applies to the whole period, including new products. Intraday stockouts and historical reservations are not measured. Use completed days before the earliest snapshot date.', 'lavka-price-sync'); ?></p>
    </details>
    <?php
}

function lps_availability_sort_options(): void {
    foreach (['availabilityPercent' => __('Availability, %', 'lavka-price-sync'), 'stockoutPercent' => __('Days without stock, %', 'lavka-price-sync'), 'availabilityStatus' => __('Availability status', 'lavka-price-sync')] as $value => $label) {
        echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
    }
}
