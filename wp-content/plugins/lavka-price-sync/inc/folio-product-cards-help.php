<?php
if (!defined('ABSPATH')) exit;

/** Contextual documentation only: no API, database or export request. */
function lps_card_help_load(): void {
    $locale = determine_locale();
    if (in_array($locale, ['uk', 'ru_RU'], true)) {
        load_textdomain('lps-card-help', dirname(__DIR__).'/languages/lps-card-help-'.$locale.'.mo');
    }
}
function lps_card_help_url(string $topic = ''): string {
    return admin_url('admin.php?page=lps-folio-card-help').($topic !== '' ? '#'.sanitize_key($topic) : '');
}
function lps_card_help_sections(): array {
    return [
        'single' => [__('One product or a pasted list', 'lps-card-help'), true, [
            __('Keep Query set to 1212 все поля and Selection set to Listed SKUs.', 'lps-card-help'),
            __('Paste the exact Folio SKU into the large text box. For several products, paste one SKU per line, up to 500 unique SKUs. Use the SKU, not the product name or barcode.', 'lps-card-help'),
            __('Click Read and check. Check the returned row count and the requested SKUs not returned. The preview shows the first 50 rows.', 'lps-card-help'),
            __('Click Export product cards XLSX and save the downloaded file. It contains all returned rows for your selection, including rows beyond the preview.', 'lps-card-help'),
        ]],
        'excel' => [__('Load a list from Excel', 'lps-card-help'), true, [
            __('Prepare an XLS or XLSX file up to 5 MB. Put the SKUs on the first worksheet and store identifiers as text to preserve leading zeroes. Formulas and error cells in the selected column are not accepted.', 'lps-card-help'),
            __('In Load SKUs from Excel, choose the file and enter the column letter. Use A if the SKUs are in the first column, or B if they are in the second.', 'lps-card-help'),
            __('Set First data row to 2 when row 1 is a header, or to 1 when the file has no header. Click Load list.', 'lps-card-help'),
            __('Review the SKUs placed in the text box and correct them if needed. Loading replaces the previous list and removes duplicates. Then click Read and check, followed by Export product cards XLSX.', 'lps-card-help'),
        ]],
        'result' => [__('What is in the downloaded file', 'lps-card-help'), false, [
            __('The НК worksheet has the 57 column headings from the supplied template in the same order. The queries fill the first 54 columns. The final three assembly columns are empty because the queries do not return them. The file is XLSX.', 'lps-card-help'),
            __('The Report worksheet contains the row count, query, generation time, comparison result and requested SKUs not returned. A blank value means no value was supplied; it is not necessarily zero. Editing the downloaded file does not update Folio.', 'lps-card-help'),
            __('Export reads the cards again. If someone changed a card after the preview, the downloaded values may differ from the preview.', 'lps-card-help'),
        ]],
        'all' => [__('All cards and query comparison', 'lps-card-help'), false, [
            __('To export all eligible cards, explicitly choose All eligible cards in Selection. An empty list in Listed SKUs mode does not select the whole database. The result limit is 50,000 rows.', 'lps-card-help'),
            __('For ordinary exports, use 1212 все поля. The alternative is _dbo_Все поля по Artic. Compare both queries checks the same selection with both queries; export in this mode is available only when their results match.', 'lps-card-help'),
        ]],
        'errors' => [__('If a card or file is missing', 'lps-card-help'), false, [
            __('If a SKU is not returned, check spelling and extra spaces. A card may exist in Folio but be excluded by the query conditions for warehouses or markups. Do not treat the missing list as proof that the product does not exist.', 'lps-card-help'),
            __('If loading Excel fails, check the file size, first worksheet, column letter, first data row and the 500-SKU limit. Keep the previous list or paste the SKUs manually.', 'lps-card-help'),
            __('If reading fails, keep the message, query and time and contact the responsible operator. For an access error on the alternative query, try 1212 все поля. If comparison reports a difference, export each query separately for review. If no file appears after a successful download, check your browser downloads.', 'lps-card-help'),
        ]],
    ];
}
function lps_card_help_menu(): void {
    lps_card_help_load();
    echo '<details class="lps-card-help-menu" style="margin:16px 0;padding:12px 16px;border:1px solid #c3c4c7;background:#fff;max-width:760px"><summary style="cursor:pointer;font-weight:600">'.esc_html(__('Help: export product cards', 'lps-card-help')).'</summary><ul>';
    foreach (lps_card_help_sections() as $id => [$title]) {
        echo '<li><a target="_blank" rel="noopener" href="'.esc_url(lps_card_help_url($id)).'">'.esc_html($title).'</a></li>';
    }
    echo '</ul></details>';
}
function lps_card_help_render(): void {
    if (!current_user_can(LPS_CAP)) wp_die(esc_html__('Access denied.', 'lavka-price-sync'), '', ['response'=>403]);
    lps_card_help_load();
    echo '<div class="wrap" style="max-width:900px"><h1>'.esc_html(__('Product cards in Excel: instructions', 'lps-card-help')).'</h1><p><a href="'.esc_url(admin_url('admin.php?page=lps-folio-cards')).'">'.esc_html(__('Back to product cards', 'lps-card-help')).'</a></p><p>'.esc_html(__('Open Lavka Folio → Product cards in the site administration. Access is available to staff with permission to manage Lavka prices. Export reads Folio; it does not change product cards or prices.', 'lps-card-help')).'</p><nav aria-label="'.esc_attr(__('Help: export product cards', 'lps-card-help')).'"><ul>';
    foreach (lps_card_help_sections() as $id => [$title]) echo '<li><a href="#'.esc_attr($id).'">'.esc_html($title).'</a></li>';
    echo '</ul></nav>';
    foreach (lps_card_help_sections() as $id => [$title, $ordered, $paragraphs]) {
        echo '<section id="'.esc_attr($id).'" style="scroll-margin-top:48px"><h2>'.esc_html($title).'</h2>';
        if ($ordered) echo '<ol style="padding-left:24px">';
        foreach ($paragraphs as $text) echo $ordered ? '<li style="margin-bottom:12px">'.esc_html($text).'</li>' : '<p>'.esc_html($text).'</p>';
        if ($ordered) echo '</ol>';
        echo '</section>';
    }
    echo '</div>';
}
add_action('admin_menu', static function (): void {
    lps_card_help_load();
    add_submenu_page('lps-folio-cards', __('Product cards in Excel: instructions', 'lps-card-help'), __('Product cards in Excel: instructions', 'lps-card-help'), LPS_CAP, 'lps-folio-card-help', 'lps_card_help_render');
}, 20);
