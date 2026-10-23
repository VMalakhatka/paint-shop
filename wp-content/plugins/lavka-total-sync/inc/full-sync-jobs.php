<?php
defined('ABSPATH') || exit;

const LTS_FULL_JOB_OPTION = 'lts_full_sync_job';
const LTS_FULL_JOB_TICK = 'lts_full_sync_job_tick';

function lts_full_job_label(string $status): string {
    $labels = [
        'WAITING_QUEUE' => __('Waiting for queue', 'lavka-total-sync'),
        'STARTING' => __('Starting; checking acceptance', 'lavka-total-sync'),
        'QUEUED' => __('Running', 'lavka-total-sync'),
        'RUNNING' => __('Running', 'lavka-total-sync'),
        'COMPLETED' => __('Completed', 'lavka-total-sync'),
        'COMPLETED_WITH_ERRORS' => __('Completed with errors', 'lavka-total-sync'),
        'PAUSED_LIMIT' => __('Stopped at the configured limit', 'lavka-total-sync'),
        'OUTCOME_UNKNOWN' => __('Write outcome requires review', 'lavka-total-sync'),
    ];
    return $labels[$status] ?? $status;
}

function lts_full_job_terminal(array $job): bool {
    return ($job['safeToRelease'] ?? false) === true && in_array($job['status'] ?? '', ['COMPLETED', 'COMPLETED_WITH_ERRORS', 'PAUSED_LIMIT'], true);
}

function lts_full_job_public(array $job): array {
    foreach (['limit','pageSizeWoo','cursorAfter','dryRun','forceRefresh'] as $key) {
        if (isset($job['request'][$key])) $job[$key] = $job['request'][$key];
    }
    unset($job['lock_token'], $job['request']);
    $job['label'] = lts_full_job_label($job['status'] ?? 'WAITING_QUEUE');
    if (!empty($job['dryRun'])) $job['label'] = __('Dry run', 'lavka-total-sync') . ': ' . $job['label'];
    return $job;
}

function lts_full_job_save(array $job): void {
    global $wpdb;
    $claim = $GLOBALS['lts_full_job_claim'] ?? null;
    $stored_claim = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
        'lts_full_sync_control_claim'));
    if (!$claim || $stored_claim !== maybe_serialize($claim)) {
        throw new RuntimeException('JOB_CONTROLLER_LEASE_LOST');
    }
    $previous = get_option(LTS_FULL_JOB_OPTION, []);
    $job['updated_at'] = time();
    update_option(LTS_FULL_JOB_OPTION, $job, false);
    if (get_option(LTS_FULL_JOB_OPTION) !== $job) throw new RuntimeException('JOB_STATE_NOT_SAVED');
    if (($previous['jobId'] ?? '') !== $job['jobId'] || ($previous['status'] ?? '') !== $job['status']
        || (int)($previous['chunks'] ?? 0) !== (int)($job['chunks'] ?? 0)) {
        $level = $job['status'] === 'OUTCOME_UNKNOWN' ? 'error' : ($job['status'] === 'COMPLETED_WITH_ERRORS' ? 'warn' : 'info');
        lts_log_db($level, 'full_sync_job', ['result' => $job['status'], 'message' => lts_full_job_label($job['status']),
            'ctx' => lts_full_job_public($job)]);
    }
}

function lts_full_job_schedule(int $delay = 30): void {
    if (!wp_next_scheduled(LTS_FULL_JOB_TICK)) wp_schedule_single_event(time() + $delay, LTS_FULL_JOB_TICK);
}

function lts_full_job_release(array $job): bool {
    if (empty($job['lock_token'])) return true;
    if (!function_exists('lavka_ecosystem_lock_get')) return false;
    lts_ecosystem_lock_release($job['lock_token']);
    $lock = lavka_ecosystem_lock_get();
    return ($lock['token'] ?? '') !== $job['lock_token'];
}

/** Serialize controller/tick writers, not a second ecosystem lock. */
function lts_full_job_claim() {
    $key = 'lts_full_sync_control_claim';
    $value = ['token' => wp_generate_uuid4(), 'time' => time()];
    if (add_option($key, $value, '', 'no')) { $GLOBALS['lts_full_job_claim'] = $value; return $value; }
    $old = get_option($key, []);
    if (!empty($old['time']) && (int)$old['time'] < time() - 90) {
        lts_full_job_unclaim($old);
        if (add_option($key, $value, '', 'no')) { $GLOBALS['lts_full_job_claim'] = $value; return $value; }
    }
    return false;
}

function lts_full_job_unclaim(array $claim): void {
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",
        'lts_full_sync_control_claim', maybe_serialize($claim)));
    wp_cache_delete('lts_full_sync_control_claim', 'options');
    if (($GLOBALS['lts_full_job_claim'] ?? null) === $claim) unset($GLOBALS['lts_full_job_claim']);
}

function lts_full_job_enqueue(array $payload, string $source, bool $force = false): array {
    $claim = lts_full_job_claim();
    if (!$claim) return ['ok' => true, 'job' => lts_full_job_public(get_option(LTS_FULL_JOB_OPTION, []))];
    try {
        $job = get_option(LTS_FULL_JOB_OPTION, []);
        if ($job && !lts_full_job_terminal($job)) {
            lts_full_job_schedule();
            return ['ok' => true, 'job' => lts_full_job_public($job)];
        }
        if ($job && !lts_full_job_release($job)) {
            lts_full_job_schedule();
            return ['ok' => true, 'job' => lts_full_job_public($job)];
        }
        $job = ['jobId' => wp_generate_uuid4(), 'status' => 'WAITING_QUEUE', 'source' => $source,
            'safeToRelease' => false, 'accepted' => false, 'chunks' => 0, 'processed' => 0,
            'created' => 0, 'updated' => 0, 'drafted' => 0, 'errorCount' => 0, 'started_at' => time(),
            'request' => [
                'limit' => max(1, min(1000000, (int)($payload['limit'] ?? 900000))),
                'pageSizeWoo' => max(1, min(100, (int)($payload['pageSizeWoo'] ?? 50))),
                'cursorAfter' => lts_normalize_cursor_after($payload['cursorAfter'] ?? null),
                'dryRun' => !empty($payload['dryRun']), 'forceRefresh' => $force,
            ]];
        lts_full_job_save($job);
        lts_full_job_schedule(1);
        return ['ok' => true, 'job' => lts_full_job_public($job)];
    } finally { lts_full_job_unclaim($claim); }
}

function lts_full_job_http(string $method, string $path, ?array $body = null): array {
    $opts = lts_get_options();
    if (empty($opts['java_base_url']) || empty($opts['api_token'])) return ['http' => 0, 'error' => 'JOB_API_NOT_CONFIGURED'];
    $args = ['method' => $method, 'timeout' => 10, 'redirection' => 0, 'headers' => [
        'Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Auth-Token' => $opts['api_token'],
    ]];
    if ($body !== null) $args['body'] = wp_json_encode($body);
    $res = wp_remote_request(rtrim($opts['java_base_url'], '/') . '/admin/sync/jobs' . $path, $args);
    if (is_wp_error($res)) return ['http' => 0, 'error' => 'JOB_STATUS_UNAVAILABLE'];
    return ['http' => wp_remote_retrieve_response_code($res), 'data' => json_decode(wp_remote_retrieve_body($res), true)];
}

function lts_full_job_accept_status(array &$job, array $data): bool {
    if (($data['protocol'] ?? 0) !== 1 || ($data['jobId'] ?? '') !== $job['jobId']) return false;
    foreach (['limit','pageSizeWoo','cursorAfter','dryRun','forceRefresh'] as $key) {
        if (!array_key_exists($key, $data) || $data[$key] !== $job['request'][$key]) return false;
    }
    if (!in_array($data['status'] ?? '', ['QUEUED','RUNNING','COMPLETED','COMPLETED_WITH_ERRORS','PAUSED_LIMIT','OUTCOME_UNKNOWN'], true)) return false;
    if (!isset($data['safeToRelease']) || !is_bool($data['safeToRelease'])) return false;
    foreach (['processed','created','updated','drafted','chunks','errorCount'] as $key) {
        if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key] < (int)($job[$key] ?? 0)) return false;
    }
    if ($data['safeToRelease'] && !in_array($data['status'], ['COMPLETED','COMPLETED_WITH_ERRORS','PAUSED_LIMIT'], true)) return false;
    $job['accepted'] = true;
    foreach (['status','safeToRelease','nextAfter','processed','created','updated','drafted','chunks','errorCount','errors','error','exhausted'] as $key) {
        if (array_key_exists($key, $data)) $job[$key] = $data[$key];
    }
    return true;
}

function lts_full_job_tick(): void {
    $claim = lts_full_job_claim();
    if (!$claim) { lts_full_job_schedule(); return; }
    try {
        $job = get_option(LTS_FULL_JOB_OPTION, []);
        if (!$job) return;
        if (lts_full_job_terminal($job)) {
            if (lts_full_job_release($job)) wp_clear_scheduled_hook(LTS_FULL_JOB_TICK);
            else lts_full_job_schedule();
            return;
        }
        // Always have a recovery tick scheduled before a network call or a PHP shutdown.
        lts_full_job_schedule();
        if (!function_exists('lavka_ecosystem_lock_get')) {
            $job['poll_error'] = 'ECOSYSTEM_LOCK_UNAVAILABLE'; lts_full_job_save($job); return;
        }
        if (empty($job['lock_token'])) {
            $cap = lts_full_job_http('GET', '/capabilities');
            if ($cap['http'] !== 200 || ($cap['data']['protocol'] ?? 0) !== 1 || empty($cap['data']['persistent'])) {
                $job['poll_error'] = 'JOB_API_UNAVAILABLE_OR_INCOMPATIBLE'; lts_full_job_save($job); return;
            }
            if (!empty($cap['data']['activeJobId']) && $cap['data']['activeJobId'] !== $job['jobId']) return;
            $current = lavka_ecosystem_lock_get();
            if (($current['full_sync_job_id'] ?? '') === $job['jobId'] && ($current['owner'] ?? '') === 'lavka-total-sync') {
                // Recover a PHP crash between acquiring the lock and saving its token.
                $lock = ['ok' => true, 'token' => $current['token']];
            } else {
                $lock = lavka_ecosystem_lock_acquire('lavka-total-sync', 'product_fields_job', $job['source'],
                    __('Background full synchronization', 'lavka-total-sync'), 900, ['full_sync_job_id' => $job['jobId']]);
            }
            if (empty($lock['ok'])) return;
            $job['lock_token'] = $lock['token']; $job['status'] = 'STARTING';
            lts_full_job_save($job);
        }
        $current = lavka_ecosystem_lock_get();
        if (($current['token'] ?? '') !== $job['lock_token'] || ($current['full_sync_job_id'] ?? '') !== $job['jobId']) {
            $job['status'] = 'OUTCOME_UNKNOWN'; $job['error'] = 'ECOSYSTEM_LEASE_LOST'; lts_full_job_save($job); return;
        }
        $res = lts_full_job_http('GET', '/' . rawurlencode($job['jobId']));
        if ($res['http'] === 404 && ($res['data']['error'] ?? '') === 'JOB_NOT_FOUND' && ($res['data']['protocol'] ?? 0) === 1) {
            if (!empty($job['accepted'])) {
                $job['status'] = 'OUTCOME_UNKNOWN'; $job['error'] = 'ACCEPTED_JOB_NOT_FOUND'; lts_full_job_save($job); return;
            }
            $request = $job['request'] + ['jobId' => $job['jobId'], 'lockToken' => $job['lock_token']];
            // Re-sending this exact ID is idempotent at Java's unique job key, never a new run.
            $res = lts_full_job_http('POST', '', $request);
        }
        if (in_array($res['http'], [200,202], true) && is_array($res['data']) && lts_full_job_accept_status($job, $res['data'])) {
            unset($job['poll_error']);
            $job['last_checked_at'] = time();
            lts_full_job_save($job);
            if (lts_full_job_terminal($job)) {
                if (lts_full_job_release($job)) wp_clear_scheduled_hook(LTS_FULL_JOB_TICK);
            }
        } else {
            $job['poll_error'] = 'JOB_STATUS_UNAVAILABLE_OR_INVALID'; lts_full_job_save($job);
        }
    } finally { lts_full_job_unclaim($claim); }
}
add_action(LTS_FULL_JOB_TICK, 'lts_full_job_tick');

// A read-only callback: Woo REST authentication applies to this wc/v3 route too.
add_action('rest_api_init', function () {
    register_rest_route('wc/v3', '/lavka-full-sync/lease', [
        'methods' => 'POST',
        'permission_callback' => static fn() => current_user_can('manage_woocommerce'),
        'callback' => static function (WP_REST_Request $request) {
            $job = get_option(LTS_FULL_JOB_OPTION, []); $lock = lavka_ecosystem_lock_get();
            $id = (string)$request->get_param('jobId'); $token = (string)$request->get_param('token');
            $owned = $id !== '' && $token !== '' && ($job['jobId'] ?? '') === $id
                && ($lock['full_sync_job_id'] ?? '') === $id && ($lock['owner'] ?? '') === 'lavka-total-sync'
                && hash_equals((string)($lock['token'] ?? ''), $token)
                && hash_equals((string)($job['lock_token'] ?? ''), $token) && !lts_full_job_terminal($job);
            $response = new WP_REST_Response(['owned' => $owned]);
            $response->header('Cache-Control', 'no-store'); return $response;
        },
    ]);
});

add_action('wp_ajax_lts_full_job_status', function () {
    if (!current_user_can(LTS_CAP)) wp_send_json_error(['error' => 'forbidden'], 403);
    check_ajax_referer('lts_admin_nonce', 'nonce');
    $job = get_option(LTS_FULL_JOB_OPTION, []);
    if ($job && !lts_full_job_terminal($job)) lts_full_job_schedule();
    wp_send_json_success(['job' => lts_full_job_public($job)]);
});

// Force-refresh no longer ends in one PHP request: index each acknowledged Woo update instead.
add_action('woocommerce_rest_insert_product_object', static function ($product, $request): void {
    if ($request->get_param('lavka_supplier') !== null && function_exists('relevanssi_insert_edit')) {
        relevanssi_insert_edit($product->get_id());
    }
}, 110, 2);

function lts_full_job_render_panel(): void {
    ?>
    <section id="lts-full-job" class="lts-full-job" hidden>
        <h2><?php esc_html_e('Background full synchronization', 'lavka-total-sync'); ?></h2>
        <p><strong data-job-field="label" role="status" aria-live="polite"></strong></p>
        <p class="lts-job-connection" hidden></p>
        <dl>
            <?php foreach ([
                'jobId' => __('Job ID', 'lavka-total-sync'),
                'chunks' => __('Completed chunks', 'lavka-total-sync'),
                'processed' => __('Processed', 'lavka-total-sync'),
                'created' => __('Created', 'lavka-total-sync'),
                'updated' => __('Updated', 'lavka-total-sync'),
                'drafted' => __('Removed from Woo', 'lavka-total-sync'),
                'errorCount' => __('Errors', 'lavka-total-sync'),
                'nextAfter' => __('Saved cursor', 'lavka-total-sync'),
            ] as $key => $label): ?>
                <div><dt><?php echo esc_html($label); ?></dt><dd data-job-field="<?php echo esc_attr($key); ?>"></dd></div>
            <?php endforeach; ?>
        </dl>
        <details><summary><?php esc_html_e('Diagnostics', 'lavka-total-sync'); ?></summary><pre class="lts-job-diagnostics"></pre></details>
    </section>
    <?php
}
