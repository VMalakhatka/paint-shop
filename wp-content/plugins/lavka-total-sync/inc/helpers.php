<?php
if (!defined('ABSPATH')) exit;

/**
 * Retrieve and normalize plugin options for Lavka Total Sync.
 *
 * Options are stored as a single array in the wp_options table under the key
 * defined by LTS_OPT. This helper ensures sane defaults and casts to the
 * appropriate types when options are missing. The default values mirror
 * sensible defaults used in other Lavka sync modules, but exclude price
 * and stock synchronisation.
 *
 * @return array The merged options array with defaults applied.
 */
function lts_get_options(): array {
    $o = get_option(LTS_OPT, []);
    return wp_parse_args(is_array($o) ? $o : [], [
        'base_url'    => '',            // Base URL of the remote Java service
        'api_token'   => '',            // API token for authentication
        'path_sync'   => '/sync/goods', // Endpoint to trigger a goods synchronisation
        'path_status' => '/sync/goods/{id}', // Endpoint to poll task status
        'path_cancel' => '/sync/goods/{id}/cancel', // Endpoint to cancel a task
        'batch'       => LTS_DEF_BATCH, // Batch size for pagination
        'timeout'     => 160,           // HTTP timeout in seconds
    ]);
}

/**
 * Persist plugin options for Lavka Total Sync.
 *
 * This helper simply updates the options array in the database. It does
 * minimal validation; callers should sanitise values before calling.
 *
 * @param array $o The options array to store.
 * @return void
 */
function lts_update_options(array $o): void {
    update_option(LTS_OPT, $o, false);
}

/**
 * Build a full Java service URL from a path.
 *
 * Concatenates the configured base URL and the provided path, ensuring
 * leading/trailing slashes are handled gracefully. Does not perform any
 * network requests.
 *
 * @param string $path The relative path or endpoint.
 * @return string Full URL to the endpoint on the Java service.
 */
function lts_build_url(string $path): string {
    $o = lts_get_options();
    $base = rtrim($o['base_url'], '/');
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

/**
 * Perform a GET request to the Java service.
 *
 * Wraps WordPress's wp_remote_get() with appropriate headers (API token and
 * JSON accept header) and timeout. Returns the raw response; callers
 * should handle errors and decode JSON if necessary.
 *
 * @param string $path Relative path on the Java service.
 * @param array $args Additional request arguments.
 * @return array|WP_Error Response from wp_remote_get().
 */
function lts_java_get(string $path, array $args = []) {
    $o = lts_get_options();
    $url = lts_build_url($path);
    $args = wp_parse_args($args, [
        'timeout' => (int)($o['timeout'] ?? 160),
        'headers' => [
            'X-Auth-Token' => (string)($o['api_token'] ?? ''),
            'Accept'       => 'application/json',
        ],
    ]);
    return wp_remote_get($url, $args);
}

/**
 * Perform a POST request to the Java service.
 *
 * Like lts_java_get() but for POST requests, automatically encoding the
 * body as JSON when necessary and setting the appropriate content type.
 *
 * @param string $path Relative path on the Java service.
 * @param mixed  $body Request payload; array/object will be JSON encoded.
 * @param array $args Additional request arguments.
 * @return array|WP_Error Response from wp_remote_post().
 */
function lts_java_post(string $path, $body, array $args = []) {
    $o = lts_get_options();
    $url = lts_build_url($path);
    $args = wp_parse_args($args, [
        'timeout' => (int)($o['timeout'] ?? 160),
        'headers' => [
            'X-Auth-Token'   => (string)($o['api_token'] ?? ''),
            'Accept'         => 'application/json',
            'Content-Type'   => 'application/json',
        ],
        'body'    => is_string($body) ? $body : wp_json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    return wp_remote_post($url, $args);
}