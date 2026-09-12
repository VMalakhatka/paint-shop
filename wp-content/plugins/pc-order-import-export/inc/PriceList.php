<?php
namespace PaintCore\PCOE;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

defined('ABSPATH') || exit;

/** Private, current-customer catalogue. No Folio calls or order writes. */
class PriceList
{
    public static function can_access(): bool
    {
        return is_user_logged_in() && function_exists('pc_wholesale_customer_can_access')
            && pc_wholesale_customer_can_access();
    }

    public static function render(): void
    {
        if (!self::can_access() || !class_exists(Spreadsheet::class)) return;
        $url = add_query_arg(['action' => 'pcoe_price_list', '_wpnonce' => wp_create_nonce('pcoe_price_list')], admin_url('admin-ajax.php'));
        ?>
        <div class="pcoe-price-list">
            <button type="button" class="button pcoe-price-list-download" data-url="<?php echo esc_url($url); ?>"
                data-pending="<?php echo esc_attr__('Preparing the full price list…', 'pc-order-import-export'); ?>"
                data-error="<?php echo esc_attr__('Could not download the price list. Please try again.', 'pc-order-import-export'); ?>">
                <?php esc_html_e('Download full price list (XLSX)', 'pc-order-import-export'); ?>
            </button>
            <a href="<?php echo esc_url(is_cart() ? wc_get_account_endpoint_url('orders') : wc_get_cart_url()); ?>">
                <?php echo esc_html(is_cart() ? __('Import to draft', 'pc-order-import-export') : __('Import to cart', 'pc-order-import-export')); ?>
            </a>
            <span class="pcoe-price-list-status" role="status" aria-live="polite"></span>
        </div>
        <?php
    }

    /** Resolve selling locations by Folio codes, never by editable warehouse names. */
    public static function location_ids(): array
    {
        $ids = [];
        $covered = [];
        $mapping = function_exists('lavka_get_locations_mapping_for_java') ? lavka_get_locations_mapping_for_java() : [];
        foreach ($mapping as $location) {
            if (array_intersect(['1', '5'], array_map('strval', $location['codes'] ?? []))) {
                $ids[] = (int) $location['id'];
                $covered = array_merge($covered, array_intersect(['1', '5'], array_map('strval', $location['codes'])));
            }
        }
        return count(array_unique($covered)) === 2 ? array_values(array_unique($ids)) : [];
    }

    public static function stock(\WC_Product $product, array $locations): ?float
    {
        if (!$locations) return null;
        $total = 0.0;
        foreach ($locations as $location) {
            $raw = get_post_meta($product->get_id(), '_stock_at_' . $location, true);
            if ($raw === '' && $product->is_type('variation') && $product->get_manage_stock() === 'parent') {
                $raw = get_post_meta($product->get_parent_id(), '_stock_at_' . $location, true);
            }
            if ($raw === '' || !is_numeric($raw)) return null;
            $total += max(0, (float) $raw);
        }
        return $total;
    }

    public static function eligible(\WC_Product $product): bool
    {
        if (!in_array($product->get_type(), ['simple', 'variation'], true)
            || $product->get_status() !== 'publish' || $product->get_sku() === '') return false;
        $parent = $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;
        return $parent && $parent->get_status() === 'publish'
            && in_array($parent->get_catalog_visibility(), ['visible', 'catalog'], true);
    }

    /** Keyset pagination includes the whole public catalogue, including zero stock. */
    public static function products(): \Generator
    {
        global $wpdb;
        $after = 0;
        do {
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish'
                 AND post_type IN ('product', 'product_variation') ORDER BY ID LIMIT 250", $after
            )));
            if (!$ids) break;
            _prime_post_caches($ids, true, true);
            foreach ($ids as $id) {
                $after = $id;
                $product = wc_get_product($id);
                if ($product && self::eligible($product)) yield $product;
            }
            // Bound product-meta memory without evicting the shared persistent cache.
            if (wp_cache_supports('flush_runtime')) wp_cache_flush_runtime();
        } while (count($ids) === 250);
    }

    public static function headers(): array
    {
        $role = wp_get_current_user()->roles[0] ?? '';
        $role_name = wp_roles()->roles[$role]['name'] ?? $role;
        return [
            __('SKU', 'pc-order-import-export'), __('GTIN', 'pc-order-import-export'),
            __('Name', 'pc-order-import-export'), __('Supplier', 'pc-order-import-export'),
            __('Category', 'pc-order-import-export'),
            sprintf(__('Your price (%1$s), %2$s', 'pc-order-import-export'), translate_user_role($role_name), get_woocommerce_currency()),
            __('Stock: Kyiv + Odesa', 'pc-order-import-export'),
            __('Order quantity', 'pc-order-import-export'), __('Product information', 'pc-order-import-export'),
        ];
    }

    public static function workbook(iterable $products, array $locations): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $book->getProperties()->setCreator(get_bloginfo('name'))->setTitle(__('Full price list', 'pc-order-import-export'));
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Lavka');
        $sheet->fromArray(self::headers(), null, 'A1');
        $row = 1;
        foreach ($products as $product) {
            $row++;
            $parent_id = $product->get_parent_id() ?: $product->get_id();
            $names = static function (string $taxonomy) use ($parent_id): string {
                $terms = get_the_terms($parent_id, $taxonomy);
                return !$terms || is_wp_error($terms) ? '' : implode(', ', wp_list_pluck($terms, 'name'));
            };
            $barcode = function_exists('psu_product_display_barcode') ? psu_product_display_barcode($product) : '';
            $values = [$product->get_sku(), $barcode, $product->get_name(), $names('product_brand'), $names('product_cat')];
            foreach ($values as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $row], $value, DataType::TYPE_STRING);
            }
            $price = $product->get_price();
            if ($price !== '' && is_numeric($price)) {
                $sheet->setCellValue('F' . $row, wc_get_price_to_display($product, ['price' => (float) $price]));
            }
            $stock = self::stock($product, $locations);
            if ($stock !== null) $sheet->setCellValue('G' . $row, $stock);
            $url = get_permalink($parent_id);
            if ($product->is_type('variation')) $url = $product->get_permalink();
            $sheet->setCellValueExplicit('I' . $row, __('View product', 'pc-order-import-export'), DataType::TYPE_STRING);
            $sheet->getCell('I' . $row)->getHyperlink()->setUrl($url);
        }
        $sheet->freezePane('D2');
        $sheet->setAutoFilter('A1:I' . $row);
        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '27634F']],
            'alignment' => ['wrapText' => true, 'vertical' => 'center'],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(38);
        foreach (['A'=>22, 'B'=>20, 'C'=>65, 'D'=>23, 'E'=>35, 'F'=>23, 'G'=>23, 'H'=>18, 'I'=>23] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        if ($row > 1) {
            $sheet->getStyle('A2:I' . $row)->getAlignment()->setVertical('center');
            $sheet->getStyle('C2:E' . $row)->getAlignment()->setWrapText(true);
            $sheet->getStyle('F2:F' . $row)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('G2:H' . $row)->getNumberFormat()->setFormatCode('0.###');
            $sheet->getStyle('H2:H' . $row)->getFill()->setFillType('solid')->getStartColor()->setRGB('FFF2B3');
            $sheet->getStyle('I2:I' . $row)->getFont()->getColor()->setRGB('1766A0');
            $validation = $sheet->getCell('H2')->getDataValidation();
            $validation->setType('decimal')->setOperator('greaterThanOrEqual')->setFormula1('0')
                ->setAllowBlank(true)->setShowErrorMessage(true)->setErrorStyle('stop')
                ->setError(__('Enter a quantity of zero or more.', 'pc-order-import-export'))->setSqref('H2:H' . $row);
        }
        $sheet->getHeaderFooter()->setOddHeader('&L' . get_bloginfo('name') . '&R' . wp_date('Y-m-d H:i'));
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
        return $book;
    }

    public static function handle(): void
    {
        if (!self::can_access()) wp_send_json_error(['msg' => __('Access denied.', 'pc-order-import-export')], 403);
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'pcoe_price_list')) {
            wp_send_json_error(['msg' => __('Security check failed (bad nonce).', 'pc-order-import-export')], 403);
        }
        $path = null;
        try {
            if (!class_exists(Spreadsheet::class)) throw new \RuntimeException('XLSX unavailable');
            $locations = self::location_ids();
            if (!$locations) throw new \RuntimeException('Selling locations are not mapped');
            wp_raise_memory_limit('admin');
            wc_set_time_limit(180);
            $path = wp_tempnam('lavka-price-list-');
            if (!$path) throw new \RuntimeException('Temporary file unavailable');
            register_shutdown_function(static function () use ($path) { if (file_exists($path)) @unlink($path); });
            $book = self::workbook(self::products(), $locations);
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $book->disconnectWorksheets();
            nocache_headers();
            header('Cache-Control: private, no-store, max-age=0');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="lavka-price-list-' . wp_date('Y-m-d') . '.xlsx"');
            header('X-Content-Type-Options: nosniff');
            readfile($path);
        } catch (\Throwable $e) {
            if ($path) @unlink($path);
            error_log('PCOE price list: ' . $e->getMessage());
            wp_send_json_error(['msg' => __('Could not download the price list. Please try again.', 'pc-order-import-export')], 500);
        } finally {
            if ($path && file_exists($path)) @unlink($path);
        }
        exit;
    }
}
