<?php
namespace PaintCore\PCOE;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

defined('ABSPATH') || exit;

/** Flat mailing workbook; receives the same visible products and customer prices as PriceList. */
final class CommercialOffer {
    public static function options(array $input): array {
        $format=(string)($input['format']??'price');
        $quantity=(string)($input['quantity']??'one');
        if(!in_array($format,['price','offer'],true) || !in_array($quantity,['one','stock'],true))
            throw new \RuntimeException(__('Select a file format and quantity option.','pc-order-import-export'));
        return ['format'=>$format,'quantity'=>$format==='offer'?$quantity:'one'];
    }

    public static function format_label(string $format): string {
        return $format==='offer'?__('Commercial offer','pc-order-import-export'):__('Standard price list','pc-order-import-export');
    }
    public static function quantity_label(string $quantity): string {
        return $quantity==='stock'?__('Available stock: Kyiv + Odesa','pc-order-import-export'):__('One unit per product','pc-order-import-export');
    }

    /** Best-effort thumbnail, with bounded network, image size and memory. Never changes media. */
    private static function photo(\WC_Product $product, float $deadline, int &$bytes) {
        if(microtime(true)>=$deadline || $bytes>=3*1024*1024 || !function_exists('imagecreatefromstring'))return null;
        $id=$product->get_image_id();
        if(!$id && $product->get_parent_id()){
            $parent=wc_get_product($product->get_parent_id());$id=$parent?$parent->get_image_id():0;
        }
        $url=$id?wp_get_attachment_image_url($id,'thumbnail'):false;
        if(!$url)return null;
        $response=wp_safe_remote_get($url,['timeout'=>min(3,max(0.1,$deadline-microtime(true))),'redirection'=>2,'limit_response_size'=>524288]);
        if(is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200)return null;
        $body=wp_remote_retrieve_body($response);$size=@getimagesizefromstring($body);
        if(!$size || $size[0]*$size[1]>4000000 || !in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_GIF,IMAGETYPE_WEBP],true))return null;
        $source=@imagecreatefromstring($body);if(!$source)return null;
        $scale=min(160/$size[0],100/$size[1],1);$width=max(1,(int)round($size[0]*$scale));$height=max(1,(int)round($size[1]*$scale));
        $image=imagecreatetruecolor($width,$height);imagefill($image,0,0,imagecolorallocate($image,255,255,255));
        imagecopyresampled($image,$source,0,0,0,0,$width,$height,$size[0],$size[1]);imagedestroy($source);
        ob_start();imagejpeg($image,null,80);$bytes+=strlen(ob_get_clean());
        return $image;
    }

    public static function workbook(array $entries, string $quantity): array {
        $book=new Spreadsheet();$book->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $book->getProperties()->setCreator(get_bloginfo('name'))->setTitle(self::format_label('offer'));
        $sheet=$book->getActiveSheet();$sheet->setTitle('Lavka');
        $header=1;
        $sheet->getHeaderFooter()->setOddHeader('&L'.self::format_label('offer').'&R'.wp_date('Y-m-d H:i'));
        $sheet->fromArray([__('SKU','pc-order-import-export'),__('GTIN','pc-order-import-export'),__('Name','pc-order-import-export'),__('Description','pc-order-import-export'),sprintf(__('Your price, %s','pc-order-import-export'),get_woocommerce_currency()),__('Order quantity','pc-order-import-export'),__('Photo','pc-order-import-export')],null,'A'.$header);
        $sheet->getStyle('A'.$header.':G'.$header)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>'AD5943']],'alignment'=>['wrapText'=>true,'vertical'=>'center']]);
        $sheet->getRowDimension($header)->setRowHeight(32);
        $rows=array_values(array_filter($entries,static fn($e)=>isset($e['values'])));
        usort($rows,static fn($a,$b)=>strnatcasecmp($a['values'][0],$b['values'][0]));
        $row=$header;$photos=0;$bytes=0;$deadline=microtime(true)+25;
        foreach($rows as $entry){
            $v=$entry['values'];$product=wc_get_product($entry['product_id']);$row++;
            $description=$product?($product->get_short_description()?:$product->get_description()):'';
            if(!$description && $product && $product->get_parent_id()){
                $parent=wc_get_product($product->get_parent_id());$description=$parent?($parent->get_short_description()?:$parent->get_description()):'';
            }
            $description=html_entity_decode(wp_strip_all_tags(strip_shortcodes(preg_replace('/<\/(p|div|li)>|<br\s*\/?>/i',"\n",$description))),ENT_QUOTES|ENT_HTML5,'UTF-8');
            $description=mb_substr(trim($description),0,32767);
            foreach([$v[0],$v[1],$v[2],$description] as $col=>$value)$sheet->setCellValueExplicit([$col+1,$row],$value,DataType::TYPE_STRING);
            if($v[5]!==null)$sheet->setCellValue('E'.$row,$v[5]);
            if($quantity==='one' || $v[6]!==null)$sheet->setCellValue('F'.$row,$quantity==='one'?1:$v[6]);
            $sheet->getCell('C'.$row)->getHyperlink()->setUrl($v[8]);
            $sheet->getRowDimension($row)->setRowHeight(min(409,max(84,(ceil(mb_strlen($description)/60)+substr_count($description,"\n"))*14)));
            $image=$product?self::photo($product,$deadline,$bytes):null;
            if($image){
                $drawing=new MemoryDrawing();$drawing->setImageResource($image)->setRenderingFunction(MemoryDrawing::RENDERING_JPEG)->setMimeType(MemoryDrawing::MIMETYPE_JPEG)->setCoordinates('G'.$row)->setOffsetX(5)->setOffsetY(5)->setWorksheet($sheet);$photos++;
            }else{
                $sheet->setCellValueExplicit('G'.$row,__('View product','pc-order-import-export'),DataType::TYPE_STRING);$sheet->getCell('G'.$row)->getHyperlink()->setUrl($v[8]);
            }
        }
        foreach(['A'=>23,'B'=>20,'C'=>44,'D'=>64,'E'=>19,'F'=>18,'G'=>25] as $col=>$width)$sheet->getColumnDimension($col)->setWidth($width);
        if($row>$header){
            $start=$header+1;$sheet->getStyle('A'.$start.':G'.$row)->getAlignment()->setVertical('center')->setWrapText(true);
            $sheet->getStyle('E'.$start.':E'.$row)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('F'.$start.':F'.$row)->getNumberFormat()->setFormatCode('0.###');
            $sheet->getStyle('F'.$start.':F'.$row)->getFill()->setFillType('solid')->getStartColor()->setRGB('FFF2B3');
            $validation=$sheet->getCell('F'.$start)->getDataValidation();$validation->setType('decimal')->setOperator('greaterThanOrEqual')->setFormula1('0')->setAllowBlank(true)->setShowErrorMessage(true)->setErrorStyle('stop')->setError(__('Enter a quantity of zero or more.','pc-order-import-export'))->setSqref('F'.$start.':F'.$row);
        }
        $sheet->setAutoFilter('A'.$header.':G'.$row);$sheet->freezePane('D'.($header+1));
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd($header,$header);
        return ['book'=>$book,'photos'=>$photos];
    }
}
