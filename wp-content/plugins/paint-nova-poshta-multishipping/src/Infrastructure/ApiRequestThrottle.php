<?php
namespace Paint\NovaPoshta\Infrastructure;

defined('ABSPATH') || exit;

/** One in-flight JSON request per site/key. No SQL transaction is held over HTTP. */
class ApiRequestThrottle
{
    public function run(string $key, callable $request)
    {
        global $wpdb;
        $scope = substr(hash('sha256', $wpdb->prefix . '|' . $key), 0, 40);
        $lock = 'pnpm:api:' . $scope;
        $cache = 'pnpm_api_after_' . $scope;
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)', $lock)) !== '1') return self::busy();
        try {
            // Bypass the per-request object-cache copy after taking the cross-process lock.
            if (wp_using_ext_object_cache()) {
                $next = (float)wp_cache_get($cache, 'transient', true);
            } else {
                wp_cache_delete('_transient_' . $cache, 'options');
                wp_cache_delete('_transient_timeout_' . $cache, 'options');
                $next = (float)get_transient($cache);
            }
            $wait = max(0, $next - microtime(true));
            if ($wait > 3) return self::busy();
            if ($wait > 0) usleep((int)ceil($wait * 1000000));
            // Reserve before sending. Failed persistence must not permit an unpaced request.
            if (!set_transient($cache, microtime(true) + 1.1, 10)) return self::busy();
            $result = $request();
            if (is_wp_error($result) && $result->get_error_code() === 'pnpm_api_rate_limited') {
                set_transient($cache, microtime(true) + 2, 10);
            }
            return $result;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function busy(): \WP_Error
    {
        return new \WP_Error('pnpm_api_busy', __('Nova Poshta requests are busy. Wait a few seconds before checking again.', 'paint-nova-poshta-multishipping'));
    }
}
