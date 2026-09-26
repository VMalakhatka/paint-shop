<?php
namespace Lavka\Workshops;
defined('ABSPATH') || exit;

/** Fail closed. Opening the section is an explicit operator action, not a post status. */
function section_closed(): bool { return get_option('lw_section_visibility', 'closed') !== 'public'; }
function section_allowed(): bool {
    return !section_closed() || current_user_can('manage_options') || current_user_can('edit_lavka_workshops');
}
function section_request(array $vars): bool {
    if (in_array('lavka_workshop', (array) ($vars['post_type'] ?? []), true) || !empty($vars['lavka_workshop'])) return true;
    return !empty($vars['p']) && get_post_type((int) $vars['p']) === 'lavka_workshop';
}
function section_headers(): void {
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    nocache_headers();
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    header('Vary: Cookie', false);
}
add_action('parse_request', static function ($wp) {
    if (!section_closed() || !section_request($wp->query_vars)) return;
    section_headers();
    if (!section_allowed()) {
        // Stop before canonical redirects, feeds, embeds or templates expose any content.
        status_header(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Not found';
        exit;
    }
}, 0);
// Also cover custom queries, REST search and get_posts() with suppress_filters enabled.
add_action('pre_get_posts', static function ($query) {
    if (section_allowed()) return;
    $types = $query->get('post_type');
    if ($types === 'any') {
        $query->set('post_type', array_values(array_diff(get_post_types(['exclude_from_search' => false]), ['lavka_workshop'])) ?: ['post']);
    } elseif (in_array('lavka_workshop', (array) $types, true)) {
        $remaining = array_values(array_diff((array) $types, ['lavka_workshop']));
        $query->set('post_type', $remaining ?: ['post']);
        if (!$remaining) $query->set('post__in', [0]);
    }
    if ($query->get('p') && get_post_type((int) $query->get('p')) === 'lavka_workshop') $query->set('post__in', [0]);
});
add_filter('rest_pre_dispatch', static function ($result, $server, $request) {
    if (!section_allowed() && preg_match('#^/wp/v2/lavka_workshop(?:/|$)#', $request->get_route())) {
        return new \WP_Error('rest_no_route', __('Not found.', 'lavka-workshops'), ['status' => 404]);
    }
    return $result;
}, 10, 3);
add_filter('rest_post_dispatch', static function ($response, $server, $request) {
    if (section_closed() && preg_match('#^/wp/v2/lavka_workshop(?:/|$)#', $request->get_route())) {
        $response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
    return $response;
}, 10, 3);
// Embeds and sitemaps must not advertise test articles, even during a staff request.
add_filter('oembed_request_post_id', static fn($id) => section_closed() && get_post_type($id) === 'lavka_workshop' ? 0 : $id);
add_filter('wp_sitemaps_post_types', static function ($types) {
    if (section_closed()) unset($types['lavka_workshop']);
    return $types;
});
add_filter('rank_math/sitemap/exclude_post_type', static fn($exclude, $type) => $type === 'lavka_workshop' && section_closed() ? true : $exclude, 10, 2);
add_filter('wpseo_sitemap_exclude_post_type', static fn($exclude, $type) => $type === 'lavka_workshop' && section_closed() ? true : $exclude, 10, 2);
add_filter('wp_robots', static function ($robots) {
    if (section_closed() && (is_singular('lavka_workshop') || is_post_type_archive('lavka_workshop'))) {
        $robots['noindex'] = true; $robots['nofollow'] = true;
    }
    return $robots;
});
function closed_notice(): string {
    return __('Closed testing: only administrators and workshop instructors can view this section, including published workshops. Booking requests here are tests. The rest of the shop is public.', 'lavka-workshops');
}
add_action('admin_notices', static function () {
    $screen = get_current_screen();
    if (section_closed() && $screen && in_array($screen->post_type, ['lavka_workshop', 'lavka_mk_request'], true)) {
        echo '<div class="notice notice-warning"><p>' . esc_html(closed_notice()) . '</p></div>';
    }
});
