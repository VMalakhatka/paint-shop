# Paint Core

Общие правила магазина: остатки/распределение, элементы покупки и интеграции.
Карта владельцев: [SYSTEM_OVERVIEW](../../../docs/SYSTEM_OVERVIEW.md).

## Выбор склада в собственной шапке

С 2026-10-21 Paint Shop UX 1.7.0 вызывает существующий `pc_render_alloc_control()`
непосредственно в своей шапке. Фильтр `pc_alloc_header_rendered` сообщает только о
фактическом выводе: после него legacy mount в `wp_footer` пропускается. При откате
шапки фильтр остаётся false и прежнее размещение в `.site-branding` работает.
Роли, nonce/AJAX, cookie/session preferences и правила пересчёта корзины не менялись.
Интеграционные и browser-проверки — `paint-shop-ux/tests/site-header-local.php`
и `site-header.spec.cjs`; production-переключение склада проверять после deploy
на согласованной тестовой корзине. Миграций и новых настроек нет.

## Дата создания заказа classic checkout

С 2026-10-21 `inc/checkout-created-date.php` регистрирует именованный callback
`pc_checkout_set_created_date` на `woocommerce_checkout_create_order` (priority 10).
Модуль автоматически загружается штатным loader `inc/*.php`. Это перенос из
GeneratePress Child: прежний `current_time('timestamp')` передаётся в Woo CRUD setter,
семантика времени и момент вызова сохранены. Модуль не сохраняет заказ самостоятельно
и не обновляет существующие заказы. Новых options/миграций нет.

Read-only проверка на несохранённом WC_Order находится в
`../paint-shop-ux/tests/site-footer-local.php`. При полном откате переноса вернуть
theme callback и убрать модуль совместно; смена активной темы больше не удаляет правило.
