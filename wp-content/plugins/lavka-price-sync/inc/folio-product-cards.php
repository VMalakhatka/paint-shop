<?php
if (!defined('ABSPATH')) exit;

const LPS_CARD_NONCE = 'lps_folio_product_cards';

function lps_card_selection(array $input): array {
    $mode = $input['mode'] ?? 'direct';
    $text = $input['skus'] ?? '';
    if (!is_string($mode) || !in_array($mode, ['direct','view','compare'], true) || !is_string($text) || strlen($text) > 60000) {
        throw new RuntimeException('INVALID_SELECTION');
    }
    $skus = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), static fn($s) => $s !== '')));
    if (count($skus) > 500) throw new RuntimeException('INVALID_SELECTION');
    foreach ($skus as $sku) if (mb_strlen($sku) > 100 || preg_match('/[\x00-\x1f\x7f]/u', $sku)) throw new RuntimeException('INVALID_SELECTION');
    return ['mode'=>$mode, 'skus'=>$skus];
}

function lps_card_read(array $selection): array {
    $options = lps_get_options();
    if (empty($options['java_base_url']) || empty($options['api_token'])) throw new RuntimeException('CARD_READ_FAILED');
    $response = lps_java_post('/admin/folio/product-cards/read', $selection, ['timeout'=>70]);
    if (is_wp_error($response)) throw new RuntimeException('CARD_READ_FAILED');
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (wp_remote_retrieve_response_code($response) !== 200) {
        throw new RuntimeException(is_array($data) && ($data['error'] ?? '') === 'CARD_QUERY_ACCESS_DENIED' ? 'CARD_QUERY_ACCESS_DENIED' : 'CARD_READ_FAILED');
    }
    $expected = lps_card_keys();
    if (!is_array($data) || ($data['ok'] ?? null) !== true || ($data['sourceDatabase'] ?? '') !== 'Paint_Ua'
        || ($data['mode'] ?? '') !== $selection['mode'] || !is_array($data['data']['rows'] ?? null)
        || count($data['data']['rows']) > 50000 || !is_array($data['data']['columns'] ?? null)
        || array_column($data['data']['columns'], 'key') !== $expected || !is_array($data['missingSkus'] ?? null)) throw new RuntimeException('CARD_READ_FAILED');
    foreach ($data['data']['columns'] as $column) if (!in_array($column['type'] ?? '', ['text','number'], true)) throw new RuntimeException('CARD_READ_FAILED');
    foreach ($data['data']['rows'] as $row) {
        if (!is_array($row) || count($row) !== 54) throw new RuntimeException('CARD_READ_FAILED');
        foreach ($row as $i=>$value) {
            if ($value !== null && !is_string($value)) throw new RuntimeException('CARD_READ_FAILED');
            if ($value !== null && $data['data']['columns'][$i]['type'] === 'number' && !preg_match('/^-?\d+(\.\d+)?$/D', $value)) throw new RuntimeException('CARD_READ_FAILED');
        }
    }
    if ($selection['mode'] === 'compare' && (!is_bool($data['identical'] ?? null) || !is_int($data['directRows'] ?? null) || !is_int($data['viewRows'] ?? null))) throw new RuntimeException('CARD_READ_FAILED');
    return $data;
}

function lps_card_error(string $code): string {
    if ($code === 'CARD_QUERY_ACCESS_DENIED') return __('SQL Server denied read access to this query. Use 1212 all fields, or ask the database administrator to allow reading card_tov.', 'lavka-price-sync');
    if ($code === 'INVALID_SELECTION') return __('Enter up to 500 exact SKUs, one per line, with at most 100 characters each.', 'lavka-price-sync');
    if ($code === 'CARD_DIFFERENCE') return __('The queries returned different results. Export each query separately for review.', 'lavka-price-sync');
    return __('Product cards could not be read. Check the Java service, API token, Paint_Ua source and 50,000-row limit, then try again.', 'lavka-price-sync');
}

add_action('admin_menu', static function (): void {
    add_menu_page(__('Lavka Folio', 'lavka-price-sync'), __('Lavka Folio', 'lavka-price-sync'), LPS_CAP,
        'lps-folio-cards', 'lps_card_render', 'dashicons-clipboard', 59);
    add_submenu_page('lps-folio-cards', __('Product cards', 'lavka-price-sync'), __('Product cards', 'lavka-price-sync'), LPS_CAP,
        'lps-folio-cards', 'lps_card_render');
});

function lps_card_render(): void {
    if (!current_user_can(LPS_CAP)) wp_die(esc_html__('Access denied.', 'lavka-price-sync'), '', ['response'=>403]);
    echo '<div class="wrap"><h1>'.esc_html__('Lavka Folio', 'lavka-price-sync').'</h1><h2>'.esc_html__('Product cards', 'lavka-price-sync').'</h2>';
    echo '<p>'.esc_html__('Read product cards from Paint_Ua and export 54 fields in the supplied Excel column order. No Folio data is changed.', 'lavka-price-sync').'</p>';
    echo '<p>'.esc_html__('Both queries require the legacy warehouse and markup records. Requested SKUs excluded by those conditions are listed separately. Assembly columns 55–57 are not returned by these queries.', 'lavka-price-sync').'</p>';
    echo '<form method="post" id="lps-card-form" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field(LPS_CARD_NONCE);
    echo '<input type="hidden" name="action" value="lps_folio_product_cards"><p><label>'.esc_html__('Query', 'lavka-price-sync').' <select name="mode">';
    foreach (['direct'=>'1212 все поля','view'=>'_dbo_Все поля по Artic','compare'=>__('Compare both queries', 'lavka-price-sync')] as $key=>$label) echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';
    echo '</select></label></p><p><label for="lps-card-skus">'.esc_html__('Exact SKUs, one per line. Leave empty for all eligible cards.', 'lavka-price-sync').'</label></p>';
    echo '<textarea id="lps-card-skus" name="skus" rows="7" maxlength="60000" style="width:100%;max-width:600px"></textarea>';
    echo '<p><button type="button" class="button button-secondary" id="lps-card-preview">'.esc_html__('Read and check', 'lavka-price-sync').'</button> ';
    echo '<button type="submit" class="button button-primary">'.esc_html__('Export product cards XLSX', 'lavka-price-sync').'</button></p></form>';
    echo '<div id="lps-card-status" role="status" aria-live="polite"></div><div id="lps-card-result" style="max-width:100%;overflow:auto"></div></div>';
}

add_action('admin_enqueue_scripts', static function (): void {
    if (($_GET['page'] ?? '') !== 'lps-folio-cards') return;
    wp_enqueue_script('lps-folio-cards', plugins_url('../assets/folio-product-cards.js', __FILE__), [], (string)filemtime(__DIR__.'/../assets/folio-product-cards.js'), true);
    wp_localize_script('lps-folio-cards', 'lpsCards', [
        'url'=>admin_url('admin-ajax.php'), 'reading'=>__('Reading product cards…', 'lavka-price-sync'),
        'error'=>lps_card_error('CARD_READ_FAILED'), 'match'=>__('Both queries match.', 'lavka-price-sync'),
        'difference'=>lps_card_error('CARD_DIFFERENCE'), 'rows'=>__('Rows returned:', 'lavka-price-sync'),
        'missing'=>__('Requested SKUs not returned:', 'lavka-price-sync'),
        'preview'=>__('Preview: first 50 rows. The Excel export contains all returned rows.', 'lavka-price-sync'),
        'download'=>__('Preparing the Excel download…', 'lavka-price-sync'),
    ]);
});

add_action('wp_ajax_lps_folio_product_cards', static function (): void {
    if (!current_user_can(LPS_CAP)) wp_send_json_error(['message'=>__('Access denied.', 'lavka-price-sync')],403);
    check_ajax_referer(LPS_CARD_NONCE, '_wpnonce');
    try {
        $selection = lps_card_selection(wp_unslash($_POST)); $data = lps_card_read($selection);
        $data['rowCount'] = count($data['data']['rows']);
        $data['data']['rows'] = array_slice($data['data']['rows'], 0, 50);
        $data['headers'] = lps_card_headers();
        wp_send_json_success($data);
    } catch (Throwable $e) { wp_send_json_error(['message'=>lps_card_error($e->getMessage())],503); }
});

add_action('admin_post_lps_folio_product_cards', static function (): void {
    if (!current_user_can(LPS_CAP)) wp_die(esc_html__('Access denied.', 'lavka-price-sync'), '', ['response'=>403]);
    check_admin_referer(LPS_CARD_NONCE);
    $path = null;
    try {
        if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('admin');
        $selection = lps_card_selection(wp_unslash($_POST)); $data = lps_card_read($selection);
        if ($selection['mode'] === 'compare' && $data['identical'] !== true) throw new RuntimeException('CARD_DIFFERENCE');
        $path = lps_card_xlsx($data);
        while (ob_get_level()) ob_end_clean();
        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="folio-product-cards-'.$selection['mode'].'-'.gmdate('Ymd-His').'.xlsx"');
        header('Content-Length: '.filesize($path));
        readfile($path);
    } catch (Throwable $e) { wp_die(esc_html(lps_card_error($e->getMessage())), '', ['response'=>503]); }
    finally { if ($path && is_file($path)) unlink($path); }
    exit;
});

function lps_card_keys(): array { return ['Now_COD_ARTIC', 'COD_ARTIC', 'ARTIC_BAZA', 'NAME_ARTIC', 'DOP1_ARTIC', 'EDIN_IZMER', 'EDN_V_UPAK', 'FASOVKA_KALM', 'DOP2_ARTIC', 'NGROUP_TVR', 'NGROUP_TV2', 'NGROUP_TV3', 'NGROUP_TV4', 'NGROUP_TV5', 'NGROUP_TV6', 'COEF_BZNAL', 'DEPARTAM', 'TIP_TOVR', 'PR_CENA', 'CENA_VALT', 'COD_VALT', 'NACENKA_UA', 'NACENKA_OPT_UA', 'CENA_ARTIC', 'COUNTRY', 'BALL2', 'SHTRIH', 'MAX_KIOPT', 'MAX_XR_KIOPT', 'MAX_ODESS', 'MAX_ODESS_HR', 'MAX_KIEV', 'MAX_KIEV_3', 'MIN_KIOPT', 'MIN_ODESS', 'MIN_ODESS_HR', 'MIN_KIEV', 'MIN_KIEV_3', 'BALL5 k1', 'BALL5 k3', 'BALL5 od', 'BALL1', 'BALL4', 'DLINA', 'SHIRINA', 'VISOTA', 'VEC', 'ADREC_KIO', 'ADREC_HR', 'SCHET_ART', 'tr_trans', 'Art_dop', 'S50', 'DESCRIPTION']; }

/** Source template headings are data, not translatable UI messages. */
function lps_card_headers(): array { return ['Сущиствующий
Артикул', 'Артикул', 'Главный
Артикул', 'Наименование', 'Оригинальное название ', 'Ед.изм.', 'Фасовка', 'Фасовка   ОПТ     всталять 1', 'Поставщик', 'Группа', 'подгруппа1', 'Подгруппа2', 'подгруппа3', 'подгруппа4', 'подгруппа5', 'Коэф.безн./нал.', 'отдел', 'Типтовара', 'Признак
Перерасчета
Розници по умолчанию 1', 'валютная цена ', 'код валюты', 'Доп
Наценка UA', 'Доп
Наценка UA
ОПТ', 'Цена грн', 'страна', 'МЕРЗНЕТ! Балл2', 'Штрихкод', 'МАКС КИО', 'МАКС Хран+ОПТ', 'МАКС ОД', 'МАКС ОД Хран', 'МАКС К1', 'мАксзапасКиев3', 'МИН КОПТ', 'МИН ОД', 'МИН   ОД Хран', 'МИН   К1', 'мИнзапасКиев3', ' для К1       1-КИО        11-Укр ', 'закз к3       1-ОПТ        11- Укр пост', ' для Одес       1-КИО        11-Укр ', 'Таможеный код ', 'Признак
Разборки', 'Длина, мм', 'Ширина, мм', 'Высота, мм', 'Вес, гр', 'Адресс
Товара КиО', 'Адресс
Товара Хранение', 'транспорт', 'Транспорт трансфер по умолчанию 1,3', 'Доп артикул', 'картинка', 'Описание']; }
