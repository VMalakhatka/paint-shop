<?php
namespace Lavka\Workshops;
defined('ABSPATH') || exit;

/** Plain core blocks keep publications portable, revisionable and editable without this plugin. */
function pattern_block(string $name, array $attrs = [], string $html = ''): string {
    return get_comment_delimited_block_content('core/' . $name, $attrs, $html);
}
function pattern_paragraph(string $text): string { return pattern_block('paragraph', [], '<p>' . esc_html($text) . '</p>'); }
function pattern_heading(string $text): string { return pattern_block('heading', [], '<h2 class="wp-block-heading">' . esc_html($text) . '</h2>'); }
function pattern_group(string $content, string $class = 'lw-composition'): string {
    return pattern_block('group', ['className' => $class, 'layout' => ['type' => 'constrained']], '<div class="wp-block-group ' . esc_attr($class) . '">' . $content . '</div>');
}
function studio_patterns(): array {
    $image = pattern_block('image', ['sizeSlug' => 'large', 'linkDestination' => 'none'], '<figure class="wp-block-image size-large"><img alt="" /></figure>');
    $intro = pattern_paragraph(__('Give yourself a creative pause. Describe the idea of your workshop and the experience your guests will enjoy.', 'lavka-workshops'));
    $text = pattern_heading(__('What we will create', 'lavka-workshops')) . pattern_paragraph(__('Describe the artwork, its size and the techniques you will explore together. Replace this example with your own story.', 'lavka-workshops'));
    $column = static fn($body) => pattern_block('column', [], '<div class="wp-block-column">' . $body . '</div>');
    $columns = static fn($left, $right) => pattern_block('columns', [], '<div class="wp-block-columns">' . $column($left) . $column($right) . '</div>');
    $photo_text = pattern_group($columns($image, $text));
    $two_photos = pattern_group($columns($image, $image));
    $quote = pattern_group(pattern_block('quote', [], '<blockquote class="wp-block-quote">' . pattern_paragraph(__('Add a thought, a participant’s impression or a short quotation. Name the author when quoting someone.', 'lavka-workshops')) . '</blockquote>'), 'lw-composition lw-pullquote');
    $details = pattern_group(pattern_heading(__('Good to know', 'lavka-workshops')) . pattern_paragraph(__('Who is this workshop for? What is included? What should guests bring, and when can they collect their artwork? Add your actual details here.', 'lavka-workshops')), 'lw-composition lw-note-card');
    $teacher = pattern_group($columns($image, pattern_heading(__('Meet your instructor', 'lavka-workshops')) . pattern_paragraph(__('Introduce yourself: your name, favourite techniques and how you help participants create their own work.', 'lavka-workshops'))), 'lw-composition lw-note-card');
    $gallery = pattern_group(pattern_heading(__('Moments from the studio', 'lavka-workshops')) . pattern_block('gallery', ['linkTo' => 'none'], '<figure class="wp-block-gallery has-nested-images columns-default is-cropped"></figure>'));
    $cta = pattern_group(pattern_heading(__('Join us at the studio', 'lavka-workshops')) . pattern_paragraph(__('Choose a convenient date in the booking form below. The instructor will contact you to confirm your request.', 'lavka-workshops')) . pattern_block('buttons', [], '<div class="wp-block-buttons">' . pattern_block('button', [], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#lw-booking">' . esc_html__('Choose a date', 'lavka-workshops') . '</a></div>') . '</div>'), 'lw-composition lw-note-card');
    return [
        'workshop' => ['title' => __('Workshop presentation', 'lavka-workshops'), 'description' => __('A welcoming introduction, photo with text, practical details and an invitation to book.', 'lavka-workshops'), 'starter' => true, 'content' => pattern_group($intro . $photo_text . $details . $cta, 'lw-publication')],
        'story' => ['title' => __('Creative article', 'lavka-workshops'), 'description' => __('An introduction, a large photo, story sections and a highlighted quotation.', 'lavka-workshops'), 'starter' => true, 'content' => pattern_group($intro . $image . pattern_heading(__('The story behind the artwork', 'lavka-workshops')) . pattern_paragraph(__('Tell your story here. Add observations, useful advice and details that help the reader see the creative process through your eyes.', 'lavka-workshops')) . $quote . $photo_text, 'lw-publication')],
        'report' => ['title' => __('Studio photo report', 'lavka-workshops'), 'description' => __('A short story about the event, two photos, a gallery and participant impressions.', 'lavka-workshops'), 'starter' => true, 'content' => pattern_group(pattern_paragraph(__('Tell readers when the event took place, what you created together and what made the day special.', 'lavka-workshops')) . $two_photos . $gallery . $quote, 'lw-publication')],
        'photo-text' => ['title' => __('Photo and text', 'lavka-workshops'), 'content' => $photo_text],
        'two-photos' => ['title' => __('Two photos side by side', 'lavka-workshops'), 'content' => $two_photos],
        'quote' => ['title' => __('Highlighted quotation', 'lavka-workshops'), 'content' => $quote],
        'details' => ['title' => __('Practical details', 'lavka-workshops'), 'content' => $details],
        'instructor' => ['title' => __('Meet your instructor', 'lavka-workshops'), 'content' => $teacher],
        'gallery' => ['title' => __('Photo gallery', 'lavka-workshops'), 'content' => $gallery],
        'booking' => ['title' => __('Booking invitation', 'lavka-workshops'), 'content' => $cta],
    ];
}
add_action('init', static function () {
    register_block_pattern_category('lavka-studio', ['label' => __('Lavka · Studio', 'lavka-workshops')]);
    foreach (studio_patterns() as $slug => $pattern) {
        register_block_pattern('lavka-studio/' . $slug, [
            'title' => $pattern['title'], 'description' => $pattern['description'] ?? $pattern['title'],
            'categories' => ['lavka-studio'], 'postTypes' => ['lavka_workshop'],
            'viewportWidth' => 1000, 'content' => $pattern['content'],
        ]);
    }
}, 20);
