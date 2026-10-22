<?php
/** Safe recovery of an unsubmitted checkout order, shared by customer/manual/automatic paths. */
defined('ABSPATH') || exit;

function pc_folio_order_locked(int $id, callable $callback) {
    global $wpdb;
    static $held=[];
    if (isset($held[$id])) return $callback();
    $key='pc-folio:'.substr(hash('sha256',DB_NAME.$wpdb->prefix.':'.$id),0,45);
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!=='1')
        return ['ok'=>false,'message'=>__('Folio processing is already running. Refresh the order shortly.', 'pc-folio-order-link')];
    $held[$id]=true;
    try {return $callback();}
    finally {unset($held[$id]);$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
}

function pc_folio_fresh_order(int $id): ?WC_Order {
    $order=wc_get_order($id);
    if (!$order instanceof WC_Order) return null;
    $order->get_data_store()->read($order);
    $order->read_meta_data(true);
    return $order;
}

function pc_folio_create_documents_for_order(WC_Order $order,string $source='manual'): array {
    return pc_folio_order_locked($order->get_id(),static function()use($order,$source){
        $fresh=pc_folio_fresh_order($order->get_id());
        if (!$fresh || $fresh->get_meta('_folio_create_attempted_at'))
            return ['ok'=>false,'message'=>__('A Folio creation request was already attempted. A manager must reconcile its result before any new request.', 'pc-folio-order-link')];
        $result=pc_folio_create_documents_for_order_unlocked($fresh,$source);
        // Existing callers apply the response on their original object.
        $order->get_data_store()->read($order);$order->read_meta_data(true);
        return $result;
    });
}

function pc_folio_auto_process_checkout_order($order_or_id): void {
    $id=$order_or_id instanceof WC_Order?$order_or_id->get_id():absint($order_or_id);
    if (!$id) return;
    pc_folio_order_locked($id,static function()use($id){
        $order=pc_folio_fresh_order($id);
        if ($order) pc_folio_auto_process_checkout_order_unlocked($order, in_array(current_filter(), ['woocommerce_checkout_order_processed','woocommerce_store_api_checkout_order_processed'], true));
    });
}

/** BACS transitions after checkout; no status or paid flag is manufactured here. */
function pc_folio_resume_bacs_hold($id): void {
    $order=wc_get_order($id);
    if ($order && $order->get_payment_method()==='bacs' && $order->get_meta('_folio_auto_status')==='waiting_payment')
        pc_folio_auto_process_checkout_order($id);
}
add_action('woocommerce_order_status_on-hold','pc_folio_resume_bacs_hold',90);

function pc_folio_customer_can_resume(WC_Order $order,int $user): bool {
    return $user>0 && (int)$order->get_customer_id()===$user
        && pc_folio_auto_checkout_enabled() && !$order->get_parent_id()
        && !$order->get_meta('_folio_split_from_order_id')
        && in_array($order->get_status(),['on-hold','processing'],true)
        && $order->get_meta('_folio_auto_status')==='waiting_payment'
        && $order->get_meta('_folio_recovery_safe')==='1'
        && !$order->get_meta('_folio_create_attempted_at')
        && !pc_folio_order_has_saved_documents($order)
        && !pc_folio_order_should_wait_for_online_payment($order);
}

function pc_folio_customer_recovery_view($order): void {
    if (!$order instanceof WC_Order || !get_current_user_id() || (int)$order->get_customer_id()!==get_current_user_id()) return;
    if (pc_folio_order_has_saved_documents($order)) {
        echo '<p>'.esc_html__('Folio links show the saved creation result, not a live check of the current warehouse reserve. Non-accounting documents do not reserve stock.', 'pc-folio-order-link').'</p>';
        return;
    }
    $state=(string)$order->get_meta('_folio_auto_status');
    if (!in_array($state,['waiting_payment','running','error'],true)) return;
    $waiting=$state==='waiting_payment' && pc_folio_order_should_wait_for_online_payment($order);
    echo '<section class="pc-folio-customer-notice"><h2>'.esc_html__('Folio processing', 'pc-folio-order-link').'</h2><p>';
    echo esc_html($waiting?__('Waiting for payment confirmation. Folio document creation has not been confirmed.', 'pc-folio-order-link'):
        __('Folio document creation has not been confirmed. If no Continue button is shown, contact your manager; do not place a duplicate order.', 'pc-folio-order-link'));
    echo '</p>';
    if (pc_folio_customer_can_resume($order,get_current_user_id())) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="pc_folio_customer_resume"><input type="hidden" name="order_id" value="'.(int)$order->get_id().'">';
        wp_nonce_field('pc_folio_resume_'.$order->get_id());
        echo '<p>'.esc_html__('Continue submits this order to Folio using current stock availability. Payment is not recorded by this action.', 'pc-folio-order-link').'</p><button type="submit" class="button">'.esc_html__('Continue Folio processing', 'pc-folio-order-link').'</button></form>';
    }
    echo '</section>';
}
add_action('woocommerce_order_details_before_order_table','pc_folio_customer_recovery_view',6);

function pc_folio_customer_resume_post(): void {
    $id=absint($_POST['order_id']??0);$user=get_current_user_id();
    if (!$user || ($_SERVER['REQUEST_METHOD']??'')!=='POST') wp_die('Forbidden', '', ['response'=>403]);
    check_admin_referer('pc_folio_resume_'.$id);
    $order=wc_get_order($id);
    if (!$order || (int)$order->get_customer_id()!==$user) wp_die('Forbidden', '', ['response'=>403]);
    pc_folio_order_locked($id,static function()use($id,$user){
        $fresh=pc_folio_fresh_order($id);
        if ($fresh && pc_folio_customer_can_resume($fresh,$user)) pc_folio_auto_process_checkout_order($id);
    });
    wp_safe_redirect($order->get_view_order_url());exit;
}
add_action('admin_post_pc_folio_customer_resume','pc_folio_customer_resume_post');
