
Lavka Sync — assets
===================

Файлы для плагина WordPress "lavka-sync". Положите их рядом с lavka-sync.php:
- reports.js
- reports.css

Пути:
wp-content/plugins/lavka-sync/reports.js
wp-content/plugins/lavka-sync/reports.css

Далее в lavka-sync.php должны быть:
- add_menu_page('Lavka Reports', ...)
- admin_enqueue_scripts с подключением Chart.js CDN и этих файлов
- AJAX-хук wp_ajax_lavka_reports_data (возвращает JSON с ключом 'preview')
- capability 'view_lavka_reports' для страницы отчётов
