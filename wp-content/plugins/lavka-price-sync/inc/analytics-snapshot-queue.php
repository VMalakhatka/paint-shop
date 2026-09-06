<?php
if (!defined('ABSPATH')) exit;

const LPS_ANALYTICS_SNAPSHOT_OPTION = 'lps_analytics_snapshot_queue';
const LPS_ANALYTICS_SNAPSHOT_HOOK = 'lps_analytics_snapshot_queue_tick';
const LPS_ANALYTICS_SNAPSHOT_NONCE = 'lps_analytics_snapshot_queue';

function lps_analytics_snapshot_state(): array {
    $state = get_option(LPS_ANALYTICS_SNAPSHOT_OPTION, []);
    return is_array($state) ? $state : [];
}

function lps_analytics_snapshot_store(array $state, int $delay = 15): void {
    $state['next_at'] = time() + $delay;
    $state['updated_at'] = current_time('mysql');
    update_option(LPS_ANALYTICS_SNAPSHOT_OPTION, $state, false);
    if (!empty($state['active'])) {
        if (!wp_next_scheduled(LPS_ANALYTICS_SNAPSHOT_HOOK)) wp_schedule_single_event(time() + $delay, LPS_ANALYTICS_SNAPSHOT_HOOK);
    } else wp_clear_scheduled_hook(LPS_ANALYTICS_SNAPSHOT_HOOK);
}

function lps_analytics_snapshot_public(array $state): array {
    unset($state['lock_token'], $state['baseline_generation']);
    return $state;
}

function lps_analytics_snapshot_finish(array &$state, string $status, string $message): void {
    if (!empty($state['lock_token']) && function_exists('lavka_ecosystem_lock_release')) lavka_ecosystem_lock_release($state['lock_token']);
    $state['lock_token'] = '';
    $state['active'] = false;
    $state['status'] = $status;
    $state['message'] = $message;
    $state['completed_at'] = current_time('mysql');
    lps_analytics_snapshot_store($state);
}

function lps_analytics_snapshot_complete_warehouse(array &$state, string $status, array $body): void {
    $state['reports'][] = [
        'warehouseId' => $state['warehouse_ids'][$state['index']], 'status' => $status,
        'generationId' => $body['generationId'] ?? null, 'analyticsSchemaVersion' => $body['analyticsSchemaVersion'] ?? null,
        'totalProducts' => $body['totalProducts'] ?? null, 'completedAt' => current_time('mysql'),
        'error' => sanitize_textarea_field((string)($body['error'] ?? $body['message'] ?? '')),
        'errorCode' => $body['errorCode'] ?? $body['code'] ?? '',
        'accountingRawCode' => $body['accountingRawCode'] ?? null,
        'accountingMode' => $body['accountingMode'] ?? '', 'recommendation' => $body['recommendation'] ?? '',
    ];
    $state['index']++;
    $state['generation_id'] = 0;
    $state['phase'] = 'WAITING';
    $state['snapshot'] = [];
    if (!empty($state['stop_requested'])) {
        lps_analytics_snapshot_finish($state, 'STOPPED', __('Snapshot queue stopped after the current warehouse.', 'lavka-price-sync'));
    } elseif ($state['index'] >= count($state['warehouse_ids'])) {
        $warnings = count(array_filter($state['reports'], static fn($r) => $r['status'] !== 'COMPLETED'));
        lps_analytics_snapshot_finish($state, $warnings ? 'COMPLETED_WITH_WARNINGS' : 'COMPLETED', __('Snapshot queue finished. Accounting prices were not recalculated.', 'lavka-price-sync'));
    } else lps_analytics_snapshot_store($state, 1);
}

function lps_analytics_snapshot_step(array &$state): void {
    if (empty($state['active']) || time() < ($state['next_at'] ?? 0)) return;
    $warehouse = (int)$state['warehouse_ids'][$state['index']];
    if ($state['phase'] === 'WAITING') {
        if (!empty($state['stop_requested'])) {
            lps_analytics_snapshot_finish($state, 'STOPPED', __('Snapshot queue stopped before the next warehouse.', 'lavka-price-sync'));
            return;
        }
        if (empty($state['lock_token'])) {
            $campaign = lps_accounting_price_campaign_state();
            $native = lps_accounting_prices_native_job_state();
            if (!empty($campaign['active']) || !empty($native['running'])) {
                $state['message'] = __('Waiting for the accounting-price operation to finish.', 'lavka-price-sync');
                lps_analytics_snapshot_store($state, 60);
                return;
            }
            if (!function_exists('lavka_ecosystem_lock_acquire')) {
                lps_analytics_snapshot_finish($state, 'FAILED', __('The global Lavka lock is unavailable.', 'lavka-price-sync'));
                return;
            }
            $lock = lavka_ecosystem_lock_acquire('lavka-price-sync', 'analytics_snapshot_queue', 'manual', __('Analytics snapshots without price recalculation', 'lavka-price-sync'), 2 * HOUR_IN_SECONDS, ['queue_id' => $state['id']]);
            if (empty($lock['ok'])) {
                $state['message'] = (string)($lock['message'] ?? __('Waiting for the global Lavka lock.', 'lavka-price-sync'));
                lps_analytics_snapshot_store($state, 60);
                return;
            }
            $state['lock_token'] = $lock['token'];
            lps_analytics_snapshot_store($state, 1);
        }
    }
    $owns_lock = !empty($state['lock_token']) && lavka_ecosystem_lock_touch($state['lock_token'], 2 * HOUR_IN_SECONDS, ['progress' => ['warehouse_id' => $warehouse, 'phase' => $state['phase']]]);
    if (!$owns_lock && $state['phase'] === 'WAITING') {
        lps_analytics_snapshot_finish($state, 'REVIEW_REQUIRED', __('Snapshot queue lost its lock. No next warehouse was started.', 'lavka-price-sync'));
        return;
    }

    $response = lps_accounting_prices_native_decode_response(lps_java_get(LPS_ACCOUNTING_PRICE_CAMPAIGN_SNAPSHOT_STATUS_PATH, ['timeout' => 30]));
    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    if (!$response['ok'] || !isset($body['running']) || !is_bool($body['running'])) {
        $state['message'] = __('Snapshot status is unavailable. Waiting without repeating POST.', 'lavka-price-sync');
        lps_analytics_snapshot_store($state, 60);
        return;
    }
    $state['snapshot'] = lps_accounting_prices_native_sanitize_report_value($body);
    if ($state['phase'] === 'WAITING') {
        if ($body['running']) {
            $state['message'] = __('Another snapshot is running. Waiting without starting a new one.', 'lavka-price-sync');
            lps_analytics_snapshot_store($state, 30);
            return;
        }
        $state['baseline_generation'] = (int)($body['generationId'] ?? 0);
        $state['phase'] = 'POLLING';
        $state['message'] = __('Snapshot request sent. Checking status without repeating POST.', 'lavka-price-sync');
        // Persist before POST: a worker crash or lost response must never cause an automatic replay.
        lps_analytics_snapshot_store($state, 15);
        $response = lps_accounting_prices_native_decode_response(lps_java_post(LPS_ACCOUNTING_PRICE_CAMPAIGN_SNAPSHOT_PATH, [
            'warehouseId' => $warehouse, 'horizonMonths' => $state['horizon_months'],
        ], ['timeout' => 30]));
        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        if (!$response['ok'] && in_array((int)$response['httpStatus'], [400, 403, 404, 422], true)) {
            if (lps_accounting_price_campaign_snapshot_has_unsupported_mode(['errorCode' => $body['errorCode'] ?? $body['code'] ?? ''])) {
                lps_analytics_snapshot_complete_warehouse($state, 'SKIPPED_UNSUPPORTED_MODE', $body);
                return;
            }
            $state['snapshot'] = lps_accounting_prices_native_sanitize_report_value($body);
            lps_analytics_snapshot_finish($state, 'FAILED', sanitize_textarea_field((string)($body['message'] ?? $body['error'] ?? __('Snapshot request was rejected.', 'lavka-price-sync'))));
            return;
        }
        if ($response['ok'] && !empty($body['accepted']) && (int)($body['warehouseId'] ?? 0) === $warehouse) {
            $state['generation_id'] = (int)($body['generationId'] ?? 0);
            $state['snapshot'] = lps_accounting_prices_native_sanitize_report_value($body);
        }
        lps_analytics_snapshot_store($state, 15);
        return;
    }

    if ($body['running']) {
        $state['message'] = __('Snapshot is running. Accounting prices are not being recalculated by this queue.', 'lavka-price-sync');
        lps_analytics_snapshot_store($state, 15);
        return;
    }
    if (!$owns_lock) {
        lps_analytics_snapshot_finish($state, 'REVIEW_REQUIRED', __('Snapshot queue lost its lock. No next warehouse was started.', 'lavka-price-sync'));
        return;
    }
    if (lps_accounting_price_campaign_snapshot_was_interrupted($body)) {
        lps_analytics_snapshot_finish($state, 'INTERRUPTED', __('Snapshot interrupted by Java restart. No active process remains. Start the snapshot queue again.', 'lavka-price-sync'));
        return;
    }
    $generation = (int)($body['generationId'] ?? 0);
    $matches = (int)($body['warehouseId'] ?? 0) === $warehouse && $generation > (int)$state['baseline_generation']
        && (empty($state['generation_id']) || $generation === (int)$state['generation_id']);
    if (!$matches) {
        lps_analytics_snapshot_finish($state, 'REVIEW_REQUIRED', __('The snapshot result does not match this request. No automatic retry was made.', 'lavka-price-sync'));
        return;
    }
    if (($body['status'] ?? '') === 'ACTIVE' && ($body['phase'] ?? '') === 'COMPLETED') {
        lps_analytics_snapshot_complete_warehouse($state, (int)($body['analyticsSchemaVersion'] ?? 0) >= 5 ? 'COMPLETED' : 'SCHEMA_TOO_OLD', $body);
    } elseif (lps_accounting_price_campaign_snapshot_has_unsupported_mode(['errorCode' => $body['errorCode'] ?? $body['code'] ?? ''])) {
        lps_analytics_snapshot_complete_warehouse($state, 'SKIPPED_UNSUPPORTED_MODE', $body);
    } else {
        lps_analytics_snapshot_finish($state, 'FAILED', sanitize_textarea_field((string)($body['error'] ?? __('Snapshot did not complete successfully.', 'lavka-price-sync'))));
    }
}

// Connection-scoped mutex serializes queue state only; the shared ecosystem lock protects Folio work.
function lps_analytics_snapshot_dispatch(string $operation, array $input = []): array {
    global $wpdb;
    $mutex = 'lps_snapshot_' . md5($wpdb->prefix);
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $mutex)) !== 1) {
        if (in_array($operation, ['start', 'stop'], true)) throw new InvalidArgumentException(__('The snapshot queue is busy. Try again shortly.', 'lavka-price-sync'));
        return lps_analytics_snapshot_public(lps_analytics_snapshot_state());
    }
    try {
        $state = lps_analytics_snapshot_state();
        if ($operation === 'start') {
            if (!empty($state['active'])) throw new InvalidArgumentException(__('A snapshot queue is already active.', 'lavka-price-sync'));
            $ids = lps_accounting_prices_native_normalize_warehouse_ids((array)($input['warehouseIds'] ?? []));
            if (!$ids || count($ids) > 50 || ($input['confirmed'] ?? false) !== true) throw new InvalidArgumentException(__('Select warehouses and confirm the snapshot workload.', 'lavka-price-sync'));
            $horizon = absint($input['horizonMonths'] ?? 36);
            if ($horizon < 12 || $horizon > 36) throw new InvalidArgumentException(__('Snapshot horizon must be between 12 and 36 months.', 'lavka-price-sync'));
            $state = ['id' => wp_generate_uuid4(), 'active' => true, 'status' => 'RUNNING', 'phase' => 'WAITING',
                'warehouse_ids' => $ids, 'horizon_months' => $horizon, 'index' => 0, 'generation_id' => 0,
                'lock_token' => '', 'reports' => [], 'snapshot' => [], 'stop_requested' => false,
                'started_at' => current_time('mysql'), 'message' => __('Snapshot queue accepted. No accounting-price recalculation will be run.', 'lavka-price-sync')];
            lps_analytics_snapshot_store($state, 1);
        } elseif ($operation === 'stop' && !empty($state['active'])) {
            $state['stop_requested'] = true;
            $state['message'] = __('The current snapshot will finish; subsequent warehouses will not start.', 'lavka-price-sync');
            lps_analytics_snapshot_store($state, 1);
        } elseif ($operation === 'tick') {
            lps_analytics_snapshot_step($state);
        }
        return lps_analytics_snapshot_public($state);
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $mutex)); }
}

add_action(LPS_ANALYTICS_SNAPSHOT_HOOK, static function (): void {
    lps_analytics_snapshot_dispatch('tick');
    $state = lps_analytics_snapshot_state();
    if (!empty($state['active']) && !wp_next_scheduled(LPS_ANALYTICS_SNAPSHOT_HOOK)) wp_schedule_single_event(time() + 15, LPS_ANALYTICS_SNAPSHOT_HOOK);
});
add_action('init', static function (): void {
    if (!empty(lps_analytics_snapshot_state()['active']) && !wp_next_scheduled(LPS_ANALYTICS_SNAPSHOT_HOOK)) wp_schedule_single_event(time() + 15, LPS_ANALYTICS_SNAPSHOT_HOOK);
}, 32);
add_action('wp_ajax_lps_analytics_snapshot_queue', static function (): void {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['message' => __('Access denied.', 'lavka-price-sync')], 403);
    check_ajax_referer(LPS_ANALYTICS_SNAPSHOT_NONCE);
    $operation = sanitize_key(wp_unslash($_POST['operation'] ?? 'status'));
    if (!in_array($operation, ['status', 'start', 'stop', 'tick'], true)) wp_send_json_error(['message' => __('Invalid request.', 'lavka-price-sync')], 400);
    $input = json_decode((string)wp_unslash($_POST['payload'] ?? '{}'), true);
    try { wp_send_json_success(lps_analytics_snapshot_dispatch($operation, is_array($input) ? $input : [])); }
    catch (InvalidArgumentException $error) { wp_send_json_error(['message' => $error->getMessage()], 409); }
});

function lps_analytics_snapshot_render(): void {
    ?>
    <details class="lps-pa-filter-editor" id="lps-snapshot-queue">
        <summary><?php echo esc_html__('Update analytics snapshots without price recalculation', 'lavka-price-sync'); ?></summary>
        <div class="lps-snapshot-queue-body">
            <p><?php echo esc_html__('Uses the warehouses selected above, one at a time. Reads Folio and updates MariaDB only; does not change Folio documents, stock or accounting prices. Reading may hold locks and create server load.', 'lavka-price-sync'); ?></p>
            <div class="lps-pa-toolbar">
                <label><?php echo esc_html__('Snapshot horizon, months', 'lavka-price-sync'); ?> <input id="lps-snapshot-horizon" type="number" min="12" max="36" value="36"></label>
                <label><input type="checkbox" id="lps-snapshot-confirm"> <?php echo esc_html__('I confirm the workload for the selected warehouses.', 'lavka-price-sync'); ?></label>
            </div>
            <div class="lps-pa-toolbar">
                <button type="button" class="button button-primary" id="lps-snapshot-start" disabled><?php echo esc_html__('Update analytics snapshots', 'lavka-price-sync'); ?></button>
                <button type="button" class="button" id="lps-snapshot-stop" disabled><?php echo esc_html__('Stop after the current snapshot', 'lavka-price-sync'); ?></button>
                <span class="spinner" id="lps-snapshot-spinner"></span>
            </div>
            <p id="lps-snapshot-message" role="status" aria-live="polite"></p>
            <div class="lps-pa-table-scroll"><table class="widefat striped"><thead><tr>
                <th><?php echo esc_html__('Warehouse', 'lavka-price-sync'); ?></th><th><?php echo esc_html__('Status', 'lavka-price-sync'); ?></th><th><?php echo esc_html__('Snapshot generation', 'lavka-price-sync'); ?></th><th><?php echo esc_html__('Analytics schema version', 'lavka-price-sync'); ?></th>
            </tr></thead><tbody id="lps-snapshot-results"></tbody></table></div>
            <details><summary><?php echo esc_html__('Technical details', 'lavka-price-sync'); ?></summary><pre id="lps-snapshot-details"></pre></details>
        </div>
    </details>
    <?php
}

add_action('admin_enqueue_scripts', static function (): void {
    if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== LPS_PRODUCT_ANALYTICS_PAGE) return;
    $base = dirname(__DIR__);
    wp_enqueue_script('lps-analytics-snapshot-queue', plugins_url('assets/analytics-snapshot-queue.js', $base . '/lavka-price-sync.php'), [], filemtime($base . '/assets/analytics-snapshot-queue.js'), true);
    wp_enqueue_style('lps-analytics-snapshot-queue', plugins_url('assets/analytics-snapshot-queue.css', $base . '/lavka-price-sync.php'), [], filemtime($base . '/assets/analytics-snapshot-queue.css'));
    wp_localize_script('lps-analytics-snapshot-queue', 'LPS_SNAPSHOT_QUEUE', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce(LPS_ANALYTICS_SNAPSHOT_NONCE), 'i18n' => [
        'loading' => __('Checking snapshot queue...', 'lavka-price-sync'),
        'error' => __('Snapshot queue request failed. Check status before starting again.', 'lavka-price-sync'),
        'idle' => __('No analytics snapshot queue has been started.', 'lavka-price-sync'),
        'reload' => __('Snapshots finished. Reload capabilities before building the report.', 'lavka-price-sync'),
        'statuses' => [
            'RUNNING' => __('Running', 'lavka-price-sync'), 'WAITING' => __('Waiting', 'lavka-price-sync'),
            'COMPLETED' => __('Completed', 'lavka-price-sync'), 'COMPLETED_WITH_WARNINGS' => __('Completed with warnings', 'lavka-price-sync'),
            'FAILED' => __('Failed', 'lavka-price-sync'), 'REVIEW_REQUIRED' => __('Review required', 'lavka-price-sync'),
            'STOPPED' => __('Stopped', 'lavka-price-sync'), 'INTERRUPTED' => __('Interrupted', 'lavka-price-sync'),
            'NOT_STARTED' => __('Not started', 'lavka-price-sync'), 'SCHEMA_TOO_OLD' => __('Snapshot built; update Java for schema v5', 'lavka-price-sync'),
            'SKIPPED_UNSUPPORTED_MODE' => __('Skipped: unsupported accounting mode', 'lavka-price-sync'),
        ],
    ]]);
});
