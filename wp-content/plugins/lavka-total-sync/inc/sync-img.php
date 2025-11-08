<?php
/**
 * Plugin Name: Lavka — Media Link Only (MU)
 * Description: Эндпоинт, который создаёт/находит attachment БЕЗ загрузки файла и привязывает к товару. Путь/URL не переписываются.
 * Author: Lavka
 * Version: 1.1
 */
if (!defined('ABSPATH')) { exit; }

if (!defined('LAVKA_MEDIA_TOKEN')) {
    define('LAVKA_MEDIA_TOKEN', '');
}

add_action('rest_api_init', function () {
  register_rest_route('lavka/v1', '/media/link-only', [
    'methods'  => 'POST',
    'callback' => 'lavka_media_link_only',
    'permission_callback' => function(\WP_REST_Request $r){
      // A) токен из опций/константы
      $opts1 = get_option('lts_options');
      $opts2 = get_option('lavka_sync_options');
      $expected = '';
      if (is_array($opts1) && !empty($opts1['api_token'])) $expected = (string)$opts1['api_token'];
      if (!$expected && is_array($opts2) && !empty($opts2['api_token'])) $expected = (string)$opts2['api_token'];
      if (!$expected && defined('LAVKA_MEDIA_TOKEN')) $expected = (string)LAVKA_MEDIA_TOKEN;

      $tok = (string)$r->get_header('X-Auth-Token');
      $hasToken = ($expected !== '' && is_string($tok) && hash_equals($expected, $tok));

      // B) Application Password → достаточно current_user_can()
      $hasBasic = current_user_can('manage_lavka_sync');

      return $hasToken || $hasBasic;
    },
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
});

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
    } else {
      $att_id = wp_insert_post([
        'post_status'    => 'inherit',
        'post_type'      => 'attachment',
        'post_mime_type' => $mime,
        'guid'           => $url,
        'post_title'     => basename($s3key),
      ], true);
      if (is_wp_error($att_id)) return $att_id;
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
    ], 200);

  } catch (\Throwable $e) {
    error_log('[lavka] link-only fatal: '.$e->getMessage()."\n".$e->getTraceAsString());
    return new \WP_Error('internal_server_error', $e->getMessage(), ['status'=>500]);
  }
}