<?php
namespace PaintCore\PCOE;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

defined('ABSPATH') || exit;

/** Grouped mailing workbook; receives the same visible products and customer prices as PriceList. */
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

    /** Read packaging in bounded batches; never substitute volume, dimensions or stock. */
    private static function packaging(array $skus): array {
        $error=__('Could not load pack quantities from Folio. Check the Java service update and API token, then prepare the mailing again.','pc-order-import-export');
        if(!function_exists('lps_java_post'))throw new \RuntimeException($error);
        $packing=[];
        foreach(array_chunk(array_values(array_unique($skus)),500) as $batch){
            $response=lps_java_post('/admin/folio/product-packaging',['skus'=>$batch],['timeout'=>25]);
            if(is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200)throw new \RuntimeException($error);
            $items=json_decode(wp_remote_retrieve_body($response),true);
            if(!is_array($items) || !array_is_list($items) || count($items)>count($batch))throw new \RuntimeException($error);
            $seen=[];
            foreach($items as $item){
                if(!is_array($item) || !isset($item['sku']) || !in_array($item['sku'],$batch,true) || isset($seen[$item['sku']]) || !array_key_exists('unitsPerPack',$item))throw new \RuntimeException($error);
                $seen[$item['sku']]=true;$value=$item['unitsPerPack'];
                if($value===null)continue;
                if(!is_numeric($value) || !is_finite((float)$value))throw new \RuntimeException($error);
                if((float)$value>0)$packing[$item['sku']]=(float)$value;
            }
        }
        return $packing;
    }

    public static function workbook(array $entries, string $quantity): array {
        $book=new Spreadsheet();$book->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $book->getProperties()->setCreator(get_bloginfo('name'))->setTitle(self::format_label('offer'));
        $sheet=$book->getActiveSheet();$sheet->setTitle('Lavka');
        $header=1;
        $sheet->getHeaderFooter()->setOddHeader('&L'.self::format_label('offer').'&R'.wp_date('Y-m-d H:i'));
        $sheet->fromArray([__('SKU','pc-order-import-export'),__('GTIN','pc-order-import-export'),__('Name','pc-order-import-export'),__('Description','pc-order-import-export'),sprintf(__('Your price, %s','pc-order-import-export'),get_woocommerce_currency()),__('Order quantity','pc-order-import-export'),__('Photo','pc-order-import-export'),sprintf(__('Retail price, %s','pc-order-import-export'),get_woocommerce_currency()),__('Unit of measure','pc-order-import-export'),__('Units per pack','pc-order-import-export'),__('Available stock: Kyiv + Odesa','pc-order-import-export'),__('Group','pc-order-import-export'),__('Subgroup','pc-order-import-export')],null,'A'.$header);
        $sheet->getStyle('A'.$header.':M'.$header)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>'800000']],'alignment'=>['wrapText'=>true,'vertical'=>'center']]);
        $sheet->getRowDimension($header)->setRowHeight(48);
        $rows=array_values(array_filter($entries,static fn($e)=>isset($e['values'])));
        $packing=self::packaging(array_column(array_column($rows,'values'),0));
        $row=$header;$photos=0;$bytes=0;$deadline=microtime(true)+25;
        $headings=[];$categoryPath=[];
        foreach($entries as $entry){
            if(isset($entry['heading'])){
                $row++;$headings[$row]=(int)$entry['depth'];
                $categoryPath=array_slice($categoryPath,0,(int)$entry['depth']);
                $categoryPath[]=(string)$entry['heading'];
                $sheet->mergeCells('C'.$row.':M'.$row);
                $sheet->setCellValueExplicit('C'.$row,$entry['heading'],DataType::TYPE_STRING);
                $sheet->getRowDimension($row)->setOutlineLevel(min(7,(int)$entry['depth']));
                continue;
            }
            $v=$entry['values'];$product=wc_get_product($entry['product_id']);$row++;
            $sheet->getRowDimension($row)->setOutlineLevel(min(7,(int)$entry['depth']));
            $sheet->setCellValueExplicit('L'.$row,$categoryPath[0]??'',DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('M'.$row,implode(' / ',array_slice($categoryPath,1)),DataType::TYPE_STRING);
            $description=$product?($product->get_short_description()?:$product->get_description()):'';
            if(!$description && $product && $product->get_parent_id()){
                $parent=wc_get_product($product->get_parent_id());$description=$parent?($parent->get_short_description()?:$parent->get_description()):'';
            }
            $description=html_entity_decode(wp_strip_all_tags(strip_shortcodes(preg_replace('/<\/(p|div|li)>|<br\s*\/?>/i',"\n",$description))),ENT_QUOTES|ENT_HTML5,'UTF-8');
            $description=mb_substr(trim($description),0,32767);
            foreach([$v[0],$v[1],$v[2],$description] as $col=>$value)$sheet->setCellValueExplicit([$col+1,$row],$value,DataType::TYPE_STRING);
            if($v[5]!==null)$sheet->setCellValue('E'.$row,$v[5]);
            // Order quantity F is intentionally blank, including legacy quantity presets.
            if($v[6]!==null)$sheet->setCellValue('K'.$row,$v[6]);
            if($product){
                $retail=$product->get_regular_price('edit');
                if($retail!=='')$sheet->setCellValue('H'.$row,wc_get_price_to_display($product,['price'=>(float)$retail]));
                $unit=trim((string)$product->get_meta('_edin_izmer'));
                if($unit==='')$unit=$product->get_attribute('pa_edin_izmer');
                if($unit==='' && $product->get_parent_id()){
                    $parent=wc_get_product($product->get_parent_id());
                    if($parent)$unit=trim((string)$parent->get_meta('_edin_izmer'))?:$parent->get_attribute('pa_edin_izmer');
                }
                $sheet->setCellValueExplicit('I'.$row,$unit,DataType::TYPE_STRING);
                if(isset($packing[$v[0]]))$sheet->setCellValue('J'.$row,$packing[$v[0]]);
            }
            $sheet->getCell('C'.$row)->getHyperlink()->setUrl($v[8]);
            $sheet->getRowDimension($row)->setRowHeight(min(409,max(84,(ceil(mb_strlen($description)/60)+substr_count($description,"\n"))*14)));
            $image=$product?self::photo($product,$deadline,$bytes):null;
            if($image){
                $drawing=new MemoryDrawing();$drawing->setImageResource($image)->setRenderingFunction(MemoryDrawing::RENDERING_JPEG)->setMimeType(MemoryDrawing::MIMETYPE_JPEG)->setCoordinates('G'.$row)->setOffsetX(5)->setOffsetY(5)->setWorksheet($sheet);$photos++;
            }else{
                $sheet->setCellValueExplicit('G'.$row,__('View product','pc-order-import-export'),DataType::TYPE_STRING);$sheet->getCell('G'.$row)->getHyperlink()->setUrl($v[8]);
            }
        }
        foreach(['A'=>23,'B'=>20,'C'=>44,'D'=>64,'E'=>19,'F'=>18,'G'=>25,'H'=>19,'I'=>18,'J'=>18,'K'=>23,'L'=>28,'M'=>42] as $col=>$width)$sheet->getColumnDimension($col)->setWidth($width);
        if($row>$header){
            $start=$header+1;$sheet->getStyle('A'.$start.':M'.$row)->getAlignment()->setVertical('center')->setWrapText(true);
            $sheet->getStyle('E'.$start.':E'.$row)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('H'.$start.':H'.$row)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle('J'.$start.':J'.$row)->getNumberFormat()->setFormatCode('0.###');
            $sheet->getStyle('K'.$start.':K'.$row)->getNumberFormat()->setFormatCode('0.###');
            $sheet->getStyle('F'.$start.':F'.$row)->getNumberFormat()->setFormatCode('0.###');
            $sheet->getStyle('F'.$start.':F'.$row)->getFill()->setFillType('solid')->getStartColor()->setRGB('FFF2B3');
            $validation=$sheet->getCell('F'.$start)->getDataValidation();$validation->setType('decimal')->setOperator('greaterThanOrEqual')->setFormula1('0')->setAllowBlank(true)->setShowErrorMessage(true)->setErrorStyle('stop')->setError(__('Enter a quantity of zero or more.','pc-order-import-export'))->setSqref('F'.$start.':F'.$row);
        }
        foreach($headings as $number=>$depth){
            $sheet->getStyle('A'.$number.':M'.$number)->applyFromArray([
                'font'=>['bold'=>true,'size'=>$depth===0?14:11,'color'=>['rgb'=>$depth===0?'FFFFFF':'263A40']],
                'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$depth===0?'800000':($depth===1?'D9B3B3':'F2E6E6')]],
            ]);
            $sheet->getStyle('C'.$number)->getAlignment()->setIndent(min(7,$depth));
            $sheet->getRowDimension($number)->setRowHeight($depth===0?29:24);
        }
        $sheet->setAutoFilter('A'.$header.':M'.$row);$sheet->freezePane('D'.($header+1));
        $sheet->setShowSummaryBelow(false);
        // Leave SKU and order quantity empty so the footer cannot become an imported order line.
        $footer=$row+2;$sheet->mergeCells('C'.$footer.':M'.$footer);
        $sheet->setCellValueExplicit('C'.$footer,__('Need additional product information, another data format (XML or another format), or direct links to higher-resolution photos for your website? Contact us with your request and we will discuss what we can provide.','pc-order-import-export'),DataType::TYPE_STRING);
        $sheet->getStyle('C'.$footer.':M'.$footer)->applyFromArray(['font'=>['color'=>['rgb'=>'263A40']],
            'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>'F2E6E6']],
            'alignment'=>['wrapText'=>true,'vertical'=>'center']]);
        $sheet->getRowDimension($footer)->setRowHeight(55);
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd($header,$header);
        return ['book'=>$book,'photos'=>$photos];
    }
}
