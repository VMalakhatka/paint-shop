<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Supplier YML: price is the owner's purchase price; never changes the Prom formatter. */
final class CrmCatalogFormat {
    public static function part(array $data): string {
        foreach($data['rows'] as &$r){
            if(!is_numeric($r['cost']) || !is_finite((float)$r['cost']) || round((float)$r['cost'],2)<0.01 || $r['cost']>9999999999)
                throw new \RuntimeException(__('A product has no purchase price. Ask the manager to check prices before enabling the CRM feed.','pc-order-import-export'),400);
            $r['retail']=$r['cost'];
        }unset($r);
        $doc=new \DOMDocument();
        if(!$doc->loadXML(PartnerCatalogFormats::yml($data),LIBXML_NONET))throw new \RuntimeException('Invalid CRM XML');
        $out='';
        foreach($doc->getElementsByTagName('offer') as $i=>$offer){
            $offer->setAttribute('selling_type','w');
            foreach(['purchase_price'=>number_format($data['rows'][$i]['cost'],2,'.',''),'barcode'=>$data['rows'][$i]['gtin'],'url'=>$data['rows'][$i]['url']] as $tag=>$v){
                if($v==='')continue;$node=$doc->createElement($tag);$node->appendChild($doc->createTextNode($v));$offer->appendChild($node);
            }
            $out.=$doc->saveXML($offer)."\n";
        }
        return $out;
    }
    public static function header(array $snapshot): string {
        $e=[PartnerCatalogFormats::class,'xmlText'];
        $out='<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<yml_catalog date="'.$e(gmdate('Y-m-d H:i',$snapshot['at'])).'"><shop><name>'.$e(get_bloginfo('name')).'</name><company>'.$e(get_bloginfo('name')).'</company><url>'.$e(home_url('/')).'</url><currencies><currency id="'.$e($snapshot['currency']).'" rate="1"/></currencies><categories>';
        foreach($snapshot['categories'] as $id=>$c)$out.='<category id="'.(int)$id.'"'.($c['parent']?' parentId="'.(int)$c['parent'].'"':'').'>'.$e($c['name']).'</category>';
        return $out.'</categories><offers>';
    }
    public static function footer(): string {return '</offers></shop></yml_catalog>';}
}
