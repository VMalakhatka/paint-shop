<?php
if (!defined('ABSPATH')) exit;

/** Tax firm settings belong to Java application storage, never to Folio. */
class Lavka_Reports_Profit_Tax_Settings {
    const ACTION = 'lavr_profit_tax_settings';
    const NONCE = 'lavr_profit_tax_settings_nonce';
    public function __construct() { add_action('wp_ajax_' . self::ACTION, [$this, 'ajax']); }
    public static function assets() {
        wp_enqueue_script('lavr-profit-tax-settings', LAVR_URL . 'profit-tax-settings.js', ['lavr-profit-history'], LAVR_VER, true);
        wp_localize_script('lavr-profit-tax-settings', 'LavkaProfitTaxSettingsConfig', [
            'ajaxUrl'=>admin_url('admin-ajax.php'), 'action'=>self::ACTION,
            'nonce'=>wp_create_nonce(self::NONCE), 'i18n'=>self::translations(),
        ]);
    }
    public static function render() { ?>
        <details class="lavr-profit-manager-help" id="lavr-profit-tax-settings">
            <summary><?php echo esc_html__('Tax firms: retail and wholesale', 'lavka-reports'); ?></summary>
            <div class="lavr-profit-tax-editor">
                <p><?php echo esc_html__('Add all historical and current firm codes. No dates are needed: the report uses tax documents for the selected month. Retail taxes are shared between the cities; wholesale taxes belong to Kyiv.', 'lavka-reports'); ?></p>
                <p><?php echo esc_html__('Unknown firms remain in the audit as unallocated taxes and require review. Saving these lists does not recalculate or change saved reports.', 'lavka-reports'); ?></p>
                <div id="lpt-groups"></div>
                <p id="lpt-version"></p>
                <button id="lpt-save" type="button" class="button button-primary" disabled><?php echo esc_html__('Save firm lists', 'lavka-reports'); ?></button>
                <button id="lpt-reload" type="button" class="button"><?php echo esc_html__('Reload saved lists', 'lavka-reports'); ?></button>
                <p id="lpt-state" role="status" aria-live="polite"></p>
                <p id="lpt-error" role="alert" hidden></p>
            </div>
        </details>
    <?php }
    public function ajax() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>__('You do not have permission to view this report.', 'lavka-reports')],403);
        if (false === check_ajax_referer(self::NONCE,'nonce',false)) wp_send_json_error(['message'=>__('Your audit session has expired. Reload the page and select the dates again.', 'lavka-reports')],403);
        $op=$_POST['operation'] ?? '';
        if (!is_string($op) || !in_array($op,['get','save'],true)) $this->bad();
        $args=['method'=>$op==='save'?'PUT':'GET','timeout'=>40];
        if ($op==='save') {
            $raw=$_POST['settings'] ?? null;
            if (!is_string($raw) || strlen($raw)>30000) $this->bad();
            $body=json_decode(wp_unslash($raw),true);
            if (!is_array($body) || !isset($body['version']) || !is_int($body['version']) || $body['version']<0 || $body['version']>9007199254740991) $this->bad();
            $clean=['version'=>$body['version']]; $seen=[];
            foreach (['retailFirmCodes','wholesaleFirmCodes'] as $key) {
                if (!isset($body[$key]) || !is_array($body[$key]) || !array_is_list($body[$key]) || count($body[$key])>100) $this->bad();
                $clean[$key]=[];
                foreach ($body[$key] as $code) {
                    if (!is_string($code) || !preg_match('/\A[\p{L}\p{N}_-]{1,64}\z/u',trim($code)) || trim($code)==='') $this->bad();
                    $normalized=mb_strtoupper(trim($code),'UTF-8');
                    if (isset($seen[$normalized])) $this->bad();
                    $seen[$normalized]=true; $clean[$key][]=$normalized;
                }
            }
            $args['body']=wp_json_encode($clean);
        }
        $options=function_exists('lavka_sync_get_options')?lavka_sync_get_options():get_option('lavka_sync_options',[]);
        $base=rtrim((string)($options['java_base_url']??''),'/');
        if ($base==='') wp_send_json_error(['message'=>__('Java service URL is not configured.', 'lavka-reports')],500);
        $args['headers']=array_filter(['Accept'=>'application/json','X-Auth-Token'=>$options['api_token']??null]);
        if ($op==='save') $args['headers']['Content-Type']='application/json';
        $response=wp_remote_request($base.'/admin/folio/profit-report/tax-settings',$args);
        if (is_wp_error($response)) wp_send_json_error(['message'=>__('Could not confirm the settings request. Reload saved lists before trying again.', 'lavka-reports')],502);
        wp_send_json_success(['httpStatus'=>(int)wp_remote_retrieve_response_code($response),'bodyRaw'=>(string)wp_remote_retrieve_body($response)]);
    }
    private function bad() { wp_send_json_error(['message'=>__('Check the firm codes: blank or duplicate rows are not allowed. A firm cannot belong to both lists.', 'lavka-reports')],400); }
    public static function translations() { return [
        'retail'=>__('Retail tax firms', 'lavka-reports'),
        'wholesale'=>__('Wholesale tax firms', 'lavka-reports'),
        'code'=>__('Firm code in Folio', 'lavka-reports'),
        'add'=>__('Add firm', 'lavka-reports'),
        'remove'=>__('Remove row', 'lavka-reports'),
        'loading'=>__('Loading firm lists…', 'lavka-reports'),
        'saving'=>__('Saving firm lists…', 'lavka-reports'),
        'saved'=>__('Firm lists saved. They will apply to new calculations; saved reports remain unchanged.', 'lavka-reports'),
        'dirty'=>__('Unsaved firm changes. Save them or reload the saved lists before calculating.', 'lavka-reports'),
        'version'=>__('Settings version', 'lavka-reports'),
        'invalid'=>__('Check the firm codes: blank or duplicate rows are not allowed. A firm cannot belong to both lists.', 'lavka-reports'),
        'failed'=>__('Could not load tax settings. Check that the Java service supports tax firm lists.', 'lavka-reports'),
        'uncertain'=>__('Saving was not confirmed. Reload saved lists and check them before saving again.', 'lavka-reports'),
        'conflict'=>__('Another user changed these lists. Reload saved lists, review them, then make your changes again.', 'lavka-reports'),
        'discard'=>__('Discard your unsaved edits and load the saved firm lists?', 'lavka-reports'),
        'busy'=>__('Wait for the current operation to finish.', 'lavka-reports'),
    ]; }
}
