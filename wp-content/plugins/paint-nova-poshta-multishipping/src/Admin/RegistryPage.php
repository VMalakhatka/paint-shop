<?php

namespace Paint\NovaPoshta\Admin;

use Paint\NovaPoshta\Registry\{Schema,Record,Store,Service,Folio};

defined('ABSPATH') || exit;

final class RegistryPage
{
    public static function t(string $text): string { return __($text,'paint-nova-poshta-multishipping'); }
    private static function e(string $text): string { return esc_html(self::t($text)); }
    public static function allowed(): bool { return current_user_can('manage_woocommerce') && current_user_can('manage_pnpm_shipments'); }

    public function hooks(): void
    {
        add_action('admin_menu', function () {
            if (self::allowed()) add_submenu_page('woocommerce',self::t('Nova Poshta registry'),self::t('Nova Poshta registry'),'manage_pnpm_shipments','pnpm-registry',[$this,'render']);
        });
        add_action('wp_ajax_pnpm_registry',[$this,'ajax']);
        add_action('admin_post_pnpm_registry_install',[$this,'install']);
        add_action('admin_enqueue_scripts',function () {
            if (($_GET['page']??'')!=='pnpm-registry' || !self::allowed()) return;
            wp_enqueue_style('pnpm-registry',PNPM_URL.'assets/registry.css',[],PNPM_VERSION);
            wp_enqueue_script('pnpm-registry',PNPM_URL.'assets/registry.js',[],PNPM_VERSION,true);
            wp_enqueue_script('pnpm-registry-picker',PNPM_URL.'assets/registry-picker.js',['pnpm-registry'],PNPM_VERSION,true);
            wp_enqueue_script('pnpm-registry-woo',PNPM_URL.'assets/registry-woo.js',['pnpm-registry'],PNPM_VERSION,true);
            wp_localize_script('pnpm-registry','pnpmRegistry',['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('pnpm_registry'),
                'page'=>admin_url('admin.php?page=pnpm-registry'),'loading'=>self::t('Checking…'),
                'failure'=>self::t('The request failed. Reload and check the saved result before repeating.'),
                'saved'=>self::t('Saved to the site registry.'),'preview'=>self::t('Preview only. Check the data and confirm below.'),
                'empty'=>self::t('Empty page received. This only ends the selected account document list; incoming and historical events are not proven complete.'),
                'more'=>self::t('Page saved. Continue with the next page or month until the selected period is finished.'),
                'labels'=>self::labels(),'picker'=>self::pickerText()]);
        });
    }

    public static function pickerText(): array
    {
        return array_map([self::class,'t'],[
            'empty'=>'No matches on this page.','more'=>'More pages remain. Continue loading or narrow the period.',
            'selected'=>'Selected document:','choose'=>'Choose a document','savedPartner'=>'Save the classification before choosing a document.',
            'receipt'=>'Receipts are listed by warehouse and day. Confirm the supplier manually; the list does not identify the supplier.',
            'manual'=>'Use manual verification for returns or documents unavailable in the list.',
            'selectPartner'=>'Choose a partner from the search results or clear the search.',
            'account'=>'An expense document can be linked without an account or WooCommerce order.',
            'changed'=>'Selection changed. Load the document list again.',
            'wooSelected'=>'Selected WooCommerce order:',
            'selectDocument'=>'Select document','selectedCount'=>'Selected documents:','limit'=>'Select between 1 and 20 documents.',
        ]);
    }

    public function ajax(): void
    {
        nocache_headers();
        if (!self::allowed()) wp_send_json_error(['message'=>self::t('Registry access denied.')],403);
        if (!check_ajax_referer('pnpm_registry','nonce',false)) wp_send_json_error(['message'=>self::t('Reload the page and try again.')],403);
        try {
            $input=wp_unslash($_POST);
            // No arbitrary arrays, files, models or paths from the browser.
            foreach ($input as $value) if (!is_string($value)) throw new \InvalidArgumentException('Invalid field.');
            $result=(new Service())->run((string)($input['command']??''),$input);
            unset($result['account'],$result['ttns']);
            wp_send_json_success($result);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            wp_send_json_error(['message'=>self::t($e->getMessage())],400);
        } catch (\Throwable $e) {
            wp_send_json_error(['message'=>self::t('The request failed. Reload and check the saved result before repeating.')],500);
        }
    }

    public function install(): void
    {
        if (!self::allowed() || !current_user_can('manage_options')) wp_die(esc_html(self::t('Registry access denied.')), '', ['response'=>403]);
        check_admin_referer('pnpm_registry_install');
        try { Schema::install(); }
        catch (\Throwable $e) { wp_die(esc_html(self::t('Registry installation failed. Ask the administrator to check the database.'))); }
        wp_safe_redirect(admin_url('admin.php?page=pnpm-registry')); exit;
    }

    public static function labels(): array
    {
        $labels=[
            'unknown'=>'Not classified','outgoing'=>'Outgoing','incoming'=>'Incoming','internal'=>'Between our warehouses',
            'unclassified'=>'Purpose not selected','sale'=>'Customer sale','customer_return'=>'Customer return',
            'purchase'=>'Supplier purchase','supplier_return'=>'Return to supplier','transfer'=>'Internal transfer','other'=>'Other',
            'ACCOUNT'=>'Account','EXPENSE'=>'Expense document','RECEIPT'=>'Receipt document','RETURN'=>'Return document',
            'sale_account'=>'Sale: account','sale_expense'=>'Sale: expense document',
            'api'=>'Verify through Folio API','manual'=>'Manager verification with evidence',
            'api_header_manual_party'=>'Header verified; supplier confirmed by manager',
            'np_documents'=>'Nova Poshta document list','np_tracking'=>'Nova Poshta tracking','csv'=>'Imported CSV',
            'type'=>'Document type','role'=>'Document role','id'=>'Internal ID','number'=>'Document number','date'=>'Document date',
            'partner'=>'Folio partner code','warehouse'=>'Folio warehouse code','verification'=>'Verification method','evidence'=>'Verification evidence',
            'from'=>'From date','to'=>'To date','page'=>'Page','count'=>'Records','empty_page'=>'Empty page','next_page'=>'Next page',
            'window_from'=>'Current month: from','window_to'=>'Current month: to','next_from'=>'Next read: from',
            'coverage'=>'Coverage','account_document_list_only'=>'Only documents visible to this API account',
            'known_ttn_only'=>'Only the specified TTN','manual_csv_only'=>'Only supplied CSV rows','manual_reference_only'=>'Manual TTN reference',
            'source'=>'Source','scope'=>'Import scope','observed_at'=>'Read at (UTC)',
            'IntDocNumber'=>'TTN','Number'=>'TTN','Status'=>'Carrier status','StatusCode'=>'Carrier status code','StateName'=>'Carrier status','StateId'=>'Carrier status code',
            'PayerType'=>'Delivery payer','payer'=>'Delivery payer','CostOnSite'=>'Carrier delivery amount (not proof of payment)',
            'DocumentCost'=>'Declared value','Cost'=>'Declared value','delivery_cost'=>'Delivery amount from CSV (unverified)',
            'SenderDescription'=>'Sender','RecipientDescription'=>'Recipient','SenderAddressDescription'=>'Origin',
            'RecipientAddressDescription'=>'Destination','CitySenderDescription'=>'Sender city','CityRecipientDescription'=>'Recipient city',
            'sender'=>'Sender','recipient'=>'Recipient','origin'=>'Origin','destination'=>'Destination',
            'Weight'=>'Weight','SeatsAmount'=>'Packages','DateTime'=>'Carrier document date','DateCreated'=>'Carrier creation date',
            'ScheduledDeliveryDate'=>'Estimated delivery date','ActualDeliveryDate'=>'Actual delivery date',
            'values'=>'Document values at confirmation',
            'document_amount'=>'Document total, UAH',
            'shipment_amount'=>'Goods amount in document lines, UAH',
            'accounting_amount'=>'Accounting cost in document lines, UAH',
            'captured_at_utc'=>'Read at (UTC)',
            'line_count'=>'Document lines',
            'missing_amount_lines'=>'Lines without goods amount',
            'missing_accounting_lines'=>'Lines without accounting cost',
            'currency'=>'Currency','status'=>'Status',
            'folio_header'=>'Folio document header',
            'folio_document_values'=>'Folio document header and lines',
            'values_unavailable'=>'Detailed values unavailable; unknown amounts are not zero',
            'values_captured'=>'Values read from Folio',
            'total_weight_kg'=>'Total weight, kg',
            'place_count'=>'Declared number of places',
            'site_package_entry'=>'Our package data used for creation',
            'shipment_id'=>'Site shipment ID',
            'order_id'=>'WooCommerce order ID',
            'basis'=>'Expense basis','basis_lookup_status'=>'Basis account lookup',
            'MATCHED'=>'Unique account found; confirming saves both links',
            'NO_BASIS'=>'No basis account specified; the expense can be linked alone',
            'UNRECOGNIZED_BASIS'=>'Basis format not recognized; the expense can be linked alone',
            'NOT_FOUND'=>'Basis account not found; the expense can be linked alone',
            'AMBIGUOUS'=>'Several basis accounts found; no account will be linked automatically',
            'UNAVAILABLE'=>'Basis account lookup unavailable; the expense can be linked alone',
            'api_basis'=>'Account matched from the expense basis',
            'ACTIVE_LEDGER'=>'Active Folio documents','ARCHIVE_LEDGER'=>'Folio archive',
            'related_account'=>'Account to link together with the expense',
            'account_link_id'=>'Related account link ID', 'api_snapshot'=>'Folio header evidence',
            'Sender'=>'Sender','Recipient'=>'Recipient',
            'ThirdPerson'=>'Third party',
            'WOO_ORDER'=>'WooCommerce order','woo_order'=>'WooCommerce order',
            'manager_confirmation'=>'Confirmed by manager','evidence_optional'=>'Comment (optional)',
            'folio_documents'=>'Folio documents saved in this WooCommerce order',
            'registry_context'=>'Saved shipment classification',
            'old_woo_folio'=>'Folio details were not captured in this saved link. Preview the order again to read them.',
            'registry_documents'=>'Confirmed Folio documents for this TTN',
            'no_order_folio'=>'No Folio documents are saved in this WooCommerce order.',
            'no_registry_folio'=>'No confirmed Folio documents for this TTN. A selection in the form above is not a saved link.',
            'folio_context_hint'=>'Order documents and TTN links are separate sources. Check both; linking WooCommerce does not confirm a Folio link.',
            'missing_stock_account'=>'Non-accounting missing-stock account',
            'order_snapshot'=>'Reviewed WooCommerce order','order_id'=>'WooCommerce order ID',
            'order_number'=>'Order number','order_date'=>'Order date','order_modified'=>'Order revision time',
            'order_status'=>'Order status','order_total'=>'Order total','order_currency'=>'Currency',
            'customer_id'=>'Customer ID','customer_name'=>'Customer name','recipient_name'=>'Recipient',
            'recipient_city'=>'Recipient city','parent_order_id'=>'Parent order ID',
            'EWDateCreated'=>'Carrier creation date','RecipientDateTime'=>'Actual delivery date',
            'PreferredDeliveryDate'=>'Estimated delivery date',
        ];
        $labels=array_map([self::class,'t'],$labels);
        if (function_exists('wc_get_order_statuses')) foreach (wc_get_order_statuses() as $key=>$label) $labels[substr($key,3)]=$label;
        return $labels;
    }

    public function render(): void
    {
        if (!self::allowed()) return;
        echo '<div class="wrap pnpm-registry"><h1>'.self::e('Nova Poshta registry').'</h1>';
        echo '<p>'.self::e('Outgoing, incoming and returns since 2026-06-01. Folio links do not require a WooCommerce order.').'</p>';
        echo '<details class="pnpm-box"><summary>'.self::e('How to work with the registry').'</summary><ol>';
        foreach (['Read a TTN, import an account page or paste CSV. Preview does not save the registry record.',
            'Check the preview, tick the confirmation and save. The preview expires after 10 minutes. Editing a field requires a new preview.',
            'Open a TTN and choose its direction, purpose and Folio partner code. For a return, also enter the original TTN if known.',
            'Link the expense document directly. An account and WooCommerce order are optional; a unique basis account, including the archive, is offered in the same preview.',
            'Find a WooCommerce order, review its details and confirm its link separately. The comment is optional. Saved carrier responses do not confirm WooCommerce or Folio links.',
            'Incoming purchases use receipt documents. Returns use a separate return reference and evidence checked by the manager.',
            'Carrier reads retain available fields and changes observed by the site. They cannot reconstruct events the carrier no longer returns.',
            'Delivery amounts, declared value and cash on delivery are separate facts. No margin, payment, stock, TTN or customer notification is created here.'] as $text) echo '<li>'.self::e($text).'</li>';
        echo '</ol></details>';
        if (!Schema::ready()) {
            echo '<p>'.self::e('The administrator must initialize the registry after a database backup. Existing shipment tables are not changed.').'</p>';
            if (current_user_can('manage_options')) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="pnpm_registry_install">';
                wp_nonce_field('pnpm_registry_install');
                echo '<button class="button button-primary">'.self::e('Initialize registry tables').'</button></form>';
            }
            echo '</div>'; return;
        }
        try {
            $id=isset($_GET['id']) ? absint($_GET['id']) : 0;
            if ($id) $this->detail((new Store())->detail($id));
            else { $this->imports(); $this->listing(); $this->coverage(); }
        } catch (\Throwable $e) { echo '<p role="alert">'.self::e('Could not load the registry. Check the selection or contact the administrator.').'</p>'; }
        echo '</div>';
    }

    private function imports(): void
    {
        echo '<details class="pnpm-box"><summary>'.self::e('Add shipments / load history').'</summary><p>'.self::e('Incoming discovery is not verified. Add known incoming TTNs or CSV; an empty account list does not prove that there were no incoming parcels.').'</p>';
        $this->form('tracking'); $this->input('ttn','TTN','text','',true); $this->input('phone','Sender or recipient phone (optional)','tel'); $this->previewEnd();
        echo '<h3>'.self::e('Account history').'</h3>';
        echo '<p>'.self::e('Select the full period. The site reads one month at a time; after an empty page, continue to the next month.').'</p>';
        $this->form('history');
        $this->input('from','From date','date',Record::START,true); $this->input('to','To date','date',wp_date('Y-m-d'),true);
        echo '<input type="hidden" name="previous" value="">';
        $this->previewEnd(true);
        echo '<h3>'.self::e('CSV import').'</h3><p>'.self::e('UTF-8, semicolon separator, up to 100 rows. Blank cost means unknown, not zero. Use this exact header:').'</p><code>ttn;date;sender;recipient;origin;destination;payer;delivery_cost</code>';
        $this->form('csv'); echo '<label>'.self::e('CSV contents').'<textarea name="csv" rows="5" required maxlength="262144"></textarea></label>'; $this->previewEnd();
        echo '<h3>'.self::e('Manual TTN reference').'</h3><p>'.self::e('Use for old TTNs unavailable through tracking. This does not verify the shipment with Nova Poshta.').'</p>';
        $this->form('manual'); $this->input('ttn','TTN','text','',true); $this->input('evidence','Verification evidence','text','',true); $this->previewEnd();
        echo '</details>';
    }

    private function listing(): void
    {
        $filter=[];
        foreach (['ttn','direction','purpose'] as $key) $filter[$key]=isset($_GET[$key]) && is_string($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
        $page=min(100000,max(1,absint($_GET['registry_page']??1)));
        echo '<form method="get" class="pnpm-filter"><input type="hidden" name="page" value="pnpm-registry">';
        $this->input('ttn','TTN','text',$filter['ttn']);
        $this->select('direction','Direction',array_merge([''],Record::DIRECTIONS),$filter['direction']);
        $this->select('purpose','Purpose',array_merge([''],Record::PURPOSES),$filter['purpose']);
        echo '<button class="button">'.self::e('Search').'</button></form>';
        $rows=(new Store())->listing($filter,$page); $more=count($rows)>30;
        echo '<p>'.self::e('Amounts are in UAH. Delivery payer does not prove payment. The NP date may be the document date; missing data is shown as a dash.').'</p>';
        echo '<div class="pnpm-table-scroll" role="region" aria-label="'.esc_attr(self::t('Nova Poshta registry')).'" tabindex="0"><table class="widefat striped pnpm-registry-list"><thead><tr>';
        foreach (['TTN','Recipient','Recipient city','Shipment / document date','Delivery, UAH','Declared value, UAH','Delivery payer','Folio partner code','Updated (UTC)'] as $label) echo '<th scope="col">'.self::e($label).'</th>';
        echo '</tr></thead><tbody>';
        foreach (array_slice($rows,0,30) as $row) {
            $summary=$row['summary'];
            echo '<tr><td><a class="pnpm-ttn" href="'.esc_url(add_query_arg(['page'=>'pnpm-registry','id'=>$row['id']],admin_url('admin.php'))).'">'.esc_html($row['ttn']).'</a>';
            foreach (['direction'=>'unknown','purpose'=>'unclassified'] as $key=>$empty) if ($row[$key]!==$empty) echo '<small>'.esc_html(self::labels()[$row[$key]]??$row[$key]).'</small>';
            if ($summary['csv']) echo '<small>'.self::e('Contains CSV data (unverified)').'</small>';
            echo '</td><td class="pnpm-recipient">'.esc_html($summary['recipient']?:'—');
            if ($summary['contact']!=='') echo '<small>'.esc_html($summary['contact']).'</small>';
            echo '</td><td>'.esc_html($summary['city']?:'—').'</td><td>'.esc_html($summary['date']?:'—').'</td>';
            foreach (['delivery','declared'] as $key) echo '<td class="pnpm-amount">'.esc_html($summary[$key]===''?'—':number_format_i18n((float)$summary[$key],2)).'</td>';
            echo '<td>'.esc_html(self::labels()[$summary['payer']]??($summary['payer']?:'—')).'</td>';
            echo '<td>'.esc_html($row['partner_code']?:'—').'</td><td class="pnpm-updated">'.esc_html($row['updated_at']).'</td>';
            echo '</tr>';
        }
        if (!$rows) echo '<tr><td colspan="9">'.self::e('No records match. Import data or change the filter.').'</td></tr>';
        echo '</tbody></table></div><p>';
        foreach ([$page-1=>'Previous page',$page+1=>'Next page'] as $p=>$label) if ($p>=1 && ($p<$page || $more)) {
            echo '<a class="button" href="'.esc_url(add_query_arg(array_merge($filter,['page'=>'pnpm-registry','registry_page'=>$p]),admin_url('admin.php'))).'">'.self::e($label).'</a> ';
        }
        echo '</p>';
    }

    private function detail(array $row): void
    {
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=pnpm-registry')).'">'.self::e('Back to registry').'</a></p><h2>'.esc_html($row['ttn']).'</h2>';
        echo '<section class="pnpm-box"><h3>'.self::e('Shipment classification').'</h3>';
        $this->form('classify',$row);
        $this->select('direction','Direction',Record::DIRECTIONS,$row['direction']); $this->select('purpose','Purpose',Record::PURPOSES,$row['purpose']);
        echo '<div class="pnpm-partner-picker"><label>'.self::e('Find Folio partner (code or name)').'<input type="search" class="pnpm-partner-search" autocomplete="off" maxlength="100"></label>';
        echo '<input type="hidden" name="partner_code" value="'.esc_attr($row['partner_code']).'"><p class="pnpm-partner-current">'.self::e('Folio partner code').': <strong>'.esc_html($row['partner_code']?:'—').'</strong></p>';
        echo '<button type="button" class="button pnpm-partner-clear">'.self::e('Clear').'</button><div class="pnpm-partner-results" role="status" aria-live="polite"></div></div>';
        $this->input('related_ttn','Original / related TTN','text',$row['related_ttn']); $this->input('note','Manager note','text',(string)$row['note']);
        $this->saveEnd(); echo '</section>';
        echo '<section class="pnpm-box"><h3>'.self::e('Confirmed Folio links').'</h3><p>'.self::e('A link is evidence of an association, not a payment or cost allocation. The same TTN may have several documents.').'</p>';
        $this->valueTable($row['links']);
        $accounts=[];
        $folioLinks=array_filter($row['links'],static fn($link)=>$link['document_type']!=='WOO_ORDER');
        foreach ($folioLinks as $link) {
            if ($link['active'] && $link['document_type']==='ACCOUNT') $accounts[$link['id']]=$link['document_number'].' · '.$link['document_date'];
            echo '<details id="pnpm-link-'.esc_attr($link['id']).'"><summary>'.esc_html(($link['active']?'✓ ':self::t('Removed link').' · ').(self::labels()[$link['role']]??$link['role']).' · '.$link['document_number'].' · '.$link['document_date']).'</summary>';
            echo '<p>'.self::e('Confirmed by user ID').' '.esc_html($link['confirmed_by']).' · '.esc_html($link['confirmed_at']).' UTC</p>';
            $snapshot=json_decode($link['snapshot'],true)?:[]; $values=$snapshot['values']??null; unset($snapshot['values']);
            $this->data($snapshot);
            if ($values) { unset($values['schema_version']); $this->data($values); }
            if ($link['active'] && in_array($link['document_type'],['ACCOUNT','EXPENSE','RECEIPT'],true) && $link['document_id'] && $link['verification']!=='api_basis') {
                $this->form('refresh_link_preview',$row); echo '<input type="hidden" name="link" value="'.esc_attr($link['id']).'">';
                echo '<p>'.self::e('Read this linked document again to refresh its saved values. Check and confirm; previous evidence remains in the audit.').'</p>';
                $this->previewEnd(false,'Save updated amounts','Read current amounts');
            }
            if ($link['active']) {
                $this->form('unlink',$row); echo '<input type="hidden" name="link" value="'.esc_attr($link['id']).'">';
                echo '<p>'.self::e('Wrong document? Remove this association, then choose and confirm the correct document below. This does not delete or change Folio documents or the TTN.').'</p>';
                $this->input('reason','Reason for revoking the link (10–1000 characters)','text','',true);
                $this->saveEnd('Revoke link, retain audit');
            }
            echo '</details>';
        }
        if (!$folioLinks) echo '<p>'.self::e('No confirmed links yet.').'</p>';
        echo '<h3>'.self::e('Add a Folio document link').'</h3><p>'.self::e('Choose the saved partner, warehouse, period and document. Check the preview and confirm the link. Folio documents are not changed.').'</p>';
        echo '<p class="pnpm-partner-save-help"'.($row['partner_code']?' hidden':'').'>'.self::e('Select and save the shipment partner first.').'</p>';
        $this->form('link_preview',$row);
        $type=$row['purpose']==='purchase'?'RECEIPT':(in_array($row['purpose'],['customer_return','supplier_return'],true)?'RETURN':'EXPENSE');
        $role=['RECEIPT'=>'purchase','RETURN'=>$row['purpose'],'ACCOUNT'=>'sale_account','EXPENSE'=>'sale_expense'][$type];
        if ($row['direction']==='internal') { $type='EXPENSE'; $role='other'; }
        $this->select('type','Document type',Folio::TYPES,$type); $this->select('role','Document role',Folio::ROLES,$role);
        $this->select('verification','Verification method',['api','manual'],'api');
        echo '<label>'.self::e('Folio partner code').'<input name="partner" value="'.esc_attr($row['partner_code']).'" readonly></label>';
        echo '<div class="pnpm-actions pnpm-classification-continue" hidden><button type="button" class="button button-primary pnpm-save-continue">'.self::e('Save classification and continue').'</button><p>'.self::e('Classification changes are not saved yet. Save to enable document loading; warehouse and dates will stay selected.').'</p></div>';
        echo '<fieldset class="pnpm-document-picker"><legend>'.self::e('Choose a Folio document').'</legend><div class="pnpm-picker-filters">';
        echo '<fieldset class="pnpm-warehouse-picker"><legend>'.self::e('Warehouses (select several)').'</legend><div class="pnpm-warehouse-options"></div></fieldset><input type="hidden" name="picker_warehouse"><button type="button" class="button pnpm-warehouses">'.self::e('Load warehouses').'</button>';
        $this->input('date_from','From date','date',current_time('Y-m-01')); $this->input('date_to','To date','date',current_time('Y-m-d'));
        $this->input('operation','Operation type contains');
        echo '<button type="button" class="button pnpm-documents-load">'.self::e('Load documents').'</button></div><p class="pnpm-picker-message" role="status" aria-live="polite"></p>';
        echo '<p>'.self::e('Select up to 20 documents. Loading the next page keeps your selection; changing filters clears it. One confirmation saves the whole group.').'</p>';
        echo '<div class="pnpm-table-scroll"><table class="widefat striped pnpm-document-table"><thead><tr><th>'.self::e('Select').'</th>';
        foreach (['Document number','Document date','Amount','Folio partner code','Warehouse','Operation type','Source information','Additional information'] as $label) echo '<th>'.self::e($label).'</th>';
        echo '</tr></thead><tbody class="pnpm-document-rows"></tbody></table></div><input type="hidden" name="selected_documents" value="[]">';
        echo '<button type="button" class="button pnpm-documents-more" hidden>'.self::e('Load next page / day').'</button><p>'.self::e('An expense document does not require an account. The preview looks for its basis account, including the archive. Receipts require a manual supplier check.').'</p></fieldset>';
        echo '<p class="pnpm-document-selected" role="status"></p><details class="pnpm-manual-fields"><summary>'.self::e('Document details / manual entry').'</summary><div class="pnpm-picker-filters">';
        $this->input('document_id','Internal Folio document ID (optional for manual verification)');
        $this->input('number','Document number','text','',true); $this->input('date','Document date','date','',true);
        $this->input('warehouse','Folio warehouse code'); echo '</div></details>';
        echo '<p class="pnpm-link-requirement" role="status"></p><label>'.self::e('Previously linked account (optional)').'<select name="account_link_id"><option value="">—</option>';
        foreach ($accounts as $id=>$label) echo '<option value="'.esc_attr($id).'">'.esc_html($label).'</option>';
        echo '</select></label>';
        $this->input('evidence','Verification evidence'); $this->previewEnd(false,'Confirm Folio link'); echo '</section>';
        $this->wooLinks($row);
        $this->packageTable($row);
        echo '<section class="pnpm-box"><h3>'.self::e('Refresh carrier data').'</h3>';
        $this->form('tracking'); $this->input('ttn','TTN','text',$row['ttn'],true); $this->input('phone','Sender or recipient phone (optional)','tel'); $this->previewEnd(false,'Save carrier data'); echo '</section>';
        echo '<section class="pnpm-box"><h3>'.self::e('Carrier observations').'</h3><p>'.self::e('Latest 100 changed responses, newest observation first. Unavailable fields remain unknown. Read times are UTC, not the shipment event time.').'</p>';
        foreach ($row['observations'] as $i=>$observation) {
            echo '<details'.($i===0?' open':'').'><summary>'.esc_html((self::labels()[$observation['source']]??$observation['source']).' · '.$observation['observed_at'].' UTC').'</summary>';
            $this->data(json_decode($observation['payload'],true)?:[]); echo '</details>';
        }
        echo '</section><details class="pnpm-box"><summary>'.self::e('Manager audit (latest 100)').'</summary>';
        foreach ($row['audit'] as $event) {
            echo '<details><summary>'.esc_html($event['created_at'].' UTC · #'.$event['actor_id'].' · '.$event['event_type']).'</summary>';
            $this->data(json_decode($event['context'],true)?:[]); echo '</details>';
        }
        echo '</details>';
    }

    private function wooLinks(array $row): void
    {
        echo '<section class="pnpm-box"><h3>'.self::e('Linked WooCommerce orders').'</h3><p>'.self::e('One TTN can be linked to several WooCommerce orders. This records an association only: order status, payment, shipments and Folio documents are not changed.').'</p>';
        $links=array_filter($row['links'],static fn($link)=>$link['document_type']==='WOO_ORDER');
        foreach ($links as $link) {
            echo '<details><summary>'.esc_html(($link['active']?'✓ ':'— ').'#'.$link['document_number'].' · '.$link['document_date']).'</summary>';
            $order=wc_get_order((int)$link['document_id']);
            if ($order instanceof \WC_Order) echo '<p><a href="'.esc_url($order->get_edit_order_url()).'">'.self::e('Open WooCommerce order').'</a></p>';
            else echo '<p>'.self::e('WooCommerce order is unavailable. Reload the selection.').'</p>';
            $snapshot=json_decode($link['snapshot'],true)?:[];
            $this->wooOrderData($snapshot['order_snapshot']??[]);
            if (isset($snapshot['registry_context'])) {
                $context=$snapshot['registry_context'];
                echo '<h4>'.self::e('Folio context at confirmation').'</h4>';
                $this->data(['partner'=>$context['partner'] ?: '—']);
                $this->folioHeaders($context['documents'],'Confirmed Folio documents for this TTN','No confirmed Folio documents for this TTN. A selection in the form above is not a saved link.');
            }
            if ($link['evidence']!=='') echo '<p>'.self::e('Comment (optional)').': '.esc_html($link['evidence']).'</p>';
            echo '<p>'.self::e('Confirmed by user ID').' '.esc_html($link['confirmed_by']).' · '.esc_html($link['confirmed_at']).' UTC</p>';
            if ($link['active'] && in_array($link['document_type'],['ACCOUNT','EXPENSE','RECEIPT'],true) && $link['document_id'] && $link['verification']!=='api_basis') {
                $this->form('refresh_link_preview',$row); echo '<input type="hidden" name="link" value="'.esc_attr($link['id']).'">';
                echo '<p>'.self::e('Read this linked document again to refresh its saved values. Check and confirm; previous evidence remains in the audit.').'</p>';
                $this->previewEnd(false,'Confirm Folio link');
            }
            if ($link['active']) {
                $this->form('unlink',$row); echo '<input type="hidden" name="link" value="'.esc_attr($link['id']).'">';
                $this->input('reason','Reason for revoking the link','text','',true); $this->saveEnd('Revoke link, retain audit');
            }
            echo '</details>';
        }
        if (!$links) echo '<p>'.self::e('No confirmed WooCommerce links yet.').'</p>';
        echo '<h3>'.self::e('Link a WooCommerce order').'</h3>';
        $this->form('woo_preview',$row);
        echo '<fieldset class="pnpm-woo-picker"><legend>'.self::e('Find WooCommerce orders').'</legend><div class="pnpm-picker-filters">';
        $this->input('woo_id','WooCommerce order ID (optional)','number');
        $this->input('woo_email','Customer email (optional)','email');
        $this->input('woo_from','From date','date',current_time('Y-m-01')); $this->input('woo_to','To date','date',current_time('Y-m-d'));
        echo '<button type="button" class="button pnpm-woo-load">'.self::e('Find orders').'</button></div><p>'.self::e('Search by exact order ID, or by creation dates and optional customer email. Results are not automatically matched to the Folio partner.').'</p>';
        echo '<p class="pnpm-woo-message" role="status" aria-live="polite"></p><label>'.self::e('Choose a WooCommerce order').'<select class="pnpm-woo-select" size="6" aria-label="'.esc_attr(self::t('Choose a WooCommerce order')).'"></select></label>';
        echo '<button type="button" class="button pnpm-woo-more" hidden>'.self::e('Next page').'</button></fieldset><p class="pnpm-woo-selected" role="status"></p><input type="hidden" name="woo_order_id">';
        $this->input('evidence','Comment (optional)');
        $this->previewEnd(false,'Confirm WooCommerce link'); echo '</section>';
    }

    private function wooOrderData(array $order): void
    {
        $captured=array_key_exists('folio_documents',$order);
        $documents=$order['folio_documents']??[];
        unset($order['folio_documents'],$order['order_modified']);
        if (empty($order['parent_order_id'])) unset($order['parent_order_id']);
        $this->data($order);
        if ($captured) $this->folioHeaders($documents,'Folio documents saved in this WooCommerce order','No Folio documents are saved in this WooCommerce order.');
        else echo '<p>'.self::e('Folio details were not captured in this saved link. Preview the order again to read them.').'</p>';
    }

    private function folioHeaders(array $documents,string $title,string $empty): void
    {
        echo '<h4>'.self::e($title).'</h4>';
        if (!$documents) echo '<p>'.self::e($empty).'</p>';
        foreach ($documents as $document) $this->data(array_filter($document,static fn($value)=>$value!=='' && $value!==null));
    }

    private function coverage(): void
    {
        echo '<details class="pnpm-box"><summary>'.self::e('Import coverage (latest 30 confirmations)').'</summary><p>'.self::e('Completed pages prove only what this account returned at that time. Empty pages do not prove full incoming coverage or historical movement. CSV and manual records remain separate sources.').'</p>';
        foreach ((new Store())->recentImports() as $batch) {
            $result=json_decode($batch['result'],true)?:[]; unset($result['account'],$result['ttns'],$result['id']);
            echo '<details><summary>'.esc_html('#'.$batch['id'].' · '.$batch['created_at'].' UTC').'</summary>'; $this->data($result); echo '</details>';
        }
        echo '</details>';
    }

    private function valueTable(array $links): void
    {
        echo '<p>'.self::e('Values describe the whole document at the time of reading, not the part allocated to this TTN. Do not add account and expense amounts as separate sales.').'</p>';
        $links=array_filter($links,static fn($link)=>$link['active'] && $link['document_type']!=='WOO_ORDER');
        if (!$links) return;
        echo '<div class="pnpm-table-scroll"><table class="widefat striped pnpm-evidence-table"><thead><tr>';
        foreach(['Document number','Warehouse','Document total, UAH','Goods amount in document lines, UAH','Accounting cost in document lines, UAH','Read at (UTC)','Actions'] as $label) echo '<th>'.self::e($label).'</th>';
        echo '</tr></thead><tbody>';
        foreach($links as $link) {
            $v=json_decode($link['snapshot'],true)['values']??[];
            echo '<tr>';
            foreach([$link['document_number'],$link['warehouse_id'],$v['document_amount']??null,$v['shipment_amount']??null,$v['accounting_amount']??null,$v['captured_at_utc']??null] as $value) echo '<td>'.esc_html($value===null?'—':(string)$value).'</td>';
            echo '<td class="pnpm-link-actions">';
            if (in_array($link['document_type'],['ACCOUNT','EXPENSE','RECEIPT'],true) && $link['document_id'] && $link['verification']!=='api_basis') {
                echo '<button type="button" class="button" data-link-target="pnpm-link-'.esc_attr($link['id']).'" data-link-action="refresh_link_preview">'.self::e('Update amounts').'</button> ';
            }
            echo '<button type="button" class="button" data-link-target="pnpm-link-'.esc_attr($link['id']).'" data-link-action="unlink">'.self::e('Correct / remove link').'</button></td></tr>';
        }
        echo '</tbody></table></div><p>'.self::e('A dash means no saved value. Open the link below and read it again to update older links.').'</p>';
    }

    private function packageTable(array $row): void
    {
        echo '<section class="pnpm-box"><h3>'.self::e('Saved package weights and dimensions').'</h3><p>'.self::e('Carrier data and our creation data are separate snapshots. Missing dimensions remain unknown; total weight is never divided between places.').'</p>';
        $sets=array_merge($row['carrier_packages']?[$row['carrier_packages']]:[],$row['site_packages']);
        if (!$sets) echo '<p>'.self::e('No saved package data yet. Our TTN creation retains the weight and dimensions of every entered box.').'</p>';
        foreach($sets as $set) {
            $places=$set['places']; unset($set['places']); $this->data($set);
            if (!$places) { echo '<p>'.self::e('The source did not provide dimensions for individual places.').'</p>'; continue; }
            echo '<div class="pnpm-table-scroll"><table class="widefat striped pnpm-evidence-table"><thead><tr>';
            foreach(['Place','Weight, kg','Length, cm','Width, cm','Height, cm','Volumetric weight, kg','Volume, m³'] as $label) echo '<th>'.self::e($label).'</th>';
            echo '</tr></thead><tbody>';
            foreach($places as $place) { echo '<tr>'; foreach($place as $value) echo '<td>'.esc_html($value===null?'—':(string)$value).'</td>'; echo '</tr>'; }
            echo '</tbody></table></div>';
        }
        echo '</section>';
    }

    private function data(array $data): void
    {
        echo '<dl class="pnpm-data">';
        foreach ($data as $key=>$value) {
            echo '<dt>'.esc_html(self::labels()[$key]??$key).'</dt><dd>';
            if (is_array($value)) echo '<pre>'.esc_html(wp_json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre>';
            else echo esc_html($value===null ? self::t('Unknown') : (is_bool($value) ? ($value ? self::t('Yes') : self::t('No')) : (self::labels()[(string)$value]??(string)$value)));
            echo '</dd>';
        }
        echo '</dl>';
    }

    private function form(string $command,array $row=[]): void
    {
        echo '<form class="pnpm-registry-form" data-command="'.esc_attr($command).'">';
        foreach (['id','revision'] as $key) if (isset($row[$key])) echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($row[$key]).'">';
    }
    private function input(string $name,string $label,string $type='text',string $value='',bool $required=false): void
    {
        echo '<label>'.self::e($label).'<input name="'.esc_attr($name).'" type="'.esc_attr($type).'" value="'.esc_attr($value).'" maxlength="1000"'.($required?' required':'').($name==='reason'?' minlength="10"':'').'></label>';
    }
    private function select(string $name,string $label,array $options,string $selected=''): void
    {
        echo '<label>'.self::e($label).'<select name="'.esc_attr($name).'">';
        foreach ($options as $value) echo '<option value="'.esc_attr($value).'"'.selected($selected,$value,false).'>'.esc_html(self::labels()[$value]??($value ?: self::t('All'))).'</option>';
        echo '</select></label>';
    }
    private function previewEnd(bool $history=false,string $saveLabel='Confirm and save',string $readLabel='Read and preview'): void
    {
        echo '<div class="pnpm-actions"><button class="button" type="submit">'.self::e($readLabel).'</button>';
        if ($history) echo '<button class="button pnpm-next" type="button" hidden>'.self::e('Read next page').'</button>';
        echo '</div><p class="pnpm-message" role="status" aria-live="polite"></p><div class="pnpm-preview"></div><fieldset class="pnpm-confirm" hidden><label><input type="checkbox" name="confirmed" value="1"> '.self::e('I checked this preview and confirm saving it to the site registry.').'</label><button type="button" class="button button-primary pnpm-apply" disabled>'.self::e($saveLabel).'</button></fieldset></form>';
    }
    private function saveEnd(string $label='Save classification'): void
    {
        echo '<div class="pnpm-actions"><button class="button" type="submit">'.self::e($label).'</button></div><p class="pnpm-message" role="status" aria-live="polite"></p></form>';
    }
}
