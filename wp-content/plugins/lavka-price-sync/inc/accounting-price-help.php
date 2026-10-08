<?php
if (!defined('ABSPATH')) exit;

/** Read-only help; never loads snapshots or starts a Folio job. */
function lps_accounting_help_load(): void {
    $locale = determine_locale();
    if (in_array($locale, ['uk', 'ru_RU'], true)) {
        load_textdomain('lps-accounting-help', dirname(__DIR__) . '/languages/lps-accounting-help-' . $locale . '.mo');
    }
}
function lps_accounting_help_url(string $anchor = 'start'): string {
    return admin_url('admin.php?page=lps-accounting-prices&view=help') . '#' . sanitize_key($anchor);
}
function lps_accounting_help_sections(): array {
    return [
        'start' => [__('Start safely', 'lps-accounting-help'), [
            __('Open Lavka → Folio accounting prices. Access requires manage_lavka_prices (normally administrator or shop manager). This is accounting cost recalculation, not the Opt/Partner selling-price category. Select the Folio warehouse by name before any operation.', 'lps-accounting-help'),
            __('Preview does not commit accounting changes, but may use database resources and save job diagnostics. Apply performs real changes. Agree a maintenance window and current backup with the responsible operator. Never start a real recalculation just to learn the interface.', 'lps-accounting-help'),
        ]],
        'single' => [__('Recalculate one product', 'lps-accounting-help'), [
            __('1. Select the warehouse. 2. Open One product and enter the exact SKU, not the product name or barcode. 3. Click Check without changes. 4. Read the result and warnings. If the result permits apply, tick the confirmation and click Recalculate product in Folio once; confirm the dialog.', 'lps-accounting-help'),
            __('Changing SKU or warehouse invalidates the previous preview: check again. A disabled apply button is a safety gate, not an invitation to bypass it. After apply inspect the final result, warnings and job ID. A preview result alone does not mean the price was saved.', 'lps-accounting-help'),
        ]],
        'warehouse' => [__('Recalculate the entire warehouse', 'lps-accounting-help'), [
            __('1. Select the correct warehouse and open Entire warehouse. 2. Run exact rollback preview and wait for its final result. This runs the full native algorithm in portions and rolls preview transactions back; it is not a quick stock lookup. 3. Resolve blocking errors. 4. Confirm the backup and maintenance window, then run full native recalculation once.', 'lps-accounting-help'),
            __('Apply repeats rollback preflight before committing portions. Watch phase, progress, committed portions and warnings. COMPLETED_WITH_WARNINGS requires reviewing skipped products; FAILED_PARTIAL or OUTCOME_UNKNOWN is not a safe retry. Previously committed portions may remain. Refresh status and ask the operator to reconcile the job before any new apply.', 'lps-accounting-help'),
        ]],
        'campaign' => [__('SKU campaign, stop and schedule', 'lps-accounting-help'), [
            __('Completed failed SKU attempts, including FAILED_PARTIAL with running=false, do not exclude a warehouse from the next campaign. It starts from a fresh snapshot and keeps the previous error in diagnostics and the event journal. An active job or unknown write outcome is not a completed failure; do not start a duplicate.', 'lps-accounting-help'),
            __('SKU campaign and schedule is a different workflow from Entire warehouse. It builds a fresh snapshot, selects UNVERIFIED, NEW and DIRTY, processes sequential batches and ends with a verification snapshot. FAILED is also selected for a fresh check because its data may have been corrected; VERIFIED and REMOVED are not selected. Confirm the maintenance window before starting. Stop safely finishes the current operation and builds the final snapshot; closing the browser does not cancel server work.', 'lps-accounting-help'),
            __('The schedule can perform real recalculation without an open browser. Check selected warehouses, their order, days, time and limits before saving. Do not enable it just to view the overview. A request timeout is not proof of rollback; preserve the job ID and do not start a duplicate campaign.', 'lps-accounting-help'),
        ]],
        'overview' => [__('Processing state for all warehouses', 'lps-accounting-help'), [
            __('Where the tables are now. In SKU campaign and schedule, the current campaign result comes first, then Processing state for all Folio warehouses, then Campaign parameters and schedule. Details selected in the warehouse table appear BELOW that table, before the schedule. A new selection replaces those details. Scroll down if clicking a count or diagnostics button seems to do nothing.', 'lps-accounting-help'),
            __('Open SKU campaign and schedule → Processing state for all Folio warehouses. The permanent overview shows warehouses, their last processing result and snapshot time, state counts, negative-stock cases, warnings and errors. Refresh warehouse overview reloads saved information; it does not recalculate accounting prices or guarantee a new snapshot.', 'lps-accounting-help'),
            __('Never processed or No snapshot means there is no confirmed processing/snapshot history, not that the warehouse is empty. If the warehouse directory is unavailable, only warehouses known from saved data may appear: the list may be incomplete. Check dates before drawing conclusions from zero counters. Click a non-zero state count or the negative-stock/error buttons for details.', 'lps-accounting-help'),
        ]],
        'states' => [__('What product states mean', 'lps-accounting-help'), [
            __('UNVERIFIED: present in the snapshot, no confirmed successful recalculation yet. NEW: first observed, not recalculated yet. DIRTY: movements changed after the last confirmed recalculation. FAILED: recalculation failed; read the saved error. VERIFIED: confirmed recalculation for the observed data, not a permanent guarantee that future movements are correct. REMOVED: absent from the current snapshot; this does not mean someone deleted the product on the website.', 'lps-accounting-help'),
            __('Clickable lists are available for UNVERIFIED, NEW, DIRTY, FAILED and REMOVED. FAILED is not synonymous with negative stock: check the error code. A completed campaign can still contain skipped products. Use the final snapshot and detailed errors, not only the green job status.', 'lps-accounting-help'),
        ]],
        'negative' => [__('Negative stock: how to read it', 'lps-accounting-help'), [
            __('Open all-warehouse negative stock or a warehouse negative-stock report. The compact table opens immediately with only SKU, movement date and warehouse. Click the SKU to expand the other details. The date belongs to the problematic movement, not the recalculation; a dash means the date is unavailable. Use Previous/Next for additional pages; the count covers displayed entries, not all unique products. Collapsing details does not remove diagnostics.', 'lps-accounting-help'),
            __('Click View negative stock for all warehouses, or the negative-stock count/button in one warehouse row. NEGATIVE_CHRONOLOGICAL_STOCK means the calculated balance became negative at a historical movement. Example: before 27 units, expense 50, after −23, shortage 23. A positive physical balance today does not remove that historical inconsistency.', 'lps-accounting-help'),
            __('On screen inspect SKU, warehouse, document type/number/date, movement record, initial quantity, before/operation/after quantities and shortage. Current physical and accounting quantities are separate values captured with the diagnostic, not necessarily live stock now. A blank or dash means unavailable, not zero. Details may be missing in older records.', 'lps-accounting-help'),
            __('The displayed document identifies where the problem first became visible, not necessarily the document that must be corrected. Check earlier receipts, dates, warehouse, quantities and movement order with the Folio operator. Do not add a fictitious receipt or edit a document solely to make the warning disappear. After an authorised correction, preview that SKU again before apply; the campaign also checks FAILED products again, but this does not mean that the underlying error has been corrected.', 'lps-accounting-help'),
        ]],
        'excel' => [__('Download Excel: choose the right report', 'lps-accounting-help'), [
            __('If the state table or Excel button is hidden, expand the snapshot-state report below the warehouse overview, select the required state and wait for its table. Export selected state XLSX belongs to that state report. For negative-stock evidence use the separate permanent diagnostic log: select the warehouse, apply the required filters there, then export diagnostic XLSX. Do not look for this button inside the collapsed campaign warning table. To export only negative cases, open the downloaded workbook, enable the Reason column filter and select NEGATIVE_CHRONOLOGICAL_STOCK. Keep the original workbook unchanged for the operator.', 'lps-accounting-help'),
            __('For a product list: click a non-zero state count (for example FAILED) in the warehouse overview, then Export selected state XLSX. The workbook includes all pages of that warehouse/state selection, not only the visible page: SKU, name, state/reason, last error, latest available document and quantities, movement dates, observation/recalculation dates and technical details.', 'lps-accounting-help'),
            __('For detailed negative-stock investigation: in the warehouse row click View permanent diagnostics, or open it for a FAILED SKU. Check database/warehouse at the top. Optionally enter exact SKU, job ID, date range and Preview/Apply mode. Click Apply filters, then Export diagnostic XLSX. Dates filter when diagnostics were recorded (UTC), not the document date. Clear SKU to export all matching products in that warehouse.', 'lps-accounting-help'),
            __('The diagnostic XLSX exports all matching journal pages for one warehouse and database. It can include several attempts for the same SKU and other errors. In Excel filter Reason to NEGATIVE_CHRONOLOGICAL_STOCK and compare job ID, mode and recorded time. The all-warehouses negative-stock viewer has no single combined XLSX button: export journals warehouse by warehouse. Campaign report CSV is a separate campaign summary, not this detailed workbook.', 'lps-accounting-help'),
        ]],
        'columns' => [__('What the diagnostic workbook contains', 'lps-accounting-help'), [
            __('Identity: record ID, recorded time, database, warehouse, SKU, Preview/Apply, Java job ID, reason and message. Document: type, number, ID, date and movement record (RECNO). Chronology: initial quantity, before operation, operation kind/quantity, after operation, shortage and movement position/count. These columns help locate the movement in Folio.', 'lps-accounting-help'),
            __('Current state: physical, available and accounting quantity plus accounting price at diagnostic time. Confirmation fields distinguish a confirmed trigger movement from an unconfirmed business root cause. Rollback confirmed and Committed describe the recorded operation, not all earlier warehouse work. Missing flags do not mean No. SQL code/state, recommendation and Technical details (JSON) are for the specialist; preserve them when sharing the file internally.', 'lps-accounting-help'),
            __('The workbook has filters and a frozen header; SKU stays text and quantities stay numeric. Blank means unavailable, zero is a real value. This is an investigation report, not a stock-adjustment document or an import template: editing Excel does not change Folio. Accounting data should be shared only with authorised staff.', 'lps-accounting-help'),
        ]],
        'errors' => [__('If details or results are missing', 'lps-accounting-help'), [
            __('No journal records may mean a filter mismatch, old diagnostics not saved in detail, or an unavailable journal; it is not proof that there are no errors. Check warehouse, database, exact SKU, dates and mode, then ask the operator. An agreed new preview may collect new diagnostics; do not run apply just to obtain an Excel report.', 'lps-accounting-help'),
            __('For an unknown result or lost connection, preserve warehouse, SKU, job ID, time and message, refresh status and contact the responsible operator. Do not repeat apply or unlock a job blindly. A fresh snapshot alone does not prove whether the previous operation committed. Spreadsheet download does not recalculate or repair anything.', 'lps-accounting-help'),
        ]],
    ];
}
function lps_accounting_help_render(): void {
    if (!current_user_can(LPS_CAP)) return;
    lps_accounting_help_load();
    echo '<div class="wrap lps-accounting-help"><h1>' . esc_html__('Folio accounting prices: manager guide', 'lps-accounting-help') . '</h1>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=lps-accounting-prices')) . '">' . esc_html__('Back to accounting prices', 'lps-accounting-help') . '</a></p><nav>';
    foreach (lps_accounting_help_sections() as $id => [$title, $paragraphs]) {
        echo '<a href="#' . esc_attr($id) . '">' . esc_html($title) . '</a>';
    }
    echo '</nav>';
    foreach (lps_accounting_help_sections() as $id => [$title, $paragraphs]) {
        echo '<section id="' . esc_attr($id) . '"><h2>' . esc_html($title) . '</h2>';
        foreach ($paragraphs as $paragraph) echo '<p>' . esc_html($paragraph) . '</p>';
        echo '</section>';
    }
    echo '</div>';
}
add_action('admin_enqueue_scripts', function (): void {
    if (($_GET['page'] ?? '') !== 'lps-accounting-prices' || !current_user_can(LPS_CAP)) return;
    lps_accounting_help_load();
    $base = dirname(__DIR__);
    wp_enqueue_style('lps-accounting-help', plugins_url('assets/accounting-price-help.css', $base . '/lavka-price-sync.php'), [], filemtime($base . '/assets/accounting-price-help.css'));
    if (($_GET['view'] ?? '') === 'help') return;
    wp_enqueue_script('lps-accounting-help', plugins_url('assets/accounting-price-help.js', $base . '/lavka-price-sync.php'), [], filemtime($base . '/assets/accounting-price-help.js'), true);
    wp_localize_script('lps-accounting-help', 'lpsAccountingHelp', [
        'url' => lps_accounting_help_url(''),
        'label' => __('? How it works', 'lps-accounting-help'),
        'title' => __('Open explanation in a new tab', 'lps-accounting-help'),
    ]);
});
