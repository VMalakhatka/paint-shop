<?php
  if (!defined('ABSPATH')) exit;
  
  class Lavka_Reports_NoMovement_Settings {
      private function name($path){ return Lavka_Reports_Admin::OPT.'['.$path.']'; }
  
      public function __construct(){
          add_action('lavr/no_movement/settings', [$this,'render']);
          add_action('admin_init', [$this,'register_defaults']);
      }
  
      public function register_defaults(){
          $opt = get_option(Lavka_Reports_Admin::OPT, []);
          $opt += [
              'period_from' => '',
              'period_to'   => '',
              'warehouses'  => [],
              'ops_exclude' => [],
              'endpoint_warehouses' => rest_url('lavka/v1/locations?hide_empty=0&per_page=200'),
              'endpoint_ops'        => rest_url('lavka/v1/ops/types'),
          ];
          update_option(Lavka_Reports_Admin::OPT, $opt);
      }
  
      public function render(){
          $opt = get_option(Lavka_Reports_Admin::OPT, []);
          ?>
          <form method="post" action="options.php" class="lavr-form">
              <?php settings_fields(Lavka_Reports_Admin::PAGE_SLUG); ?>
              <input type="hidden" name="<?php echo esc_attr($this->name('endpoint_warehouses')); ?>" value="<?php echo esc_attr($opt['endpoint_warehouses']); ?>" />
              <input type="hidden" name="<?php echo esc_attr($this->name('endpoint_ops')); ?>" value="<?php echo esc_attr($opt['endpoint_ops']); ?>" />
  
              <h2 class="title"><?php esc_html_e('Period', 'lavka-reports'); ?></h2>
              <div class="lr-grid">
                  <label>
                      <?php esc_html_e('From (YYYY-MM-DD)', 'lavka-reports'); ?>
                      <input type="date" name="<?php echo esc_attr($this->name('period_from')); ?>" value="<?php echo esc_attr($opt['period_from']); ?>" />
                  </label>
                  <label>
                      <?php esc_html_e('To (YYYY-MM-DD)', 'lavka-reports'); ?>
                      <input type="date" name="<?php echo esc_attr($this->name('period_to')); ?>" value="<?php echo esc_attr($opt['period_to']); ?>" />
                  </label>
              </div>
  
              <h2 class="title"><?php esc_html_e('Warehouses to analyse (MS SQL)', 'lavka-reports'); ?></h2>
              <div class="lr-ref">
                  <button type="button" class="button" id="lr-load-warehouses"><?php esc_html_e('Load from MS SQL', 'lavka-reports'); ?></button>
                  <button type="button" class="button" id="lr-clear-warehouses"><?php esc_html_e('Clear', 'lavka-reports'); ?></button>
              </div>
              <div class="lr-multicol">
                  <select id="lr-warehouses" multiple size="10" style="min-width:360px"></select>
                  <div id="lr-warehouses-selected">
                        <?php foreach (($opt['warehouses'] ?? []) as $i=>$w): ?>
                            <input type="hidden" name="<?php echo esc_attr( $this->name("warehouses[$i][id]") ); ?>" value="<?php echo esc_attr($w['id']); ?>" />
                            <input type="hidden" name="<?php echo esc_attr( $this->name("warehouses[$i][name]") ); ?>" value="<?php echo esc_attr($w['name']); ?>" />
                            <div class="lr-chip" data-id="<?php echo esc_attr($w['id']); ?>"><?php echo esc_html($w['name']); ?></div>
                        <?php endforeach; ?>
                  </div>
              </div>
  
              <h2 class="title" style="margin-top:24px;"><?php esc_html_e('Operation types to exclude', 'lavka-reports'); ?></h2>
                <p class="description">
                <?php esc_html_e('Load from MS SQL (Java /ref/op-types), then select one or multiple items. Selected types will be ignored in analysis.', 'lavka-reports'); ?>
                </p>

                <div class="lr-ref">
                <button type="button" class="button" id="lr-load-ops"><?php esc_html_e('Load from MS SQL', 'lavka-reports'); ?></button>
                <button type="button" class="button" id="lr-clear-ops"><?php esc_html_e('Clear', 'lavka-reports'); ?></button>
                </div>

                <div class="lr-multicol">
                <select id="lr-ops" multiple size="10" style="min-width:380px;"></select>
                <div id="lr-ops-selected">
                    <?php if (!empty($opt['ops_exclude'])): foreach ($opt['ops_exclude'] as $i => $o): ?>
                    <input type="hidden"
                        name="<?php echo esc_attr( $this->name("ops_exclude[$i][code]") ); ?>"
                        value="<?php echo esc_attr($o['code']); ?>" />
                    <input type="hidden"
                        name="<?php echo esc_attr( $this->name("ops_exclude[$i][name]") ); ?>"
                        value="<?php echo esc_attr($o['name']); ?>" />
                    <div class="lr-chip" data-code="<?php echo esc_attr($o['code']); ?>"><?php echo esc_html($o['name']); ?></div>
                    <?php endforeach; endif; ?>
                </div>
                </div>
                  <p>
                     <?php submit_button(__('Save settings', 'lavka-reports')); ?>
                    <button type="button" class="button button-primary" id="lr-run-report">
                        <?php esc_html_e('Generate report', 'lavka-reports'); ?>
                    </button>
                    <button type="button" class="button" id="lr-export-csv" disabled>
                        <?php esc_html_e('Export CSV', 'lavka-reports'); ?>
                    </button>
                    </p>
                    <div id="lr-report-status" class="description"></div>
                    <table class="widefat fixed striped" id="lr-report-table" style="margin-top:10px; display:none;">
                    <thead>
                        <tr>
                        <th style="width:220px;"><?php esc_html_e('SKU', 'lavka-reports'); ?></th>
                        <th><?php esc_html_e('Title', 'lavka-reports'); ?></th>
                        <th style="width:120px; text-align:right;"><?php esc_html_e('Total qty', 'lavka-reports'); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    </table>      
             
                    </form>
              <?php
      }
  }