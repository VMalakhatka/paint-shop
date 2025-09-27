<?php
/**
 * Plugin Name: Lavka Sync
 * Description: Кнопки/настройки синхронизации с Java-сервисом + REST API.
 * Version: 0.2.0
 */

if (!defined('ABSPATH')) exit;

define('LAVKA_SYNC_VER', '0.2.0');
define('LAVKA_SYNC_DIR', plugin_dir_path(__FILE__));
define('LAVKA_SYNC_URL', plugin_dir_url(__FILE__));
define('LAVKA_SYNC_INC', LAVKA_SYNC_DIR . 'inc');

foreach (['core.php', 'admin-ui.php', 'rest-api.php', 'warehouse-map.php', 'sync-java-query.php','sync.php'] as $f) {
    $p = LAVKA_SYNC_INC . '/' . $f;
    if (file_exists($p)) {
        require_once $p;
    } else {
        add_action('admin_notices', function() use ($f){
            echo '<div class="notice notice-error"><p>Lavka Sync: не найден файл inc/' . esc_html($f) . '</p></div>';
        });
    }
}