<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;
final class BroadcastUi {
    public static function url(int $id=0): string { return add_query_arg(['view'=>'mailings','mailing'=>$id],ManagerWorkspace::url(0)); }
    public static function label(string $key): string {
        return ['preparing'=>__('Preparing files','pc-order-import-export'),'ready'=>__('Ready for review','pc-order-import-export'),
            'running'=>__('Sending','pc-order-import-export'),'paused'=>__('Paused','pc-order-import-export'),
            'finished'=>__('Finished','pc-order-import-export'),'cancelled'=>__('Cancelled','pc-order-import-export'),
            'error'=>__('Preparation failed','pc-order-import-export'),'pending'=>__('Waiting','pc-order-import-export'),
            'sending'=>__('Sending; result not yet recorded','pc-order-import-export'),'accepted'=>__('Accepted by the mail service','pc-order-import-export'),
            'failed'=>__('Email sending failed','pc-order-import-export'),'unknown'=>__('Uncertain result; check the mail log','pc-order-import-export'),
            'skipped'=>__('Skipped: address, subscription or pricing conditions changed','pc-order-import-export')][$key]??$key;
    }
    public static function hidden(string $key,$value): void { echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">'; }
    public static function action(int $id,string $operation,string $label,bool $confirm=false): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:10px 0">';wp_nonce_field('pcoe_broadcast');
        foreach(['action'=>'pcoe_broadcast','operation'=>$operation,'mailing'=>$id] as $k=>$v)self::hidden($k,$v);
        if($confirm)echo '<label><input type="checkbox" name="confirm" value="1" required> '.esc_html__('I checked the message, recipients and price files and want to send these emails.','pc-order-import-export').'</label><br>';
        echo '<button class="button '.($confirm?'button-primary':'').'">'.esc_html($label).'</button></form>';
    }
    public static function post(): void {
        if(!current_user_can('manage_woocommerce'))wp_die('Forbidden','',['response'=>403]);
        check_admin_referer('pcoe_broadcast');
        try {
            $input=wp_unslash($_POST);$operation=(string)($input['operation']??'');$id=absint($input['mailing']??0);
            if($operation==='create')$id=Broadcasts::create($input);
            elseif($operation==='prepare'){
                if(BroadcastStore::get($id)['status']!=='preparing')throw new \RuntimeException('Invalid state');
                Broadcasts::tick($id);
            }else{
                if(in_array($operation,['start','resume','retry'],true) && ($input['confirm']??'')!=='1')throw new \RuntimeException(__('Confirm sending after reviewing the mailing.','pc-order-import-export'));
                Broadcasts::control($id,$operation);
            }
            wp_safe_redirect(self::url($id));exit;
        }catch(\Throwable $e){wp_die(esc_html($e->getMessage()),'',['response'=>409,'back_link'=>true]);}
    }
    public static function receipts(): void {
        if(!current_user_can('manage_woocommerce'))wp_send_json_error(['message'=>'Forbidden'],403);
        check_ajax_referer('pcoe_broadcast','nonce');
        try {
            $kind=sanitize_key($_POST['kind']??'');
            if($kind==='warehouses')$data=BroadcastSources::request('/warehouses');
            elseif($kind==='documents')$data=BroadcastSources::request('',[
                'warehouseId'=>absint($_POST['warehouse']??0),'date'=>sanitize_text_field(wp_unslash($_POST['date']??'')),'afterId'=>absint($_POST['after']??0)]);
            else throw new \RuntimeException('Invalid request');
            wp_send_json_success($data);
        }catch(\Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],409);}
    }
    public static function download(): void {
        if(!current_user_can('manage_woocommerce'))wp_die('Forbidden','',['response'=>403]);
        $id=absint($_GET['mailing']??0);check_admin_referer('pcoe_broadcast_file_'.$id);
        try{
            $d=BroadcastStore::get($id);$group=(string)($_GET['group']??'');
            if(empty($d['groups'][$group]['ready']))throw new \RuntimeException('Invalid group');
            $bytes=BroadcastStore::bytes(BroadcastStore::file($id,$group));
            nocache_headers();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="price-preview-'.$id.'.xlsx"');echo $bytes;exit;
        }catch(\Throwable $e){wp_die(esc_html($e->getMessage()),'',['response'=>409]);}
    }
    public static function render(): void {
        if(!current_user_can('manage_woocommerce'))return;
        echo '<h2>'.esc_html__('Customer mailings','pc-order-import-export').'</h2>';ManagerHelp::link('mailings');
        try{require PCOE_DIR.'/inc/broadcast-view.php';}catch(\Throwable $e){echo '<p role="alert">'.esc_html($e->getMessage()).'</p>';}
    }
}
