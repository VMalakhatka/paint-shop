# Paint Nova Poshta Multishipping

## Request pacing 0.9.2 — 2026-10-18

Confirmed production failure: HTTP 200 / success=false / errorCodes 20000401501,
`To many requests` during the second exact point lookup. A valid preview and unchanged
row can still fail this fresh pre-create read. This is not a contact mismatch.
`ApiRequestThrottle` serializes JSON transport per site/API-key with a MySQL named
lock (2-second acquisition limit) and a shared 10-second transient reservation.
Requests start at least 1.1 seconds apart; rate limits impose a 2-second cooldown.
These are conservative application choices, not a documented carrier quota.
An explicit read-method allowlist permits one retry ONLY for this rate error or
HTTP 429. Other errors and unknown methods are single-attempt. All mutations,
including recipient save and document save, remain single-attempt; PDF transport
is unchanged. No new automatic notification or durable create retry exists.

Fresh sender/point validation is retained; the limiter does not reuse stale limits
or weaken the row/plan fingerprint. Busy/rate errors before the claim explicitly
say creation was not started. After a claim, existing unknown/locked recovery
rules remain unchanged. Failure to lock or persist the reservation prevents HTTP.
Only a hash of the site/key and a timestamp are stored, never request/recipient data.
Other installations using the same carrier key are outside this site's limiter.

Tests: standalone `tests/api-rate-limit.php` (HTTP 429 + business code, retry bound,
no save retry, spacing/shared timestamp, lock/persistence failure and release),
plus local intercepted lifecycle tests for pre-claim rejection and rate recovery.
Operator instructions: [manager guide](../../../docs/MANAGER_CUSTOMER_GUIDE_UK.md#np-preparation).
Rollback: restore the prior plugin files from the deployment backup; do not reset
shipment rows, change mappings or enable/disable write gates as part of rollback.

## Manager lifecycle 0.9.1 — production gates enabled 2026-10-18

Production runtime checked on 2026-10-18: active 0.9.1, API key configured, both
write gates enabled by explicit owner approval. Fresh PHP process confirmed
`writesEnabled() === true`; unrelated option values stayed unchanged. Configuration
and options were privately backed up outside web-root. This was a configuration
operation, not a code deploy, real TTN, PDF or email pilot. Local gates remain off.
Reload the order and run a fresh preflight before confirming creation. Operator
instructions and narrow disable/rollback: manager guide `#np-preparation`.

The existing-order PCOE workspace supports saved preparation → carrier preflight →
explicit per-warehouse TTN creation → private PDF (document, A4 or Zebra marking) →
explicit service email to the **order billing email**, not the dropship recipient.
No automatic email on save/create, no stock/payment/profile updates and no automatic
Woo status transitions. Created means prepared, not physically handed over.

Scope: private-person recipient with confirmed Ukrainian first/last names and
optional patronymic, branch drop-off to branch/locker, Cash carrier payment, no COD.
Courier addresses, origin lockers, NonCash and COD fail closed.
`RecipientIdentity` suggests structured order shipping/billing names, then customer
card names, only when the saved recipient name and normalized phone match. Never
split a free-text full name, overwrite a dropship recipient, or update a profile.
The saved recipient phone is visible beside the editable suggested names.
Before document save, returned contact FirstName/LastName/Phones and any supplied
MiddleName must match. Missing/different fields lock as `np_recipient_mismatch`:
the recipient may exist, but no TTN is requested. No retry/reset UI. This compares
the specific response, not ownership in a global phone registry.
One TTN per saved physical warehouse; multiple boxes at branches, one at lockers.
Preview refreshes sender ownership/contact/phone and exact point directories, checks
known bounds plus stricter locker limits, computes declared goods value after
discounts with proportional item tax (ceil UAH, minimum 1), and requests a boxed
carrier estimate. Missing bounds are unknown, never unlimited; the manager must
verify them, payer and subsidy. The estimate is not the final carrier invoice.

`ShipmentLifecycle` uses the same PCOE named order lock as preparation. A 5-minute,
manager-bound preview token binds the row and whole-order fingerprint. Both write
gates remain required: `PNPM_ALLOW_REAL_TTN === true` and settings writes_enabled=yes.
Code defaults do not enable them; the production enablement is recorded above.
A committed `np_creating` claim and audit precede
CounterpartyGeneral.save and InternetDocumentGeneral.save. Exact final payload is
stored without the API key before the second write. Validate success plus Ref/TTN;
warnings with a valid success do not trigger another save. `np_created` repeats
return the existing TTN. Unknown transport/result/storage failure and rejected
documents stay locked (`np_creating`, `np_unknown`, `np_rejected`): no automatic
retry or status reset. An administrator must reconcile the carrier account manually;
there is no recovery UI yet. The first attempt freezes preparation, including after
failure; subsequent warehouses require the unchanged plan.

PDF uses a POST nonce, both manager capabilities and exact order/owned shipment,
fixed carrier host/path, no redirects, timeout, 10 MiB cap, PDF MIME/signature and
private no-store response. External customer TTNs never use this print transport.
Secret-bearing carrier URLs never appear in HTML, JSON, emails or our logs.
Email persists a claim before transport under the order lock; all accepted/failed/
unknown outcomes prevent automatic resend. Accepted is **not delivered**. Service
mail is independent of advertising opt-outs. Telegram, new tracking polling,
customer shipment dashboard, cancellation and automatic reconciliation remain open.

Tests: `tests/shipment-lifecycle-local.php`, run using its narrowly scoped
`--require=.../tests/shipment-lifecycle-bootstrap.php` CLI bootstrap. Only that local
fixture process has fake credentials/write flags; every HTTP/mail is intercepted,
synthetic rows cleaned in finally. Preparation and existing checkout tests remain
separate. See the final verification below and the [contract review](../../../docs/api/NOVA_POSHTA_SHIPMENT_CONTRACT.md).
Help canonical/operator recovery: [manager guide](../../../docs/MANAGER_CUSTOMER_GUIDE_UK.md#np-preparation).
Code, UK/RU UI/help and skill invariants are updated together. No migration/options,
customer notice or deployment was performed. One separately authorized live
recipient-only save on 2026-10-18 accepted empty MiddleName and returned both Refs;
no real TTN was created. Roll back code
only; never remove shipment/audit records to permit another carrier save.

Verified locally on 2026-10-18: 64 lifecycle fixture checks, 40 preparation checks,
30 point-directory checks, 41 delivery checks and TTN normalizer passed. All NP
PHP files and changed JavaScript pass syntax checks; scoped manager-help UK/RU
canonical parity and documentation-impact checks pass. Standalone skill validation
could not run for 0.9.1 because the local Python runtimes lack PyYAML; reference
links were checked by the documentation validator. Browser fixture inspection
(desktop and 390 px iframe) confirmed translated autofilled fields and optional
patronymic; lifecycle checks cover separate confirmation before
creation, invalidation after name edits, three print formats and per-warehouse
email state. This is mocked/local verification, **not carrier or production acceptance**.

## Historical preparation-only stage 0.8.2 (superseded by 0.9.0 above)

The existing-order PCOE workspace now has an order-only recipient/packaging panel,
explicit fresh confirmation import, save and preview. This is **not the complete
create/print/track/notify release**. `Create TTN` is disabled, regardless of write
flags; there is no new carrier mutation route, scheduler or notification hook.

`ManagerPreparation` persists `source=store_api,status=manager_draft` in the existing
shipment tables with the stable order/location idempotency key. The request snapshot
is a typed `manager-preparation-v1` draft, **not an InternetDocument payload**.
All warehouse rows, item allocations and private audit events commit in one InnoDB
transaction, under the same per-order named lock as PCOE. Failure rolls back; stale
revisions reject repeat writes. No profile/address/stock/payment changes. Existing
external or non-draft shipments block preparation, including mixed-source orders.
Child orders must use the original; missing allocations and refunds fail closed.
The manager's profile never determines the customer's COD policy. Multi-origin COD
remains blocked; no duplicated full-order COD is stored. A single-origin COD amount
is a preview from the saved order, not permission to collect it.

The preview validates local typed fields and mapping completeness, but does not
approve packed-point compatibility, final tariff, sender ownership or subsidy/payer
settlement. These remain mandatory preflight checks before real creation. No draft
is exposed as a created TTN in the customer account; existing external TTN display
is unchanged. Audit records actor/time, reviewed fields and snapshot hashes, not
recipient values in logs. Access requires manage_woocommerce and
manage_pnpm_shipments. No migration, option changes or deployment performed.

**Contract review, 2026-10-18:** the [official developer portal](https://developers.novaposhta.ua/)
is accessible in the user's Chrome tab; the earlier HTTP 403 is no longer an access
blocker. Save, string-recipient, locker, marking, tracking and document-list pages
were read without executing API requests. Verified fields, conflicting official
examples and remaining checks are recorded in the
[shipment contract review](../../../docs/api/NOVA_POSHTA_SHIPMENT_CONTRACT.md).
This does not enable creation or replace implementation tests. Remaining work:
verified payload/compiler and sender/recipient preflight, durable create claim plus
unknown-outcome reconciliation, authenticated PDF proxy, shipment tracking/customer
view and idempotent email/linked-Telegram outbox. Creation means prepared; only
carrier-confirmed physical acceptance means dispatched. Service-channel settings
apply, advertising opt-outs must not. None of these notifications are implemented
by saving a manager draft.

Local check: `tests/manager-preparation-local.php` via WP CLI, HTTP/mail intercepted,
synthetic order/customer/approval rows deleted in finally. Covers two origins/split
line, multiple boxes, stale revision, atomic rollback, external/locked protections,
capabilities, unchanged profile, COD and fresh/stale confirmation. Workflow and
safe recovery: `docs/MANAGER_CUSTOMER_GUIDE_UK.md#np-preparation`. UK/RU help updated;
publication awaits deployment. Roll back code only; preserve saved drafts/audit.

Verification: 40 local DB checks, 40 desktop/mobile manager fixture checks, 54
shared checkout UI checks, 30 directory checks, 41 delivery checks, TTN normalizer,
20 approval checks and the scoped manager-help UK/RU canonical-parity check passed.
PHP/JS syntax, skill validator and documentation-impact checks passed. Full manager
help test still finds two pre-existing `mailings` canonical-text mismatches,
confirmed unchanged from HEAD; the new `--np-preparation` mode checks this section
without weakening the default full check. No production acceptance claimed.

Окремий WooCommerce-плагін прямої інтеграції з офіційним API Нової пошти для багатоскладських замовлень Paint/Lavka.

## Попередній checkout-етап 0.8.1 (історія)

Версія `0.8.1` залишає в налаштованому checkout НП та безкоштовний самовивіз для одного складу; доставку перевізнику не додає до оплати товарів. Показує поріг покриття базового тарифу з політики ролі клієнта. Збережені клієнтські ТТН, менеджерська обробка та доставка в листах залишаються доступними. Створення реальних ТТН нашим API залишається вимкненим.

Уже реалізовано:

- HPOS-сумісне завантаження плагіна для WooCommerce 11;
- власні таблиці відправлень, товарних рядків, аудиту та приватних документів;
- ключ API лише з `PNPM_NOVA_POSHTA_API_KEY`, без збереження у WordPress та без показу в HTML;
- окрема сторінка Лавка → Лавка: налаштування → Лавка: Нова пошта;
- read-only перевірка офіційного API;
- read-only завантаження бізнес-відправника, адрес і контактних осіб з Нової пошти;
- зіставлення кожної Stock Location із зареєстрованою адресою відправника;
- окремий пошук і вибір точного відділення або поштомата, куди склад самостійно здає посилки;
- діагностика фактичного розподілу товарів за `_slw_data`, включно з поділом кількості одного рядка між складами;
- fallback `_stock_location` лише для старих замовлень без `_slw_data`;
- окрема сутність shipment, таблиця її товарного складу та глобальний захист від дубля номера ТТН;
- нормалізація текстового номера й офіційного tracking-посилання до канонічного 14-значного номера;
- подвійне блокування будь-яких API-операцій запису.
- окремі профілі оплати доставки для роздрібних покупців і партнерів/оптовиків;
- зіставлення Woo-ролей із профілем доставки;
- поріг суми замовлення та окремі правила нижче/вище нього: без компенсації, повна оплата магазином, фіксований бюджет, відсоток від товарів або від доставки;
- окремий платник для основного тарифу, оголошеної вартості, кур'єрського забору, адресної доставки, поштомата, пакування та додаткових складських посилок;
- окремі дозволи COD для однієї та кількох посилок і правило сплати комісії.
- окремий спосіб доставки WooCommerce для класичного checkout;
- пошук населеного пункту, відділення або поштомата через офіційні read-only довідники Нової пошти;
- адресну доставку кур'єром із використанням стандартних адресних полів checkout;
- розрахунок окремої посилки для кожного фактичного складу, включно з поділом кількості одного товару;
- показ покупцю кількості посилок, повної вартості перевізника та внеску магазину;
- застосування роздрібної або партнерської політики оплати доставки й обмеження післяплати;
- збереження у замовленні знімка одержувача, тарифів, розподілу посилок і політики.

## Подвійний запобіжник реальних ТТН

Запис у Нову пошту можливий лише коли одночасно:

1. внутрішня настройка `writes_enabled` дорівнює `yes`;
2. у `wp-config.php` явно визначено `PNPM_ALLOW_REAL_TTN` як `true`;
3. конкретна дія отримала окреме підтвердження оператора.

У версії `0.6.0` немає UI для ввімкнення першого пункту і немає реалізації створення ТТН. Збереження клієнтської ТТН змінює тільки локальний запис.

## Налаштування відправників складів

1. Відкрити Лавка → Лавка: налаштування → Лавка: Нова пошта.
2. Переконатися, що read-only перевірка API успішна.
3. Натиснути «Завантажити відправників з Нової пошти».
4. Для кожної Stock Location вибрати бізнес-відправника, зареєстровану адресу, контактну особу та перевірити телефон.
5. Якщо склад сам здає посилки, знайти за номером або адресою і вибрати точне відділення чи поштомат Нової пошти. Якщо потрібен забір кур'єром, вибрати відповідний тип відправлення; окрема точка передавання тоді не потрібна.
6. Заповнити зрозумілу покупцю назву складу, увімкнути рядок і зберегти налаштування.

Статус рядка має стати «Готово». Для самостійного передавання він неможливий без конкретної точки Нової пошти. Вимкнені склади не використовуються для побудови відправлень. Технічні Ref залишені у згорнутих блоках лише для діагностики.

## Політика оплати доставки

На цій самій сторінці налаштовуються два профілі: роздрібний і партнерський. Ролі `partner`, `opt`, `opt_osn` та `schule` початково віднесені до партнерського профілю; `guest`, `customer`, `subscriber` та `internet_client` — до роздрібного. Призначення можна змінити в адмінці.

Для кожного профілю задається поріг суми товарів після знижок і правило бюджету магазину нижче та вище порога:

- покупець сплачує все;
- магазин сплачує вибрані складові повністю;
- фіксований бюджет у гривнях;
- бюджет як відсоток суми товарів;
- бюджет як відсоток вибраних складових доставки;
- необов'язковий максимальний внесок магазину.

Кожна складова має власного платника: покупець, магазин або спільна оплата в межах бюджету магазину. Це дозволяє, наприклад, залишити базовий тариф покупцю, але оплатити магазином другу посилку, яка виникла через внутрішнє розділення складів; або спрямувати на доставку до 3% суми товарів.

Початкові значення 0.8.0 за погодженими умовами: до 1200 грн включно все сплачує
одержувач; строго понад 1200 грн базовий тариф кожної посилки покриває магазин,
страхування та додаткові послуги — одержувач. Поріг рахують із суми товарів після
знижок у shipping package. COD для однієї посилки дозволено, для кількох складів —
заборонено. Збережена політика `pnpm_delivery_policy_v1` має пріоритет над defaults
і автоматично не перезаписується. На deploy обов'язково звірити обидва профілі:
поріг 1200, нижній рівень customer, верхній store без cap, base_transport та
extra_shipments — budget, решта — customer. Збережені індивідуальні правила
потребують окремого погодження, а не автоматичної міграції.

## Класичне оформлення замовлення

Плагін реєструє зональний спосіб доставки `pnpm_nova_poshta` та один раз додає його до наявної зони доставки України. На сторінці WooCommerce -> Налаштування -> Доставка треба переконатися, що спосіб «Доставка Новою поштою» увімкнений у потрібній зоні.

НП доступна до вибору міста. Покупець обирає населений пункт і спосіб отримання:
відділення, поштомат або кур'єр. Тариф показується як орієнтир окремої оплати НП:
Woo shipping cost завжди 0, не додається до оплати товарів. Ознака
`pnpm_payment_collection=carrier` і покриття базового тарифу зберігаються у shipping
item, деталі — `_pnpm_checkout_quote`. При безкоштовному базовому тарифі текст
явно залишає страхування і допослуги за одержувачем. Грошове відшкодування або
створення ТТН цим кодом не виконується; менеджер застосовує збережену політику
під час оформлення перевезення.

Вага каталогу Lavka історично часто записана у грамах у полі ваги WooCommerce.
Початковий режим — грами. Якщо хоча б один товар не має ваги, checkout не запитує
тариф за резервною вагою і не показує вигадану суму. Помилка тарифного API також
не блокує окрему оплату перевізнику. Помилки розподілу/налаштування складів і
незаповнена адреса НП залишаються блокуючими. Оцінки на сторінці погодження
документів зберігають свій попередній контракт та попередження про резервну вагу.

`Shipping/CheckoutRates.php` працює лише для українського package з доступним
`pnpm_nova_poshta`. Залишає тільки цей спосіб та власний самовивіз: старі тарифи
можуть мати довільний ID, зокрема `flat_rate`, тому deny-list не достатній.
Окремий варіант клієнтської ТТН у цьому checkout також не показується; збережені
ТТН та менеджерські операції не змінюються. Налаштування зон не видаляються.
Самовивіз додається лише за повного розподілу товарів на одну location;
ID складу зберігається у shipping meta. НП є початковим вибором, явний вибір
клієнта зберігається. Версія/ролі/політика входять у hash кешу shipping package.

Підпис НП показує поріг поточного профілю (retail/partner) строго «понад», якщо
політика справді покриває весь базовий тариф без ліміту. Часткову/лімітовану
компенсацію не рекламує як безкоштовну. Після досягнення порогу підпис повідомляє,
що базовий тариф покриває магазин. Самовивіз називає склад розподілу, а не місто
одержувача; для вибраного самовивозу приховано Woo destination/address calculator.
За адресної доставки показано попередження про дорожчу доставку та можливість
вибрати відділення вище. Без фіксованої суми доплати:
[офіційні тарифи НП](https://novaposhta.ua/shipping-cost/?language=uk).
Версія 0.8.1 інвалідує старі session rates; старий вибір замінюється НП.

Локальна перевірка 18.10.2026: 41 standalone перевірка shipping/policy, 12 перевірок
реальних Woo-класів, 80 браузерних перевірок 390/1280 px на реальних PHP-шаблонах
і перекладах з mocked directory API. Окремо перевірено живий `paint.local`:
guest cart, два методи, перемикання самовивозу, попередження address/branch та
ширину checkout. Тестову корзину очищено, замовлення/оплати/ТТН не створювалися.
Новий код, канон та MU-справка підготовлені локально; production очікує deploy.

Deploy: наявний плагін + MU-справка і переклади, без нових плагінів та схем.
Перевірити на цільовому сайті наявність увімкненого `pnpm_nova_poshta` у зоні,
зіставлення складів та політику. Якщо власний метод не налаштовано, legacy не
вимикається мовчки. Rollback: повернути попередній код і оновити shipping cache;
збережені замовлення не перераховувати. Production цим завданням не змінюється.

Перевірки 0.8.0: `php tests/checkout-delivery.php`, `php tests/point-directory.php`,
local `tests/point-card-fixture.php` + `tests/point-card-ui.cjs` (desktop/mobile).
Тести не створюють реальні замовлення, оплати чи ТТН.
Перевірено локально 16.10.2026: 28 standalone перевірок доставки, 8 перевірок
реальних Woo-класів (`wp eval-file tests/checkout-delivery-local.php`),
30 перевірок довідника та 48 браузерних перевірок при 390/1280 px.
`PNPM_POINT_FIXTURE_DIR` додає до локального тесту HTML реальних Woo-labels для
4,00 / 1200,00 / 1200,01 грн і самовивозу. API у тестах перехоплено; повне
production-оформлення та живий тариф НП не перевірено. Канон оптової довідки
і код «Як замовляти» оновлені, публікація очікує deploy.

Checkout виконує лише read-only виклики `searchSettlements`, `getWarehouses` і `getDocumentPrice`. Він не створює, не змінює і не видаляє ТТН.

## Пошук і картка точки — 0.7.0, локально 2026-09-26

`WarehouseDirectory::searchPage` виконує один bounded `Address/getWarehouses` запит на сторінку (50 сирих рядків). `nextPage` розраховується до фільтрації branch/postomat, за `info.totalCount` або повною сирою сторінкою, якщо total відсутній. Порожня відфільтрована сторінка не завершує пошук. Кнопка продовження дає доступ до наступних сторінок; порожній query дозволений. Межа page 1–10000 захищає від некоректного вводу. Дублікати Ref усуваються в межах сторінки й у браузері між сторінками. Старий `search()` зберігає контракт першої сторінки для sender settings; цей етап змінює саме клієнтський checkout.

Whitelist картки: `PlaceMaxWeightAllowed` → одна коробка, `TotalMaxWeightAllowed` → усе відправлення, `MaxDeclaredCost` → оголошена вартість; `ReceivingLimitationsOnDimensions` та `SendingLimitationsOnDimensions` не змішуються, порядок Length/Width/Height. Schedule/Reception/Delivery показуються окремо. Нуль, відсутнє, від'ємне або нечислове значення — unknown, не unlimited. Немає універсальних hardcoded 20/30 кг. `Working` і відсутність `DenyToSelect=1` дозволяють вибір; інші/невідомі статуси показуються без збереження вибору. Це довідникова доступність, не перевірка поточних годин.

Кеш сирих сторінок v2 — 15 хвилин, помилки не кешуються. Перезавантаження відновлює картку окремим `pnpm_recipient_point` AJAX із exact Ref та перевіркою CityRef/типу; клієнт не передає власні ліміти. Обидва endpoint перевіряють nonce, ключ залишається на сервері. JS скасовує застарілі відповіді, очищує картку при зміні міста/типу/тексту, показує retry при помилці. Значення API вставляються тільки як текст. Оптова контекстна справка — `#np-point-limits`, лише дозволеним ролям.

Прямі read-only відповіді офіційного API 2026-09-26 підтвердили exact Ref, totalCount, поля маси/габаритів/графіка й MaxDeclaredCost поштомата. Портал developers недоступний через інструмент перевірки; не трактувати довідник як повний перелік юридичних/товарних правил. Джерела: [офіційна інтеграція](https://novaposhta.ua/for-business/cooperation/integration/), [актуальні умови](https://novaposhta.ua/help/). При rate-limit показується помилка й явний повтор, без нескінченних автоматичних запитів.

Перевірка реального пакування/об'ємної ваги/придатності замовлення, авторитетна повторна перевірка точки при submit, зміна тарифу і старого способу `novaposhta` — поза цим етапом. Картка прямо попереджає про це. Дані замовлень, shipping zones і write-флаги не змінюються; міграції немає. Deploy окремо; rollback — попередній код, кеш v2 сам спливає. Production 0.7.0 не розгорнуто.

Тести: `php tests/point-directory.php` (без БД/мережі); `wp eval-file tests/point-card-fixture.php` з `PNPM_POINT_FIXTURE_DIR` у local, потім `PLAYWRIGHT_MODULE=/path/to/playwright node tests/point-card-ui.cjs /path/to/fixture-dir`. Фікстура використовує реальний PHP/JS/CSS, віртуальну оптову роль і mocked API; це не E2E оформлення production-замовлення.

Перевірено: 30 standalone перевірок довідника і 40 браузерних перевірок при 1280/390 px; HTML-значення, застарілі відповіді, помилки/retry, зміна міста/типу, відновлення картки та оптове контекстне посилання. Горизонтального переповнення немає. Регресія: 42 external-TTN перевірки, 21 перевірка листів і normalizer — успішно; HTTP/mail перехоплені, тестові замовлення видалені.

## Наступні етапи

1. Незмінний shipment-plan після створення замовлення на основі фактичного `_slw_data`.
2. QR, штрихкод, PDF та зображення у приватному сховищі; текст/офіційне посилання вже приймаються на checkout.
3. Автоматичний polling та заміна/відкликання поданої ТТН з аудитом; ручна tracking-перевірка та підтвердження вже реалізовані.
4. Змішані замовлення: частина зовнішніх ТТН, частина створених нашим бізнес-акаунтом.
5. Окремо підтверджуване створення ТТН, друк та автоматичні повідомлення про зміну tracking-статусу. Перегляд клієнтських ТТН у кабінеті та звичайних листах замовлення вже реалізовано.
6. Action Scheduler для оновлення статусів та повторів лише read-only операцій.

## Бізнес-правила, які треба погодити

- чи може менеджер змінювати розподіл до першої ТТН;
- коли саме дозволяти кнопку створення реальної ТТН;
- які внутрішні назви складів можна показувати покупцю;
- дедлайн і правила заміни зовнішньої ТТН клієнта;
- хто друкує зовнішню маркировку;
- чи приймаємо реєстр ТТН у першій робочій версії.

## Зовнішня ТТН B2B-клієнта

### Доставка у листах (0.6.1)

`Email/DeliverySummary.php` додає блок після заголовка замовлення перед товарами у стандартних HTML/plain Woo-листах клієнту й менеджеру. Показує збережену назву доставки (також самовивіз/кур'єр), зареєстровані клієнтські ТТН, склади та стан перевірки. Читає локальні дані без API-запитів. План, який не вдалося зареєструвати, показує попередження менеджеру без удавано збережених номерів.

Дочірній Folio-заказ без власної доставки використовує `_folio_parent_order_id` або ранній `_folio_split_from_order_id`, лише після перевірки власника. Успадковані дані позначаються як усі склади початкового замовлення; посилання для клієнта веде до кабінету, для менеджера — до адміністративного замовлення.

Буферизація обмежена `emails/email-order-details.php` та `emails/plain/email-order-details.php` через before/after-template hooks; Woo-шаблони не копіюються. Для стороннього конструктора, який обходить ці шаблони, потрібен окремий адаптер. Вже надіслані листи не змінюються та автоматично не пересилаються. Локальний regression: `tests/email-delivery.php`, HTTP/mail заблоковані, синтетичні замовлення видаляються. Версія 0.6.1 ще не розгорнута в production.

Перевірено локально 2026-09-20: 21 email-перевірка (new order / processing / on-hold, HTML/plain, split, самовивіз, кур'єр, escaping, незбережений план), 32 перевірки external-TTN та normalizer. HTML-лист перевірено у браузері при ширині 390/900 px без горизонтального переповнення. Реальні листи не надсилались.

На checkout оптовик вибирає «Відправка за моєю ТТН Нової пошти». Доступ визначається спільним allow-list `pc_wholesale_customer_roles` (типово partner/opt/opt_osn/schule), а не тільки видимістю поля. Спосіб доступний для України та одного логічного Woo shipping package, який може включати кілька фізичних складів. Для кожного складу потрібен окремий номер; можна вставити повідомлення або офіційне tracking-посилання. Кілька різних номерів в одному полі відхиляються. Клієнт підтверджує розпізнані номери та показаний склад товарів.

Доставка у Woo — 0 грн. Це не безплатна послуга НП: платник і тариф визначені клієнтською ТТН. Наш товар оплачується окремо; Woo COD прибирається на checkout і сторінці повторної оплати. Відправлення має `source=customer_external`, `status=submitted`, без прав API edit/delete. Зовнішній COD не потрапляє в наші платежі/фіскалізацію.

`ExternalTtnCheckout` валідовує актуальний серверний `pc_alloc_plan` і зберігає `_pnpm_external_plan`. `ExternalShipmentStore` на `woocommerce_checkout_order_created` до запуску ФОЛІО записує весь batch в наявні InnoDB `pnpm_shipments/items/events`: per-order lock, транзакція, глобальний unique TTN. Помилка не пропускає оплату/обробку ФОЛІО. Повтор неоплаченого checkout допускає тільки ті самі номери/товари, переприв'язує нові Woo item IDs, не дублює відправлення. Зміна вже поданого набору блокується до втручання менеджера.

Кабінет показує номери та статуси тільки власнику/менеджеру. Після split записи залишаються в оригінальному замовленні: дочірні замовлення явно посилаються на весь список його відправлень. Немає автоматичного дублювання чи перепризначення ТТН дочірнім рахункам.

Кнопка менеджера викликає read-only `TrackingDocument/getStatusDocuments` без телефону клієнта. Зберігається тільки номерний код, публічний текст статусу і час. Підтвердження потребує checklist; статус НП має бути `1` (очікує передачі). Якщо API недоступне, потрібна ручна перевірка і примітка 10–500 символів. Інший доступний статус блокує підтвердження. Поточні кількості та `_slw_data`/`_pc_alloc_plan` звіряються з незмінним складом. Саме підтвердження не означає фактичну передачу НП. Інструкція менеджера: [операційний runbook](../../../docs/OPERATIONS_RUNBOOK.md#клиентские-ттн-новой-почты).

Перевірено локально 2026-09-20: normalizer та інтеграційні тести з блокуванням HTTP/mail. Read-only запит офіційному API з тестовим нульовим номером підтвердив маршрутизацію методу та помилку валідації номера; реальна клієнтська ТТН не запитувалась. Портал developers повернув 403. Production не розгорнуто й не перевірено.

Перед підтвердженням склад перевіряє фізичне місце відправлення, склад товарів, одержувача, платника, COD, оголошену вартість, вагу, габарити, кількість місць і маркировку.

Активація вже наявного плагіна/нова схема не потрібні. `pnpm_settings.external_ttn_enabled=no` приховує прийом нових ТТН, записи й перегляд залишаються. Production activation policy у manifest — `manual`. Відкат: вимкнути прийом і повернути попередній код; існуючі записи не видаляти. Для виправлення помилкової/скасованої ТТН звернутися до менеджера; UI заміни/видалення та автоматичне звільнення номера ще не реалізовані. Не використовувати старий номер у новому замовленні.

Тести (тільки local, синтетичні об'єкти видаляються):

```bash
php wp-content/plugins/paint-nova-poshta-multishipping/tests/manual-ttn-normalizer.php
wp eval-file wp-content/plugins/paint-nova-poshta-multishipping/tests/external-ttn.php
```

## Локальна перевірка

```bash
wp plugin activate paint-nova-poshta-multishipping
wp plugin status paint-nova-poshta-multishipping
```

Після активації відкрити Лавка → Лавка: налаштування → Лавка: Нова пошта. Без API-ключа read-only перевірка повинна показати зрозумілу помилку; створення ТТН відсутнє.

## Checkout recovery: absent external TTN plan

Verified 2026-09-22: `ExternalShipmentStore::submit()` treats missing/empty
`_pnpm_external_plan` as no external shipment and returns before acquiring a
database lock. Do not cast missing Woo meta to an array: `(array) ''` is `['']`,
not an empty array. Non-empty malformed plans are rejected before transaction
work using the existing checkout validation message. Ordinary delivery does not
require a customer TTN. The local `tests/external-ttn.php` suite covers empty
meta, malformed records, the ordinary checkout-created hook, real synthetic
multi-parcel persistence and idempotent retries; HTTP and email are intercepted.


## Nova Poshta on customer confirmation — 2026-09-29

PCOE owns authorization, saved consent and ten-minute delivery estimate receipts.
PNPM reuses its recipient directory/point card, tariff service and delivery policy.
`DocumentShipmentBuilder` reads saved order allocation or uniquely mapped Folio
warehouses; it never allocates current stock. The customer sees carrier/store/customer
amounts, parcels and fallback-weight warnings. A receipt is invalidated by changes to
destination, document, parcel inputs, policy or owner; non-permitted COD is rejected.
Estimates remain separate from Woo totals and do not create shipments. Existing
packaging/volumetric-weight limitations remain; a manager checks final charges.
Deploy both plugins, MU help and translations together. No migration or new secret.
Operator/rollback: `docs/OPERATIONS_RUNBOOK.md`, customer confirmation section.
Local tests: PCOE `tests/approval-delivery.php`, `tests/approval-delivery-ui.cjs`,
existing confirmation HTTP and PNPM point-directory/card regressions. HTTP/mail
are intercepted; production verification remains pending deployment.

Combined confirmation integration (2026-10-11): `DocumentShipmentBuilder` reads
`bundle.documents` as independent saved Folio account snapshots when no Woo order is
supplied. It maps each document warehouse to a sender, gives rows distinct composite
keys, and counts merchandise once. Accounts at the same physical location share the
existing shipment grouping. No live-stock allocation, carrier API contract or waybill
creation changes. Covered by PCOE `tests/approval-delivery.php`.
