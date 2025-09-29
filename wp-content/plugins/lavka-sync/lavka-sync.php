    <?php
    /**
     * Plugin Name: Lavka Sync
     * Description:Provides buttons and settings to synchronize with the Java service and exposes a REST API.
     * Version: 0.2.0
     * Text Domain: lavka-sync
     * Domain Path: /languages
     */

    if (!defined('ABSPATH')) exit;

    define('LAVKA_SYNC_VER', '0.2.0');
    define('LAVKA_SYNC_DIR', plugin_dir_path(__FILE__));
    define('LAVKA_SYNC_URL', plugin_dir_url(__FILE__));
    define('LAVKA_SYNC_INC', LAVKA_SYNC_DIR . 'inc');

    /** i18n: грузим переводы */
    add_action('plugins_loaded', function () {
        // обычная загрузка
        load_plugin_textdomain('lavka-sync', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // подстраховка (бывает, что uk/ru_RU не подцепляется автоматически)
        if (!is_textdomain_loaded('lavka-sync')) {
            $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
            $candidates = ["lavka-sync-$locale.mo"];
            foreach ($candidates as $name) {
                $p = plugin_dir_path(__FILE__) . "languages/$name";
                if (file_exists($p) && load_textdomain('lavka-sync', $p)) break;
            }
        }
    });

    /** загрузка модулей */
    foreach (['core.php', 'admin-ui.php', 'rest-api.php', 'warehouse-map.php', 'sync-java-query.php', 'sync.php'] as $f) {
        $p = LAVKA_SYNC_INC . '/' . $f;
        if (file_exists($p)) {
            require_once $p;
        } else {
            add_action('admin_notices', function() use ($f){
                echo '<div class="notice notice-error"><p>' .
                    esc_html(sprintf(__('Lavka Sync: file %s is missing.', 'lavka-sync'), "inc/$f")) .
                    '</p></div>';
            });
        }
    }