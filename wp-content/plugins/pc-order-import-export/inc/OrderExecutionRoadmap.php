<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Manual execution roadmap. It never executes the business action described by a stage. */
final class OrderExecutionRoadmap {
    public static function stages(): array {
        return [
            'intake'=>['optional'=>false,'label'=>__('Accept request and assign a manager','pc-order-import-export'),'scenario'=>__('Check the customer, source list and responsible manager. Complete when the request and agreed next deadline are recorded.','pc-order-import-export')],
            'wait_stock'=>['optional'=>true,'label'=>__('Wait for receipt or stock transfer','pc-order-import-export'),'scenario'=>__('Record the expected goods, warehouse and date. Complete after checking their actual receipt; an expected arrival is not available stock.','pc-order-import-export')],
            'prepare_orders'=>['optional'=>false,'label'=>__('Prepare Woo orders and Folio accounts','pc-order-import-export'),'scenario'=>__('Check stock and create linked orders using the preparation block. Complete only after verifying the saved accounts, warehouse allocation and shortage for all parts of the request.','pc-order-import-export')],
            'approval'=>['optional'=>false,'label'=>__('Confirm contents and delivery with the customer','pc-order-import-export'),'scenario'=>__('Verify the current confirmed version, recipient, delivery and payment choice. Existing checkout confirmation may be used if the contents have not changed; otherwise request a new confirmation.','pc-order-import-export')],
            'picking'=>['optional'=>false,'label'=>__('Pick the goods','pc-order-import-export'),'scenario'=>__('Check the warehouse picking record against every ordered line. Complete when picked quantities and all missing or damaged items are recorded.','pc-order-import-export')],
            'packing'=>['optional'=>false,'label'=>__('Pack and check the goods','pc-order-import-export'),'scenario'=>__('Check the actual packed contents, package count and responsible packer. Complete after the packing record has been checked; a Folio account alone is not packing evidence.','pc-order-import-export')],
            'box_passports'=>['optional'=>true,'label'=>__('Prepare box passports','pc-order-import-export'),'scenario'=>__('Number the boxes and record the contents of each. Complete when every required passport matches the packing record; refer to the paper passport if it is kept on paper.','pc-order-import-export')],
            'shipment_documents'=>['optional'=>false,'label'=>__('Check actual shipment documents','pc-order-import-export'),'scenario'=>__('Check the expense documents in Folio against the packed quantities and link them to this request. Complete when quantities, amounts and document references have been reconciled.','pc-order-import-export')],
            'shortage'=>['optional'=>true,'label'=>__('Agree shortages and remaining orders','pc-order-import-export'),'scenario'=>__('Agree waiting, replacement, cancellation or a later shipment for every shortage. Complete when the customer decision and the linked remaining account or draft are recorded.','pc-order-import-export')],
            'payment'=>['optional'=>false,'label'=>__('Check payment and agreed payment terms','pc-order-import-export'),'scenario'=>__('Reconcile the packed amount, advance and Folio payments. Request only the agreed remaining amount when needed. Complete when payment or an authorized deferral is verified and its evidence is recorded.','pc-order-import-export')],
            'shipment'=>['optional'=>true,'label'=>__('Prepare carrier shipment and TTN','pc-order-import-export'),'scenario'=>__('Verify the customer delivery data and package details, then prepare the shipment in the delivery block. Complete when the actual TTN and package data are saved. Skip with a reason for pickup or delivery without a TTN.','pc-order-import-export')],
            'dispatch'=>['optional'=>false,'label'=>__('Hand over the goods and notify the customer','pc-order-import-export'),'scenario'=>__('Verify physical handover to the carrier or pickup recipient and notify the customer using the agreed channel. Complete when handover and notification are evidenced; creating a TTN is not handover.','pc-order-import-export')],
            'receipt'=>['optional'=>false,'label'=>__('Verify delivery or pickup receipt','pc-order-import-export'),'scenario'=>__('Check carrier tracking or obtain receipt confirmation from the customer. Record the source and date. This confirms receipt of the shipment, not acceptance of its contents.','pc-order-import-export')],
            'acceptance'=>['optional'=>false,'label'=>__('Confirm customer acceptance','pc-order-import-export'),'scenario'=>__('Obtain the customer acceptance result or receiving report. Complete after recording the result; open the claim stage for shortages, damage or disputed contents.','pc-order-import-export')],
            'claim'=>['optional'=>true,'label'=>__('Review a customer claim','pc-order-import-export'),'scenario'=>__('Record disputed lines, quantities and evidence; agree the resolution and responsible person. Complete only after the agreed resolution, using return or correction stages when needed.','pc-order-import-export')],
            'return'=>['optional'=>true,'label'=>__('Arrange and verify a return','pc-order-import-export'),'scenario'=>__('Agree return quantities and conditions, verify actual receipt and the Folio return documents. Complete when goods and documents are reconciled; this mark does not issue a refund.','pc-order-import-export')],
            'adjustment'=>['optional'=>true,'label'=>__('Reconcile corrections, balance or a later shipment','pc-order-import-export'),'scenario'=>__('Verify corrected Folio documents, the customer balance and any linked additional shipment. Complete when the agreed correction is actually applied and evidenced.','pc-order-import-export')],
            'signatures'=>['optional'=>true,'label'=>__('Collect signed delivery documents','pc-order-import-export'),'scenario'=>__('Check which signatures are required for this customer and collect the signed documents. Record whether the evidence is paper, a scan or an electronic signature; these are different forms of evidence.','pc-order-import-export')],
            'close'=>['optional'=>false,'label'=>__('Close execution control','pc-order-import-export'),'scenario'=>__('Review all linked orders, required stages and opened exception branches. Close only after their completion. Closing this roadmap does not change Woo statuses, accounting documents or manager bonuses.','pc-order-import-export')],
        ];
    }
    public static function label(string $id): string { return self::stages()[$id]['label']??''; }
    public static function status_label(string $status): string {
        return ['in_progress'=>__('In progress','pc-order-import-export'),'completed'=>__('Completed by manager','pc-order-import-export'),
            'skipped'=>__('Skipped with a reason','pc-order-import-export'),'pending'=>__('Not completed','pc-order-import-export'),
            'optional'=>__('If needed','pc-order-import-export')][$status]??__('Requires review','pc-order-import-export');
    }
    public static function next_label(array $state): string {
        return !empty($state['roadmap']['current']) ? self::label($state['roadmap']['current']) : (string)($state['next']??'');
    }
    /** Planning only. A plan is not evidence that the business operation happened. */
    public static function apply(array &$state,array $input,int $manager,string $due,int $actor,string $reason): array {
        $stages=self::stages(); $id=(string)($input['stage']??'');$status=(string)($input['stage_status']??'in_progress');
        $detail=sanitize_textarea_field($input['stage_detail']??'');
        if (!isset($stages[$id]) || mb_strlen($detail)>500) OrderExecution::fail();
        if ($status!=='in_progress') throw new \RuntimeException(__('Planning cannot complete an operation. Record its actual result in the operation form.','pc-order-import-export'));
        $map=$state['roadmap']??['version'=>1,'current'=>'','stages'=>[]];
        if (($map['version']??0)!==1) OrderExecution::fail();
        $before=$map['stages'][$id]??[];
        $legacy=OrderExecutionOperations::legacy_plan($state);
        if ($legacy) { $state['legacy_plan']=$legacy;$state['legacy_next']=$legacy['detail']; }
        $time=gmdate('c');
        $record=['status'=>'in_progress','detail'=>$detail,'reason'=>$reason,'actor'=>$actor,'at'=>$time,'manager'=>$manager,
            'due'=>$due,'original_due'=>!empty($before['original_due'])?$before['original_due']:$due,
            'plan'=>['detail'=>$detail,'manager'=>$manager,'due'=>$due,'at'=>$time,'actor'=>$actor]];
        if (!empty($before['performed_at'])) $record['performed_at']=$before['performed_at'];
        $map['stages'][$id]=$record;
        if (!empty($map['closed_at'])) { unset($map['closed_at']); if($id!=='close') unset($map['stages']['close']); }
        $map['current']=$id;
        $state['roadmap']=$map;
        $state['next']=self::label($id);
        return ['stage'=>$id,'before'=>$before,'after'=>$record,'next_stage'=>$id];
    }
    /** Actual result, called only after authorization and source selection under the shared lock. */
    public static function record(array &$state,string $id,string $status,string $result,string $performed,int $actor,?array $plan): void {
        $stages=self::stages();
        if (!isset($stages[$id]) || !in_array($status,['in_progress','completed','skipped'],true)) OrderExecution::fail();
        if ($status==='skipped' && !$stages[$id]['optional']) throw new \RuntimeException(__('A required stage cannot be skipped. Record its completion or keep it in progress.','pc-order-import-export'));
        $map=$state['roadmap']??['version'=>1,'current'=>'','stages'=>[]];
        if (($map['version']??0)!==1) OrderExecution::fail();
        $before=$map['stages'][$id]??[];
        if (!empty($before['performed_at']) && strtotime($performed)<strtotime($before['performed_at']))
            throw new \RuntimeException(__('The operation time is earlier than the last recorded result of this stage. Check the history before recording a correction.','pc-order-import-export'));
        if ($id==='close' && $status==='completed') {
            if (OrderExecutionOperations::legacy_plan($state))
                throw new \RuntimeException(__('Record the result of the outstanding legacy plan before closing execution.','pc-order-import-export'));
            foreach ($stages as $key=>$stage) {
                if ($key==='close') continue;
                $record=$map['stages'][$key]??[];$s=$record['status']??'pending';
                if ((!$stage['optional'] && $s!=='completed') || ($stage['optional'] && $record && !in_array($s,['completed','skipped'],true)))
                    throw new \RuntimeException(__('Complete all required stages and resolve every opened optional stage before closing execution.','pc-order-import-export'));
            }
        }
        $legacy=OrderExecutionOperations::legacy_plan($state);
        if ($legacy) { $state['legacy_plan']=$legacy;$state['legacy_next']=$legacy['detail']; }
        $due=(string)($plan['due']??'');
        $map['stages'][$id]=['status'=>$status,'detail'=>$result,'reason'=>'','actor'=>$actor,'at'=>gmdate('c'),
            'performed_at'=>$performed,'manager'=>(int)$state['manager'],'due'=>$due,
            'original_due'=>!empty($before['original_due'])?$before['original_due']:$due,'plan'=>$plan];
        if ($status==='in_progress' && !empty($map['closed_at'])) {
            unset($map['closed_at']);if($id!=='close') unset($map['stages']['close']);
            if (($map['current']??'')==='close') { $map['current']='';$state['next']='';$state['due']=''; }
        }
        if ($id==='close' && $status==='completed') {
            $map['closed_at']=gmdate('c');$map['current']='';$state['next']='';$state['due']='';
        } elseif ($status!=='in_progress' && ($map['current']??'')===$id) {
            $map['current']='';$state['next']='';$state['due']='';
        }
        $state['roadmap']=$map;
    }
}
