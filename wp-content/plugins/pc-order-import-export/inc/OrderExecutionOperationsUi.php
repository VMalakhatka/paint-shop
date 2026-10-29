<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Recording a fact is separate from assigning the next task. */
final class OrderExecutionOperationsUi {
    public static function fields(array $state): void {
        $planned=OrderExecutionOperations::planned($state);$stages=OrderExecutionRoadmap::stages();
        $current=(string)($state['roadmap']['current']??'');
        $legacy=OrderExecutionOperations::legacy_plan($state);
        $choice=isset($planned[$current])?'planned:'.$current:($legacy?'planned:legacy':'');
        echo '<label>'.esc_html__('Operation to document','pc-order-import-export').'<select name="operation_choice" required data-operation-choice><option value="">—</option>';
        if ($planned || $legacy) {
            echo '<optgroup label="'.esc_attr__('Previously planned','pc-order-import-export').'">';
            foreach ($planned as $id=>$plan) echo '<option value="'.esc_attr('planned:'.$id).'" data-stage="'.esc_attr($id).'" data-optional="'.(!empty($stages[$id]['optional'])?'1':'0').'" '.selected($choice,'planned:'.$id,false).'>'.esc_html($stages[$id]['label']).'</option>';
            if ($legacy) echo '<option value="planned:legacy" '.selected($choice,'planned:legacy',false).'>'.esc_html($legacy['detail']).'</option>';
            echo '</optgroup>';
        }
        echo '<optgroup label="'.esc_attr__('Another roadmap stage','pc-order-import-export').'">';
        foreach ($stages as $id=>$stage) if (!isset($planned[$id])) echo '<option value="'.esc_attr('stage:'.$id).'" data-stage="'.esc_attr($id).'" data-optional="'.($stage['optional']?'1':'0').'">'.esc_html($stage['label']).'</option>';
        echo '</optgroup><option value="custom">'.esc_html__('Other operation — enter a name','pc-order-import-export').'</option></select></label>';
        echo '<label data-operation-title>'.esc_html__('Operation name (for Other operation)','pc-order-import-export').'<input type="text" name="operation_title" maxlength="160"></label>';
        echo '<label>'.esc_html__('Resulting stage status','pc-order-import-export').'<select name="operation_status" data-operation-status>';
        foreach (['completed','in_progress','skipped'] as $status) echo '<option value="'.esc_attr($status).'">'.esc_html(OrderExecutionRoadmap::status_label($status)).'</option>';
        echo '</select></label><label>'.esc_html__('Actual operation time','pc-order-import-export').'<input type="datetime-local" name="performed_at" required value="'.esc_attr(wp_date('Y-m-d\TH:i')).'"></label>';
        echo '<label class="pcoe-operation-result">'.esc_html__('Result and evidence','pc-order-import-export').'<textarea name="operation_result" required maxlength="2000"></textarea></label>';
        echo '<div class="pcoe-stage-scenario" aria-live="polite">';
        foreach ($planned+($legacy?['legacy'=>$legacy]:[]) as $id=>$plan) {
            echo '<div data-operation-plan="'.esc_attr('planned:'.$id).'" '.($choice==='planned:'.$id?'':'hidden').'>';
            self::plan($plan);echo '</div>';
        }
        foreach ($stages as $id=>$stage) echo '<div data-operation-scenario="'.esc_attr($id).'" '.($choice!=='planned:'.$id?'hidden':'').'><strong>'.esc_html($stage['label']).'</strong><p>'.esc_html($stage['scenario']).'</p></div>';
        echo '<p data-operation-custom '.($choice==='planned:legacy'?'':'hidden').'>'.esc_html__('This records an operation in history without completing a roadmap stage. The next planned action remains separate.','pc-order-import-export').'</p>';
        echo '<p data-operation-empty '.($choice?'hidden':'').'>'.esc_html__('Select a previous plan, another roadmap stage, or enter your own operation. Describe what actually happened.','pc-order-import-export').'</p></div>';
    }

    public static function event(array $operation): void {
        $title=!empty($operation['stage'])?OrderExecutionRoadmap::label($operation['stage']):(string)($operation['title']??'');
        $source=['planned'=>__('Previously planned','pc-order-import-export'),'stage'=>__('Another roadmap stage','pc-order-import-export'),
            'legacy'=>__('Previously planned','pc-order-import-export'),'custom'=>__('Other operation','pc-order-import-export')][$operation['source']??'']??'';
        echo '<p><strong>'.esc_html($title).'</strong> · '.esc_html($source).' · '.esc_html(OrderExecutionRoadmap::status_label($operation['status']??'completed')).'</p>';
        echo '<p>'.esc_html__('Actual operation time','pc-order-import-export').': '.esc_html(OrderExecutionUi::time($operation['performed_at']??'')).'</p>';
        echo '<p>'.nl2br(esc_html($operation['result']??'')).'</p>';
        if (!empty($operation['planned'])) {
            self::plan($operation['planned']);
        }
    }

    private static function plan(array $plan): void {
        echo '<p><strong>'.esc_html__('Original plan','pc-order-import-export').'</strong> · '.esc_html__('Agreed deadline','pc-order-import-export').': '.esc_html(OrderExecutionUi::time($plan['due']??''));
        if (!empty($plan['manager'])) echo ' · '.esc_html(OrderExecutionUi::name((int)$plan['manager']));
        echo '</p><p>'.nl2br(esc_html($plan['detail']??'')).'</p>';
    }

    public static function latest(array $state): void {
        foreach (array_reverse($state['events']??[]) as $event) if ($event['type']==='operation_recorded') {
            echo '<details class="pcoe-operation-latest"><summary>'.esc_html__('Last recorded operation','pc-order-import-export').' · '.esc_html(OrderExecutionUi::time($event['time'])).'</summary>';
            self::event($event['details']['operation']??[]);echo '</details>';return;
        }
    }
}
