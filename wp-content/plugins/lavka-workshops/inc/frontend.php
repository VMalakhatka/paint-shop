<?php
namespace Lavka\Workshops;
defined('ABSPATH') || exit;

add_filter('template_include', static function ($template) {
    if (is_post_type_archive('lavka_workshop') || is_singular('lavka_workshop')) return dirname(FILE) . '/templates/page.php';
    return $template;
});
add_action('wp_enqueue_scripts', static function () {
    if (!is_post_type_archive('lavka_workshop') && !is_singular('lavka_workshop')) return;
    wp_enqueue_style('lw-public', plugins_url('assets/public.css', FILE), [], VERSION);
    wp_enqueue_script('lw-public', plugins_url('assets/public.js', FILE), [], VERSION, true);
    wp_localize_script('lw-public', 'lwPublic', ['endpoint' => admin_url('admin-ajax.php'), 'sending' => __('Sending…', 'lavka-workshops'),
        'error' => __('Could not confirm receipt. Your details are still here. Please try again; repeated requests will not create a duplicate.', 'lavka-workshops')]);
});
function cover(int $id, string $size = 'large'): void {
    if (has_post_thumbnail($id)) echo get_the_post_thumbnail($id, $size, ['class' => 'lw-cover', 'loading' => $size === 'full' ? 'eager' : 'lazy']);
    else echo '<div class="lw-cover lw-placeholder"><span>' . esc_html__('A little time for creativity', 'lavka-workshops') . '</span></div>';
}
function schedule(): void {
    $city = sanitize_key(input_text($_GET, 'city'));
    if (!isset(cities()[$city])) $city = '';
    $month = input_text($_GET, 'month');
    if (!preg_match('/^\d{4}-\d{2}$/D', $month)) $month = '';
    $posts = get_posts(['post_type' => 'lavka_workshop', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    $events = []; $months = [];
    foreach ($posts as $post) foreach (sessions($post->ID, true) as $row) {
        $key = substr($row['date'], 0, 7);
        $months[$key] = wp_date('F Y', $row['timestamp'], timezone());
        if (($city && $row['city'] !== $city) || ($month && $month !== $key)) continue;
        $events[] = ['post' => $post, 'session' => $row];
    }
    usort($events, static fn($a, $b) => $a['session']['timestamp'] <=> $b['session']['timestamp']);
    ksort($months);
    echo '<header class="lw-intro"><span class="lw-eyebrow">' . esc_html__('LAVKA · CREATIVE STUDIO', 'lavka-workshops') . '</span><h1>' . esc_html__('Make time for', 'lavka-workshops') . '<br><em>' . esc_html__('something beautiful.', 'lavka-workshops') . '</em></h1><p>' . esc_html__('Choose a workshop, bring your curiosity and create something of your own.', 'lavka-workshops') . '</p></header>';
    echo '<form class="lw-filters" method="get" action="' . esc_url(get_post_type_archive_link('lavka_workshop')) . '"><div><label for="lw-city">' . esc_html__('City', 'lavka-workshops') . '</label><select name="city" id="lw-city"><option value="">' . esc_html__('All cities', 'lavka-workshops') . '</option>';
    foreach (cities() as $key => $label) echo '<option value="' . $key . '" ' . selected($city, $key, false) . '>' . esc_html($label) . '</option>';
    echo '</select></div><div><label for="lw-month">' . esc_html__('When', 'lavka-workshops') . '</label><select name="month" id="lw-month"><option value="">' . esc_html__('All upcoming dates', 'lavka-workshops') . '</option>';
    foreach ($months as $key => $label) echo '<option value="' . esc_attr($key) . '" ' . selected($month, $key, false) . '>' . esc_html($label) . '</option>';
    echo '</select></div><button class="lw-button" type="submit">' . esc_html__('Show workshops', 'lavka-workshops') . '</button><p>' . esc_html__('Materials, inspiration and your next favourite memory.', 'lavka-workshops') . '</p></form>';
    echo '<div class="lw-section-heading"><h2>' . esc_html__('Upcoming workshops', 'lavka-workshops') . '</h2><span>' . esc_html__('All times are Kyiv time', 'lavka-workshops') . '</span></div>';
    if (!$events) {
        echo '<div class="lw-empty"><h3>' . esc_html__('New creative dates are on their way', 'lavka-workshops') . '</h3><p>' . esc_html__('There are no workshops for this selection yet. Try another city or month.', 'lavka-workshops') . '</p><a href="' . esc_url(get_post_type_archive_link('lavka_workshop')) . '">' . esc_html__('Reset filters', 'lavka-workshops') . '</a></div>';
        return;
    }
    $pages = (int) ceil(count($events) / 12);
    $page = min($pages, max(1, absint(input_text($_GET, 'mk_page'))));
    echo '<div class="lw-grid">';
    foreach (array_slice($events, ($page - 1) * 12, 12) as $event) {
        $post = $event['post']; $row = $event['session'];
        $url = add_query_arg('session', $row['id'], get_permalink($post));
        echo '<article class="lw-card"><a class="lw-card-image" href="' . esc_url($url) . '" aria-label="' . esc_attr($post->post_title) . '">';
        cover($post->ID);
        echo '<span class="lw-date-badge"><strong>' . esc_html(wp_date('d', $row['timestamp'], timezone())) . '</strong>' . esc_html(wp_date('M', $row['timestamp'], timezone())) . '</span></a><div class="lw-card-body"><div class="lw-meta">' . esc_html(cities()[$row['city']] . ' · ' . $row['time']) . '<span>' . esc_html(sprintf(__('%s min', 'lavka-workshops'), $row['duration'])) . '</span></div><h3><a href="' . esc_url($url) . '">' . esc_html($post->post_title) . '</a></h3><p>' . esc_html(wp_trim_words($post->post_excerpt ?: wp_strip_all_tags($post->post_content), 22)) . '</p><div class="lw-card-bottom"><strong>' . esc_html(price_label($row)) . '</strong><a href="' . esc_url($url) . '">' . esc_html($row['state'] === 'full' ? __('Fully booked', 'lavka-workshops') : __('Explore workshop', 'lavka-workshops')) . ' <span aria-hidden="true">↗︎</span></a></div></div></article>';
    }
    echo '</div>';
    if ($pages > 1) {
        echo '<nav class="lw-pagination" aria-label="' . esc_attr__('Schedule pages', 'lavka-workshops') . '">';
        for ($i = 1; $i <= $pages; $i++) echo '<a ' . ($i === $page ? 'aria-current="page"' : '') . ' href="' . esc_url(add_query_arg(['city' => $city, 'month' => $month, 'mk_page' => $i], get_post_type_archive_link('lavka_workshop'))) . '">' . $i . '</a>';
        echo '</nav>';
    }
}
function article(): void {
    the_post(); $id = get_the_ID();
    echo '<a class="lw-back" href="' . esc_url(get_post_type_archive_link('lavka_workshop')) . '">← ' . esc_html__('All workshops', 'lavka-workshops') . '</a><div class="lw-article-head"><div><span class="lw-eyebrow">' . esc_html__('YOUR CREATIVE PAUSE', 'lavka-workshops') . '</span><h1>' . esc_html(get_the_title()) . '</h1><p>' . esc_html(get_the_excerpt()) . '</p><a class="lw-button" href="#lw-booking">' . esc_html__('Choose a date', 'lavka-workshops') . ' <span aria-hidden="true">↗︎</span></a></div><div class="lw-hero-photo">';
    cover($id, 'full');
    $blocks = has_blocks(get_the_content());
    echo '</div></div><div class="lw-article-layout' . ($blocks ? ' lw-block-article' : '') . '"><article class="lw-article-content' . ($blocks ? ' lw-publication' : '') . '">';
    the_content();
    echo '</article><aside class="lw-booking" id="lw-booking"><span class="lw-eyebrow">' . esc_html__('LET’S CREATE TOGETHER', 'lavka-workshops') . '</span><h2>' . esc_html__('Your place at the table', 'lavka-workshops') . '</h2>';
    booking_form($id);
    echo '</aside></div>';
}
