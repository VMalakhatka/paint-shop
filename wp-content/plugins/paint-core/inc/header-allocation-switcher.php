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

/** Прочитать предпочтение пользователя */
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

/** Сохранить предпочтение пользователя */
function pc_set_alloc_pref(array $pref): void {
    $mode   = in_array(($pref['mode'] ?? 'auto'), ['auto','manual'], true) ? $pref['mode'] : 'auto';
    $termId = max(0, (int)($pref['term_id'] ?? 0));
    $val    = ['mode'=>$mode, 'term_id'=>$termId];

    if (function_exists('WC') && WC() && WC()->session) {
        WC()->session->set('pc_alloc_pref', $val);
    }

    // cookie для гостей и как резерв
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

/* ============================ UI в шапке ============================ */

/**
 * Рендер переключателя.
 * Повешен сразу на два GP-хука (после названия и после логотипа).
 * Выводим 1 раз за загрузку (static-сторожок).
 */
function pc_render_alloc_control() {
    static $printed = false;
    if ($printed) return;
    $printed = true;

    // Список складов
    $terms = get_terms([
        'taxonomy'   => 'location',
        'hide_empty' => false,
    ]);
    if (is_wp_error($terms) || empty($terms)) {
        return; // если таксономии нет — молча выходим
    }

    $pref  = pc_get_alloc_pref();
    $mode  = $pref['mode'];
    $curId = (int) $pref['term_id'];
    $nonce = wp_create_nonce('pc_alloc_nonce');

    $ajax_url = admin_url('admin-ajax.php');
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
          <option value="<?php echo (int) $t->term_id; ?>" <?php selected($curId, (int)$t->term_id); ?>>
            <?php echo esc_html($t->name); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <script>
    (function($){
      var nonce = <?php echo wp_json_encode($nonce); ?>;
      var ajaxu = <?php echo wp_json_encode($ajax_url); ?>;

      function savePref(mode, term_id){
        return $.post(ajaxu, { action:'pc_set_alloc_pref', nonce:nonce, mode:mode, term_id:term_id });
      }
      function refreshUI(){
        // Обновим мини-корзину/фрагменты Woo, если они есть
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

// === Заменяем стандартный вывод site title на "title + наш переключатель" ===
// Делаем это ПОСЛЕ того, как тема повесила свои хуки (большой приоритет)
add_action('after_setup_theme', function () {
    // 1) Выключаем стандартный вывод тайтла (если он вообще подключён)
    if (has_action('generate_site_title', 'generate_construct_site_title')) {
        remove_action('generate_site_title', 'generate_construct_site_title');
    }

    // 2) Рисуем свой блок внутри брендинга (ранний приоритет, чтобы быть ближе к началу)
    add_action('generate_site_branding', function () {
        static $printed = false;
        if ($printed) return; // защита от дублей, если тема дернёт хук несколько раз
        $printed = true;

        echo '<div class="site-title-with-alloc">';
        if (function_exists('generate_construct_site_title')) {
            // отрисуем «Paint» так же, как делает GP
            generate_construct_site_title();
        } else {
            // страховка: простой линк на главную
            echo '<h1 class="main-title"><a href="' . esc_url(home_url('/')) . '">'
               . esc_html(get_bloginfo('name'))
               . '</a></h1>';
        }
        // наш переключатель
        pc_render_alloc_control();
        echo '</div>';
    }, 5);

    // 3) Доп. страховки, если вдруг пункт 1 не сработал (например, логотип/вариант темы другой)
    // — просто попробуем «приклеить» переключатель сразу после тайтла/логотипа.
    add_action('generate_after_site_title', 'pc_render_alloc_control', 15);
    add_action('generate_after_logo', 'pc_render_alloc_control', 15);
}, 100);

// === Вариант 3: рендерим внизу и переносим рядом с логотипом/тайтлом JS-ом ===
add_action('wp_footer', function () {
    // рисуем скрыто и потом перенесём в .site-branding
    echo '<div id="pc-alloc-mount" style="display:none">';
    pc_render_alloc_control();
    echo '</div>';

    ?>
    <script>
    (function($){
      $(function(){
        var $branding = $('.site-branding');  // контейнер с "paint"
        var $ctrl     = $('#pc-alloc-mount .pc-alloc');

        if ($branding.length && $ctrl.length) {
          // добавим в конец брендинга (сразу после paint)
          $ctrl.appendTo($branding).show();
        }
      });
    })(jQuery);
    </script>
    <?php
}, 99);