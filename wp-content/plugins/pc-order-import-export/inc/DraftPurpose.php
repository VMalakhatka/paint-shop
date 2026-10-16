<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Manual document intent; never infer a shortage from non-accounting status. */
final class DraftPurpose {
    public const META = '_pcoe_draft_purpose';
    public static function normalize($value): string { return $value === 'preorder' ? 'preorder' : 'draft'; }
    public static function current(\WC_Order $order): string { return self::normalize($order->get_meta(self::META)); }
    public static function store(\WC_Order $order, array $request): void {
        if (isset($request['draft_purpose'])) $order->update_meta_data(self::META, self::normalize($request['draft_purpose']));
    }
    public static function label(string $purpose): string {
        return $purpose === 'preorder' ? __('Preorder invoice', 'pc-order-import-export') : __('Ordinary draft', 'pc-order-import-export');
    }
    public static function field(string $purpose = 'draft', bool $disabled = false): void {
        echo '<label>' . esc_html__('Save as', 'pc-order-import-export') . ' <select name="draft_purpose"' . ($disabled ? ' disabled' : '') . '>';
        foreach (['draft', 'preorder'] as $value) echo '<option value="' . $value . '"' . selected(self::normalize($purpose), $value, false) . '>' . esc_html(self::label($value)) . '</option>';
        echo '</select></label>';
    }
    public static function header(array $header, \WC_Order $order, bool $shortage = false): array {
        $preorder = !$shortage && self::current($order) === 'preorder';
        $header['accountingEnabled'] = false;
        if ($preorder) {
            $header['folioOperationKind'] = '*РАЗОВАЯ';
            // A newly confirmed preorder must not inherit the age of an old Woo draft.
            $header['documentDate'] = current_time('Y-m-d') . 'T00:00:00';
        }
        $header['sourceInfo'] = $shortage ? 'нет на складе' : ($preorder ? 'Предзаказ' : 'Черновик');
        // L_CP2_PLAT is the field used by FolioPreorderRule. Keep the marker first.
        $header['additionalInfo'] = ($shortage ? 'Нет на складе' : ($preorder ? 'ПРД' : 'Черновик')) . ' Int ' . $order->get_id();
        return $header;
    }
}
