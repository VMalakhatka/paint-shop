<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Actual manager operations, recorded independently from the next-action plan. */
final class OrderExecutionOperations {
    /** Only an outstanding legacy plan is selectable; a historical legacy_next note is not a plan. */
    public static function legacy_plan(array $state): array {
        if (is_array($state['legacy_plan']??null)) return $state['legacy_plan'];
        if (empty($state['roadmap']) && !empty($state['next'])) {
            return ['detail'=>(string)$state['next'],'manager'=>(int)($state['manager']??0),
                'due'=>(string)($state['due']??''),'at'=>'','actor'=>0];
        }
        return [];
    }

    /** Outstanding explicit plans; old v1 in-progress entries are compatible plans. */
    public static function planned(array $state): array {
        $plans=[];$stages=OrderExecutionRoadmap::stages();
        foreach (($state['roadmap']['stages']??[]) as $id=>$record) {
            if (!isset($stages[$id]) || ($record['status']??'')!=='in_progress') continue;
            if (is_array($record['plan']??null)) $plans[$id]=$record['plan'];
            elseif (!array_key_exists('plan',$record) && empty($record['performed_at'])) {
                $plans[$id]=['detail'=>(string)($record['detail']??''),'manager'=>(int)($record['manager']??0),
                    'due'=>(string)($record['due']??''),'at'=>(string)($record['at']??''),'actor'=>(int)($record['actor']??0)];
            }
        }
        return $plans;
    }

    public static function record(int $id,string $revision,array $input): array {
        if (!current_user_can('manage_woocommerce')) OrderExecution::fail();
        $root=OrderExecution::root(wc_get_order($id));ManagerWorkspace::customer($root->get_customer_id());
        foreach (['operation_choice','operation_status','operation_title','operation_result','performed_at','manager'] as $key)
            if (isset($input[$key]) && !is_scalar($input[$key])) OrderExecution::fail();
        $choice=(string)($input['operation_choice']??'');$status=(string)($input['operation_status']??'completed');
        $title=sanitize_text_field($input['operation_title']??'');$result=sanitize_textarea_field($input['operation_result']??'');
        if (!in_array($status,['in_progress','completed','skipped'],true) || trim($result)==='' || mb_strlen($result)>2000 || mb_strlen($title)>160) OrderExecution::fail();
        $performed=OrderExecution::date((string)($input['performed_at']??''));
        if (!$performed || strtotime($performed)>time()+60)
            throw new \RuntimeException(__('Enter the actual operation time. A future time belongs in the next-action plan.','pc-order-import-export'));
        $actor=get_current_user_id();
        return OrderExecution::change($root->get_id(),$revision,static function($state,$order)use($input,$choice,$status,$title,$result,$performed,$actor){
            if (!$state) {
                $manager=absint($input['manager']??$actor);
                if (!$manager || !user_can($manager,'manage_woocommerce')) OrderExecution::fail();
                $state=['customer'=>$order->get_customer_id(),'folio'=>OrderExecution::context($order),'manager'=>$manager,
                    'due'=>'','original_due'=>'','next'=>'','active'=>false,'links'=>[],'documents'=>[],'events'=>[],'observed'=>[]];
            }
            $source='';$stage='';$plan=null;$operation_title=$title;
            if ($choice==='custom') {
                if ($status!=='completed' || trim($title)==='') OrderExecution::fail();
                $source='custom';
            } elseif ($choice==='planned:legacy') {
                $plan=self::legacy_plan($state);
                if (!$plan || $status!=='completed') OrderExecution::fail();
                $source='legacy';$operation_title=(string)$plan['detail'];
                $state['legacy_next']=$operation_title;unset($state['legacy_plan']);
                if (empty($state['roadmap']['current']) && ($state['next']??'')===$operation_title) { $state['next']='';$state['due']=''; }
            } else {
                if (!preg_match('/^(planned|stage):([a-z_]+)$/D',$choice,$match) || !isset(OrderExecutionRoadmap::stages()[$match[2]])) OrderExecution::fail();
                $source=$match[1];$stage=$match[2];$plans=self::planned($state);
                if ($source==='planned') { if (!isset($plans[$stage])) OrderExecution::fail();$plan=$plans[$stage]; }
                elseif (isset($plans[$stage])) OrderExecution::fail();
                $operation_title=OrderExecutionRoadmap::label($stage);
                OrderExecutionRoadmap::record($state,$stage,$status,$result,$performed,$actor,$plan);
            }
            OrderExecution::event($state,'operation_recorded',['operation'=>['source'=>$source,'stage'=>$stage,'title'=>$operation_title,
                'status'=>$status,'result'=>$result,'performed_at'=>$performed,'planned'=>$plan]],$actor);
            return $state;
        });
    }
}
