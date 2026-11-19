<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Supplier YML: price is the owner's purchase price; never changes the Prom formatter. */
final class CrmCatalogFormat {
    /** @return array{xml: string, unpriced: int} */
    public static function part(array $data): array {
        $xml = '';
        $unpriced = 0;
        foreach ($data['rows'] as $row) {
            $price = PartnerCatalogFormats::priceText($row['cost'] ?? null);
            if ($price === null) {
                ++$unpriced;
            }
            $xml .= PartnerCatalogFormats::offerXml($row, $data['currency'], 'w', [
                'price' => $price,
                'purchase_price' => $price,
                'retail_price' => PartnerCatalogFormats::priceText($row['retail'] ?? null),
                'purchase_price_status' => $price === null ? 'unavailable' : null,
                'barcode' => $row['gtin'] !== '' ? $row['gtin'] : null,
                'url' => $row['url'] !== '' ? $row['url'] : null,
            ])."\n";
        }
        return ['xml' => $xml, 'unpriced' => $unpriced];
    }
    public static function header(array $snapshot): string {
        $e=[PartnerCatalogFormats::class,'xmlText'];
        $out='<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<yml_catalog date="'.$e(gmdate('Y-m-d H:i',$snapshot['at'])).'"><shop><name>'.$e(get_bloginfo('name')).'</name><company>'.$e(get_bloginfo('name')).'</company><url>'.$e(home_url('/')).'</url><currencies><currency id="'.$e($snapshot['currency']).'" rate="1"/></currencies><categories>';
        foreach($snapshot['categories'] as $id=>$c)$out.='<category id="'.(int)$id.'"'.($c['parent']?' parentId="'.(int)$c['parent'].'"':'').'>'.$e($c['name']).'</category>';
        return $out.'</categories><offers>';
    }
    public static function footer(): string {return '</offers></shop></yml_catalog>';}
}
