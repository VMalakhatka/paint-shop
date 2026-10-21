# Paint Core

Общие правила магазина: остатки/распределение, элементы покупки и интеграции.
Карта владельцев: [SYSTEM_OVERVIEW](../../../docs/SYSTEM_OVERVIEW.md).

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
