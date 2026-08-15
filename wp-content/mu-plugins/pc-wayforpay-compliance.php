<?php
/**
 * Plugin Name: PC WayForPay Compliance
 * Description: Publishes the legal, payment, delivery, refund, and seller details required for online payments.
 * Author: PaintCore
 * Version: 1.2.0
 * Text Domain: pc-wayforpay-compliance
 */

if (!defined('ABSPATH')) {
    exit;
}

const PC_WAYFORPAY_CONTENT_VERSION = '2026-08-15-5';
const PC_WAYFORPAY_PAGES_OPTION = 'pc_wayforpay_compliance_page_ids';
const PC_WAYFORPAY_CHECKOUT_VERSION = '2026-08-15-1';
const PC_WAYFORPAY_CHECKOUT_VERSION_OPTION = 'pc_wayforpay_classic_checkout_version';
const PC_WAYFORPAY_CHECKOUT_BACKUP_META = '_pc_wayforpay_checkout_block_backup';

/**
 * Page identities are kept separate from their content so internal links can be
 * generated after WordPress assigns the page IDs.
 */
function pc_wayforpay_page_definitions(): array
{
    return [
        'terms' => [
            'title' => 'Правила та умови (публічна оферта)',
            'slug'  => 'pravyla-ta-umovy',
        ],
        'payment_delivery' => [
            'title' => 'Оплата і доставка',
            'slug'  => 'oplata-i-dostavka',
        ],
        'refund' => [
            'title' => 'Повернення товару та коштів',
            'slug'  => 'povernennia-ta-vidshkoduvannia',
        ],
        'contacts' => [
            'title' => 'Контакти та реквізити продавця',
            'slug'  => 'kontakty-ta-rekvizyty',
        ],
    ];
}

/**
 * Public seller data supplied for the WayForPay verification.
 */
function pc_wayforpay_seller(): array
{
    return [
        'name'           => 'ФОП МАЛАХАТКА ВОЛОДИМИР ЄВГЕНОВИЧ',
        'tax_id'         => '2588409758',
        'legal_address'  => 'Україна, 01021, місто Донецьк, вул. Постишева, будинок 109, квартира 21',
        'actual_address' => 'Україна, м. Київ, вул. Велика Васильківська, буд. 72-Б, ТЦ «Олімпійський»',
        'contact_phone'  => '+38 (050) 347-25-18',
        'contact_href'   => '+380503472518',
        'store_phone'    => '+38 (044) 593-26-05',
        'store_href'     => '+380445932605',
        'manager_phone'  => '+38 (050) 348-01-38',
        'manager_href'   => '+380503480138',
        'email'          => 'shop@paint.dn.ua',
        'iban'           => 'UA423348510000000026007158346',
        'bank'           => 'АТ «ПУМБ»',
    ];
}

function pc_wayforpay_updated_label(): string
{
    return '<p class="pc-policy-updated"><strong>Останнє оновлення:</strong> 15 серпня 2026 року.</p>';
}

/**
 * Build the compliance page bodies with links that work on local and production domains.
 *
 * @param array<string,string> $urls
 * @return array<string,string>
 */
function pc_wayforpay_page_contents(array $urls): array
{
    $seller = pc_wayforpay_seller();
    $terms_url = esc_url($urls['terms']);
    $payment_url = esc_url($urls['payment_delivery']);
    $refund_url = esc_url($urls['refund']);
    $contacts_url = esc_url($urls['contacts']);
    $law_url = 'https://zakon.rada.gov.ua/laws/show/1023-12#Text';
    $exceptions_url = 'https://zakon.rada.gov.ua/laws/show/172-94-%D0%BF#Text';

    $terms = pc_wayforpay_updated_label() . '
<div class="pc-policy-lead"><p>Цей документ є публічною пропозицією укласти договір купівлі-продажу товарів на сайті ' . esc_html(wp_parse_url(home_url('/'), PHP_URL_HOST)) . '. Оформлюючи замовлення, покупець підтверджує, що ознайомився та погоджується з цими правилами.</p></div>
<h2>1. Продавець</h2>
<p><strong>' . esc_html($seller['name']) . '</strong>, РНОКПП ' . esc_html($seller['tax_id']) . '. Повні адреси та засоби зв’язку наведені на сторінці <a href="' . $contacts_url . '">«Контакти та реквізити продавця»</a>.</p>
<h2>2. Товари, ціни та наявність</h2>
<p>Основні характеристики товарів, ціни та доступні варіанти зазначені у картках товарів. Ціни вказані у гривнях. Підсумкова вартість замовлення відображається перед його підтвердженням.</p>
<p>Інформація про залишки оновлюється регулярно, однак продавець може додатково підтвердити наявність, кількість, комплектацію або строк відправлення. Якщо виконати замовлення неможливо, продавець повідомить покупця та запропонує заміну, інший строк або повне повернення сплаченої суми.</p>
<h2>3. Оформлення та підтвердження замовлення</h2>
<ol>
<li>Покупець обирає товар і додає його до кошика.</li>
<li>На сторінці оформлення покупець надає достовірні контактні дані, обирає доступний спосіб отримання й оплати та перевіряє склад замовлення.</li>
<li>Після натискання кнопки оформлення сайт створює замовлення та показує його номер. Для онлайн-оплати покупець переходить на захищену платіжну сторінку WayForPay.</li>
<li>Замовлення приймається до виконання після успішної оплати або підтвердження менеджером — залежно від обраного способу оплати.</li>
</ol>
<h2>4. Оплата</h2>
<p>Доступні способи та порядок оплати описані на сторінці <a href="' . $payment_url . '">«Оплата і доставка»</a>. Онлайн-оплата банківською карткою проводиться через платіжний сервіс WayForPay. Продавець не отримує та не зберігає повні реквізити картки покупця.</p>
<h2>5. Отримання товару</h2>
<p>Доступний безкоштовний самовивіз із магазину та доставка «Новою поштою» по Україні. Порядок, строк відправлення і правила оплати доставки наведені на сторінці <a href="' . $payment_url . '">«Оплата і доставка»</a>.</p>
<h2>6. Скасування, обмін і повернення</h2>
<p>До передачі замовлення покупець може звернутися до продавця для скасування. Після отримання товару обмін, повернення товару та коштів здійснюються відповідно до законодавства України й опублікованих <a href="' . $refund_url . '">правил повернення</a>.</p>
<h2>7. Права та обов’язки сторін</h2>
<p>Продавець зобов’язується надати покупцеві достовірну інформацію про товар, належно опрацювати підтверджене замовлення та виконувати вимоги законодавства про захист прав споживачів. Покупець зобов’язується надати коректні дані, оплатити й отримати замовлення на погоджених умовах та перевірити його під час отримання.</p>
<h2>8. Персональні дані</h2>
<p>Дані, надані під час оформлення, використовуються для обробки замовлення, оплати, доставки та зв’язку з покупцем. Необхідні дані можуть передаватися платіжному сервісу й службі доставки лише в обсязі, потрібному для виконання замовлення.</p>
<h2>9. Вирішення звернень</h2>
<p>Питання щодо замовлення спочатку вирішуються шляхом звернення до продавця за контактами, опублікованими <a href="' . $contacts_url . '">на сайті</a>. Права покупця також визначаються <a href="' . esc_url($law_url) . '" rel="noopener" target="_blank">Законом України «Про захист прав споживачів»</a>.</p>
<h2>10. Чинність умов</h2>
<p>Оферта діє з моменту її публікації. До конкретного замовлення застосовується редакція, чинна на момент оформлення. Продавець може оновлювати умови для майбутніх замовлень із публікацією нової редакції на цій сторінці.</p>';

    $payment_delivery = pc_wayforpay_updated_label() . '
<div class="pc-policy-lead"><p>Перед підтвердженням замовлення покупець бачить доступні для нього способи оплати й отримання. Якщо потрібного варіанта немає на сторінці оформлення, погодьте його з менеджером до оплати.</p></div>
<h2>Оплата банківською карткою</h2>
<p>Роздрібне замовлення можна оплатити онлайн карткою Visa або Mastercard через захищену платіжну сторінку <strong>WayForPay</strong>. Після успішної операції покупець повертається на сайт і бачить результат оплати; підтвердження також може надходити на вказану електронну адресу.</p>
<p>Якщо кошти списані, але статус замовлення не змінився, не сплачуйте повторно: повідомте менеджеру номер замовлення, ім’я та телефон. Не надсилайте повний номер картки, строк її дії або CVV.</p>
<h2>Інші способи оплати</h2>
<ul>
<li><strong>Під час самовивозу</strong> — готівкою або іншим способом, який доступний у магазині на момент отримання.</li>
<li><strong>Безготівковий рахунок для оптового замовлення</strong> — після перевірки реквізитів, цін і наявності менеджером.</li>
</ul>
<p>Чинним є спосіб, показаний у конкретному замовленні або письмово підтверджений менеджером.</p>
<h2>Самовивіз</h2>
<p>Отримати підтверджене замовлення можна за адресою: <strong>' . esc_html($seller['actual_address']) . '</strong>. <strong>Самовивіз безкоштовний.</strong> Перед поїздкою дочекайтеся повідомлення менеджера про готовність замовлення. Години видачі уточнюються під час підтвердження.</p>
<h2>Доставка «Новою поштою» по Україні</h2>
<p>Замовлення відправляються службою доставки «Нова пошта» у населені пункти України, які вона обслуговує. Наявний товар передається перевізнику протягом <strong>1–2 робочих днів</strong> після успішної оплати або підтвердження замовлення менеджером.</p>
<h3>Хто оплачує доставку</h3>
<ul>
<li>Якщо сума товарів у замовленні становить до 1 200 грн включно, базову вартість доставки оплачує покупець за тарифами «Нової пошти».</li>
<li>Якщо сума товарів у замовленні перевищує 1 200 грн, базову вартість доставки оплачує продавець.</li>
<li><strong>Страхування відправлення (плата за оголошену вартість) та додаткові послуги «Нової пошти» завжди оплачує покупець</strong>, незалежно від суми замовлення.</li>
</ul>
<p>Строк перевезення визначає «Нова пошта»; він не входить до строку підготовки продавцем. Після створення відправлення покупцеві повідомляється номер експрес-накладної для відстеження на сайті або в застосунку перевізника.</p>
<h2>Перевірка під час отримання</h2>
<p>Під час отримання перевірте цілісність упаковки, кількість і зовнішній стан товару. Якщо є пошкодження або невідповідність, зафіксуйте це разом із представником служби доставки та одразу зв’яжіться з продавцем.</p>
<h2>Контакти щодо оплати й доставки</h2>
<p>Телефон менеджера: <a href="tel:' . esc_attr($seller['manager_href']) . '">' . esc_html($seller['manager_phone']) . '</a>. Електронна пошта: <a href="mailto:' . esc_attr($seller['email']) . '">' . esc_html($seller['email']) . '</a>. Повні реквізити наведені <a href="' . $contacts_url . '">на окремій сторінці</a>.</p>';

    $refund = pc_wayforpay_updated_label() . '
<div class="pc-policy-lead"><p>Ми приймаємо звернення щодо скасування замовлення, обміну, повернення товару та коштів відповідно до законодавства України. Для швидкого розгляду спочатку зв’яжіться з нами та не відправляйте товар без погодження.</p></div>
<h2>Скасування до відправлення</h2>
<p>Щоб скасувати ще не відправлене замовлення або платіжну операцію, якнайшвидше повідомте номер замовлення за телефоном <a href="tel:' . esc_attr($seller['contact_href']) . '">' . esc_html($seller['contact_phone']) . '</a> або електронною поштою <a href="mailto:' . esc_attr($seller['email']) . '">' . esc_html($seller['email']) . '</a>. Якщо платіж уже завершено, продавець ініціює повернення коштів після перевірки замовлення.</p>
<h2>Повернення або обмін товару належної якості</h2>
<p>Покупець може звернутися щодо обміну або повернення непродовольчого товару належної якості протягом <strong>14 днів, не рахуючи дня купівлі</strong>, якщо товар не використовувався, збережені його товарний вигляд, споживчі властивості, пломби, ярлики, комплектність та документ, що підтверджує придбання.</p>
<p>Право на повернення може бути обмежене для товарів із чинного <a href="' . esc_url($exceptions_url) . '" rel="noopener" target="_blank">переліку Кабінету Міністрів України</a>, а також коли не виконані встановлені законом умови збереження товару. Акційна ціна сама по собі не позбавляє покупця законних прав.</p>
<h2>Неналежна якість, пошкодження або помилка в замовленні</h2>
<p>Якщо товар має недолік, був пошкоджений або не відповідає замовленню, зверніться до нас одразу після виявлення. Додайте фото товару, упаковки й транспортної накладної, якщо це допоможе підтвердити проблему. Вимоги покупця розглядаються відповідно до <a href="' . esc_url($law_url) . '" rel="noopener" target="_blank">Закону України «Про захист прав споживачів»</a>.</p>
<h2>Як подати звернення</h2>
<ol>
<li>Напишіть на <a href="mailto:' . esc_attr($seller['email']) . '">' . esc_html($seller['email']) . '</a> або зателефонуйте <a href="tel:' . esc_attr($seller['contact_href']) . '">' . esc_html($seller['contact_phone']) . '</a>.</li>
<li>Вкажіть номер замовлення, ПІБ покупця, телефон, назву товару, причину та бажаний варіант вирішення. Не повідомляйте повні дані банківської картки.</li>
<li>Дочекайтеся підтвердження адреси та способу повернення. Особисто передати погоджений товар можна за адресою магазину: ' . esc_html($seller['actual_address']) . '.</li>
<li>Надішліть товар у погодженій комплектації та надайте номер відправлення, якщо використовувалася служба доставки.</li>
</ol>
<h2>Вартість зворотної доставки</h2>
<p>Якщо підтверджено дефект, пошкодження з вини продавця або помилку комплектації, погоджені витрати на повернення сплачує продавець. Для повернення товару належної якості витрати на доставку несе покупець, якщо інше не погоджено сторонами або не передбачено законом.</p>
<h2>Строк і спосіб повернення коштів</h2>
<p>Після отримання та перевірки поверненого товару продавець повідомляє рішення покупцеві. Погоджені кошти повертаються тим самим способом, яким було здійснено оплату, якщо інше не погоджено та не суперечить законодавству.</p>
<p>Продавець проводить повернення у строк, передбачений законом, але не пізніше семи днів після погодження повернення. Для карткової оплати операція ініціюється через WayForPay; фактичний строк зарахування після цього залежить від банку покупця та платіжної системи.</p>';

    $contacts = pc_wayforpay_updated_label() . '
<div class="pc-policy-lead"><p>Продавцем товарів на цьому сайті та отримувачем платежів є фізична особа-підприємець, реквізити якої наведені нижче.</p></div>
<h2>Реквізити продавця</h2>
<dl class="pc-seller-details">
<div><dt>Повне найменування</dt><dd><strong>' . esc_html($seller['name']) . '</strong></dd></div>
<div><dt>РНОКПП (ІПН)</dt><dd>' . esc_html($seller['tax_id']) . '</dd></div>
<div><dt>Юридична адреса</dt><dd>' . esc_html($seller['legal_address']) . '</dd></div>
<div><dt>Фактична адреса магазину</dt><dd>' . esc_html($seller['actual_address']) . '</dd></div>
<div><dt>Поточний рахунок (IBAN)</dt><dd><code>' . esc_html($seller['iban']) . '</code></dd></div>
<div><dt>Банк</dt><dd>' . esc_html($seller['bank']) . '</dd></div>
</dl>
<h2>Контактна інформація</h2>
<ul>
<li>Контактний телефон продавця: <a href="tel:' . esc_attr($seller['contact_href']) . '">' . esc_html($seller['contact_phone']) . '</a></li>
<li>Магазин у Києві: <a href="tel:' . esc_attr($seller['store_href']) . '">' . esc_html($seller['store_phone']) . '</a></li>
<li>Замовлення та оптовий менеджер: <a href="tel:' . esc_attr($seller['manager_href']) . '">' . esc_html($seller['manager_phone']) . '</a></li>
<li>Електронна пошта: <a href="mailto:' . esc_attr($seller['email']) . '">' . esc_html($seller['email']) . '</a></li>
<li>Сайт: <a href="' . esc_url(home_url('/')) . '">' . esc_html(home_url('/')) . '</a></li>
</ul>
<p>Для звернення щодо замовлення, платежу, доставки або повернення повідомте номер замовлення та контактний телефон. Повні дані банківської картки в листах і повідомленнях не надсилайте.</p>
<h2>Документи для покупця</h2>
<ul>
<li><a href="' . $terms_url . '">Правила та умови (публічна оферта)</a></li>
<li><a href="' . $payment_url . '">Оплата і доставка</a></li>
<li><a href="' . $refund_url . '">Повернення товару та коштів</a></li>
</ul>';

    return [
        'terms'           => $terms,
        'payment_delivery' => $payment_delivery,
        'refund'          => $refund,
        'contacts'        => $contacts,
    ];
}

/**
 * Create or update the managed pages and connect the terms page to WooCommerce.
 */
function pc_wayforpay_install_pages(): void
{
    if (get_option('pc_wayforpay_compliance_content_version') === PC_WAYFORPAY_CONTENT_VERSION) {
        return;
    }

    $definitions = pc_wayforpay_page_definitions();
    $page_ids = [];
    $created_page = false;

    foreach ($definitions as $key => $definition) {
        $page = get_page_by_path($definition['slug'], OBJECT, 'page');
        $post_data = [
            'post_title'   => $definition['title'],
            'post_name'    => $definition['slug'],
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '',
        ];

        if ($page instanceof WP_Post) {
            $post_data['ID'] = $page->ID;
            $page_id = wp_update_post(wp_slash($post_data), true);
        } else {
            $page_id = wp_insert_post(wp_slash($post_data), true);
            $created_page = true;
        }

        if (is_wp_error($page_id) || !$page_id) {
            continue;
        }

        $page_ids[$key] = (int) $page_id;
        update_post_meta((int) $page_id, '_pc_wayforpay_managed', PC_WAYFORPAY_CONTENT_VERSION);
    }

    if (count($page_ids) !== count($definitions)) {
        return;
    }

    $urls = [];
    foreach ($page_ids as $key => $page_id) {
        $urls[$key] = (string) get_permalink($page_id);
    }

    $contents = pc_wayforpay_page_contents($urls);
    foreach ($page_ids as $key => $page_id) {
        wp_update_post(wp_slash([
            'ID'           => $page_id,
            'post_content' => $contents[$key],
            'post_status'  => 'publish',
        ]));
        update_post_meta($page_id, '_pc_wayforpay_managed', PC_WAYFORPAY_CONTENT_VERSION);
    }

    update_option(PC_WAYFORPAY_PAGES_OPTION, $page_ids, false);
    update_option('woocommerce_terms_page_id', $page_ids['terms']);
    update_option('pc_wayforpay_compliance_content_version', PC_WAYFORPAY_CONTENT_VERSION, false);

    if ($created_page) {
        flush_rewrite_rules(false);
    }
}
add_action('init', 'pc_wayforpay_install_pages', 20);

/**
 * Keep the assigned WooCommerce checkout compatible with the legacy WayForPay
 * gateway. The gateway is available in shortcode checkout, but it does not
 * register a payment method for WooCommerce Checkout Blocks.
 */
function pc_wayforpay_install_classic_checkout(): void
{
    if (get_option(PC_WAYFORPAY_CHECKOUT_VERSION_OPTION) === PC_WAYFORPAY_CHECKOUT_VERSION) {
        return;
    }

    $checkout_page_id = absint(get_option('woocommerce_checkout_page_id'));
    if (!$checkout_page_id || get_post_type($checkout_page_id) !== 'page') {
        return;
    }

    $content = (string) get_post_field('post_content', $checkout_page_id, 'raw');
    if (strpos($content, '[woocommerce_checkout') !== false) {
        update_option(PC_WAYFORPAY_CHECKOUT_VERSION_OPTION, PC_WAYFORPAY_CHECKOUT_VERSION, false);
        return;
    }

    // Do not overwrite an unknown custom checkout implementation.
    if (!has_block('woocommerce/checkout', $content)) {
        return;
    }

    if (!metadata_exists('post', $checkout_page_id, PC_WAYFORPAY_CHECKOUT_BACKUP_META)) {
        update_post_meta($checkout_page_id, PC_WAYFORPAY_CHECKOUT_BACKUP_META, wp_slash($content));
    }

    $result = wp_update_post(wp_slash([
        'ID' => $checkout_page_id,
        'post_content' => "<!-- wp:shortcode -->\n[woocommerce_checkout]\n<!-- /wp:shortcode -->",
    ]), true);

    if (is_wp_error($result) || !$result) {
        return;
    }

    update_post_meta($checkout_page_id, '_pc_wayforpay_checkout_migrated_version', PC_WAYFORPAY_CHECKOUT_VERSION);
    update_option(PC_WAYFORPAY_CHECKOUT_VERSION_OPTION, PC_WAYFORPAY_CHECKOUT_VERSION, false);
}
add_action('init', 'pc_wayforpay_install_classic_checkout', 25);

/**
 * Return a managed page URL without hard-coding a deployment domain.
 */
function pc_wayforpay_page_url(string $key): string
{
    $page_ids = get_option(PC_WAYFORPAY_PAGES_OPTION, []);
    if (is_array($page_ids) && !empty($page_ids[$key])) {
        $url = get_permalink((int) $page_ids[$key]);
        if ($url) {
            return $url;
        }
    }

    $definitions = pc_wayforpay_page_definitions();
    return home_url('/' . $definitions[$key]['slug'] . '/');
}

/**
 * Build the payment and delivery link shown along the purchase flow.
 */
function pc_wayforpay_purchase_guidance_html(string $context): string
{
    $messages = [
        'cart' => __('Before checkout, review the available payment methods and the delivery terms.', 'pc-wayforpay-compliance'),
        'checkout' => __('Before placing the order, review the payment and delivery terms.', 'pc-wayforpay-compliance'),
        'order_pay' => __('Before paying for the order, review the payment and delivery terms.', 'pc-wayforpay-compliance'),
    ];
    $message = $messages[$context] ?? $messages['checkout'];

    return sprintf(
        '<aside class="pc-payment-delivery-guidance pc-payment-delivery-guidance--%1$s" aria-label="%2$s"><span>%3$s</span><a class="pc-payment-delivery-guidance__link" href="%4$s" target="_blank" rel="noopener">%5$s</a></aside>',
        esc_attr($context),
        esc_attr__('Payment and delivery terms', 'pc-wayforpay-compliance'),
        esc_html($message),
        esc_url(pc_wayforpay_page_url('payment_delivery')),
        esc_html__('Payment and delivery', 'pc-wayforpay-compliance')
    );
}

function pc_wayforpay_render_cart_guidance(): void
{
    echo wp_kses_post(pc_wayforpay_purchase_guidance_html('cart'));
}
add_action('woocommerce_proceed_to_checkout', 'pc_wayforpay_render_cart_guidance', 10);

function pc_wayforpay_render_checkout_guidance(): void
{
    echo wp_kses_post(pc_wayforpay_purchase_guidance_html('checkout'));
}
add_action('woocommerce_review_order_before_submit', 'pc_wayforpay_render_checkout_guidance', 5);

function pc_wayforpay_render_order_pay_guidance(): void
{
    echo wp_kses_post(pc_wayforpay_purchase_guidance_html('order_pay'));
}
add_action('woocommerce_pay_order_before_submit', 'pc_wayforpay_render_order_pay_guidance', 5);

/**
 * Keep the link available if a deployment still uses WooCommerce Cart or
 * Checkout blocks. Classic templates render it through the hooks above.
 *
 * @param array<string,mixed> $block
 */
function pc_wayforpay_append_purchase_guidance_to_block(string $block_content, array $block): string
{
    if (is_admin()) {
        return $block_content;
    }

    $block_name = (string) ($block['blockName'] ?? '');
    if ($block_name === 'woocommerce/cart' && function_exists('is_cart') && is_cart()) {
        return $block_content . pc_wayforpay_purchase_guidance_html('cart');
    }

    if (
        $block_name === 'woocommerce/checkout'
        && function_exists('is_checkout')
        && is_checkout()
        && (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay'))
        && (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-received'))
    ) {
        return pc_wayforpay_purchase_guidance_html('checkout') . $block_content;
    }

    return $block_content;
}
add_filter('render_block', 'pc_wayforpay_append_purchase_guidance_to_block', 20, 2);

/**
 * Legal links and seller identity must be visible from every storefront page.
 */
function pc_wayforpay_render_legal_footer(): void
{
    $seller = pc_wayforpay_seller();
    ?>
    <section class="pc-legal-footer" aria-label="Правова інформація та контакти продавця">
        <div class="grid-container pc-legal-footer__inner">
            <div class="pc-legal-footer__seller">
                <strong><?php echo esc_html($seller['name']); ?></strong>
                <span>РНОКПП <?php echo esc_html($seller['tax_id']); ?></span>
                <span><?php echo esc_html($seller['actual_address']); ?></span>
            </div>
            <nav class="pc-legal-footer__links" aria-label="Документи для покупця">
                <a href="<?php echo esc_url(pc_wayforpay_page_url('terms')); ?>">Правила та умови</a>
                <a href="<?php echo esc_url(pc_wayforpay_page_url('payment_delivery')); ?>">Оплата і доставка</a>
                <a href="<?php echo esc_url(pc_wayforpay_page_url('refund')); ?>">Повернення коштів</a>
                <a href="<?php echo esc_url(pc_wayforpay_page_url('contacts')); ?>">Контакти та реквізити</a>
            </nav>
            <div class="pc-legal-footer__contacts">
                <a href="tel:<?php echo esc_attr($seller['contact_href']); ?>"><?php echo esc_html($seller['contact_phone']); ?></a>
                <a href="mailto:<?php echo esc_attr($seller['email']); ?>"><?php echo esc_html($seller['email']); ?></a>
                <a class="pc-legal-footer__payments" href="https://wayforpay.com/" target="_blank" rel="noopener">
                    <img src="https://wfpstorage.s3-eu-west-1.amazonaws.com/help/1746550624_%D0%B4%D0%BB%D1%8F%20%D1%82%D0%B5%D0%BC%D0%BD%D0%BE%D0%B3%D0%BE%20%D1%84%D0%BE%D0%BD%D1%83.png" alt="WayForPay — оплата картками Visa та Mastercard" width="300" height="40">
                </a>
            </div>
        </div>
    </section>
    <?php
}
add_action('generate_before_footer_content', 'pc_wayforpay_render_legal_footer', 5);

/**
 * Keep the legal pages and footer readable without taking over the shop design.
 */
function pc_wayforpay_enqueue_styles(): void
{
    $css = '
        .pc-policy-updated{color:#616161;font-size:.875rem;margin-bottom:1.5rem}
        .pc-policy-lead{background:#f5f4f0;border-left:4px solid #d5a727;margin:0 0 2rem;padding:1rem 1.25rem}
        .pc-policy-lead p{margin:0}
        .pc-payment-delivery-guidance{align-items:baseline;background:#f5f4f0;border-left:4px solid #d5a727;box-sizing:border-box;color:#333;display:flex;flex-wrap:wrap;gap:.35rem .7rem;margin:1rem 0;padding:.8rem 1rem;text-align:left;width:100%}
        .pc-payment-delivery-guidance__link{font-weight:600;text-decoration:underline;text-underline-offset:3px}
        .entry-content .pc-seller-details{margin:0 0 2rem}
        .pc-seller-details>div{border-bottom:1px solid #e4e4e4;display:grid;gap:.5rem;grid-template-columns:minmax(180px,30%) 1fr;padding:.75rem 0}
        .pc-seller-details dt{font-weight:600}
        .pc-seller-details dd{margin:0}
        .pc-seller-details code{overflow-wrap:anywhere;white-space:normal}
        .pc-legal-footer{background:#252525;border-bottom:1px solid rgba(255,255,255,.12);color:#f5f5f5;font-size:14px}
        .pc-legal-footer__inner{display:grid;gap:1.5rem;grid-template-columns:minmax(240px,1.4fr) minmax(220px,1fr) minmax(180px,.8fr);padding-bottom:30px;padding-top:30px}
        .pc-legal-footer__seller,.pc-legal-footer__links,.pc-legal-footer__contacts{display:flex;flex-direction:column;gap:.45rem}
        .pc-legal-footer__seller span{color:#cecece}
        .pc-legal-footer__payments{display:block;margin-top:.5rem;max-width:280px}
        .pc-legal-footer__payments img{display:block;height:auto;width:100%}
        .pc-legal-footer a{color:#fff;text-decoration:underline;text-decoration-color:rgba(255,255,255,.4);text-underline-offset:3px}
        .pc-legal-footer a:hover,.pc-legal-footer a:focus{color:#f2c94c;text-decoration-color:currentColor}
        @media(max-width:768px){
            .pc-seller-details>div{grid-template-columns:1fr}
            .pc-legal-footer__inner{grid-template-columns:1fr;padding-bottom:24px;padding-top:24px}
        }
    ';

    wp_register_style('pc-wayforpay-compliance', false, [], PC_WAYFORPAY_CONTENT_VERSION);
    wp_enqueue_style('pc-wayforpay-compliance');
    wp_add_inline_style('pc-wayforpay-compliance', $css);
}
add_action('wp_enqueue_scripts', 'pc_wayforpay_enqueue_styles', 20);

/**
 * The classic checkout uses this text; the block checkout uses the same terms page setting.
 */
function pc_wayforpay_checkout_terms_text(string $text): string
{
    $url = pc_wayforpay_page_url('terms');

    return sprintf(
        'Я прочитав(-ла) та погоджуюся з <a href="%s" class="woocommerce-terms-and-conditions-link" target="_blank">Правилами та умовами (публічною офертою)</a>',
        esc_url($url)
    );
}
add_filter('woocommerce_checkout_terms_and_conditions_checkbox_text', 'pc_wayforpay_checkout_terms_text');

/**
 * Legal documents should be focused and easy to scan, without the product sidebar.
 */
function pc_wayforpay_policy_sidebar_layout(string $layout): string
{
    if (!is_page()) {
        return $layout;
    }

    $page_ids = get_option(PC_WAYFORPAY_PAGES_OPTION, []);
    if (is_array($page_ids) && in_array(get_queried_object_id(), array_map('intval', $page_ids), true)) {
        return 'no-sidebar';
    }

    return $layout;
}
add_filter('generate_sidebar_layout', 'pc_wayforpay_policy_sidebar_layout');
