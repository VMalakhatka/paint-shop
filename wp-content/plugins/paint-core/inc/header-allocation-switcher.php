<?php
// inc/header-allocation-switcher.php
defined('ABSPATH') || exit;

/**
 * Переключатель стратегии списания (в шапке, рядом с логотипом).
 *  - Режимы: auto / manual (ручной выбор склада по term_id таксономии `location`)
 *  - Хранение: WC()->session['pc_alloc_pref'] + cookie pc_alloc_pref
 *  - Применение: фильтр 'slu_allocation_plan'
 */

/* ============================ Helpers ============================ */

function pc_get_alloc_pref(): array {
    $pref = [];

    if (function_exists('WC') && WC() && WC()->session) {
        $pref = (array) WC()->session->get('pc_alloc_pref', []);
    }

    if (empty($pref) && !empty($_COOKIE['pc_alloc_pref'])) {
        $try = json_decode(stripslashes($_COOKIE['pc_alloc_pref']), true);
        if (is_array($try)) $pref = $try;
    }

    $mode   = in_array(($pref['mode'] ?? 'auto'), ['auto','manual'], true) ? $pref['mode'] : 'auto';
    $termId = max(0, (int)($pref['term_id'] ?? 0));

    return ['mode'=>$mode, 'term_id'=>$termId];
}

function pc_set_alloc_pref(array $pref): void {
    $mode   = in_array(($pref['mode'] ?? 'auto'), ['auto','manual'], true) ? $pref['mode'] : 'auto';
    $termId = max(0, (int)($pref['term_id'] ?? 0));
    $val    = ['mode'=>$mode, 'term_id'=>$termId];

    if (function_exists('WC') && WC() && WC()->session) {
        WC()->session->set('pc_alloc_pref', $val);
    }

    setcookie(
        'pc_alloc_pref',
        wp_json_encode($val),
        time() + 30 * DAY_IN_SECONDS,
        COOKIEPATH ?: '/',
        COOKIE_DOMAIN ?: ''
    );
}

/* ============================ AJAX ============================ */

add_action('wp_ajax_pc_set_alloc_pref', 'pc_ajax_set_alloc_pref');
add_action('wp_ajax_nopriv_pc_set_alloc_pref', 'pc_ajax_set_alloc_pref');

function pc_ajax_set_alloc_pref() {
    check_ajax_referer('pc_alloc_nonce', 'nonce');

    $mode   = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'auto';
    $termId = isset($_POST['term_id']) ? (int) $_POST['term_id'] : 0;

    pc_set_alloc_pref(['mode'=>$mode, 'term_id'=>$termId]);
    wp_send_json_success(pc_get_alloc_pref());
}

/* ============================ UI ============================ */

function pc_render_alloc_control() {
    static $printed = false;
    if ($printed) return; // рисуем один раз
    $printed = true;

    $terms = get_terms([
        'taxonomy'   => 'location',
        'hide_empty' => false,
    ]);
    if (is_wp_error($terms) || empty($terms)) return;

    $pref   = pc_get_alloc_pref();
    $mode   = $pref['mode'];
    $curId  = (int)$pref['term_id'];
    $nonce  = wp_create_nonce('pc_alloc_nonce');
    $ajax_u = admin_url('admin-ajax.php');
    ?>
    <div class="pc-alloc" role="group" aria-label="<?php echo esc_attr__('Списание', 'woocommerce'); ?>">
      <small><?php echo esc_html__('Списание:', 'woocommerce'); ?></small>

      <select class="pc-alloc-mode" aria-label="<?php echo esc_attr__('Режим списания', 'woocommerce'); ?>">
        <option value="auto"   <?php selected($mode, 'auto');   ?>><?php echo esc_html__('Авто', 'woocommerce'); ?></option>
        <option value="manual" <?php selected($mode, 'manual'); ?>><?php echo esc_html__('Выбрать склад…', 'woocommerce'); ?></option>
      </select>

      <select class="pc-alloc-term" aria-label="<?php echo esc_attr__('Склад', 'woocommerce'); ?>" <?php disabled($mode !== 'manual'); ?>>
        <option value="0"><?php echo esc_html__('— склад —', 'woocommerce'); ?></option>
        <?php foreach ($terms as $t): ?>
          <option value="<?php echo (int)$t->term_id; ?>" <?php selected($curId, (int)$t->term_id); ?>>
            <?php echo esc_html($t->name); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <script>
    (function($){
      var nonce = <?php echo wp_json_encode($nonce); ?>;
      var ajaxu = <?php echo wp_json_encode($ajax_u); ?>;

      function savePref(mode, term_id){
        return $.post(ajaxu, { action:'pc_set_alloc_pref', nonce:nonce, mode:mode, term_id:term_id });
      }
      function refreshUI(){
        $(document.body).trigger('wc_fragment_refresh');
      }

      $(document).on('change', '.pc-alloc-mode', function(){
        var mode = this.value;
        var $term = $('.pc-alloc-term');
        if (mode === 'manual') { $term.prop('disabled', false); }
        else { $term.prop('disabled', true); }
        var tid = (mode === 'manual') ? parseInt($term.val(), 10) || 0 : 0;
        savePref(mode, tid).done(refreshUI);
      });

      $(document).on('change', '.pc-alloc-term', function(){
        var tid = parseInt(this.value, 10) || 0;
        savePref('manual', tid).done(refreshUI);
      });
    })(jQuery);
    </script>
    <?php
}

/**
 * Вариант 3: рендерим в футере (скрыто) и переносим в .site-branding JS-ом.
 * Ничего в теме не ломаем и не заменяем.
 */
add_action('wp_footer', function () {
    echo '<div id="pc-alloc-mount" style="display:none">';
    pc_render_alloc_control();
    echo '</div>';
    ?>
    <script>
    (function($){
      $(function(){
        var $branding = $('.site-branding');       // контейнер с "paint"
        var $ctrl     = $('#pc-alloc-mount .pc-alloc');
        if ($branding.length && $ctrl.length) {
          $ctrl.appendTo($branding).show();
        }
      });
    })(jQuery);
    </script>
    <?php
}, 99);

/* ============================ Применение в планировании списания ============================ */

add_filter('slu_allocation_plan', function($plan, $product, $need, $strategy){
    $pref = pc_get_alloc_pref();
    if ($pref['mode'] !== 'manual' || $pref['term_id'] <= 0) return $plan;

    if (!function_exists('slu_collect_location_stocks_for_product')) return $plan;

    $need = max(0, (int)$need);
    if ($need === 0) return [];

    $all = slu_collect_location_stocks_for_product($product); // [term_id => ['name'=>..., 'qty'=>...]]
    if (empty($all) || !is_array($all)) return $plan;

    $preferred = (int)$pref['term_id'];
    $ordered   = [];

    if (isset($all[$preferred])) {
        $ordered[$preferred] = $all[$preferred];
        unset($all[$preferred]);
    }

    if (function_exists('slu_get_primary_location_term_id')) {
        $primary_id = (int) slu_get_primary_location_term_id($product->get_id());
        if ($primary_id && isset($all[$primary_id])) {
            $ordered[$primary_id] = $all[$primary_id];
            unset($all[$primary_id]);
        }
    }

    uasort($all, function($a,$b){ return (int)($b['qty'] ?? 0) <=> (int)($a['qty'] ?? 0); });
    $ordered += $all;

    $res = []; $left = $need;
    foreach ($ordered as $tid=>$row) {
        if ($left <= 0) break;
        $take = min($left, max(0, (int)($row['qty'] ?? 0)));
        if ($take > 0) { $res[(int)$tid] = $take; $left -= $take; }
    }
    return $res;
}, 10, 4);