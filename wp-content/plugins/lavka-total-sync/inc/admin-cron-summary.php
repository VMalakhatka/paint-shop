<?php
if (!defined('ABSPATH')) exit;

/**
 * Админ-страница «Сводка задач (Cron)» для Lavka Total Sync.
 *
 * Показывает только задачи, связанные с нашим плагином:
 *  - LTS_CRON_HOOK        (тотальная синхронизация товаров)
 *  - LTS_MEDIA_CRON_HOOK  (синка картинок по диапазону)
 *
 * Никаких задач не создаёт и не удаляет — только читает расписание.
 */

/**
 * Какие крон-хуки считаем «нашими».
 * При необходимости можно добавить ещё.
 *
 * ВАЖНО: значения должны совпадать с тем, что передаётся в wp_schedule_*_event().
 */
function lts_cron_get_plugin_hooks(): array {
    $hooks = [];

    // Тотал-синк товаров (см. admin-ui.php — там мы определяли константу)
    if (defined('LTS_CRON_HOOK')) {
        $hooks[] = LTS_CRON_HOOK;
    } else {
        // fallback, если константа не определена, но хуки названы строкой
        $hooks[] = 'lts_total_sync_cron_event';
    }

    // Синхронизация медиа (images)
    if (defined('LTS_MEDIA_CRON_HOOK')) {
        $hooks[] = LTS_MEDIA_CRON_HOOK;
    } else {
        $hooks[] = 'lts_media_cron_event';
    }

    // Убираем дубли и пустые
    $hooks = array_values(array_filter(array_unique($hooks)));
    return $hooks;
}

/**
 * Регистрация пункта меню под Lavka Total Sync.
 */
add_action('admin_menu', function () {
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';

    add_submenu_page(
        'lts-main',
        __('Cron summary', 'lavka-total-sync'),
        __('Cron summary', 'lavka-total-sync'),
        $cap,
        'lts-cron-summary',
        'lts_render_cron_summary_page'
    );
});

/**
 * Рендер страницы сводки.
 */
function lts_render_cron_summary_page() {
    $cap = defined('LTS_CAP') ? LTS_CAP : 'manage_options';
    if (!current_user_can($cap)) {
        return;
    }

    // Текущие времена
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(get_option('timezone_string') ?: 'UTC');
    $nowSite = new DateTime('now', $tz);
    $nowUtc  = new DateTime('now', new DateTimeZone('UTC'));

    // Получаем весь крон-массив
    $cron = _get_cron_array();
    $pluginHooks = lts_cron_get_plugin_hooks();
    $schedules = wp_get_schedules();

    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Cron summary', 'lavka-total-sync'); ?></h1>

        <p class="description">
            <?php
            echo esc_html__(
                'На этой странице показаны только задачи WP-Cron, которые относятся к плагину Lavka Total Sync (товары и медиа).',
                'lavka-total-sync'
            );
            ?>
        </p>

        <table class="widefat striped" style="max-width: 1200px; margin-top: 1rem;">
            <tbody>
            <tr>
                <th><?php _e('Site time now', 'lavka-total-sync'); ?></th>
                <td><?php echo esc_html($nowSite->format('Y-m-d H:i:s')); ?>
                    (<?php echo esc_html($tz->getName()); ?>)
                </td>
            </tr>
            <tr>
                <th><?php _e('UTC/server time now', 'lavka-total-sync'); ?></th>
                <td><?php echo esc_html($nowUtc->format('Y-m-d H:i:s')); ?> (UTC)</td>
            </tr>
            <tr>
                <th><?php _e('Plugin hooks', 'lavka-total-sync'); ?></th>
                <td><code><?php echo esc_html(implode(', ', $pluginHooks)); ?></code></td>
            </tr>
            </tbody>
        </table>

        <h2 style="margin-top: 1.5rem;"><?php _e('Scheduled events', 'lavka-total-sync'); ?></h2>

        <?php
        if (empty($cron) || empty($pluginHooks)) {
            echo '<p>' . esc_html__('No cron events found.', 'lavka-total-sync') . '</p>';
            echo '</div>';
            return;
        }

        // Собираем строки для таблицы
        $rows = [];

        foreach ($cron as $timestamp => $hooks) {
            foreach ($hooks as $hookName => $instances) {

                // Нас интересуют только наши хуки
                if (!in_array($hookName, $pluginHooks, true)) {
                    continue;
                }

                foreach ($instances as $key => $event) {
                    // $event: [ 'schedule' => string, 'args' => array, 'interval' => int ]
                    $schedule = $event['schedule'] ?? '';
                    $interval = isset($event['interval']) ? (int)$event['interval'] : 0;
                    $args     = $event['args'] ?? [];

                    // Для удобства — человеко-читаемое название расписания
                    $scheduleLabel = '';
                    if ($schedule === '') {
                        $scheduleLabel = __('Single (one-off)', 'lavka-total-sync');
                    } elseif (isset($schedules[$schedule])) {
                        $label = $schedules[$schedule]['display'] ?? $schedule;
                        $scheduleLabel = $label . ' (' . $schedule . ')';
                    } else {
                        $scheduleLabel = $schedule . ($interval ? " ({$interval}s)" : '');
                    }

                    // Время следующего запуска в разных зонах
                    $dtSite = (new DateTime('@' . $timestamp))->setTimezone($tz);
                    $dtUtc  = new DateTime('@' . $timestamp);
                    $dtUtc->setTimezone(new DateTimeZone('UTC'));

                    $rows[] = [
                        'hook'      => $hookName,
                        'timestamp' => $timestamp,
                        'time_site' => $dtSite->format('Y-m-d H:i:s'),
                        'time_utc'  => $dtUtc->format('Y-m-d H:i:s'),
                        'schedule'  => $scheduleLabel,
                        'args'      => $args,
                        'key'       => $key,
                    ];
                }
            }
        }

        if (empty($rows)) {
            echo '<p>' . esc_html__('No cron events for this plugin found.', 'lavka-total-sync') . '</p>';
            echo '</div>';
            return;
        }

        // Сортируем по времени
        usort($rows, function($a, $b){
            return $a['timestamp'] <=> $b['timestamp'];
        });
        ?>

        <table class="widefat striped" style="max-width: 1200px; margin-top: 0.5rem;">
            <thead>
            <tr>
                <th><?php _e('Hook', 'lavka-total-sync'); ?></th>
                <th><?php _e('Next run (site time)', 'lavka-total-sync'); ?></th>
                <th><?php _e('Next run (UTC)', 'lavka-total-sync'); ?></th>
                <th><?php _e('Schedule', 'lavka-total-sync'); ?></th>
                <th><?php _e('Args', 'lavka-total-sync'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><code><?php echo esc_html($row['hook']); ?></code></td>
                    <td><?php echo esc_html($row['time_site']); ?></td>
                    <td><?php echo esc_html($row['time_utc']); ?></td>
                    <td><?php echo esc_html($row['schedule']); ?></td>
                    <td>
                        <?php
                        if (empty($row['args'])) {
                            echo '&mdash;';
                        } else {
                            echo '<code>' . esc_html(wp_json_encode($row['args'])) . '</code>';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    </div>
    <?php
}