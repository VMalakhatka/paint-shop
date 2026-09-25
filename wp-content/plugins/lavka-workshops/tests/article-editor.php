<?php
/** Run locally: wp eval-file <this-file> --skip-plugins --skip-themes */
namespace Lavka\Workshops;
if (!defined('WP_CLI') || !WP_CLI || wp_parse_url(home_url(), PHP_URL_HOST) !== 'paint.local') throw new \RuntimeException('Local test only.');
require_once dirname(__DIR__) . '/lavka-workshops.php';
register();
$checks = 0; $user = 0; $post = 0;
$check = static function ($ok, $message) use (&$checks) { if (!$ok) throw new \RuntimeException($message); $checks++; };
try {
    $legacy = '<p>Intro &amp; photo <img src="https://example.org/art.jpg" alt="Art"></p><h2>Що ми створимо</h2><p>A painting.</p><h2>Для кого ця зустріч</h2><p>Adults.</p><h2>Що входить у вартість</h2><ul><li>Paints</li></ul><h2>Маленькі деталі</h2><p>Bring an apron.</p>';
    $parts = article_parts($legacy);
    $check(str_contains($parts['about']['content'], '<img'), 'Legacy photo preserved');
    $check($parts['create']['content'] === '<p>A painting.</p>', 'Known legacy sections split');
    $check($parts['details']['title'] === 'Маленькі деталі', 'Legacy title preserved');
    $canonical = compose_article(['unknown' => '<p>Ignored</p>'], []);
    $check($canonical === '', 'Unknown input ignored');
    $values = array_map(static fn($part) => $part['content'], $parts);
    $titles = array_map(static fn($part) => $part['title'], $parts);
    $canonical = compose_article($values, $titles);
    $check(article_parts($canonical) === $parts, 'All module contents and headings round trip');
    foreach (['<div>' . $legacy . '</div>', '<h2>Unusual heading</h2><p>Custom text</p>', '<h2 class="special">Що ми створимо</h2><p>Preserve attributes</p>', '<!-- wp:paragraph --><p>Block</p><!-- /wp:paragraph -->', '<!-- lw:section unknown --><p>Unknown</p><!-- /lw:section -->'] as $custom) {
        $check(article_parts($custom)['about']['content'] === $custom, 'Unknown layout kept whole');
    }
    $duplicate = '<!-- lw:section about --><p>One</p><!-- /lw:section --><!-- lw:section about --><p>Two</p><!-- /lw:section -->';
    $check(article_parts($duplicate)['about']['content'] === $duplicate, 'Duplicate markers kept whole');
    $safe = compose_article(['about'=>'<p onclick="alert(1)">Hello</p><script>alert(1)</script>', 'create'=>'<p>  </p>', 'included'=>'[gallery ids="1"]'], []);
    $check(!str_contains($safe, 'onclick') && !str_contains($safe, '<script'), 'Unsafe markup sanitized');
    $check(!str_contains($safe, 'lw:section create'), 'Empty module omitted');
    $check(str_contains($safe, '[gallery ids="1"]'), 'Shortcode retained');
    $check(str_contains(compose_article(['about'=>'<img src="https://example.org/art.jpg">'], []), '<img'), 'Photo-only module retained');
    $user = wp_insert_user(['user_login'=>'lw-article-test-' . wp_generate_uuid4(), 'user_pass'=>wp_generate_password(32), 'role'=>'lavka_instructor']);
    if (is_wp_error($user)) throw new \RuntimeException($user->get_error_message());
    wp_set_current_user($user);
    $post = wp_insert_post(['post_type'=>'lavka_workshop', 'post_status'=>'draft', 'post_title'=>'Synthetic article test', 'post_content'=>$legacy, 'post_author'=>$user]);
    $_POST = ['lw_article_nonce'=>wp_create_nonce('lw_article_' . $post), 'lw_article_complete'=>'1', 'lw_article'=>wp_slash($values), 'lw_article_titles'=>wp_slash($titles)];
    wp_update_post(['ID'=>$post, 'post_content'=>'stale hidden field']);
    $check(get_post_field('post_content', $post) === $canonical, 'Authorized save uses canonical module content');
    $revision = _wp_put_post_revision($post);
    $_POST['lw_article']['about'] = wp_slash('<p>A new student\'s painting.</p>');
    wp_update_post(['ID'=>$post]);
    $check(str_contains(get_post_field('post_content', $post), "student's"), 'Apostrophes survive save');
    $_POST = [];
    wp_restore_post_revision($revision);
    $check(get_post_field('post_content', $post) === $canonical, 'WP revision restores complete canonical article');
    require_once ABSPATH . 'wp-admin/includes/post.php';
    $preview_content = compose_article(['about'=>'<p>Unsaved preview text.</p>'], []);
    $_POST = wp_slash(['post_ID'=>$post, 'post_type'=>'lavka_workshop', 'post_status'=>'draft', 'post_title'=>'Synthetic article test', 'content'=>$preview_content, 'excerpt'=>'']);
    $preview_url = post_preview();
    $check(str_contains($preview_url, 'preview=true') && get_post_field('post_content', $post) === $preview_content, 'Core draft preview uses synchronized content');
    $_POST = [];
    wp_update_post(['ID'=>$post, 'post_status'=>'publish', 'post_content'=>$canonical]);
    $_POST = wp_slash(['post_ID'=>$post, 'post_type'=>'lavka_workshop', 'post_status'=>'publish', 'post_title'=>'Synthetic article test', 'content'=>$preview_content, 'excerpt'=>'']);
    $preview_url = post_preview();
    $autosave = wp_get_post_autosave($post, $user);
    $check($autosave && $autosave->post_content === $preview_content && str_contains($preview_url, 'preview_nonce='), 'Published preview stores unsaved text in an autosave revision');
    $check(get_post_field('post_content', $post) === $canonical, 'Published preview leaves live article unchanged');
    $data = ['post_type'=>'lavka_workshop', 'post_content'=>'UNCHANGED'];
    $_POST = ['lw_article_nonce'=>'invalid', 'lw_article_complete'=>'1', 'lw_article'=>['about'=>'Should not replace']];
    $check(apply_filters('wp_insert_post_data', $data, ['ID'=>$post], [], true)['post_content'] === 'UNCHANGED', 'Missing/invalid nonce leaves core data alone');
    $_POST['lw_article_nonce'] = wp_create_nonce('lw_article_' . $post);
    unset($_POST['lw_article_complete']);
    $check(apply_filters('wp_insert_post_data', $data, ['ID'=>$post], [], true)['post_content'] === 'UNCHANGED', 'Incomplete form cannot replace content');
    $_POST['lw_article_complete'] = '1';
    wp_set_current_user(0);
    $check(apply_filters('wp_insert_post_data', $data, ['ID'=>$post], [], true)['post_content'] === 'UNCHANGED', 'Guest cannot change module content');
    echo "PASS: $checks article editor checks\n";
} finally {
    $_POST = []; wp_set_current_user(0);
    if ($post) wp_delete_post($post, true);
    if ($user && !is_wp_error($user)) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($user); }
}
