<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

final class OrderExecutionRoadmapUi {
    public static function fields(array $state): void {
        $stages=OrderExecutionRoadmap::stages();$current=(string)($state['roadmap']['current']??'');
        $record=$state['roadmap']['stages'][$current]??[];
        $legacy=$state['legacy_next']??(empty($state['roadmap'])?($state['next']??''):'');
        echo '<label>'.esc_html__('Next action','pc-order-import-export').'<select id="pcoe-next-stage" name="stage" required data-roadmap-stage><option value="">—</option>';
        foreach($stages as $id=>$stage) {
            $saved=$state['roadmap']['stages'][$id]??[];
            $plan=OrderExecutionOperations::planned($state)[$id]??[];
            echo '<option value="'.esc_attr($id).'" data-detail="'.esc_attr($plan['detail']??'').'" data-due="'.esc_attr(!empty($plan['due'])?wp_date('Y-m-d\TH:i',strtotime($plan['due'])):'').'" '.selected($current,$id,false).'>'.esc_html($stage['label'].($stage['optional']?' · '.__('If needed','pc-order-import-export'):'')).'</option>';
        }
        echo '</select></label><input type="hidden" name="stage_status" value="in_progress"><label>'.esc_html__('Stage details: warehouse, goods or document reference','pc-order-import-export').'<textarea name="stage_detail" maxlength="500">'.esc_textarea($record['plan']['detail']??$record['detail']??$legacy).'</textarea></label>';
        echo '<div class="pcoe-stage-scenario" aria-live="polite">';
        foreach($stages as $id=>$stage) echo '<div data-roadmap-scenario="'.esc_attr($id).'" '.($current!==$id?'hidden':'').'><strong>'.esc_html($stage['optional']?__('Optional stage','pc-order-import-export'):__('Required stage','pc-order-import-export')).'</strong><p>'.esc_html($stage['scenario']).'</p></div>';
        echo '<p data-roadmap-empty '.($current?'hidden':'').'>'.esc_html__('Choose a stage to see its scenario and completion criteria. The full roadmap is below.','pc-order-import-export').'</p></div>';
        if ($legacy) echo '<details class="pcoe-stage-legacy"><summary>'.esc_html__('Previous next action, kept as a note','pc-order-import-export').'</summary><p>'.nl2br(esc_html($legacy)).'</p></details>';
    }

    public static function roadmap(array $state): void {
        $stages=OrderExecutionRoadmap::stages();$records=$state['roadmap']['stages']??[];$done=0;$required=0;
        foreach($stages as $id=>$stage) if(!$stage['optional']) {$required++;if(($records[$id]['status']??'')==='completed')$done++;}
        echo '<details class="pcoe-roadmap"><summary>'.esc_html(sprintf(__('Execution roadmap: %1$d of %2$d required stages completed','pc-order-import-export'),$done,$required)).'</summary>';
        echo '<p>'.esc_html__('Choose the next stage according to the actual order. Moving to a later stage does not complete earlier ones. Optional stages can be skipped with a reason; all opened branches must be resolved before closure.','pc-order-import-export').'</p>';
        if (!empty($state['roadmap']['closed_at'])) echo '<p><strong>'.esc_html__('Execution roadmap closed','pc-order-import-export').'</strong> · '.esc_html(OrderExecutionUi::time($state['roadmap']['closed_at'])).'</p>';
        echo '<ol>';
        foreach($stages as $id=>$stage) {
            $record=$records[$id]??[];$status=$record['status']??($stage['optional']?'optional':'pending');
            echo '<li><a href="#pcoe-next-stage" data-roadmap-select="'.esc_attr($id).'">'.esc_html($stage['label']).'</a> · <strong>'.esc_html(OrderExecutionRoadmap::status_label($status)).'</strong>';
            if (($state['roadmap']['current']??'')===$id) echo ' · '.esc_html__('Next action','pc-order-import-export');
            echo '<p>'.esc_html($stage['scenario']).'</p>';
            if ($record) {
                echo '<p>'.esc_html(OrderExecutionUi::name((int)$record['actor']).' · '.OrderExecutionUi::time($record['at']));
                if (!empty($record['due'])) echo ' · '.esc_html__('Agreed deadline','pc-order-import-export').': '.esc_html(OrderExecutionUi::time($record['due']));
                echo '</p><p>'.nl2br(esc_html(trim(($record['detail']??'')."\n".($record['reason']??'')))).'</p>';
            }
            echo '</li>';
        }
        echo '</ol></details>';
    }

    public static function event(array $event): void {
        echo '<p><strong>'.esc_html(OrderExecutionRoadmap::label($event['stage']??'')).'</strong> · '.esc_html(OrderExecutionRoadmap::status_label($event['after']['status']??'pending')).'</p>';
        if (!empty($event['before']['status'])) echo '<p>'.esc_html__('Before','pc-order-import-export').': '.esc_html(OrderExecutionRoadmap::status_label($event['before']['status'])).'</p>';
        if (!empty($event['after']['detail'])) echo '<p>'.nl2br(esc_html($event['after']['detail'])).'</p>';
    }
}
