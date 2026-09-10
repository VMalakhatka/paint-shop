<?php
if (!defined('ABSPATH')) exit;

/** Application storage proxy; never creates a table in Folio or WordPress. */
class Lavka_Reports_Profit_History {
    const ACTION = 'lavr_profit_history';
    const NONCE = 'lavr_profit_history_nonce';
    public function __construct() { add_action('wp_ajax_' . self::ACTION, [$this, 'ajax']); }
    public static function assets() {
        wp_enqueue_script('lavr-profit-history', LAVR_URL . 'profit-history.js', ['lavr-profit-report'], LAVR_VER, true);
        wp_localize_script('lavr-profit-history', 'LavkaProfitHistoryConfig', ['ajaxUrl'=>admin_url('admin-ajax.php'),'action'=>self::ACTION,'nonce'=>wp_create_nonce(self::NONCE),'i18n'=>self::translations()]);
    }
    public static function render_toolbar() {
        $t = self::translations(); ?>
        <section id="lavr-profit-history" class="lavr-profit-summary">
          <h2><?php echo esc_html($t['title']); ?></h2><p><?php echo esc_html($t['intro']); ?></p>
          <p><?php echo esc_html($t['auditFirst']); ?></p>
          <div class="lavr-profit-toolbar">
            <label><?php echo esc_html($t['from']); ?><input id="lph-from" type="month" required></label>
            <label><?php echo esc_html($t['to']); ?><input id="lph-to" type="month" required></label>
            <button id="lph-view" type="button" class="button button-primary"><?php echo esc_html($t['view']); ?></button>
            <button id="lph-calculate" type="button" class="button"><?php echo esc_html($t['calculate']); ?></button>
            <button id="lph-stop" type="button" class="button" disabled><?php echo esc_html($t['stop']); ?></button>
            <button id="lph-export" type="button" class="button" disabled><?php echo esc_html($t['export']); ?></button>
          </div>
          <p><?php echo esc_html($t['monthlyHelp']); ?></p>
          <p id="lph-state" role="status" aria-live="polite"></p><p id="lph-error" role="alert" hidden></p>
          <div id="lph-results" hidden><p id="lph-coverage"></p><div id="lph-months" class="lavr-profit-table-wrap"></div>
          <h3><?php echo esc_html($t['range']); ?></h3><p><?php echo esc_html($t['inventoryHelp']); ?></p><div id="lph-totals"></div></div>
          <div id="lph-revisions" hidden></div>
        </section>
        <h2><?php echo esc_html($t['detail']); ?></h2><p id="lph-selected"></p>
        <?php
    }
    public function ajax() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>__('You do not have permission to view this report.', 'lavka-reports')], 403);
        if (false === check_ajax_referer(self::NONCE, 'nonce', false)) wp_send_json_error(['message'=>__('Your audit session has expired. Reload the page and select the dates again.', 'lavka-reports')], 403);
        $op = $_POST['operation'] ?? '';
        if (!is_string($op) || !in_array($op, ['range','month','revisions','calculate'], true)) $this->bad();
        $query = [];
        $path = '/admin/folio/profit-report/saved';
        if ($op === 'range') {
            foreach (['fromMonth','toMonth'] as $key) $query[$key] = $this->month($_POST[$key] ?? '');
            $a = explode('-', $query['fromMonth']); $b = explode('-', $query['toMonth']);
            $span = ((int)$b[0] - (int)$a[0])*12 + (int)$b[1] - (int)$a[1];
            if ($span < 0 || $span > 23) $this->bad();
        } else {
            $path .= '/' . $this->month($_POST['month'] ?? '');
            if ($op === 'revisions') { $path .= '/revisions'; $query['limit'] = 20; }
            foreach (($op === 'month' ? ['revisionId'] : ($op === 'revisions' ? ['beforeRevisionId'] : [])) as $key) {
                if (!isset($_POST[$key]) || $_POST[$key] === '') continue;
                $value = $_POST[$key];
                if (!is_string($value) || !preg_match('/^[1-9]\d{0,14}$/', $value)) $this->bad();
                $query[$key] = $value;
            }
        }
        $body = [];
        if ($op === 'calculate') {
            $path .= '/calculate';
            $requestId = $_POST['requestId'] ?? '';
            if (!is_string($requestId) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $requestId)) $this->bad();
            $body['requestId'] = $requestId;
            foreach (['odesaTaxShare','rubToUahRate','kyivAdditionalSalary','odesaAdditionalSalary'] as $key) {
                $v = $_POST[$key] ?? '';
                if (!is_string($v)) $this->bad();
                $v = str_replace(',', '.', trim($v));
                if ($v === '') continue;
                if (strlen($v)>100 || !preg_match('/^[+-]?\d+(?:\.\d+)?$/', $v)) $this->bad();
                // Decimal strings avoid PHP float conversion; Java validates semantic ranges.
                $body[$key] = $v;
            }
        }
        $options = function_exists('lavka_sync_get_options') ? lavka_sync_get_options() : get_option('lavka_sync_options', []);
        $base = rtrim((string)($options['java_base_url'] ?? ''), '/');
        if ($base === '') wp_send_json_error(['message'=>__('Java service URL is not configured.', 'lavka-reports')], 500);
        $args = ['timeout'=>$op === 'calculate' ? 160 : 40,'headers'=>array_filter(['Accept'=>'application/json','X-Auth-Token'=>$options['api_token'] ?? null])];
        if ($op === 'calculate') {
            $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode($body);
            $response = wp_remote_post($base . $path, $args);
        } else $response = wp_remote_get(add_query_arg($query, $base . $path), $args);
        if (is_wp_error($response)) wp_send_json_error(['message'=>$response->get_error_message()], 502);
        wp_send_json_success(['httpStatus'=>(int)wp_remote_retrieve_response_code($response),'bodyRaw'=>(string)wp_remote_retrieve_body($response)]);
    }
    private function month($value) {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2],1,(int)$m[1])) $this->bad();
        return $value;
    }
    private function bad() { wp_send_json_error(['message'=>__('Invalid saved profit report request.', 'lavka-reports')],400); }

    public static function translations() { return [
        'failedStatus'=>__('Failed attempt','lavka-reports'),
        'runningStatus'=>__('Unfinished attempt','lavka-reports'),
        'calculatedAt'=>__('Calculation time','lavka-reports'),
        'completed'=>__('Calculation completed','lavka-reports'),
        'more'=>__('Older revisions','lavka-reports'),
        'latest'=>__('Latest attempt','lavka-reports'),
        'title'=>__('Saved Lavka profit','lavka-reports'),
        'from'=>__('From month','lavka-reports'),
        'to'=>__('Through month','lavka-reports'),
        'view'=>__('View saved reports','lavka-reports'),
        'calculate'=>__('Calculate and save selected months','lavka-reports'),
        'stop'=>__('Stop after current month','lavka-reports'),
        'export'=>__('Export saved period to Excel','lavka-reports'),
        'intro'=>__('Viewing saved reports does not read Folio. Calculation is a separate action that saves a new monthly revision.','lavka-reports'),
        'monthlyHelp'=>__('When calculating a range, the manual parameters below apply to each month. A blank additional salary uses the server default for each month; zero overrides it.','lavka-reports'),
        'inventoryHelp'=>__('Period profit and expenses are summed by month. Inventory uses the opening value of the first month and closing value of the last month.','lavka-reports'),
        'loading'=>__('Loading saved reports…','lavka-reports'),
        'ready'=>__('Saved reports loaded. Folio was not recalculated.','lavka-reports'),
        'missing'=>__('No saved report','lavka-reports'),
        'month'=>__('Month','lavka-reports'),
        'status'=>__('Status','lavka-reports'),
        'revision'=>__('Revision','lavka-reports'),
        'savedAt'=>__('Saved at','lavka-reports'),
        'rulesVersion'=>__('Rules version','lavka-reports'),
        'open'=>__('Open saved month','lavka-reports'),
        'revisions'=>__('Monthly revisions','lavka-reports'),
        'range'=>__('Period totals','lavka-reports'),
        'empty'=>__('There are no saved reports in this period. Calculate the required months explicitly.','lavka-reports'),
        'invalid'=>__('The saved report response is invalid or belongs to another period.','lavka-reports'),
        'error'=>__('Could not load saved reports. Check the Java service and report storage.','lavka-reports'),
        'uncertain'=>__('The calculation result is not confirmed. Refresh saved reports before repeating calculation; a revision may already have been saved.','lavka-reports'),
        'stale'=>__('The selected period changed. Load saved reports again.','lavka-reports'),
        'running'=>__('Calculating and saving month','lavka-reports'),
        'finished'=>__('Monthly calculations finished. Review saved results and warnings.','lavka-reports'),
        'stopped'=>__('Stopped before the next month. Completed revisions remain saved.','lavka-reports'),
        'published'=>__('Current saved result','lavka-reports'),
        'draft'=>__('Preliminary revision','lavka-reports'),
        'partial'=>__('Incomplete period: missing or preliminary results require review.','lavka-reports'),
        'dates'=>__('Select a valid month range.','lavka-reports'),
        'detail'=>__('Monthly detail and calculation parameters','lavka-reports'),
        'single'=>__('Calculate and save this month','lavka-reports'),
        'savedAudit'=>__('Show saved audit','lavka-reports'),
        'savedExportHelp'=>__('The month export uses this saved revision and its saved audit. It does not recalculate Folio.','lavka-reports'),
        'noCurrent'=>__('No current revision; preliminary versions are available in monthly revisions.','lavka-reports'),
        'requestId'=>__('Calculation request ID','lavka-reports'),
        'readonly'=>__('Saved revision','lavka-reports'),
        'auditFirst'=>__('Audit document quality first. Profit selection rules remain unchanged until discrepancies are reviewed.','lavka-reports'),
    ]; }
}
