<?php
/**
 * Plugin Name: Lavka — Media Link Only (MU)
 * Description: Эндпоинт, который создаёт/находит attachment БЕЗ загрузки файла и привязывает к товару. Путь/URL не переписываются.
 * Author: Lavka
 * Version: 1.2
 */
if (!defined('ABSPATH')) { exit; }

if (!defined('LAVKA_MEDIA_TOKEN')) {
    define('LAVKA_MEDIA_TOKEN', '');
}

add_action('rest_api_init', function () {
  register_rest_route('lavka/v1', '/media/link-only', [
    'methods'  => 'POST',
    'callback' => 'lavka_media_link_only',
    'permission_callback' => 'lavka_media_permission',
    'args' => [
      'product_id'        => ['required'=>true, 'type'=>'integer'],
      's3_key'            => ['required'=>true, 'type'=>'string'],
      'url'               => ['required'=>true, 'type'=>'string'],
      'mime'              => ['required'=>false,'type'=>'string'],
      'set_featured'      => ['required'=>false,'type'=>'boolean'],
      'add_to_gallery'    => ['required'=>false,'type'=>'boolean'],
      'gallery_position'  => ['required'=>false,'type'=>'integer'],
      'alt'               => ['required'=>false,'type'=>'string'],
      'title'             => ['required'=>false,'type'=>'string'],
    ],
  ]);

  register_rest_route('lavka/v1', '/media/reconcile', [
    'methods'  => 'POST',
    'callback' => 'lavka_media_reconcile',
    'permission_callback' => 'lavka_media_permission',
    'args' => [
      'product_id'      => ['required'=>true,  'type'=>'integer'],
      'sku'             => ['required'=>true,  'type'=>'string'],
      'featured'        => ['required'=>false, 'type'=>['object', 'null']],
      'gallery'         => ['required'=>false, 'type'=>'array', 'default'=>[]],
      'replace_gallery' => ['required'=>false, 'type'=>'boolean', 'default'=>true],
      'dry_run'         => ['required'=>false, 'type'=>'boolean', 'default'=>false],
    ],
  ]);
});

function lavka_media_permission(\WP_REST_Request $r) {
  $opts1 = get_option('lts_options');
  $opts2 = get_option('lavka_sync_options');
  $expected = '';
  if (is_array($opts1) && !empty($opts1['api_token'])) $expected = (string)$opts1['api_token'];
  if (!$expected && is_array($opts2) && !empty($opts2['api_token'])) $expected = (string)$opts2['api_token'];
  if (!$expected && defined('LAVKA_MEDIA_TOKEN')) $expected = (string)LAVKA_MEDIA_TOKEN;

  $tok = (string)$r->get_header('X-Auth-Token');
  $hasToken = ($expected !== '' && hash_equals($expected, $tok));
  return $hasToken || current_user_can('manage_lavka_sync');
}

function lavka_media_link_only(\WP_REST_Request $r) {
  try {
    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log('[lavka] link-only params: '. json_encode($r->get_params(), JSON_UNESCAPED_UNICODE));
    }

    // Глушим генерацию метаданных (Media Cloud и т.п.)
    if (!defined('LAVKA_LINK_ONLY')) define('LAVKA_LINK_ONLY', true);
    add_filter('intermediate_image_sizes_advanced', '__return_empty_array', 9999);
    add_filter('wp_generate_attachment_metadata', function($md){ return []; }, 9999);

    $pid   = (int)$r->get_param('product_id');
    $s3key = (string)$r->get_param('s3_key');
    $url   = (string)$r->get_param('url');
    $mime  = $r->get_param('mime') ? (string)$r->get_param('mime') : 'image/jpeg';
    $set_featured   = (bool)$r->get_param('set_featured');
    $add_to_gallery = (bool)$r->get_param('add_to_gallery');
    $gal_pos        = is_null($r->get_param('gallery_position')) ? 0 : (int)$r->get_param('gallery_position');
    $alt_in         = (string)($r->get_param('alt') ?? '');
    $title_in       = (string)($r->get_param('title') ?? '');

    // Нормализация входа
    $s3key = wp_unslash($s3key);
    $url   = esc_url_raw(wp_unslash($url));
    $s3key = str_replace('\\', '/', $s3key);
    $s3key = ltrim($s3key, '/');
    if (strpos($s3key, 'wp-content/uploads/') === 0) {
      $s3key = substr($s3key, strlen('wp-content/uploads/'));
    }

    if (!$pid || $s3key === '' || $url === '') {
      return new \WP_Error('bad_request', 'product_id, s3_key, url required', ['status'=>400]);
    }

    global $wpdb;
    $att_id = 0;
    $attachment_created = false;
    $attachment_parent_before = null;
    $attachment_parent_after = null;
    $attachment_parent_changed = false;
    $attachment_parent_conflict = false;

    // 1) по короткому _wp_attached_file
    $att_id = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1", $s3key
    ));

    // 2) по длинному варианту (совместимость)
    if (!$att_id) {
      $long = 'wp-content/uploads/' . $s3key;
      $att_id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1", $long
      ));
    }

    // 3) по guid
    if (!$att_id) {
      $att_id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND guid=%s LIMIT 1", $url
      ));
    }

    if ($att_id) {
      update_post_meta($att_id, '_wp_attached_file', $s3key);
      if ($mime) wp_update_post(['ID'=>$att_id,'post_mime_type'=>$mime]);

      $attachment_parent_before = (int)get_post_field('post_parent', $att_id);
      $attachment_parent_after = $attachment_parent_before;

      // Fill an empty Media Library relation, but never steal an attachment
      // that is already owned by another post.
      if ($attachment_parent_before === 0) {
        $parent_result = wp_update_post([
          'ID'          => $att_id,
          'post_parent' => $pid,
        ], true);
        if (is_wp_error($parent_result)) return $parent_result;

        $attachment_parent_after = $pid;
        $attachment_parent_changed = true;
      } elseif ($attachment_parent_before !== $pid) {
        $attachment_parent_conflict = true;
      }
    } else {
      $att_id = wp_insert_post([
        'post_status'    => 'inherit',
        'post_type'      => 'attachment',
        'post_mime_type' => $mime,
        'post_parent'    => $pid,
        'guid'           => $url,
        'post_title'     => basename($s3key),
      ], true);
      if (is_wp_error($att_id)) return $att_id;
      $attachment_created = true;
      $attachment_parent_after = $pid;
      $attachment_parent_changed = true;
      update_post_meta($att_id, '_wp_attached_file', $s3key);
    }

    // ✅ ТЕПЕРЬ можно писать title/alt
    $title = $title_in !== '' ? sanitize_text_field($title_in) : '';
    $alt   = $alt_in   !== '' ? sanitize_text_field($alt_in)   : '';
    if ($title !== '') wp_update_post(['ID' => $att_id, 'post_title' => $title]);
    if ($alt   !== '') update_post_meta($att_id, '_wp_attachment_image_alt', $alt);

    // Привязка к товару
    if ($set_featured) {
      update_post_meta($pid, '_thumbnail_id', $att_id);
    }
    if ($add_to_gallery) {
      $ids = get_post_meta($pid, '_product_image_gallery', true);
      $arr = $ids ? array_filter(array_map('intval', explode(',', $ids))) : [];
      $gal_pos = max(0, min($gal_pos, count($arr)));
      array_splice($arr, $gal_pos, 0, [$att_id]);
      $arr = array_values(array_unique($arr));
      update_post_meta($pid, '_product_image_gallery', implode(',', $arr));
    }

    return new \WP_REST_Response([
      'ok'            => true,
      'attachment_id' => $att_id,
      'product_id'    => $pid,
      'featured_set'  => $set_featured,
      'gallery_added' => $add_to_gallery,
      's3_key'        => $s3key,
      'url'           => $url,
      'attachment_created'         => $attachment_created,
      'attachment_parent_before'   => $attachment_parent_before,
      'attachment_parent_after'    => $attachment_parent_after,
      'attachment_parent_changed'  => $attachment_parent_changed,
      'attachment_parent_conflict' => $attachment_parent_conflict,
    ], 200);

  } catch (\Throwable $e) {
    error_log('[lavka] link-only fatal: '.$e->getMessage()."\n".$e->getTraceAsString());
    return new \WP_Error('internal_server_error', $e->getMessage(), ['status'=>500]);
  }
}

/** Полностью согласует главное изображение и упорядоченную галерею одного товара. */
function lavka_media_reconcile(\WP_REST_Request $r) {
  $created_ids = [];
  try {
    if (!function_exists('wc_get_product')) {
      return new \WP_Error('woocommerce_unavailable', 'WooCommerce is not available', ['status'=>503]);
    }

    $pid = (int)$r->get_param('product_id');
    $sku = trim((string)$r->get_param('sku'));
    $dry_run = (bool)$r->get_param('dry_run');
    $replace_gallery = (bool)$r->get_param('replace_gallery');
    $product = wc_get_product($pid);
    if (!$product) {
      return new \WP_Error('product_not_found', 'WooCommerce product not found', ['status'=>404]);
    }
    if ($sku === '' || (string)$product->get_sku() !== $sku) {
      return new \WP_Error('sku_mismatch', 'product_id does not match sku', ['status'=>409]);
    }

    $featured_input = $r->get_param('featured');
    $gallery_input = $r->get_param('gallery');
    if (!is_array($gallery_input)) $gallery_input = [];
    usort($gallery_input, static function($a, $b) {
      return ((int)($a['position'] ?? 0)) <=> ((int)($b['position'] ?? 0));
    });

    // Разрешаем/создаём все attachment до изменения товара. При ошибке товар
    // остаётся без изменений, а созданные в этом запросе attachment удаляются.
    $featured_prepared = null;
    if (is_array($featured_input) && !empty($featured_input)) {
      $featured_prepared = lavka_media_prepare_descriptor($featured_input, $pid, $dry_run, $created_ids);
      if (is_wp_error($featured_prepared)) {
        lavka_media_cleanup_created($created_ids);
        return $featured_prepared;
      }
    }

    $gallery_prepared = [];
    $seen_keys = [];
    foreach ($gallery_input as $descriptor) {
      if (!is_array($descriptor)) {
        lavka_media_cleanup_created($created_ids);
        return new \WP_Error('bad_gallery_item', 'Each gallery item must be an object', ['status'=>400]);
      }
      $key = lavka_media_normalize_key((string)($descriptor['s3_key'] ?? ''));
      if ($key !== '' && isset($seen_keys[$key])) continue;
      if ($key !== '') $seen_keys[$key] = true;

      $prepared = lavka_media_prepare_descriptor($descriptor, $pid, $dry_run, $created_ids);
      if (is_wp_error($prepared)) {
        lavka_media_cleanup_created($created_ids);
        return $prepared;
      }
      $gallery_prepared[] = $prepared;
    }

    $before_featured = (int)$product->get_image_id();
    $before_gallery = array_values(array_unique(array_map('intval', $product->get_gallery_image_ids())));
    $featured_after = $before_featured;
    if ($featured_prepared !== null && $featured_prepared['attachment_id'] !== null) {
      $featured_after = (int)$featured_prepared['attachment_id'];
    }

    $desired_ids = [];
    foreach ($gallery_prepared as $prepared) {
      if ($prepared['attachment_id'] === null) continue;
      $id = (int)$prepared['attachment_id'];
      if ($id > 0 && $id !== $featured_after && !in_array($id, $desired_ids, true)) {
        $desired_ids[] = $id;
      }
    }
    $after_gallery = $desired_ids;
    if (!$replace_gallery) {
      foreach ($before_gallery as $id) {
        if ($id > 0 && $id !== $featured_after && !in_array($id, $after_gallery, true)) {
          $after_gallery[] = $id;
        }
      }
    }

    $removed = array_values(array_diff($before_gallery, $after_gallery));
    $featured_changed = $featured_after !== $before_featured;
    $gallery_changed = $after_gallery !== $before_gallery;

    if (!$dry_run) {
      foreach (array_filter([$featured_prepared]) as $prepared) {
        lavka_media_apply_attachment_meta($prepared, $pid);
      }
      foreach ($gallery_prepared as $prepared) {
        lavka_media_apply_attachment_meta($prepared, $pid);
      }

      if ($featured_prepared !== null) $product->set_image_id($featured_after);
      if ($gallery_changed) $product->set_gallery_image_ids($after_gallery);
      if ($featured_changed || $gallery_changed) $product->save();
    }

    $status = 'noop';
    if ($dry_run) {
      $status = ($featured_changed || $gallery_changed || !empty($created_ids)) ? 'preview' : 'noop';
    } elseif ($featured_changed || $gallery_changed) {
      $status = $replace_gallery ? 'applied' : 'partial';
    }

    return new \WP_REST_Response([
      'ok' => true,
      'product_id' => $pid,
      'sku' => $sku,
      'dry_run' => $dry_run,
      'featured' => [
        'before' => $before_featured ?: null,
        'after' => $featured_after ?: null,
        'attachment_id' => $featured_after ?: null,
        'changed' => $featured_changed,
        'would_create' => $featured_prepared['would_create'] ?? false,
      ],
      'gallery' => [
        'before' => $before_gallery,
        'after' => $after_gallery,
        'removed' => $removed,
        'changed' => $gallery_changed,
        'replace' => $replace_gallery,
        'items' => $gallery_prepared,
      ],
      'status' => $status,
    ], 200);
  } catch (\Throwable $e) {
    lavka_media_cleanup_created($created_ids);
    error_log('[lavka] media reconcile fatal: '.$e->getMessage()."\n".$e->getTraceAsString());
    return new \WP_Error('media_reconcile_failed', $e->getMessage(), ['status'=>500]);
  }
}

function lavka_media_normalize_key($s3key) {
  $s3key = str_replace('\\', '/', wp_unslash((string)$s3key));
  $s3key = ltrim($s3key, '/');
  if (strpos($s3key, 'wp-content/uploads/') === 0) {
    $s3key = substr($s3key, strlen('wp-content/uploads/'));
  }
  return $s3key;
}

function lavka_media_prepare_descriptor(array $descriptor, $pid, $dry_run, array &$created_ids) {
  global $wpdb;
  $s3key = lavka_media_normalize_key((string)($descriptor['s3_key'] ?? ''));
  $url = esc_url_raw(wp_unslash((string)($descriptor['url'] ?? '')));
  $mime = sanitize_mime_type((string)($descriptor['mime'] ?? 'image/jpeg')) ?: 'image/jpeg';
  if ($s3key === '' || $url === '') {
    return new \WP_Error('bad_media_descriptor', 's3_key and url are required', ['status'=>400]);
  }

  $att_id = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1",
    $s3key
  ));
  if (!$att_id) {
    $long = 'wp-content/uploads/' . $s3key;
    $att_id = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1",
      $long
    ));
  }
  if (!$att_id) {
    $att_id = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND guid=%s LIMIT 1",
      $url
    ));
  }

  $would_create = !$att_id;
  if (!$att_id && !$dry_run) {
    if (!defined('LAVKA_LINK_ONLY')) define('LAVKA_LINK_ONLY', true);
    add_filter('intermediate_image_sizes_advanced', '__return_empty_array', 9999);
    add_filter('wp_generate_attachment_metadata', function($md){ return []; }, 9999);
    $att_id = wp_insert_post([
      'post_status'    => 'inherit',
      'post_type'      => 'attachment',
      'post_mime_type' => $mime,
      'post_parent'    => (int)$pid,
      'guid'           => $url,
      'post_title'     => basename($s3key),
    ], true);
    if (is_wp_error($att_id)) return $att_id;
    $att_id = (int)$att_id;
    $created_ids[] = $att_id;
  }

  return [
    'attachment_id' => $att_id ?: null,
    'would_create' => $would_create,
    's3_key' => $s3key,
    'url' => $url,
    'mime' => $mime,
    'position' => isset($descriptor['position']) ? (int)$descriptor['position'] : null,
    'alt' => sanitize_text_field((string)($descriptor['alt'] ?? '')),
    'title' => sanitize_text_field((string)($descriptor['title'] ?? '')),
  ];
}

function lavka_media_apply_attachment_meta(array $prepared, $pid) {
  $att_id = (int)($prepared['attachment_id'] ?? 0);
  if (!$att_id) return;
  update_post_meta($att_id, '_wp_attached_file', $prepared['s3_key']);

  $update = ['ID'=>$att_id, 'post_mime_type'=>$prepared['mime']];
  if ($prepared['title'] !== '') $update['post_title'] = $prepared['title'];
  $result = wp_update_post($update, true);
  if (is_wp_error($result)) throw new \RuntimeException($result->get_error_message());
  if ($prepared['alt'] !== '') update_post_meta($att_id, '_wp_attachment_image_alt', $prepared['alt']);

  $parent = (int)get_post_field('post_parent', $att_id);
  if ($parent === 0) {
    $result = wp_update_post(['ID'=>$att_id, 'post_parent'=>(int)$pid], true);
    if (is_wp_error($result)) throw new \RuntimeException($result->get_error_message());
  }
}

function lavka_media_cleanup_created(array $created_ids) {
  foreach (array_reverse($created_ids) as $id) {
    wp_delete_attachment((int)$id, true);
  }
}
