<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

class Ui
{
    /** Если нет PhpSpreadsheet — отключаем XLSX-кнопку */
    protected static bool $xlsx_enabled = true;
    public static function disableXlsx(): void { self::$xlsx_enabled = false; }

    /** Подключение всех UI-хуков */
    public static function init(): void
    {
        // CART: рендерим блок в нескольких «низах», чтобы поймать разные темы
        add_action('woocommerce_after_cart_totals',          [self::class, 'render_cart_block']);
        add_action('woocommerce_cart_totals_after_shipping', [self::class, 'render_cart_block']);
        add_action('woocommerce_proceed_to_checkout',        [self::class, 'render_cart_block']);
        add_action('woocommerce_before_account_orders', [self::class, 'render_account_import_block'], 5);

        // CART (блочный редактор): fallback — дописываем в конец контента
        add_filter('the_content', [self::class, 'maybe_append_to_cart_block'], 20);

        // ORDER: кнопки экспорта под таблицей заказа
        add_action('woocommerce_order_details_after_order_table', [self::class, 'render_order_block']);

        // Скрипты
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_js']);

        add_filter('woocommerce_my_account_my_orders_columns', function($cols){
            // вставимо після колонки "Order"
            $new = [];
            foreach ($cols as $key=>$label){
                $new[$key] = $label;
                if ($key === 'order-number') {
                    $new['pc_draft_title'] = 'Назва';
                }
            }
            return $new;
        }, 10, 1);

        add_action('woocommerce_my_account_my_orders_column_pc_draft_title', function($order){
            if (!$order instanceof \WC_Order) return;
            $title = (string)$order->get_meta('_pc_draft_title');
            if ($title !== '') {
                echo esc_html($title);
            } else {
                if ($order->has_status('pc-draft')) echo '<em style="opacity:.7">(без назви)</em>';
            }
        });
    }

    /** Алиас на случай, если где-то вызывается Ui::hooks() */
    public static function hooks(): void { self::init(); }

    /* ===================== PUBLIC RENDERERS ===================== */


    /** Блок імпорту в чернетку на сторінці "Мої замовлення" */
    public static function render_account_import_block(): void
    {
        // проста форма — той самий AJAX, що й на кошику
        $nonce_draft = wp_create_nonce('pcoe_import_draft');
        ?>
        <div class="pcoe-import" style="margin:16px 0; padding:12px; border:1px solid #eee; border-radius:8px">
        <details open>
            <summary><strong>Імпорт у чернетку замовлення</strong> (CSV / XLSX)</summary>
            <form id="pcoe-import-draft-form" enctype="multipart/form-data" method="post" onsubmit="return false;"
                style="margin-top:12px; display:flex; gap:12px; align-items:center; flex-wrap:wrap">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_draft); ?>">
                <input type="file" name="file" accept=".csv,.xlsx,.xls" required>
                <input type="text" name="title" placeholder="Назва чернетки (необов’язково)" style="min-width:260px">
                <button type="submit" class="button">Імпортувати у чернетку</button>
                <span class="pcoe-import-draft-msg" style="margin-left:8px; opacity:.8"></span>
            </form>

            <div class="pcoe-import-draft-result" style="margin-top:10px; display:none">
            <div class="pcoe-import-draft-links" style="margin:6px 0"></div>
            <div class="pcoe-import-draft-report"></div>
            </div>

            <div style="font-size:12px; opacity:.8; margin-top:10px">
            Формат: <code>sku;qty</code> або <code>gtin;qty</code>. Допускаються локальні назви колонок (Артикул, К-сть…).
            </div>
        </details>
        </div>
        <?php
    }

    public static function render_cart_block(): void
    {
        if (!function_exists('WC') || !WC()->cart) return;

        // 1) панель експорту
        echo self::render_controls_html('cart', 0);

        // 2) кнопка "Зберегти кошик у чернетку" (Cart → Draft)
        ?>
        <div style="margin:8px 0 4px; display:flex; gap:8px; align-items:center; flex-wrap:wrap">
            <form action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" method="get"
                style="display:flex; gap:8px; align-items:center">
                <input type="hidden" name="action" value="pcoe_cart_to_draft">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('pcoe_cart_to_draft')); ?>">
                <input type="hidden" name="clear" value="1">
                <input type="text" name="title" placeholder="Назва чернетки (необов’язково)" style="min-width:260px">
                <button class="button" type="submit">Зберегти кошик у чернетку</button>
            </form>
        </div>
        <?php

        // 3) блок імпорту в кошик/чернетку
        echo self::render_import_html('cart');
    }

    /** Блок на странице заказа */
    public static function render_order_block($order): void
    {
        if ( ! $order instanceof \WC_Order ) return;

        echo self::render_controls_html('order', (int)$order->get_id());

        // Кнопка «В кошик!» — доступна власнику замовлення або менеджеру
        $can_manage = current_user_can('manage_woocommerce');
        $is_owner   = (int)$order->get_user_id() === (int)get_current_user_id();

        if ( $can_manage || $is_owner ) {
            $url = \PaintCore\PCOE\DraftToCart::action_url((int)$order->get_id(), ['clear' => '1']);
            echo '<p style="margin-top:10px">
                    <a class="button" href="'.esc_url($url).'">В кошик!</a>
                </p>';
        }
}
    /** Fallback для блочного корзинного шаблона */
    public static function maybe_append_to_cart_block(string $content): string
    {
        if (!is_cart()) return $content;

        global $post;
        if ($post && function_exists('has_block') && has_block('woocommerce/cart', $post)) {
            if (strpos($content, 'class="pcoe-export"') === false) {
                $content .= self::render_controls_html('cart', 0);
            }
            if (strpos($content, 'id="pcoe-import-form"') === false) {
                $content .= self::render_import_html('cart');
            }
        }
        return $content;
    }

    /* ===================== INTERNAL HTML BUILDERS ===================== */

    /** Панель экспорта (кнопки, split, колонки) */
    protected static function render_controls_html(string $scope, int $order_id = 0): string
    {
        if ($scope === 'cart' && (!function_exists('WC') || !WC()->cart)) return '';

        $L    = Helpers::labels();
        $cols = Helpers::columns();

        $nonce = wp_create_nonce('pcoe_export');
        $base  = admin_url('admin-ajax.php?action=pcoe_export&_wpnonce='.$nonce.'&type='.$scope);
        if ($scope === 'order' && $order_id) {
            $base .= '&order_id='.$order_id;
        }

        ob_start(); ?>
        <div class="pcoe-export" style="margin-top:16px">
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0;align-items:center">
            <a class="button" href="<?php echo esc_url($base.'&fmt=csv'); ?>">
                <?php echo esc_html($L['btn_csv']); ?>
            </a>

            <?php if (self::$xlsx_enabled): ?>
              <a class="button" href="<?php echo esc_url($base.'&fmt=xlsx'); ?>">
                  <?php echo esc_html($L['btn_xlsx']); ?>
              </a>
            <?php else: ?>
              <a class="button disabled" onclick="return false"
                 title="XLSX недоступний на цьому сервері">
                 <?php echo esc_html($L['btn_xlsx']); ?>
              </a>
            <?php endif; ?>

            <label style="display:flex;align-items:center;gap:6px;margin-left:auto">
              <span><?php echo esc_html($L['split_label']); ?></span>
              <select class="pcoe-split" data-scope="<?php echo esc_attr($scope); ?>">
                <option value="agg"><?php echo esc_html($L['split_agg']); ?></option>
                <option value="per_loc"><?php echo esc_html($L['split_per_loc']); ?></option>
              </select>
            </label>
          </div>

          <details style="margin:8px 0">
            <summary><?php echo esc_html($L['conf_toggle']); ?></summary>
            <div class="pcoe-cols" data-scope="<?php echo esc_attr($scope); ?>"
                 style="display:flex;gap:14px;flex-wrap:wrap;margin:10px 0">
              <?php foreach ($cols as $key => $label): ?>
                <label style="display:flex;gap:6px;align-items:center">
                  <input type="checkbox" class="pcoe-col" value="<?php echo esc_attr($key); ?>">
                  <?php echo esc_html($label); ?>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
        </div>
        <?php
        return ob_get_clean();
    }
    

    /** Импорт (в корзину + в черновик) — отображается только на странице корзины */
    protected static function render_import_html(string $scope): string
    {
        if ($scope !== 'cart') return '';
        if (!function_exists('WC') || !WC()->cart) return '';

        $nonce_cart  = wp_create_nonce('pcoe_import_cart');
        $nonce_draft = wp_create_nonce('pcoe_import_draft');

        ob_start(); ?>
        <div class="pcoe-import" style="margin-top:18px; padding-top:10px; border-top:1px dashed #e5e5e5">
            <details>
                <summary><strong>Імпорт</strong> (CSV / XLSX)</summary>

                <!-- У кошик -->
                <div style="margin-top:12px">
                  <form id="pcoe-import-form" enctype="multipart/form-data" method="post" onsubmit="return false;"
                        style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_cart); ?>">
                      <input type="file" name="file" accept=".csv,.xlsx,.xls" required>
                      <button type="submit" class="button">Імпортувати у кошик</button>
                      <span class="pcoe-import-msg" style="margin-left:8px; opacity:.8"></span>
                  </form>
                </div>

                <!-- У чернетку замовлення -->
                <div style="margin-top:14px">
                  <form id="pcoe-import-draft-form" enctype="multipart/form-data" method="post" onsubmit="return false;"
                        style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_draft); ?>">
                      <input type="file" name="file" accept=".csv,.xlsx,.xls" required>
                      <button type="submit" class="button">Імпортувати у чернетку замовлення</button>
                      <span class="pcoe-import-draft-msg" style="margin-left:8px; opacity:.8"></span>
                  </form>

                  <div class="pcoe-import-draft-result" style="margin-top:10px; display:none">
                      <div class="pcoe-import-draft-links" style="margin:6px 0"></div>
                      <div class="pcoe-import-draft-report"></div>
                  </div>
                </div>

                <div style="font-size:12px; opacity:.8; margin-top:12px">
                    Формат CSV: <code>sku;qty</code> або <code>gtin;qty</code>. Розділювач <code>;</code> або <code>,</code>.
                    Дробові кількості: крапка або кома; тисячні пробіли і не-знак ігноруються.
                </div>
            </details>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ===================== JS ===================== */

    public static function enqueue_js(): void
    {
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', self::js_code());
    }

    protected static function js_code(): string
    {
        $ajax = esc_js(admin_url('admin-ajax.php'));
        return <<<JS
jQuery(function($){
    var KEY_COLS='pcoeCols', KEY_SPLIT='pcoeSplit';

    function readCols(scope){ try{ var all=JSON.parse(localStorage.getItem(KEY_COLS)||'{}'); return all[scope]||[]; }catch(e){ return []; } }
    function writeCols(scope, arr){ try{ var all=JSON.parse(localStorage.getItem(KEY_COLS)||'{}'); all[scope]=arr; localStorage.setItem(KEY_COLS, JSON.stringify(all)); }catch(e){} }
    function readSplit(scope){ try{ var all=JSON.parse(localStorage.getItem(KEY_SPLIT)||'{}'); return all[scope]||'agg'; }catch(e){ return 'agg'; } }
    function writeSplit(scope, val){ try{ var all=JSON.parse(localStorage.getItem(KEY_SPLIT)||'{}'); all[scope]=val; localStorage.setItem(KEY_SPLIT, JSON.stringify(all)); }catch(e){} }

    $('.pcoe-cols').each(function(){
        var scope = $(this).data('scope');
        var sel = readCols(scope);
        if (sel.length){
            $(this).find('input.pcoe-col').each(function(){
                if (sel.indexOf($(this).val())!==-1) $(this).prop('checked', true);
            });
        } else {
            $(this).find('input.pcoe-col[value="sku"],input.pcoe-col[value="name"],input.pcoe-col[value="qty"],input.pcoe-col[value="price"],input.pcoe-col[value="total"]').prop('checked', true);
        }
    });

    $('.pcoe-split').each(function(){
        var scope = $(this).data('scope');
        $(this).val(readSplit(scope));
    });

    $(document).on('change','.pcoe-col',function(){
        var wrap = $(this).closest('.pcoe-cols');
        var scope = wrap.data('scope');
        var arr = [];
        wrap.find('input.pcoe-col:checked').each(function(){ arr.push($(this).val()); });
        writeCols(scope, arr);
    });
    $(document).on('change','.pcoe-split',function(){
        var scope = $(this).data('scope');
        writeSplit(scope, $(this).val());
    });

    $(document).on('click','.pcoe-export a.button',function(){
        var box = $(this).closest('.pcoe-export');
        var colsWrap = box.find('.pcoe-cols'); var scope = colsWrap.data('scope');
        var cols=[]; colsWrap.find('input.pcoe-col:checked').each(function(){ cols.push($(this).val()); });
        if(!cols.length){ cols=['sku','name','qty','price','total']; }
        var splitSel = box.find('.pcoe-split'); var split = splitSel.val() || 'agg';
        var href = new URL(this.href);
        href.searchParams.set('cols', cols.join(','));
        href.searchParams.set('split', split);
        this.href = href.toString();
    });

    function ensureNoteChecked(scope){
        var wrap = $('.pcoe-cols[data-scope="'+scope+'"]');
        var note = wrap.find('input.pcoe-col[value="note"]');
        if (!note.prop('checked')) { note.prop('checked', true).trigger('change'); }
    }
    $('.pcoe-split').each(function(){ var scope=$(this).data('scope'); if($(this).val()==='per_loc') ensureNoteChecked(scope); });
    $(document).on('change','.pcoe-split',function(){ var scope=$(this).data('scope'); if($(this).val()==='per_loc') ensureNoteChecked(scope); });

    // === Імпорт у кошик
    $(document).on('submit','#pcoe-import-form',function(){
        var \$f=$(this), \$msg=\$f.find('.pcoe-import-msg');
        var fd=new FormData(this); fd.append('action','pcoe_import_cart');
        \$msg.text('Імпортуємо…');
        $.ajax({
          url:'{$ajax}', method:'POST', data:fd, contentType:false, processData:false,
          success:function(resp){
            if(resp && resp.success){
              \$msg.text('Додано позицій: '+resp.data.added+', пропущено: '+resp.data.skipped);
              window.location.reload();
            }else{
              \$msg.text((resp && resp.data && resp.data.msg) ? resp.data.msg : 'Помилка імпорту.');
            }
          },
          error:function(){ \$msg.text('Помилка з\\'єднання.'); }
        });
        return false;
    });

    // === Імпорт у чернетку
    $(document).on('submit','#pcoe-import-draft-form',function(){
        var \$f=$(this), \$msg=\$f.find('.pcoe-import-draft-msg');
        var \$box=$('.pcoe-import-draft-result');
        \$msg.text('Імпортуємо…'); \$box.hide();

        var fd=new FormData(this); fd.append('action','pcoe_import_order_draft');

        $.ajax({
          url:'{$ajax}', method:'POST', data:fd, contentType:false, processData:false,
          success:function(resp){
            if(resp && resp.success){
                \$msg.text('Імпортовано: '+resp.data.imported+', пропущено: '+resp.data.skipped);
                var linksHtml='';
                if(resp.data.links){
                    if(resp.data.links.edit){ linksHtml += '<a class="button" href="'+resp.data.links.edit+'" target="_blank" rel="noopener">Відкрити в адмінці</a> '; }
                    if(resp.data.links.view){ linksHtml += '<a class="button" href="'+resp.data.links.view+'" target="_blank" rel="noopener">Переглянути замовлення</a>'; }
                }
                $('.pcoe-import-draft-links').html(linksHtml||'');
                $('.pcoe-import-draft-report').html(resp.data.report_html||'');
                \$box.show();
            }else{
                \$msg.text((resp && resp.data && resp.data.msg) ? resp.data.msg : 'Помилка імпорту.');
            }
          },
          error:function(){ \$msg.text('Помилка з\\'єднання.'); }
        });
        return false;
    });
});
JS;
    }
}