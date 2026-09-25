<?php
namespace Lavka\Workshops;
defined('ABSPATH') || exit;

function article_modules(): array {
    return [
        'about' => ['title' => __('About the workshop', 'lavka-workshops'), 'hint' => __('Introduce the idea and atmosphere in two or three sentences.', 'lavka-workshops'), 'sample' => __('Give yourself a creative pause. At this workshop we will explore [technique] and create [artwork]. Choose your colours and enjoy the process at your own pace.', 'lavka-workshops')],
        'create' => ['title' => __('What we will create', 'lavka-workshops'), 'hint' => __('Describe the result: the artwork, its size and the techniques guests will try.', 'lavka-workshops'), 'sample' => __('You will create [artwork and size]. We will start with [first step], practise [technique] and add the finishing touches. Every participant will have their own unique result.', 'lavka-workshops')],
        'audience' => ['title' => __('Who it is for', 'lavka-workshops'), 'hint' => __('Specify age and experience requirements. Do not promise suitability you have not checked.', 'lavka-workshops'), 'sample' => __('This workshop is for [age group]. Experience required: [none / basic skills]. It is a lovely way to [try a new technique / spend time with friends / develop your skills].', 'lavka-workshops')],
        'included' => ['title' => __('What is included', 'lavka-workshops'), 'hint' => __('List only the materials and services actually included in your price.', 'lavka-workshops'), 'sample' => __('Included in the price:\n• [canvas or other base]\n• [paints and tools]\n• [instructor guidance]\n• [other included items]', 'lavka-workshops')],
        'details' => ['title' => __('Good to know', 'lavka-workshops'), 'hint' => __('What should guests bring? When can they collect their work? Explain cancellation terms.', 'lavka-workshops'), 'sample' => __('Please arrive [number] minutes before the start. Bring [what is needed]. Your artwork can be collected [when and how]. If your plans change, contact us [cancellation terms].', 'lavka-workshops')],
    ];
}

/** Read-only decomposition; unknown legacy layouts stay intact in the first editor. */
function article_parts(string $content): array {
    $parts = [];
    foreach (article_modules() as $key => $module) $parts[$key] = ['title' => $module['title'], 'content' => ''];
    if (!trim($content)) return $parts;
    $fallback = $parts;
    $fallback['about']['content'] = $content;
    $pattern = '~<!-- lw:section ([a-z]+) -->(.*?)<!-- /lw:section -->~s';
    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) && !trim(preg_replace($pattern, '', $content))) {
        $seen = [];
        foreach ($matches as $match) {
            $key = $match[1];
            if (!isset($parts[$key]) || isset($seen[$key])) return $fallback;
            $seen[$key] = true;
            $body = trim($match[2]);
            if ($key !== 'about' && preg_match('~^<h2\b[^>]*>(.*?)</h2>\s*~is', $body, $heading)) {
                $parts[$key]['title'] = html_entity_decode(wp_strip_all_tags($heading[1]), ENT_QUOTES, 'UTF-8');
                $body = substr($body, strlen($heading[0]));
            }
            $parts[$key]['content'] = $body;
        }
        return $parts;
    }
    // Only the known, ordered first-version template is split automatically.
    $aliases = [
        'create' => ['What we will create', 'Що ми створимо', 'Что мы создадим'],
        'audience' => ['Who it is for', 'Для кого цей майстер-клас', 'Для кого ця зустріч', 'Для кого этот мастер-класс'],
        'included' => ['What is included', 'Що входить у вартість', 'Что входит в стоимость'],
        'details' => ['Good to know', 'Важливо знати', 'Маленькі деталі', 'Важно знать'],
    ];
    $chunks = preg_split('~(<h2\b[^>]*>.*?</h2>)~is', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    $candidate = $parts;
    $candidate['about']['content'] = $chunks[0];
    $previous = -1;
    for ($i = 1; $i < count($chunks); $i += 2) {
        $title = html_entity_decode(wp_strip_all_tags($chunks[$i]), ENT_QUOTES, 'UTF-8');
        $key = null;
        foreach ($aliases as $name => $names) if (in_array(trim($title), $names, true)) $key = $name;
        $position = $key ? array_search($key, array_keys($aliases), true) : -1;
        if (!$key || $position <= $previous || !preg_match('~^<h2\s*>~i', $chunks[$i])) {
            $parts['about']['content'] = $content;
            return $parts;
        }
        $previous = $position;
        $candidate[$key] = ['title' => $title, 'content' => $chunks[$i + 1] ?? ''];
    }
    // Wrapping containers / block markup must not be split across module boundaries.
    if (preg_match('~<(?:div|section|article|table)\b|<!--\s*wp:~i', $content)) {
        $parts['about']['content'] = $content;
        return $parts;
    }
    return $candidate;
}

function compose_article(array $values, array $titles): string {
    $content = [];
    foreach (article_modules() as $key => $module) {
        $body = isset($values[$key]) && is_string($values[$key]) ? trim(wp_kses_post($values[$key])) : '';
        if (!trim(wp_strip_all_tags($body)) && !preg_match('~<(?:img|iframe|audio|video|hr)\b|\[[a-z]~i', $body)) continue;
        $title = isset($titles[$key]) && is_string($titles[$key]) ? sanitize_text_field($titles[$key]) : $module['title'];
        $content[] = '<!-- lw:section ' . $key . ' -->' . "\n" . ($key !== 'about' ? '<h2>' . esc_html($title) . '</h2>' . "\n" : '') . $body . "\n<!-- /lw:section -->";
    }
    return implode("\n\n", $content);
}

function is_article_screen(): bool {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    return $screen && $screen->post_type === 'lavka_workshop' && $screen->base === 'post';
}
add_filter('user_can_richedit', static fn($enabled) => is_article_screen() ? true : $enabled);
add_filter('wp_default_editor', static fn($editor) => is_article_screen() ? 'tinymce' : $editor);

add_action('edit_form_after_title', static function ($post) {
    if ($post->post_type !== 'lavka_workshop') return;
    $parts = article_parts($post->post_content);
    wp_nonce_field('lw_article_' . $post->ID, 'lw_article_nonce');
    echo '<textarea hidden id="content" name="content">' . esc_textarea($post->post_content) . '</textarea><div class="lw-article-editor"><div class="lw-editor-intro"><div><h2>' . esc_html__('Your workshop article', 'lavka-workshops') . '</h2><p>' . esc_html__('Fill in the sections below. The website will arrange them into a finished article. Empty sections are not shown.', 'lavka-workshops') . '</p></div><a class="button button-primary button-hero" target="_blank" rel="noopener" href="' . esc_url(get_preview_post_link($post)) . '">' . esc_html__('View saved article', 'lavka-workshops') . ' ↗</a></div><p class="description">' . esc_html__('Save your changes first, then open the article. Use the standard Preview changes button to preview unsaved text.', 'lavka-workshops') . '</p><nav class="lw-module-nav" aria-label="' . esc_attr__('Article sections', 'lavka-workshops') . '">';
    foreach (article_modules() as $key => $module) echo '<a href="#lw-module-' . $key . '">' . esc_html($module['title']) . '</a>';
    echo '</nav>';
    $number = 0;
    foreach (article_modules() as $key => $module) {
        $number++;
        echo '<section class="lw-module" id="lw-module-' . $key . '"><header><h3><span>' . $number . '</span> ' . esc_html($module['title']) . '</h3><button type="button" class="button lw-fill-example" data-editor="lw_article_' . $key . '">' . esc_html__('Insert example into empty section', 'lavka-workshops') . '</button></header><p>' . esc_html($module['hint']) . '</p><input type="hidden" name="lw_article_titles[' . $key . ']" value="' . esc_attr($parts[$key]['title']) . '">';
        wp_editor($parts[$key]['content'], 'lw_article_' . $key, [
            'textarea_name' => 'lw_article[' . $key . ']', 'textarea_rows' => 7, 'editor_height' => 180,
            'quicktags' => false, 'media_buttons' => true, 'drag_drop_upload' => false,
            'tinymce' => ['toolbar1' => 'bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo', 'toolbar2' => '', 'content_style' => 'body{font:16px/1.65 Arial,sans-serif;color:#28352d;margin:18px;}img{max-width:100%;height:auto;}'],
        ]);
        echo '<p class="lw-module-feedback" role="status" aria-live="polite"></p></section>';
    }
    echo '<input type="hidden" name="lw_article_complete" value="1"></div>';
});
add_filter('wp_insert_post_data', static function ($data, $postarr) {
    $id = absint($postarr['ID'] ?? 0);
    if ($data['post_type'] !== 'lavka_workshop' || !$id || !current_user_can('edit_post', $id) ||
        !wp_verify_nonce(input_text($_POST, 'lw_article_nonce'), 'lw_article_' . $id) || empty($_POST['lw_article_complete']) || !is_array($_POST['lw_article'] ?? null)) return $data;
    // One canonical post_content means normal WP revisions restore the full article.
    $data['post_content'] = wp_slash(compose_article(wp_unslash($_POST['lw_article']), (array) wp_unslash($_POST['lw_article_titles'] ?? [])));
    return $data;
}, 20, 2);
add_action('admin_enqueue_scripts', static function () {
    if (!is_article_screen()) return;
    wp_enqueue_editor();
    wp_enqueue_script('lw-article-editor', plugins_url('assets/article-editor.js', FILE), ['jquery', 'editor', 'wp-tinymce'], VERSION, true);
    wp_localize_script('lw-article-editor', 'lwArticle', ['modules' => article_modules(),
        'filled' => __('Example added. Replace the text in brackets with your details.', 'lavka-workshops'),
        'occupied' => __('This section already contains text or a photo. Your content has been kept.', 'lavka-workshops')]);
});
