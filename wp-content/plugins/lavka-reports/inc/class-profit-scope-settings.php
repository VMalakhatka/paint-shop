<?php
if (!defined('ABSPATH')) exit;

/** Java owns the versioned settings and Folio catalog. WordPress only validates transport. */
class Lavka_Reports_Profit_Scope_Settings {
    const ACTION = 'lavr_profit_scope_settings';
    const NONCE = 'lavr_profit_scope_settings_nonce';
    const WAREHOUSE_FIELDS = ['kyivWarehouseIds','odesaWarehouseIds','kyivStockWarehouseIds','odesaStockWarehouseIds'];
    public function __construct() { add_action('wp_ajax_' . self::ACTION, [$this, 'ajax']); }
    public static function assets() {
        wp_enqueue_script('lavr-profit-scope-settings', LAVR_URL . 'profit-scope-settings.js', ['lavr-profit-history'], LAVR_VER, true);
        wp_localize_script('lavr-profit-scope-settings', 'LavkaProfitScopeSettingsConfig', [
            'ajaxUrl'=>admin_url('admin-ajax.php'), 'action'=>self::ACTION,
            'nonce'=>wp_create_nonce(self::NONCE), 'i18n'=>self::translations(),
        ]);
    }
    public static function render() { ?>
        <details class="lavr-profit-manager-help" id="lavr-profit-scope-settings">
            <summary><?php echo esc_html__('Warehouses and inventory exclusions', 'lavka-reports'); ?></summary>
            <div class="lavr-profit-scope-editor">
                <p><?php echo esc_html__('Choose sales and inventory warehouses separately for Kyiv and Odesa. Expense filters and master-class warehouses are not changed here.', 'lavka-reports'); ?></p>
                <p><?php echo esc_html__('Excluded groups and subgroups apply only to inventory in both cities. A group excludes all its subgroups. Sales profit, master classes and expenses are not filtered by these exclusions.', 'lavka-reports'); ?></p>
                <p><?php echo esc_html__('Save the settings, then calculate the required months. Saved reports keep their original amounts and settings.', 'lavka-reports'); ?></p>
                <div id="lps-fields"></div>
                <p id="lps-version"></p>
                <button id="lps-save" type="button" class="button button-primary" disabled><?php echo esc_html__('Save warehouses and exclusions', 'lavka-reports'); ?></button>
                <button id="lps-reload" type="button" class="button"><?php echo esc_html__('Reload saved settings', 'lavka-reports'); ?></button>
                <p id="lps-state" role="status" aria-live="polite"></p>
                <p id="lps-error" role="alert" hidden></p>
            </div>
        </details>
    <?php }
    public function ajax() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message'=>__('You do not have permission to view this report.', 'lavka-reports')],403);
        if (false === check_ajax_referer(self::NONCE,'nonce',false)) wp_send_json_error(['message'=>__('Your audit session has expired. Reload the page and select the dates again.', 'lavka-reports')],403);
        $op=$_POST['operation'] ?? '';
        if (!is_string($op) || !in_array($op,['get','save','catalog'],true)) $this->bad();
        $args=['method'=>$op==='save'?'PUT':'GET','timeout'=>40];
        if ($op==='save') {
            $raw=$_POST['settings'] ?? null;
            if (!is_string($raw) || strlen($raw)>1400000) $this->bad();
            $body=json_decode(wp_unslash($raw),true);
            if (!is_array($body) || !isset($body['version']) || !is_int($body['version']) || $body['version']<0 || $body['version']>9007199254740991) $this->bad();
            $clean=['version'=>$body['version']];
            foreach (array_merge(self::WAREHOUSE_FIELDS,['excludedInventoryGroupIds','excludedInventorySubgroupIds']) as $key) {
                if (!isset($body[$key]) || !is_array($body[$key]) || !array_is_list($body[$key]) || count($body[$key])>(in_array($key,self::WAREHOUSE_FIELDS,true)?50:100)) $this->bad();
                if (in_array($key,self::WAREHOUSE_FIELDS,true) && !$body[$key]) $this->bad();
                $clean[$key]=[];
                foreach ($body[$key] as $id) {
                    if (in_array($key,self::WAREHOUSE_FIELDS,true)) {
                        if (!is_int($id) || $id<1 || $id>2147483647) $this->bad();
                    } elseif (!is_string($id) || strlen($id)>13000 || !preg_match('/\Ag:[A-Za-z0-9_-]+\z/', $id)) $this->bad();
                    if (in_array($id,$clean[$key],true)) $this->bad();
                    $clean[$key][]=$id;
                }
            }
            if (count($clean['excludedInventoryGroupIds'])+count($clean['excludedInventorySubgroupIds'])>100 || array_intersect($clean['excludedInventoryGroupIds'],$clean['excludedInventorySubgroupIds'])) $this->bad();
            if (array_intersect($clean['kyivWarehouseIds'],$clean['odesaWarehouseIds']) || array_intersect($clean['kyivStockWarehouseIds'],$clean['odesaStockWarehouseIds'])) $this->bad();
            $args['body']=wp_json_encode($clean);
        }
        $options=function_exists('lavka_sync_get_options')?lavka_sync_get_options():get_option('lavka_sync_options',[]);
        $base=rtrim((string)($options['java_base_url']??''),'/');
        if ($base==='') wp_send_json_error(['message'=>__('Java service URL is not configured.', 'lavka-reports')],500);
        $args['headers']=array_filter(['Accept'=>'application/json','X-Auth-Token'=>$options['api_token']??null]);
        if ($op==='save') $args['headers']['Content-Type']='application/json';
        $response=wp_remote_request($base.'/admin/folio/profit-report/'.($op==='catalog'?'scope-catalog':'scope-settings'),$args);
        if (is_wp_error($response)) wp_send_json_error(['message'=>__('Could not confirm the settings request. Reload saved settings before trying again.', 'lavka-reports')],502);
        wp_send_json_success(['httpStatus'=>(int)wp_remote_retrieve_response_code($response),'bodyRaw'=>(string)wp_remote_retrieve_body($response)]);
    }
    private function bad() { wp_send_json_error(['message'=>__('Check the selection: choose warehouses for each city, without city overlaps, and use valid groups and subgroups.', 'lavka-reports')],400); }
    public static function translations() { return [
        'kyivWarehouseIds'=>__('Kyiv sales warehouses', 'lavka-reports'),
        'odesaWarehouseIds'=>__('Odesa sales warehouses', 'lavka-reports'),
        'kyivStockWarehouseIds'=>__('Kyiv inventory warehouses', 'lavka-reports'),
        'odesaStockWarehouseIds'=>__('Odesa inventory warehouses', 'lavka-reports'),
        'excludedInventoryGroupIds'=>__('Exclude groups from inventory', 'lavka-reports'),
        'excludedInventorySubgroupIds'=>__('Exclude subgroups from inventory', 'lavka-reports'),
        'search'=>__('Search by name or code', 'lavka-reports'),
        'selected'=>__('Selected', 'lavka-reports'),
        'inherited'=>__('Excluded with its group', 'lavka-reports'),
        'missing'=>__('Not in the current catalog', 'lavka-reports'),
        'unnamed'=>__('Unnamed level', 'lavka-reports'),
        'empty'=>__('No matching entries', 'lavka-reports'),
        'loading'=>__('Loading warehouses, groups and settings…', 'lavka-reports'),
        'saving'=>__('Saving warehouses and exclusions…', 'lavka-reports'),
        'saved'=>__('Settings saved. Calculate the required months to apply them; saved reports remain unchanged.', 'lavka-reports'),
        'dirty'=>__('Unsaved warehouse or exclusion changes. Save them or reload saved settings before calculating.', 'lavka-reports'),
        'version'=>__('Warehouse and exclusion settings version', 'lavka-reports'),
        'invalid'=>__('Check the selection: choose warehouses for each city, without city overlaps, and use valid groups and subgroups.', 'lavka-reports'),
        'failed'=>__('Could not load warehouse settings or the Folio catalog. Check that Java supports warehouse and inventory settings.', 'lavka-reports'),
        'uncertain'=>__('Saving was not confirmed. Reload saved settings and check them before saving again.', 'lavka-reports'),
        'conflict'=>__('Another user changed warehouse settings. Reload saved settings, review them, then make your changes again.', 'lavka-reports'),
        'discard'=>__('Discard your unsaved warehouse and exclusion edits and reload saved settings?', 'lavka-reports'),
        'busy'=>__('Wait for the current operation to finish.', 'lavka-reports'),
        'used'=>__('Warehouses and exclusions used for this report', 'lavka-reports'),
        'legacy'=>__('This saved report has no warehouse-settings snapshot. Its original warehouse parameters are shown; current settings are not substituted.', 'lavka-reports'),
    ]; }
}
