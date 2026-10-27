<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;
$form_fields=static function(string $operation)use($order):void {
    foreach (['operation'=>$operation,'customer_id'=>$order->get_customer_id(),'order_id'=>$order->get_id(),'revision'=>ManagerWorkspace::revision($order)] as $k=>$v)
        echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
};
?>
<details class="pcoe-non-accounting"><summary><?php esc_html_e('Separate action: save the entire list without reservation','pc-order-import-export'); ?></summary>
<p><?php esc_html_e('This action saves the entire list in one non-accounting document on the configured warehouse. It does not check stock, split by availability or create a reserved Woo order.','pc-order-import-export'); ?></p>
<p><?php echo esc_html(sprintf(__('Non-accounting Folio warehouse: %s','pc-order-import-export'),pc_folio_warehouse_label(DraftFolioWorkflow::default_warehouse_id()))); ?></p>
<form data-pcoe-manager data-preview-form><?php $form_fields('preview'); ?><input type="hidden" name="mode" value="non_accounting"><input type="hidden" name="warehouse_mode" value="auto"><input type="hidden" name="warehouse_id" value="0">
<button class="button"><?php esc_html_e('Preview only a non-accounting document','pc-order-import-export'); ?></button></form></details>
<div data-preview-result hidden></div>
<form data-pcoe-manager data-apply-form hidden><?php $form_fields('apply'); ?><input type="hidden" name="token" value=""><input type="hidden" name="mode" value=""><label><input type="checkbox" name="confirmation" value="1" required> <span data-confirmation-label></span></label><button class="button button-primary" data-apply-button><?php esc_html_e('Confirm the reviewed action','pc-order-import-export'); ?></button></form>
