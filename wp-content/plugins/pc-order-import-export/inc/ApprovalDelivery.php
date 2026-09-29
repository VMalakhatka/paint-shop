<?php
namespace PaintCore\PCOE;
use Paint\NovaPoshta\Checkout\CheckoutIntegration;
use Paint\NovaPoshta\Infrastructure\ApprovalQuoteService;
defined('ABSPATH') || exit;

/** Owner-scoped, short-lived estimates; no checkout, payment or shipment writes. */
final class ApprovalDelivery {
    public static function selected(string $method): bool { return str_starts_with($method, 'pnpm_nova_poshta:'); }
    private static function service(): ApprovalQuoteService {
        if (!class_exists(ApprovalQuoteService::class)) throw new \RuntimeException(__('Nova Poshta calculation is unavailable. Contact the manager.', 'pc-order-import-export'));
        return new ApprovalQuoteService();
    }
    public static function assets(): void {
        if (class_exists(CheckoutIntegration::class)) CheckoutIntegration::create()->enqueueAssets(true);
        wp_enqueue_script('pcoe-approval-delivery', PCOE_URL.'assets/approval-delivery.js', ['jquery','pcoe-approval'], filemtime(PCOE_DIR.'/assets/approval-delivery.js'), true);
        wp_localize_script('pcoe-approval-delivery','pcoeApprovalDelivery',[
            'ajaxUrl'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('pcoe_approval_quote'),
            'waiting'=>__('Calculate delivery after choosing the destination.', 'pc-order-import-export'),
            'busy'=>__('Calculating delivery…', 'pc-order-import-export'),
            'failed'=>__('Delivery could not be calculated. Check the destination and try again.', 'pc-order-import-export'),
            'expired'=>__('The delivery estimate expired. Calculate it again before confirming.', 'pc-order-import-export'),
            'cod'=>__('Cash on delivery is unavailable for this delivery. Choose another payment preference.', 'pc-order-import-export'),
        ]);
    }
    public static function render(): void {
        if (class_exists(CheckoutIntegration::class)) CheckoutIntegration::create()->renderDestination([],true);
        echo '<div data-approval-delivery hidden><p>'.esc_html__('Delivery is an estimate for this order, shown separately from its total. The manager will check packaging and final shipment conditions.', 'pc-order-import-export').'</p>';
        echo '<button type="button" class="button" data-quote-button>'.esc_html__('Calculate delivery', 'pc-order-import-export').'</button>';
        echo '<div data-quote-result role="status" aria-live="polite"></div><input type="hidden" name="delivery_quote_token" value=""></div>';
    }
    private static function owner(int $id, string $revision): array {
        $data=ApprovalNotifications::data($id);
        if (!get_current_user_id() || (int)$data['source']['customer_id']!==get_current_user_id() || $data['status']!=='pending') throw new \RuntimeException(__('The order is unavailable for confirmation.', 'pc-order-import-export'));
        $snapshot=CustomerApproval::snapshot($data['source']);
        if (!hash_equals($data['revision'],$revision) || !hash_equals($revision,CustomerApproval::revision($snapshot))) throw new \RuntimeException(__('The order changed. Review it again before continuing.', 'pc-order-import-export'));
        return [$data,$snapshot];
    }
    private static function context(array $data, array $snapshot): array {
        $order=!empty($data['source']['order_id'])?wc_get_order($data['source']['order_id']):null;
        return self::service()->context($order ?: null,$snapshot);
    }
    private static function key(string $token): string { return 'pcoe_np_quote_'.hash('sha256',$token); }
    private static function money($amount): string { return html_entity_decode(wp_strip_all_tags(wc_price((float)$amount,['currency'=>'UAH'])),ENT_QUOTES,'UTF-8'); }
    public static function summary(array $quote): string {
        return sprintf(__('Estimated delivery for you: %1$s. Carrier total: %2$s. Store contribution: %3$s. Parcels: %4$d.', 'pc-order-import-export'),
            self::money($quote['policy']['customer_total']),self::money($quote['policy']['total']),self::money($quote['policy']['store_total']),$quote['policy']['shipment_count']);
    }
    public static function quote(int $id, string $revision, array $input): array {
        [$data,$snapshot]=self::owner($id,$revision);
        $method=(string)($input['delivery']??'');
        if (!self::selected($method) || !isset(CustomerApproval::choices()['delivery'][$method])) throw new \RuntimeException(__('Select delivery and payment from the available options.', 'pc-order-import-export'));
        $plan=self::context($data,$snapshot);
        $quote=self::service()->quote($plan,$input);
        $token=wp_generate_password(40,false,false);$expires=time()+10*MINUTE_IN_SECONDS;
        $receipt=['owner'=>get_current_user_id(),'approval'=>$id,'revision'=>$revision,'method'=>$method,'expires'=>$expires,'quote'=>$quote];
        if (!set_transient(self::key($token),$receipt,10*MINUTE_IN_SECONDS)) throw new \RuntimeException(__('Delivery could not be calculated. Check the destination and try again.', 'pc-order-import-export'));
        $parcels=[];
        foreach($quote['quotes'] as $row)$parcels[]=sprintf(__('%1$s: %2$s', 'pc-order-import-export'),$row['location_label'],self::money($row['carrier_cost']+$row['parcel_locker_surcharge']));
        return ['token'=>$token,'expires'=>$expires,'summary'=>self::summary($quote),'parcels'=>$parcels,'recipient'=>$quote['recipient'],
            'warnings'=>$quote['warnings'],'cod_allowed'=>$quote['policy']['cod_allowed']];
    }
    public static function ajax(): void {
        check_ajax_referer('pcoe_approval_quote','nonce');
        try {wp_send_json_success(self::quote(absint($_POST['approval_id']??0),(string)($_POST['revision']??''),wp_unslash($_POST)));}
        catch(\Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],409);}
    }
    /** Recheck destination and parcel/policy inputs, without another carrier call. */
    public static function validate(int $id, array $data, array $snapshot, array $input): array {
        $token=(string)($input['delivery_quote_token']??'');
        $receipt=preg_match('/^[A-Za-z0-9]{40}$/',$token)?get_transient(self::key($token)):false;
        if (!is_array($receipt) || $receipt['owner']!==get_current_user_id() || $receipt['approval']!==$id
            || $receipt['revision']!==$data['revision'] || $receipt['method']!==($input['delivery']??'') || $receipt['expires']<=time()) self::expired();
        $quote=$receipt['quote'];$recipient=$quote['recipient'];
        foreach(['city_ref','delivery_type','point_ref'] as $key) if ((string)($input['pnpm_'.$key]??'')!==$recipient[$key]) self::expired();
        if ($recipient['delivery_type']==='address' && trim(sanitize_text_field((string)($input['pnpm_address']??'')))!==$recipient['address']) self::expired();
        if (self::context($data,$snapshot)['fingerprint']!==$quote['fingerprint']) self::expired();
        if (($input['payment']??'')==='cod' && !$quote['policy']['cod_allowed']) throw new \RuntimeException(__('Cash on delivery is unavailable for this delivery. Choose another payment preference.', 'pc-order-import-export'));
        return $quote;
    }
    public static function destination(array $quote): string {
        $r=$quote['recipient'];return $r['city_label'].', '.($r['delivery_type']==='address'?$r['address']:$r['point_label']);
    }
    private static function expired(): never { throw new \RuntimeException(__('The delivery estimate expired or changed. Calculate it again before confirming.', 'pc-order-import-export')); }
}
