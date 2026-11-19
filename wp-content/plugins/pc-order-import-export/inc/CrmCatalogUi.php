<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

final class CrmCatalogUi {
    private static function form(): void {echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('pcoe_crm_catalog');echo '<input type="hidden" name="action" value="pcoe_crm_catalog">';}
    private static function button(string $op,string $label): void {echo '<button class="button" name="operation" value="'.esc_attr($op).'">'.esc_html__($label,'pc-order-import-export').'</button> ';}
    public static function render(): void {
        $user=get_current_user_id();if(!PartnerCatalog::allowed($user))return;
        $c=CrmCatalog::config($user);$s=$c['selection']??['brands'=>[],'locations'=>[]];$b=CrmCatalogStore::get($user,'build');$snapshot=CrmCatalogStore::get($user,'snapshot');
        echo '<section id="pcoe-crm-catalog"><h2>'.esc_html__('Purchase price feed for CRM / SalesDrive','pc-order-import-export').'</h2>';
        $help=function_exists('pc_wholesale_help_url')?pc_wholesale_help_url('crm-catalog'):wc_get_account_endpoint_url('yak-zamovyty').'#crm-catalog';
        echo '<p><a href="'.esc_url($help).'" target="_blank" rel="noopener">? '.esc_html__('How it works','pc-order-import-export').'</a></p>';
        echo '<p>'.esc_html__('A separate private link contains your purchase prices and the selected warehouse groups. The retail Prom link above is unchanged. Anyone with the CRM link can read your purchase prices; share it only with your CRM.','pc-order-import-export').'</p>';
        $notice=get_transient('pcoe_crm_notice_'.$user);if($notice){delete_transient('pcoe_crm_notice_'.$user);echo '<p role="alert">'.esc_html($notice).'</p>';}
        self::form();
        echo '<fieldset><legend>'.esc_html__('Brands for the CRM feed','pc-order-import-export').'</legend><div class="pcoe-crm-choices">';
        $brands=get_terms(['taxonomy'=>'product_brand','hide_empty'=>true,'orderby'=>'name']);
        if(!is_wp_error($brands))foreach($brands as $t)echo '<label><input type="checkbox" name="brands[]" value="'.(int)$t->term_id.'" '.checked(in_array((int)$t->term_id,$s['brands'],true),true,false).'> '.esc_html($t->name).'</label>';
        echo '</div></fieldset><fieldset><legend>'.esc_html__('Stock from warehouse groups','pc-order-import-export').'</legend>';
        foreach(CrmCatalogData::locations() as $id=>$loc)echo '<label><input type="checkbox" name="locations[]" value="'.(int)$id.'" '.checked(in_array($id,$s['locations'],true),true,false).'> '.esc_html($loc['name']).'</label>';
        echo '<p>'.esc_html__('Warehouse group composition comes from the shared site settings and is the same for every customer. Choose which groups this feed includes. Stock uses website data.','pc-order-import-export').'</p></fieldset>';
        self::button('save','Prepare CRM file');echo '<p>'.esc_html__('Saving pauses the CRM link. Files are prepared in the background, up to 30,000 records and 30 MB. Wait for completion and review the file before enabling the link.','pc-order-import-export').'</p></form>';
        if($b){
            $label=($b['status']??'')==='failed'?'CRM preparation failed':'Preparing the CRM file';
            echo '<p role="status"><strong>'.esc_html__($label,'pc-order-import-export').'</strong> · '.esc_html(sprintf(__('Products prepared: %d','pc-order-import-export'),$b['count']??0)).'</p>';
            if(!empty($b['error']))echo '<p role="alert">'.esc_html__($b['error'],'pc-order-import-export').'</p>';
        }
        $ready=false;try{$ready=$snapshot && CrmCatalog::ready($user,$c,$snapshot);}catch(\Throwable $e){}
        if($ready){
            echo '<h3>'.esc_html__('CRM file ready for review','pc-order-import-export').'</h3><p>'.esc_html(sprintf(__('Products: %d. Prepared: %s (UTC).','pc-order-import-export'),$snapshot['count'],gmdate('Y-m-d H:i',$snapshot['at']))).'</p>';
            if (!empty($snapshot['unpriced'])) {
                echo '<p>'.esc_html(sprintf(__('Products included without a purchase price: %d.','pc-order-import-export'), $snapshot['unpriced'])).'</p>';
            }
            echo '<div class="pcoe-partner-table"><table><thead><tr>';foreach(['SKU','Name','Your price','Stock'] as $h)echo '<th>'.esc_html__($h,'pc-order-import-export').'</th>';echo '</tr></thead><tbody>';
            foreach ($snapshot['preview'] as $row) {
                $row['cost'] = PartnerCatalogFormats::priceText($row['cost'] ?? null);
                echo '<tr>';
                foreach (['sku', 'name', 'cost', 'stock'] as $key) {
                    echo '<td>'.esc_html((string)($row[$key] ?? '—')).'</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div>';self::form();self::button('download','Download CRM XML for review');echo '</form>';
        }elseif($snapshot)echo '<p>'.esc_html__('The CRM file is outdated or its settings changed. Prepare it again before importing.','pc-order-import-export').'</p>';
        if(!empty($c['selection'])){
            echo '<p><a href="'.esc_url(PartnerCatalog::url().'#pcoe-crm-catalog').'">'.esc_html__('Refresh CRM status','pc-order-import-export').'</a></p>';
            self::form();self::button('retry','Prepare CRM file again');echo '</form>';
            echo '<p>'.esc_html__('Map purchase_price (or price) to purchase cost, not your selling price. Use vendorCode for SKU, barcode for EAN and quantity_in_stock for quantity. First test a few products in SalesDrive.','pc-order-import-export').'</p>';
            if(!empty($c['enabled']))echo '<label>'.esc_html__('Private CRM URL — contains purchase prices','pc-order-import-export').'<textarea readonly rows="3">'.esc_textarea(CrmCatalog::url($user,$c)).'</textarea></label>';
            else echo '<p>'.esc_html__('The CRM link is paused.','pc-order-import-export').'</p>';
            self::form();echo '<label><input type="checkbox" name="confirmed" value="1"> '.esc_html__('I reviewed the file and authorize my CRM to read my purchase prices.','pc-order-import-export').'</label><p>';
            if($ready)self::button('enable','Enable CRM link');self::button('disable','Pause CRM link');self::button('rotate','Replace CRM link');echo '</p></form>';
            echo '<p>'.esc_html__('The URL stays the same when data refreshes. Replacement invalidates the old URL. Enabled files refresh about every 30 minutes when the scheduler runs; files older than two hours are not served. Configure the read schedule in your CRM.','pc-order-import-export').'</p>';
        }
        echo '</section>';
    }
}
