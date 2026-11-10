<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;
final class PartnerCatalogUi {
    private static function form(): void {echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('pcoe_partner_catalog');echo '<input type="hidden" name="action" value="pcoe_partner_catalog">';}
    private static function button(string $operation,string $label): void {echo '<button class="button" name="operation" value="'.esc_attr($operation).'">'.esc_html__($label,'pc-order-import-export').'</button> ';}
    public static function render(): void {
        $user=get_current_user_id();if(!PartnerCatalog::allowed($user)){echo '<p>'.esc_html__('Export access is unavailable.','pc-order-import-export').'</p>';return;}
        $c=PartnerCatalog::config($user);$s=$c['selection']??['skus'=>[],'category'=>0,'brand'=>0];
        echo '<div class="pcoe-partner-catalog"><h2>'.esc_html__('Data and photos for your website','pc-order-import-export').'</h2>';
        $notice=get_transient('pcoe_catalog_notice_'.$user);if($notice){delete_transient('pcoe_catalog_notice_'.$user);echo '<p role="status" class="woocommerce-info">'.esc_html($notice).'</p>';}
        echo '<p>'.esc_html__('Choose up to 500 products. CSV/XLSX contains your purchase price and retail price separately. Prom and WooCommerce files use retail prices only.','pc-order-import-export').'</p>';
        self::form();echo '<div class="pcoe-partner-fields">';
        foreach(['category'=>['product_cat','Category and subcategories'],'brand'=>['product_brand','Brand']] as $key=>[$tax,$label]){
            echo '<label>'.esc_html__($label,'pc-order-import-export').'<select name="'.$key.'"><option value="0">'.esc_html__('All','pc-order-import-export').'</option>';
            $terms=get_terms(['taxonomy'=>$tax,'hide_empty'=>true]);if(!is_wp_error($terms))foreach($terms as $t)echo '<option value="'.(int)$t->term_id.'" '.selected($s[$key],$t->term_id,false).'>'.esc_html($t->name).'</option>';
            echo '</select></label>';
        }
        echo '</div><label>'.esc_html__('SKUs, one per line','pc-order-import-export').'<textarea rows="6" name="skus">'.esc_textarea(implode("\n",$s['skus'])).'</textarea></label>';
        echo '<label>'.esc_html__('Or upload CSV/XLSX: SKUs in column A, optional SKU header; replaces the text list.','pc-order-import-export').'<input type="file" name="sku_file" accept=".csv,.xlsx"></label>';
        echo '<p>'.esc_html__('Filters are combined. Saving a selection pauses its Prom link until you review and enable it again.','pc-order-import-export').'</p>';
        self::button('save','Save and preview');echo '</form>';
        if(!empty($c['selection'])){
            try{$data=PartnerCatalog::data($user,$c);
                echo '<h3>'.esc_html__('Preview','pc-order-import-export').'</h3><p>'.esc_html(sprintf(__('Products: %d. Data prepared: %s (UTC). Refresh interval: 10 minutes.','pc-order-import-export'),count($data['rows']),$data['at'])).'</p>';
                foreach($data['warnings']??[] as $warning)echo '<p class="woocommerce-info">'.esc_html($warning).'</p>';
                if($data['missing'])echo '<p class="woocommerce-info">'.esc_html__('Not found or excluded by visibility and filters:','pc-order-import-export').' '.esc_html(implode(', ',$data['missing'])).'</p>';
                echo '<p>'.esc_html__('First 25 products are shown below. Downloads contain the whole saved selection.','pc-order-import-export').'</p><div class="pcoe-partner-table"><table><thead><tr>';
                foreach(['SKU','Name','Your price','Retail price','Stock','Photos'] as $h)echo '<th>'.esc_html__($h,'pc-order-import-export').'</th>';
                echo '</tr></thead><tbody>';foreach(array_slice($data['rows'],0,25) as $r){echo '<tr>';foreach([$r['sku'],$r['name'],$r['cost']??'—',$r['retail']??'—',$r['stock']??'—',count($r['images'])] as $v)echo '<td>'.esc_html((string)$v).'</td>';echo '</tr>';}
                echo '</tbody></table></div>';self::form();foreach(['xlsx'=>'Download Excel','csv'=>'Download CSV','woo'=>'CSV for WooCommerce','yml'=>'XML for Prom','zip'=>'Download photos (ZIP)'] as $op=>$label)self::button($op,$label);echo '</form>';
                echo '<p>'.esc_html__('ZIP supports up to 100 images. Check manifest.csv inside the archive: it lists every missing image. Full-size image links are also included in the table files.','pc-order-import-export').'</p>';
                echo '<h3>'.esc_html__('Automatic import into Prom','pc-order-import-export').'</h3><p>'.esc_html__('Prom reads this personal link according to the import schedule you configure in Prom. The link exports retail prices, never your purchase prices. It does not send orders or reserve goods.','pc-order-import-export').'</p>';
                if(!empty($c['enabled']))echo '<label>'.esc_html__('Personal XML link — share only with your import service','pc-order-import-export').'<textarea readonly rows="3">'.esc_textarea(PartnerCatalog::feedUrl($user,$c)).'</textarea></label>';
                else echo '<p>'.esc_html__('The Prom link is paused.','pc-order-import-export').'</p>';
                self::form();echo '<label><input type="checkbox" name="confirmed" value="1"> '.esc_html__('I checked the selection and retail prices for publication on my website.','pc-order-import-export').'</label><p>';self::button('enable','Enable Prom link');self::button('disable','Pause link');self::button('rotate','Replace link');echo '</p></form>';
                echo '<p>'.esc_html__('Replacing the link invalidates the old address. In Prom, first test a small import and match groups; for later updates select prices and availability so your edited descriptions remain unchanged.','pc-order-import-export').'</p>';
            }catch(\Throwable $e){echo '<p role="alert">'.esc_html__('Could not prepare the export. Please retry or contact the manager.','pc-order-import-export').'</p>';}
        }
        echo '</div>';
    }
}
