<?php
if (!defined('ABSPATH')) exit;

/** Contextual manager help; rendering never contacts Java or Folio. */
function lps_currency_help(string $topic): void {
    $topics = [
        'file'=>[
            __('How should I prepare the file?', 'lps-currency'),
            __('Use columns A–C in this order: CODE_ARTIC (article), PRICE (currency price), KOD_VALUT (currency code). You may include these headings in the first row or start with data.', 'lps-currency'),
            __('XLS/XLSX: first sheet only. CSV: UTF-8, separated by semicolons, tabs or commas. Use 1,8987 or 1.8987; quote a decimal comma if the CSV separator is a comma. Up to 5 MB and 500 unique articles. Paste formula results as values.', 'lps-currency'),
        ],
        'preview'=>[
            __('What should I check in the preview?', 'lps-currency'),
            __('Preview changes only reads Folio. Check the file name, articles, warehouses, old and new prices and currency codes. One article can appear on several warehouses, so the row count may exceed the number of articles.', 'lps-currency'),
            __('SCL_ARTC shows product cards; SCL_PRIC shows the additional price ЯЯ_Р_ВАЛЮТ on warehouse 7. Review both missing-article lists: missing rows will be skipped, not created.', 'lps-currency'),
        ],
        'apply'=>[
            __('When can I apply the changes?', 'lps-currency'),
            __('After checking the preview, tick the confirmation box and click Apply to Folio. Both updates are saved together. The preview is valid for 15 minutes; if it expires or the Folio values change, create and check a new preview.', 'lps-currency'),
            __('The confirmation box unlocks Apply to Folio only when a preview contains target rows. A saved operation cannot be applied again. There is no automatic undo: contact the administrator if the submitted prices were wrong.', 'lps-currency'),
        ],
        'status'=>[
            __('What if the result is unclear?', 'lps-currency'),
            __('Success is confirmed by Both updates saved and verified. If processing is interrupted or the outcome is unknown, click Check status. You can also reopen the operation from Recent operations, including after refreshing the page.', 'lps-currency'),
            __('Do not upload the file again while the outcome is unknown. If checking status does not resolve it, give the administrator the file name and operation time. Recent operations lists your last 50 operations.', 'lps-currency'),
        ],
    ];
    if (!isset($topics[$topic])) return;
    [$title,$first,$second]=$topics[$topic];
    echo '<details class="lps-currency-help" id="lps-currency-help-'.esc_attr($topic).'" style="margin:12px 0;max-width:900px"><summary>'.esc_html($title).'</summary>';
    echo '<p id="lps-currency-note-'.esc_attr($topic).'">'.esc_html($first).'</p><p>'.esc_html($second).'</p></details>';
}

function lps_currency_quick_guide(): void {
    echo '<details id="lps-currency-quick-guide" style="margin:16px 0;max-width:900px"><summary>'.esc_html__('Quick manager guide', 'lps-currency').'</summary><ol>';
    foreach ([
        __('Prepare three columns: article, currency price and currency code. Example:', 'lps-currency'),
        __('Select the file and click Preview changes. Check all rows and the missing articles.', 'lps-currency'),
        __('Tick the confirmation box, then click Apply to Folio. Wait for Both updates saved and verified.', 'lps-currency'),
        __('If the connection is interrupted, click Check status or reopen the operation from Recent operations. Do not repeat the upload until the outcome is known.', 'lps-currency'),
    ] as $i=>$step) {
        echo '<li>'.esc_html($step);
        if ($i===0) echo '<pre style="overflow:auto">CODE_ARTIC;PRICE;KOD_VALUT'."\n".'ML-47068;1,8987;3'."\n".'ML-18270;1,6974;3</pre>';
        echo '</li>';
    }
    echo '</ol><p>'.esc_html__('Both Folio updates keep the existing rules: currency price and code on all warehouses; ЯЯ_Р_ВАЛЮТ only on warehouse 7.', 'lps-currency').'</p></details>';
}
