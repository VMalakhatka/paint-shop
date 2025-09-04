<?php
/**
 * Общие настройки для всех окружений.
 * ВАЖНО: здесь НЕ подключаем wp-settings.php и НЕ задаём DB_*.
 */

/* Кодировка таблиц по умолчанию */
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8');
if (!defined('DB_COLLATE')) define('DB_COLLATE', '');

/* Префикс таблиц */
if (!isset($table_prefix)) $table_prefix = 'wp_';

/* Логи/отладка: значения по дефолту — «разумные», но переопределяются в env-файлах */
if (!defined('WP_DEBUG'))         define('WP_DEBUG', false);
if (!defined('WP_DEBUG_LOG'))     define('WP_DEBUG_LOG', true);   // писать в wp-content/debug.log
if (!defined('WP_DEBUG_DISPLAY')) define('WP_DEBUG_DISPLAY', false);
@ini_set('display_errors', 0);

/* Тип окружения по умолчанию (может быть переопределён env-файлами/переменной окружения) */
if (!defined('WP_ENVIRONMENT_TYPE')) define('WP_ENVIRONMENT_TYPE', 'production');

/* Ключи и соли.
   — Безопасней держать их НЕ в репозитории:
     либо через переменные окружения, либо в отдельном некоммитимом файле.
   — На худой конец можно положить сюда «на все окружения», но реальные значения не коммитить.
*/
if (!defined('AUTH_KEY')) {
    // Пример: подтягиваем из getenv(), если заданы; иначе — заглушки (замените своими на сервере/локали).
    define('AUTH_KEY',         getenv('AUTH_KEY')         ?: 'change-me');
    define('SECURE_AUTH_KEY',  getenv('SECURE_AUTH_KEY')  ?: 'change-me');
    define('LOGGED_IN_KEY',    getenv('LOGGED_IN_KEY')    ?: 'change-me');
    define('NONCE_KEY',        getenv('NONCE_KEY')        ?: 'change-me');
    define('AUTH_SALT',        getenv('AUTH_SALT')        ?: 'change-me');
    define('SECURE_AUTH_SALT', getenv('SECURE_AUTH_SALT') ?: 'change-me');
    define('LOGGED_IN_SALT',   getenv('LOGGED_IN_SALT')   ?: 'change-me');
    define('NONCE_SALT',       getenv('NONCE_SALT')       ?: 'change-me');
    if (!defined('WP_CACHE_KEY_SALT')) {
        define('WP_CACHE_KEY_SALT', getenv('WP_CACHE_KEY_SALT') ?: 'change-me');
    }
}

/* Абсолютный путь (подстраховка) */
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}