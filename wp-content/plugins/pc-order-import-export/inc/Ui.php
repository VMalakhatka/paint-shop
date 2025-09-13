<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

class Ui
{
    /** Если нет PhpSpreadsheet — отключаем XLSX-кнопку */
    protected static bool $xlsx_enabled = true;
    public static function disableXlsx(): void { self::$xlsx_enabled = false; }

    public static function render_cart_block(): void
    {
        if (!function_exists('WC') || !WC()->cart) return;

        echo self::render_controls_html('cart', 0);
        echo self::render_import_html('cart');

        // ← наша кнопка/форма «В чернетку»
        echo self::render_cart_to_draft_html();
    }

    /** Fallback для блочного кошика (Gutenberg) */
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
            // додати форму «В чернетку», якщо її ще немає
            if (strpos($content, 'class="pcoe-cart-to-draft"') === false) {
                $content .= self::render_cart_to_draft_html();
            }
        }
        return $content;
    }

    /** HTML: форма «Зберегти кошик у чернетку» */
    protected static function render_cart_to_draft_html(): string
    {
        if (!function_exists('WC') || !WC()->cart) return '';
        $nonce  = wp_create_nonce('pcoe_cart_to_draft');
        $action = admin_url('admin-ajax.php');

        ob_start(); ?>
        <div class="pcoe-cart-to-draft" style="margin:14px 0 6px">
            <form action="<?php echo esc_url($action); ?>" method="get"
                style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="action" value="pcoe_cart_to_draft">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="dest" value="orders">
                <input type="text" name="title" placeholder="<?php echo esc_attr__('Draft title (optional)', 'pc-order-import-export'); ?>"
                    style="min-width:260px">
                <button class="button" type="submit"
                        onclick="this.disabled=true;this.innerText='<?php echo esc_js(__('Saving…', 'pc-order-import-export')); ?>';this.form.submit();">
                    <?php echo esc_html__('Save to draft', 'pc-order-import-export'); ?>
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /** Подключение всех UI-хуков */
    public static function init(): void
    {
       
        add_action('woocommerce_after_cart', [self::class, 'render_cart_block'], 5);
   
        add_action('woocommerce_before_account_orders', [self::class, 'render_account_import_block'], 5);

        // CART (блочный редактор): fallback — дописываем в конец контента
        add_filter('the_content', [self::class, 'maybe_append_to_cart_block'], 20);

        // ORDER: кнопки экспорта под таблицей заказа
        add_action('woocommerce_order_details_after_order_table', [self::class, 'render_order_block']);

        add_action('woocommerce_cart_is_empty', [self::class, 'render_cart_empty_block'], 20);

        // Скрипты
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_js']);

        add_filter('woocommerce_my_account_my_orders_columns', function($cols){
            // вставимо після колонки "Order"
            $new = [];
            foreach ($cols as $key=>$label){
                $new[$key] = $label;
                if ($key === 'order-number') {
                    $new['pc_draft_title'] = esc_html__('Title', 'pc-order-import-export');
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
                if ($order->has_status('pc-draft')) echo '<em style="opacity:.7">' . esc_html__('(untitled)', 'pc-order-import-export') . '</em>';
            }
        });
    }

    /** Алиас на случай, если где-то вызывается Ui::hooks() */
    public static function hooks(): void { self::init(); }

    public static function render_cart_empty_block(): void {
        // показываем только импорт и «в чернетку»
        echo self::render_import_html('cart');
        echo self::render_cart_to_draft_html();
    }
    /* ===================== PUBLIC RENDERERS ===================== */


/** Блок імпорту + кнопка «В чернетку» на сторінці "Мої замовлення" */
public static function render_account_import_block(): void
{
    $nonce_draft      = wp_create_nonce('pcoe_import_draft');      // для імпорту файлу у чернетку
    $nonce_cart_draft = wp_create_nonce('pcoe_cart_to_draft');      // для збереження кошика у чернетку
    $ajax             = admin_url('admin-ajax.php');
    ?>
    <div class="pcoe-import" style="margin:16px 0; padding:12px; border:1px solid #eee; border-radius:8px">

      <!-- Імпорт у чернетку замовлення -->
      <details open>
        <summary><strong><?php echo esc_html__('Import into draft order', 'pc-order-import-export'); ?></strong> <?php echo esc_html__('(CSV / XLSX)', 'pc-order-import-export'); ?> </summary>

        <form id="pcoe-import-draft-form" enctype="multipart/form-data" method="post" onsubmit="return false;"
              style="margin-top:12px; display:flex; gap:12px; align-items:center; flex-wrap:wrap">
          <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_draft); ?>">
          <input type="file" name="file" accept=".csv,.xlsx,.xls" required>
          <input type="text" name="title" placeholder="<?php echo esc_attr__('Draft title (optional)', 'pc-order-import-export'); ?>"  style="min-width:260px">
          <button type="submit" class="button"><?php echo esc_html__('Import to draft', 'pc-order-import-export'); ?> </button>
          <span class="pcoe-import-draft-msg" style="margin-left:8px; opacity:.8"></span>
        </form>

        <div class="pcoe-import-draft-result" style="margin-top:10px; display:none">
          <div class="pcoe-import-draft-links" style="margin:6px 0"></div>
          <div class="pcoe-import-draft-report"></div>
        </div>

        <div style="font-size:12px; opacity:.8; margin-top:10px">
          <?php
                printf(
                esc_html__('Format: %1$s or %2$s. Localized column names are supported (SKU, Qty…).', 'pc-order-import-export'),
                '<code>sku;qty</code>',
                '<code>gtin;qty</code>'
                );
          ?>
        </div>
      </details>

      <!-- Зберегти поточний кошик у чернетку -->
      <details style="margin-top:14px">
        <summary><strong><?php echo esc_html__('Save current cart to draft', 'pc-order-import-export'); ?></strong> </summary>

        <form action="<?php echo esc_url($ajax); ?>" method="post"
              style="margin-top:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap">
          <input type="hidden" name="action" value="pcoe_cart_to_draft">
          <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_cart_draft); ?>">
          <input type="hidden" name="clear" value="1">
          <input type="hidden" name="dest" value="orders"><!-- після збереження → My account / Orders -->
          <input type="text" name="title" placeholder="<?php echo esc_attr__('Draft title (optional)', 'pc-order-import-export'); ?>" style="min-width:260px">
          <button class="button" type="submit"> <?php echo esc_html__('Save to draft', 'pc-order-import-export'); ?> </button>
        </form>

        <div style="font-size:12px; opacity:.8; margin-top:8px">
          <?php echo esc_html__('Tip: add a meaningful name (e.g., "Template Chernivtsi") to easily find this draft later.', 'pc-order-import-export'); ?>
        </div>
      </details>

    </div>
    <?php
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
                    <a class="button" href="'.esc_url($url).'">'.esc_html__('Add to cart', 'pc-order-import-export').'</a>
                </p>';
        }
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
                 title="<?php echo esc_attr__('XLSX is unavailable on this server', 'pc-order-import-export'); ?>">
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
                <summary> <strong><?php echo esc_html__('Import', 'pc-order-import-export'); ?></strong> <?php echo esc_html__('(CSV / XLSX)', 'pc-order-import-export'); ?></summary>

                <!-- У кошик -->Формат CSV: <code>sku;qty</code> або <code>gtin;qty</code>
                <div style="margin-top:12px">
                  <form id="pcoe-import-form" enctype="multipart/form-data" method="post" onsubmit="return false;"
                        style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_cart); ?>">
                      <input type="file" name="file" accept=".csv,.xlsx,.xls" required>
                      <button type="submit" class="button"><?php echo esc_html__('Import to cart', 'pc-order-import-export'); ?></button>
                      <span class="pcoe-import-msg" style="margin-left:8px; opacity:.8"></span>
                  </form>
                </div>

                <div style="font-size:12px; opacity:.8; margin-top:12px">
                    <?php
                        echo wp_kses_post( sprintf(
                        /* translators: %1$s and %2$s are code examples like sku;qty, gtin;qty */
                        esc_html__('CSV format: %1$s or %2$s. Delimiter ";" or ",". Decimals: dot or comma; thousand separators are ignored.', 'pc-order-import-export'),
                        '<code>sku;qty</code>',
                        '<code>gtin;qty</code>'
                        ) );
                    ?>
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
       $i18n = [
        'importing'     => __('Importing…', 'pc-order-import-export'),
        'added'         => __('Added', 'pc-order-import-export'),
        'skipped'       => __('Skipped', 'pc-order-import-export'),
        'import_error'  => __('Import error.', 'pc-order-import-export'),
        'conn_error'    => __('Connection error.', 'pc-order-import-export'),
        'open_in_admin' => __('Open in admin', 'pc-order-import-export'),
        'view_order'    => __('View order', 'pc-order-import-export'),
        ];
        wp_add_inline_script('jquery', self::js_code($i18n));
    }

    protected static function js_code(array $i18n): string
    {
        $ajax = esc_js(admin_url('admin-ajax.php'));
        $i = array_map('esc_js', $i18n);
        return <<<JS
            jQuery(function($){
            var I18N = <?php echo wp_json_encode($i, JSON_UNESCAPED_UNICODE); ?>;
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

            // === Імпорт у кошик (safe)
                $(document).on('submit','#pcoe-import-form',function(e){
                e.preventDefault();
                var \$f=$(this), \$msg=\$f.find('.pcoe-import-msg');
                var fd=new FormData(this); fd.append('action','pcoe_import_cart');
                \$msg.text(I18N.importing);
                $.ajax({
                    url:'{$ajax}', method:'POST', data:fd, contentType:false, processData:false
                }).done(function(resp){
                    if(resp && resp.success){
                    \$msg.text(I18N.added+': '+resp.data.added+', '+I18N.skipped+': '+resp.data.skipped);
                    if(resp.data.report_html){
                        if(!$('.pcoe-import-report').length){
                        $('<div class="pcoe-import-report" style="margin-top:10px"></div>').insertAfter(\$f.closest('div'));
                        }
                        $('.pcoe-import-report').html(resp.data.report_html);
                    }
                    setTimeout(function(){ window.location.reload(); }, 1200);
                    }else{
                    \$msg.text((resp && resp.data && resp.data.msg) ? resp.data.msg : I18N.import_error);
                    }
                }).fail(function(){
                    \$msg.text(I18N.conn_error);
                });
                return false;
                });

            // === Імпорт у чернетку
            $(document).on('submit','#pcoe-import-draft-form',function(){
                var \$f=$(this), \$msg=\$f.find('.pcoe-import-draft-msg');
                var \$box=$('.pcoe-import-draft-result');
                \$msg.text(I18N.importing); \$box.hide();

                var fd=new FormData(this); fd.append('action','pcoe_import_order_draft');

                $.ajax({
                url:'{$ajax}', method:'POST', data:fd, contentType:false, processData:false,
                success:function(resp){
                    if(resp && resp.success){
                        // показати короткий підсумок
                        \$msg.text(I18N.imported+': '+resp.data.imported+', '+I18N.skipped+': '+resp.data.skipped);

                        // (опційно) миттєво показати кнопки
                        var linksHtml='';
                        if(resp.data.links){
                            if(resp.data.links.edit){ linksHtml += '<a class="button" href="'+resp.data.links.edit+'" target="_blank" rel="noopener">'+I18N.open_in_admin+'</a> '; }
                            if(resp.data.links.view){ linksHtml += '<a class="button" href="'+resp.data.links.view+'" target="_blank" rel="noopener">'+I18N.view_order+'</a>'; }
                        }
                        \$('.pcoe-import-draft-links').html(linksHtml||'');
                        \$('.pcoe-import-draft-report').html(resp.data.report_html||'');
                        \$box.show();

                        // ↓ головне: перезавантажити список замовлень, щоб з'явився новий чернетка
                        setTimeout(function(){ window.location.reload(); }, 800);
                    } else {
                        \$msg.text((resp && resp.data && resp.data.msg) ? resp.data.msg : I18N.import_error);
                    }
                },
                error:function(){ $msg.text(I18N.conn_error);
                });
                return false;
            });
        });
        JS;
    }
}