<?php
/**
 * Запуск: wp eval-file /tmp/make-slugs.php
 * Переменные окружения:
 *   DRY_RUN=1                — только показать изменения
 *   WHAT=all|products|cats|tags — область обработки
 */

$dry  = getenv('DRY_RUN') ?: '';
$what = getenv('WHAT') ?: 'all';

function to_slug($text) {
    $text = trim((string)$text);
    if ($text === '') return '';

    // 1) intl через Transliterator
    if (class_exists('Transliterator')) {
        try {
            $tr = Transliterator::create('Any-Latin; Latin-ASCII');
            if ($tr) {
                $t = $tr->transliterate($text);
                $t = preg_replace('/[^A-Za-z0-9]+/', '-', $t);
                $t = strtolower(trim(preg_replace('/-+/', '-', $t), '-'));
                if ($t !== '') return $t;
            }
        } catch (Throwable $e) { }
    }

    // 2) iconv
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if ($t !== false) {
            $t = preg_replace('/[^A-Za-z0-9]+/', '-', $t);
            $t = strtolower(trim(preg_replace('/-+/', '-', $t), '-'));
            if ($t !== '') return $t;
        }
    }

    // 3) fallback
    $t = strtolower($text);
    $t = preg_replace('/\s+/', '-', $t);
    $t = preg_replace('/[^a-z0-9\-]/', '', $t);
    $t = preg_replace('/-+/', '-', $t);
    return trim($t, '-');
}

function ensure_unique_slug($slug, $type, $id) {
    $base = $slug;
    $i = 2;

    if ($type === 'post') {
        while (get_page_by_path($slug, OBJECT, ['product','product_variation'])) {
            $slug = $base . '-' . $i;
            $i++;
        }
    } elseif ($type === 'term') {
        while (term_exists($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }
    }
    return $slug;
}

$updated = ['products'=>0,'cats'=>0,'tags'=>0];

if ($what === 'all' || $what === 'products') {
    $ids = get_posts([
        'post_type'   => ['product','product_variation'],
        'post_status' => ['publish','draft','pending','private'],
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);
    foreach ($ids as $id) {
        $post = get_post($id);
        if (!$post) continue;
        $title = $post->post_title ?: '';
        if ($title === '') continue;

        $slug = to_slug($title);
        if ($slug === '') continue;

        $slug = ensure_unique_slug($slug, 'post', $id);
        if ($post->post_name === $slug) continue;

        if ($dry) {
            echo "[DRY] post #$id: {$post->post_name} -> $slug\n";
            continue;
        }
        $res = wp_update_post(['ID'=>$id,'post_name'=>$slug], true);
        if (is_wp_error($res)) {
            echo "ERR post #$id: ".$res->get_error_message()."\n";
        } else {
            $updated['products']++;
            echo "OK  post #$id: {$post->post_name} -> $slug\n";
        }
    }
}

if ($what === 'all' || $what === 'cats') {
    $terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false]);
    if (!is_wp_error($terms)) {
        foreach ($terms as $t) {
            $slug = to_slug($t->name);
            if ($slug === '') continue;

            $slug = ensure_unique_slug($slug, 'term', $t->term_id);
            if ($slug === $t->slug) continue;

            if ($dry) {
                echo "[DRY] cat  #{$t->term_id}: {$t->slug} -> $slug\n";
                continue;
            }
            $r = wp_update_term($t->term_id, 'product_cat', ['slug'=>$slug]);
            if (is_wp_error($r)) {
                echo "ERR cat  #{$t->term_id}: ".$r->get_error_message()."\n";
            } else {
                $updated['cats']++;
                echo "OK  cat  #{$t->term_id}: {$t->slug} -> $slug\n";
            }
        }
    }
}

if ($what === 'all' || $what === 'tags') {
    $terms = get_terms(['taxonomy'=>'product_tag','hide_empty'=>false]);
    if (!is_wp_error($terms)) {
        foreach ($terms as $t) {
            $slug = to_slug($t->name);
            if ($slug === '') continue;

            $slug = ensure_unique_slug($slug, 'term', $t->term_id);
            if ($slug === $t->slug) continue;

            if ($dry) {
                echo "[DRY] tag  #{$t->term_id}: {$t->slug} -> $slug\n";
                continue;
            }
            $r = wp_update_term($t->term_id, 'product_tag', ['slug'=>$slug]);
            if (is_wp_error($r)) {
                echo "ERR tag  #{$t->term_id}: ".$r->get_error_message()."\n";
            } else {
                $updated['tags']++;
                echo "OK  tag  #{$t->term_id}: {$t->slug} -> $slug\n";
            }
        }
    }
}

echo "Done. Updated: products={$updated['products']}, cats={$updated['cats']}, tags={$updated['tags']}\n";

if (!$dry) {
    flush_rewrite_rules(true);
    echo "Rewrite rules flushed.\n";
}
