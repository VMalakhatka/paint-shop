<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Read the same Woo customer/order addresses used at checkout, without changing them. */
final class ApprovalContacts {
    public static function assets(): void {
        if (!is_user_logged_in() || !function_exists('is_account_page') || !is_account_page() || !isset($_GET['pcoe_approval'])) return;
        wp_enqueue_script('pcoe-approval',PCOE_URL.'assets/approval.js',[],filemtime(PCOE_DIR.'/assets/approval.js'),true);
        wp_enqueue_style('pcoe-approval',PCOE_URL.'assets/approval.css',[],filemtime(PCOE_DIR.'/assets/approval.css'));
        ApprovalDelivery::assets();
    }

    private static function contact($record,string $type): array {
        $get=static function($field)use($record,$type){$method='get_'.$type.'_'.$field;return is_callable([$record,$method])?(string)$record->$method():'';};
        $name=trim($get('first_name').' '.$get('last_name')) ?: trim($record->get_billing_first_name().' '.$record->get_billing_last_name());
        $phone=$get('phone') ?: (string)$record->get_billing_phone();
        $address=array_filter(array_map('trim',[$get('city'),$get('address_1'),$get('address_2'),$get('postcode')]));
        return ['recipient'=>$name,'phone'=>$phone,'destination'=>implode(', ',$address),'payment'=>'','delivery'=>''];
    }

    public static function options(array $source): array {
        $owner=(int)$source['customer_id'];
        if (!$owner || get_current_user_id()!==$owner) return [];
        $rows=[];$seen=[];
        $add=static function(string $label,array $value)use(&$rows,&$seen){
            foreach($value as &$text)$text=mb_substr(sanitize_textarea_field($text),0,500);unset($text);
            if (!$value['recipient'] && !$value['phone'] && !$value['destination']) return;
            $key=hash('sha256',wp_json_encode($value));if(isset($seen[$key]))return;$seen[$key]=true;
            $rows[]=['label'=>$label,'values'=>$value];
        };
        $order=!empty($source['order_id'])?wc_get_order($source['order_id']):false;
        $from_order=static function($order)use($owner,$add){
            if (!$order instanceof \WC_Order || (int)$order->get_customer_id()!==$owner) return;
            $v=self::contact($order,'shipping');if(!$v['destination'])$v=self::contact($order,'billing');
            $np=$order->get_meta('_pnpm_recipient');
            if(is_array($np) && !empty($np['point_label']) && in_array($np['delivery_type']??'', ['branch','parcel_locker'],true))
                $v['destination']=implode(', ',array_filter([(string)($np['city_label']??''),(string)$np['point_label']]));
            $v['payment']=$order->get_payment_method();
            foreach($order->get_shipping_methods() as $method){$v['delivery']=$method->get_method_id().':'.$method->get_instance_id();break;}
            $add(sprintf(__('Details from order #%s','pc-order-import-export'),$order->get_order_number()),$v);
        };
        if($order)$from_order($order);
        $customer=new \WC_Customer($owner);
        $shipping=self::contact($customer,'shipping');
        if($shipping['destination'])$add(__('Saved shipping address','pc-order-import-export'),$shipping);
        $add(__('Saved billing/contact details','pc-order-import-export'),self::contact($customer,'billing'));
        foreach(wc_get_orders(['customer_id'=>$owner,'status'=>['processing','completed','on-hold'],'limit'=>5,'orderby'=>'date','order'=>'DESC']) as $previous)$from_order($previous);
        return $rows;
    }

    public static function render(array $source,array $choices): void {
        $rows=self::options($source);$defaults=$rows[0]['values']??[];
        if($rows){
            echo '<p class="form-row form-row-wide"><label for="pcoe-saved-contact">'.esc_html__('Use saved customer details','pc-order-import-export').'</label><select id="pcoe-saved-contact" data-saved-contact>';
            foreach($rows as $i=>$row)echo '<option value="'.esc_attr($i).'" data-contact="'.esc_attr(wp_json_encode($row['values'])).'">'.esc_html($row['label']).'</option>';
            echo '<option value="">'.esc_html__('Enter other details','pc-order-import-export').'</option></select></p>';
        }
        echo '<p class="pcoe-approval-hint">'.esc_html__('Check the saved details or edit them for this order. Changes here do not overwrite your address book.','pc-order-import-export').'</p>';
        foreach($choices as $key=>$options)woocommerce_form_field($key,['type'=>'select','required'=>true,'class'=>['form-row-wide'],
            'label'=>CustomerApproval::field_label($key),'custom_attributes'=>['required'=>'required'],'options'=>[''=>'—']+$options],isset($options[$defaults[$key]??''])?$defaults[$key]:'');
        foreach(['recipient'=>['text','shipping name'],'phone'=>['tel','shipping tel'],'destination'=>['textarea','shipping street-address']] as $key=>[$type,$autocomplete])
            woocommerce_form_field($key,['type'=>$type,'required'=>true,'class'=>['form-row-wide'],'label'=>CustomerApproval::field_label($key),
                'autocomplete'=>$autocomplete,'custom_attributes'=>['maxlength'=>'500','required'=>'required']],$defaults[$key]??'');
    }
}
