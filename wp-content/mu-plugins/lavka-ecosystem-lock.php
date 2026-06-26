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

    return delete_option(LAVKA_ECOSYSTEM_LOCK_OPTION);
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
        return [
            'ok'    => true,
            'token' => $token,
            'lock'  => $lock,
        ];
    }

    $current = lavka_ecosystem_lock_get();

    // If another request left an expired lock between checks, clear and retry.
    if ($current && lavka_ecosystem_lock_is_stale($current)) {
        delete_option(LAVKA_ECOSYSTEM_LOCK_OPTION);

        if (add_option(LAVKA_ECOSYSTEM_LOCK_OPTION, $lock, '', 'no')) {
            return [
                'ok'    => true,
                'token' => $token,
                'lock'  => $lock,
            ];
        }
    }

    return [
        'ok'      => false,
        'token'   => null,
        'lock'    => $current ?: lavka_ecosystem_lock_get(),
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

    return delete_option(LAVKA_ECOSYSTEM_LOCK_OPTION);
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
    array $args = []
): int {
    $timestamp = time() + max(MINUTE_IN_SECONDS, $delay);
    wp_clear_scheduled_hook($hook, $args);
    wp_schedule_single_event($timestamp, $hook, $args);

    return $timestamp;
}
