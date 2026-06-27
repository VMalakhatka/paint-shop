<?php
/**
 * Plugin Name: Lavka Ecosystem Lock
 * Description: Shared synchronization lock helpers for Lavka-related processes.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Stores the currently running Lavka synchronization process.
if (!defined('LAVKA_ECOSYSTEM_LOCK_OPTION')) {
    define('LAVKA_ECOSYSTEM_LOCK_OPTION', 'lavka_ecosystem_sync_lock');
}

// Fallback lock lifetime when a process does not provide its own TTL.
if (!defined('LAVKA_ECOSYSTEM_LOCK_DEFAULT_TTL')) {
    define('LAVKA_ECOSYSTEM_LOCK_DEFAULT_TTL', 30 * MINUTE_IN_SECONDS);
}

// Upper bound to prevent accidentally creating a very long stale lock.
if (!defined('LAVKA_ECOSYSTEM_LOCK_MAX_TTL')) {
    define('LAVKA_ECOSYSTEM_LOCK_MAX_TTL', 2 * HOUR_IN_SECONDS);
}

// Default delay used when a cron process finds another synchronization running.
if (!defined('LAVKA_ECOSYSTEM_LOCK_CRON_DELAY')) {
    define('LAVKA_ECOSYSTEM_LOCK_CRON_DELAY', 10 * MINUTE_IN_SECONDS);
}

// Database schema version for the shared Lavka event journal.
if (!defined('LAVKA_ECOSYSTEM_EVENTS_DB_VERSION')) {
    define('LAVKA_ECOSYSTEM_EVENTS_DB_VERSION', '1');
}

// Number of days retained in the shared event journal.
if (!defined('LAVKA_ECOSYSTEM_EVENTS_RETENTION_DAYS')) {
    define('LAVKA_ECOSYSTEM_EVENTS_RETENTION_DAYS', 30);
}

/**
 * Returns the database table used by the shared Lavka event journal.
 */
function lavka_ecosystem_events_table(): string {
    global $wpdb;

    return $wpdb->prefix . 'lavka_ecosystem_events';
}

/**
 * Creates or upgrades the shared event journal table.
 *
 * MU plugins do not have an activation hook, so the schema version is checked
 * after all MU plugins have loaded.
 */
function lavka_ecosystem_events_install(): void {
    $installed_version = (string) get_option('lavka_ecosystem_events_db_version', '');

    if ($installed_version === LAVKA_ECOSYSTEM_EVENTS_DB_VERSION) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    global $wpdb;
    $table   = lavka_ecosystem_events_table();
    $charset = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        created_at_gmt DATETIME NOT NULL,
        event VARCHAR(40) NOT NULL,
        level VARCHAR(20) NOT NULL DEFAULT 'info',
        owner VARCHAR(100) NOT NULL DEFAULT '',
        process VARCHAR(100) NOT NULL DEFAULT '',
        source VARCHAR(30) NOT NULL DEFAULT '',
        token VARCHAR(36) NOT NULL DEFAULT '',
        message TEXT NULL,
        scheduled_at DATETIME NULL,
        scheduled_at_gmt DATETIME NULL,
        context_json LONGTEXT NULL,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY created_at_gmt (created_at_gmt),
        KEY event (event),
        KEY owner (owner),
        KEY process (process),
        KEY level (level)
    ) {$charset};");

    update_option('lavka_ecosystem_events_db_version', LAVKA_ECOSYSTEM_EVENTS_DB_VERSION, false);
}
add_action('muplugins_loaded', 'lavka_ecosystem_events_install', 1);

/**
 * Writes one event to the shared Lavka journal.
 *
 * Supported data fields: level, owner, process, source, token, message,
 * scheduled_at (Unix timestamp), context and user_id.
 */
function lavka_ecosystem_log_event(string $event, array $data = []): int {
    global $wpdb;

    $level = sanitize_key((string) ($data['level'] ?? 'info'));
    if (!in_array($level, ['info', 'warning', 'error'], true)) {
        $level = 'info';
    }

    $scheduled_timestamp = isset($data['scheduled_at']) ? (int) $data['scheduled_at'] : 0;
    $scheduled_at = null;
    $scheduled_at_gmt = null;

    if ($scheduled_timestamp > 0) {
        $scheduled_at = wp_date('Y-m-d H:i:s', $scheduled_timestamp, wp_timezone());
        $scheduled_at_gmt = gmdate('Y-m-d H:i:s', $scheduled_timestamp);
    }

    $context = $data['context'] ?? null;
    $context_json = $context !== null
        ? wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    $inserted = $wpdb->insert(
        lavka_ecosystem_events_table(),
        [
            'created_at'       => current_time('mysql'),
            'created_at_gmt'   => current_time('mysql', true),
            'event'            => sanitize_key($event),
            'level'            => $level,
            'owner'            => sanitize_key((string) ($data['owner'] ?? '')),
            'process'          => sanitize_key((string) ($data['process'] ?? '')),
            'source'           => sanitize_key((string) ($data['source'] ?? '')),
            'token'            => sanitize_text_field((string) ($data['token'] ?? '')),
            'message'          => isset($data['message']) ? (string) $data['message'] : null,
            'scheduled_at'     => $scheduled_at,
            'scheduled_at_gmt' => $scheduled_at_gmt,
            'context_json'     => $context_json,
            'user_id'          => isset($data['user_id']) ? (int) $data['user_id'] : get_current_user_id(),
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
    );

    return $inserted ? (int) $wpdb->insert_id : 0;
}

/**
 * Removes old shared journal entries at most once per day.
 */
function lavka_ecosystem_events_maybe_cleanup(): void {
    $last_cleanup = (int) get_option('lavka_ecosystem_events_last_cleanup', 0);

    if ($last_cleanup > time() - DAY_IN_SECONDS) {
        return;
    }

    global $wpdb;
    $retention_days = max(
        1,
        (int) apply_filters('lavka_ecosystem_events_retention_days', LAVKA_ECOSYSTEM_EVENTS_RETENTION_DAYS)
    );
    $threshold = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));

    $wpdb->query(
        $wpdb->prepare(
            'DELETE FROM ' . lavka_ecosystem_events_table() . ' WHERE created_at_gmt < %s',
            $threshold
        )
    );

    update_option('lavka_ecosystem_events_last_cleanup', time(), false);
}
add_action('init', 'lavka_ecosystem_events_maybe_cleanup');

/**
 * Returns the default lock lifetime.
 *
 * Individual processes can still pass their own TTL to
 * lavka_ecosystem_lock_acquire().
 */
function lavka_ecosystem_lock_default_ttl(): int {
    return (int) apply_filters(
        'lavka_ecosystem_lock_default_ttl',
        LAVKA_ECOSYSTEM_LOCK_DEFAULT_TTL
    );
}

/**
 * Normalizes the requested TTL.
 *
 * The value is clamped between one minute and LAVKA_ECOSYSTEM_LOCK_MAX_TTL so
 * short processes cannot create zero-second locks and mistakes cannot create
 * locks that stay active for too long.
 */
function lavka_ecosystem_lock_normalize_ttl(?int $ttl = null): int {
    $ttl = $ttl && $ttl > 0 ? $ttl : lavka_ecosystem_lock_default_ttl();

    return max(
        MINUTE_IN_SECONDS,
        min((int) $ttl, (int) LAVKA_ECOSYSTEM_LOCK_MAX_TTL)
    );
}

/**
 * Returns the current lock data, or null when no valid lock option exists.
 */
function lavka_ecosystem_lock_get(): ?array {
    $lock = get_option(LAVKA_ECOSYSTEM_LOCK_OPTION, null);

    return is_array($lock) ? $lock : null;
}

/**
 * Checks whether a lock has expired according to its expires_at timestamp.
 */
function lavka_ecosystem_lock_is_stale(?array $lock = null): bool {
    $lock = $lock ?? lavka_ecosystem_lock_get();

    if (!$lock) {
        return false;
    }

    $expires_at = isset($lock['expires_at']) ? (int) $lock['expires_at'] : 0;

    return $expires_at > 0 && $expires_at <= time();
}

/**
 * Deletes the current lock when it is stale.
 *
 * Returns true only when an expired lock was actually removed.
 */
function lavka_ecosystem_lock_clear_stale(): bool {
    $lock = lavka_ecosystem_lock_get();

    if (!$lock || !lavka_ecosystem_lock_is_stale($lock)) {
        return false;
    }

    $deleted = delete_option(LAVKA_ECOSYSTEM_LOCK_OPTION);

    if ($deleted) {
        lavka_ecosystem_log_event('stale_lock_cleared', [
            'level'   => 'warning',
            'owner'   => $lock['owner'] ?? '',
            'process' => $lock['process'] ?? '',
            'source'  => $lock['source'] ?? '',
            'token'   => $lock['token'] ?? '',
            'message' => 'Expired synchronization lock was removed.',
            'context' => [
                'label'      => $lock['label'] ?? '',
                'started_at' => $lock['started_at'] ?? null,
                'expires_at' => $lock['expires_at'] ?? null,
            ],
            'user_id' => $lock['user_id'] ?? 0,
        ]);
    }

    return $deleted;
}

/**
 * Attempts to acquire the global Lavka synchronization lock.
 *
 * add_option() is used as a lightweight mutex: it succeeds only when the lock
 * option does not already exist. The returned token must be kept by the caller
 * and passed to lavka_ecosystem_lock_release().
 *
 * @param string   $owner   Plugin or subsystem slug, for example "lavka-sync".
 * @param string   $process Process slug, for example "stock_full".
 * @param string   $source  Trigger source, for example "manual" or "cron".
 * @param string   $label   Human-readable process name for status messages.
 * @param int|null $ttl     Optional process-specific TTL in seconds.
 * @param array    $extra   Optional additional fields stored with the lock.
 */
function lavka_ecosystem_lock_acquire(
    string $owner,
    string $process,
    string $source,
    string $label,
    ?int $ttl = null,
    array $extra = []
): array {
    lavka_ecosystem_lock_clear_stale();

    $now   = time();
    $ttl   = lavka_ecosystem_lock_normalize_ttl($ttl);
    $token = wp_generate_uuid4();

    $lock = array_merge(
        $extra,
        [
            'token'      => $token,
            'owner'      => sanitize_key($owner),
            'process'    => sanitize_key($process),
            'source'     => sanitize_key($source),
            'label'      => (string) $label,
            'started_at' => $now,
            'expires_at' => $now + $ttl,
            'ttl'        => $ttl,
            'user_id'    => get_current_user_id(),
        ]
    );

    // Atomic first attempt: only one request can create this option.
    if (add_option(LAVKA_ECOSYSTEM_LOCK_OPTION, $lock, '', 'no')) {
        lavka_ecosystem_log_event('lock_acquired', [
            'owner'   => $lock['owner'],
            'process' => $lock['process'],
            'source'  => $lock['source'],
            'token'   => $lock['token'],
            'message' => 'Synchronization lock acquired.',
            'context' => [
                'label'      => $lock['label'],
                'ttl'        => $lock['ttl'],
                'expires_at' => $lock['expires_at'],
            ],
            'user_id' => $lock['user_id'],
        ]);

        return [
            'ok'    => true,
            'token' => $token,
            'lock'  => $lock,
        ];
    }

    $current = lavka_ecosystem_lock_get();

    // If another request left an expired lock between checks, clear and retry.
    if ($current && lavka_ecosystem_lock_is_stale($current)) {
        lavka_ecosystem_lock_clear_stale();

        if (add_option(LAVKA_ECOSYSTEM_LOCK_OPTION, $lock, '', 'no')) {
            lavka_ecosystem_log_event('lock_acquired', [
                'owner'   => $lock['owner'],
                'process' => $lock['process'],
                'source'  => $lock['source'],
                'token'   => $lock['token'],
                'message' => 'Synchronization lock acquired after an expired lock was removed.',
                'context' => [
                    'label'      => $lock['label'],
                    'ttl'        => $lock['ttl'],
                    'expires_at' => $lock['expires_at'],
                ],
                'user_id' => $lock['user_id'],
            ]);

            return [
                'ok'    => true,
                'token' => $token,
                'lock'  => $lock,
            ];
        }
    }

    $current = $current ?: lavka_ecosystem_lock_get();
    lavka_ecosystem_log_event('lock_blocked', [
        'level'   => 'warning',
        'owner'   => $lock['owner'],
        'process' => $lock['process'],
        'source'  => $lock['source'],
        'message' => lavka_ecosystem_lock_format_message($current),
        'context' => [
            'requested_label' => $lock['label'],
            'blocking_lock'   => $current,
        ],
        'user_id' => $lock['user_id'],
    ]);

    return [
        'ok'      => false,
        'token'   => null,
        'lock'    => $current,
        'message' => lavka_ecosystem_lock_format_message($current),
    ];
}

/**
 * Releases a lock owned by the given token.
 *
 * The token check prevents one process from accidentally releasing another
 * process' lock.
 */
function lavka_ecosystem_lock_release(?string $token): bool {
    if (!$token) {
        return false;
    }

    $lock = lavka_ecosystem_lock_get();

    if (!$lock || !isset($lock['token']) || !hash_equals((string) $lock['token'], $token)) {
        return false;
    }

    $deleted = delete_option(LAVKA_ECOSYSTEM_LOCK_OPTION);

    if ($deleted) {
        lavka_ecosystem_log_event('lock_released', [
            'owner'   => $lock['owner'] ?? '',
            'process' => $lock['process'] ?? '',
            'source'  => $lock['source'] ?? '',
            'token'   => $lock['token'] ?? '',
            'message' => 'Synchronization lock released.',
            'context' => [
                'label'            => $lock['label'] ?? '',
                'duration_seconds' => max(0, time() - (int) ($lock['started_at'] ?? time())),
                'progress'         => $lock['progress'] ?? null,
            ],
            'user_id' => $lock['user_id'] ?? 0,
        ]);
    }

    return $deleted;
}

/**
 * Extends the current lock lifetime when it is still owned by the given token.
 */
function lavka_ecosystem_lock_touch(?string $token, ?int $ttl = null, array $extra = []): bool {
    if (!$token) {
        return false;
    }

    $lock = lavka_ecosystem_lock_get();

    if (!$lock || !isset($lock['token']) || !hash_equals((string) $lock['token'], $token)) {
        return false;
    }

    $ttl = lavka_ecosystem_lock_normalize_ttl($ttl);
    $lock = array_merge($lock, $extra, [
        'expires_at' => time() + $ttl,
        'ttl'        => $ttl,
    ]);

    return update_option(LAVKA_ECOSYSTEM_LOCK_OPTION, $lock, false);
}

/**
 * Builds a human-readable message for manual/AJAX responses.
 */
function lavka_ecosystem_lock_format_message(?array $lock = null): string {
    $lock = $lock ?? lavka_ecosystem_lock_get();

    if (!$lock) {
        return 'Synchronization is already running. Please try again later.';
    }

    $label = trim((string) ($lock['label'] ?? 'Synchronization'));

    return sprintf(
        'Synchronization is already running: %s. Please try again later.',
        $label ?: 'Synchronization'
    );
}

/**
 * Builds a standard JSON error payload for a blocked synchronization.
 */
function lavka_ecosystem_lock_error_data(?array $lock = null): array {
    $lock = $lock ?? lavka_ecosystem_lock_get();

    return [
        'error'   => 'lavka_sync_locked',
        'message' => lavka_ecosystem_lock_format_message($lock),
        'lock'    => $lock,
    ];
}

/**
 * Sends a standard WordPress AJAX JSON error when a manual process is blocked.
 */
function lavka_ecosystem_lock_send_json_error(?array $lock = null, int $status_code = 409): void {
    wp_send_json_error(
        lavka_ecosystem_lock_error_data($lock),
        $status_code
    );
}

/**
 * Schedules a one-off retry for a cron hook.
 *
 * This helper is meant for cron processes that find the global lock occupied:
 * they should skip work now and retry later instead of running in parallel.
 */
function lavka_ecosystem_lock_reschedule_single_event(
    string $hook,
    int $delay = LAVKA_ECOSYSTEM_LOCK_CRON_DELAY,
    array $args = [],
    array $event_data = []
): int {
    $timestamp = time() + max(MINUTE_IN_SECONDS, $delay);
    wp_clear_scheduled_hook($hook, $args);
    $scheduled = wp_schedule_single_event($timestamp, $hook, $args);

    lavka_ecosystem_log_event($scheduled ? 'cron_rescheduled' : 'cron_reschedule_failed', [
        'level'        => $scheduled ? 'warning' : 'error',
        'owner'        => $event_data['owner'] ?? '',
        'process'      => $event_data['process'] ?? '',
        'source'       => 'cron',
        'message'      => $event_data['message'] ?? ($scheduled
            ? 'Cron event was postponed because another synchronization is running.'
            : 'Cron retry could not be scheduled.'),
        'scheduled_at' => $timestamp,
        'context'      => [
            'hook'          => $hook,
            'args'          => $args,
            'blocking_lock' => $event_data['blocking_lock'] ?? lavka_ecosystem_lock_get(),
        ],
        'user_id' => 0,
    ]);

    return $timestamp;
}

/**
 * Registers the shared Lavka event journal under the WordPress Tools menu.
 */
function lavka_ecosystem_events_register_admin_page(): void {
    add_management_page(
        __('Lavka events', 'lavka-ecosystem'),
        __('Lavka events', 'lavka-ecosystem'),
        'manage_options',
        'lavka-ecosystem-events',
        'lavka_ecosystem_events_render_admin_page'
    );
}
add_action('admin_menu', 'lavka_ecosystem_events_register_admin_page');

/**
 * Renders the shared Lavka event journal.
 */
function lavka_ecosystem_events_render_admin_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $rows = $wpdb->get_results(
        'SELECT * FROM ' . lavka_ecosystem_events_table() . ' ORDER BY id DESC LIMIT 200',
        ARRAY_A
    );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Lavka ecosystem events', 'lavka-ecosystem'); ?></h1>
        <p>
            <?php
            echo esc_html__(
                'Shared synchronization events, lock conflicts and scheduled retries from Lavka plugins.',
                'lavka-ecosystem'
            );
            ?>
        </p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Time', 'lavka-ecosystem'); ?></th>
                    <th><?php echo esc_html__('Level', 'lavka-ecosystem'); ?></th>
                    <th><?php echo esc_html__('Event', 'lavka-ecosystem'); ?></th>
                    <th><?php echo esc_html__('Owner', 'lavka-ecosystem'); ?></th>
                    <th><?php echo esc_html__('Process', 'lavka-ecosystem'); ?></th>
                    <th><?php echo esc_html__('Source', 'lavka-ecosystem'); ?></th>
                    <th><?php echo esc_html__('Message', 'lavka-ecosystem'); ?></th>
                    <th><?php echo esc_html__('Scheduled for', 'lavka-ecosystem'); ?></th>
                    <th><?php echo esc_html__('Details', 'lavka-ecosystem'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo esc_html((string) $row['created_at']); ?></td>
                            <td><?php echo esc_html((string) $row['level']); ?></td>
                            <td><code><?php echo esc_html((string) $row['event']); ?></code></td>
                            <td><?php echo esc_html((string) $row['owner']); ?></td>
                            <td><?php echo esc_html((string) $row['process']); ?></td>
                            <td><?php echo esc_html((string) $row['source']); ?></td>
                            <td><?php echo esc_html((string) $row['message']); ?></td>
                            <td><?php echo esc_html((string) $row['scheduled_at']); ?></td>
                            <td>
                                <?php if (!empty($row['context_json'])): ?>
                                    <details>
                                        <summary><?php echo esc_html__('View', 'lavka-ecosystem'); ?></summary>
                                        <pre style="max-width:520px;white-space:pre-wrap;word-break:break-word;"><?php echo esc_html((string) $row['context_json']); ?></pre>
                                    </details>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9"><?php echo esc_html__('No events yet.', 'lavka-ecosystem'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
