<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

final class PartnerCatalogFormats {
    public static function xmlText($value): string {
        $value=preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',(string)$value);
        return htmlspecialchars($value??'',ENT_QUOTES|ENT_XML1,'UTF-8');
    }
    /** Stable IDs; retail only. No cost, supplier or customer metadata in Prom XML. */
    public static function yml(array $data): string {
        $e=[self::class,'xmlText'];$categories=$data['categories'];
        $xml='<?xml version="1.0" encoding="UTF-8"?>' . '\n<yml_catalog date="'.$e(substr(str_replace('T',' ',$data['at']),0,16)).'"><shop><name>'.$e(get_bloginfo('name')).'</name><company>'.$e(get_bloginfo('name')).'</company><url>'.$e(home_url('/')).'</url><currencies><currency id="'.$e($data['currency']).'" rate="1"/></currencies><categories>';
        foreach($categories as $id=>$c)$xml.='<category id="'.$id.'"'.($c['parent']?' parentId="'.$c['parent'].'"':'').'>'.$e($c['name']).'</category>';
        $xml.='</categories><offers>';
        foreach($data['rows'] as $r){
            $price = self::priceText($r['retail']);
            if ($price === null) {
                throw new \RuntimeException(__('A product has no retail price. Contact the manager before exporting to Prom.','pc-order-import-export'));
            }
            $xml .= self::offerXml($r, $data['currency'], 'r', ['price' => $price]);
        }
        return str_replace('\\n',"\n",$xml.'</offers></shop></yml_catalog>');
    }

    public static function priceText($value): ?string {
        if (!is_numeric($value) || !is_finite((float)$value) || round((float)$value, 2) < 0.01 || $value > 9999999999) {
            return null;
        }
        return number_format((float)$value, 2, '.', '');
    }

    /** Callers supply format-specific XML fields, with null for omitted values. */
    public static function offerXml(array $row, string $currency, string $sellingType, array $fields): string {
        $escape = [self::class, 'xmlText'];
        $available = is_numeric($row['stock']) && $row['stock'] > 0;
        $description = $row['description'] ?: $row['short_description'];
        $elements = [
            'name' => $row['name'],
            'name_ua' => $row['name'],
            'vendorCode' => $row['sku'],
        ] + $fields + [
            'currencyId' => $currency,
            'categoryId' => $row['category_id'],
            'description' => $description,
            'description_ua' => $description,
        ];

        $xml = '<offer id="'.(int)$row['id'].'" available="'.($available ? 'true' : 'false').'" selling_type="'.$escape($sellingType).'">';
        foreach ($elements as $tag => $value) {
            if ($value !== null) {
                $xml .= '<'.$tag.'>'.$escape($value).'</'.$tag.'>';
            }
        }
        if (!empty($row['brand'])) {
            $xml .= '<vendor>'.$escape($row['brand']).'</vendor>';
        }
        if ($row['gtin'] !== '') {
            $xml .= '<param name="GTIN">'.$escape($row['gtin']).'</param>';
        }
        $xml .= '<quantity_in_stock>'.($available ? $escape($row['stock']) : '0').'</quantity_in_stock>';
        foreach (array_slice($row['images'], 0, 10) as $url) {
            $xml .= '<picture>'.$escape($url).'</picture>';
        }
        foreach ($row['attributes'] as $name => $value) {
            $xml .= '<param name="'.$escape($name).'">'.$escape($value).'</param>';
        }
        if (!empty($row['unit'])) {
            $xml .= '<param name="Unit">'.$escape($row['unit']).'</param>';
        }
        if (!empty($row['pack'])) {
            $xml .= '<param name="Units per pack">'.$escape($row['pack']).'</param>';
        }
        return $xml.'</offer>';
    }
    public static function cells(array $r,bool $woo=false): array {
        if($woo && (!is_numeric($r['retail']) || !is_finite((float)$r['retail']) || round((float)$r['retail'],2)<0.01 || $r['retail']>9999999999))throw new \RuntimeException(__('A product has no retail price. Contact the manager before exporting to Prom.','pc-order-import-export'));
        if($woo)return ['simple',$r['sku'],$r['name'],1,$r['short_description'],$r['description'],$r['retail'],($r['stock']??0)>0?1:0,$r['stock']??0,str_replace(' / ',' > ',$r['category']),implode(', ',$r['images'])];
        return [$r['sku'],$r['gtin'],$r['name'],$r['brand']??'',$r['short_description'],$r['description'],$r['category'],$r['unit'],$r['pack']??null,$r['cost'],$r['retail'],$r['stock'],implode(' | ',$r['images']),$r['url'],wp_json_encode($r['attributes'],JSON_UNESCAPED_UNICODE)];
    }
    public static function headers(bool $woo=false): array {
        return $woo?['Type','SKU','Name','Published','Short description','Description','Regular price','In stock?','Stock','Categories','Images']:
            ['SKU','GTIN','Name','Brand','Short description','Description','Category path','Unit','Units per pack','Your price','Retail price','Stock','Images','Product URL','Attributes'];
    }
    public static function csvSafe($value) {return is_string($value) && preg_match('/^[\s]*[=+@-]/u',$value)?"'".$value:$value;}
    public static function csv(array $data,bool $woo=false): string {
        $f=fopen('php://temp','w+');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,self::headers($woo),',','"','');
        foreach($data['rows'] as $r)fputcsv($f,array_map([self::class,'csvSafe'],self::cells($r,$woo)),',','"','');
        rewind($f);$bytes=stream_get_contents($f);fclose($f);return $bytes;
    }
    public static function xlsx(array $data): string {
        $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$s=$book->getActiveSheet();$s->fromArray(self::headers());$line=1;
        foreach($data['rows'] as $r){$line++;foreach(self::cells($r) as $i=>$v)$s->setCellValueExplicit([$i+1,$line],$v??'',is_int($v)||is_float($v)?'n':'s');}
        $s->freezePane('A2');$s->setAutoFilter('A1:O'.$line);$s->getStyle('A1:O1')->getFont()->setBold(true);
        $path=BroadcastStore::temporary_file();try{(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);return file_get_contents($path);}finally{$book->disconnectWorksheets();@unlink($path);}
    }
}
