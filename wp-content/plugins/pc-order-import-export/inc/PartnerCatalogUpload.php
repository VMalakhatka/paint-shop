<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;
final class PartnerCatalogUpload {
    public static function read(array $file): string {
        if(($file['error']??1)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size']>5*1024*1024)throw new \RuntimeException(__('Upload a CSV or XLSX file up to 5 MB.','pc-order-import-export'));
        $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));$rows=[];
        if($ext==='csv'){$f=fopen($file['tmp_name'],'r');$first=fgets($f);rewind($f);$delimiter=',';$columns=1;foreach([',',';',"\t"] as $candidate){$n=count(str_getcsv((string)$first,$candidate,'"',''));if($n>$columns){$columns=$n;$delimiter=$candidate;}}try{while(($r=fgetcsv($f,0,$delimiter,'"',''))!==false){$rows[]=trim(preg_replace('/^\xEF\xBB\xBF/','',(string)($r[0]??'')));if(count($rows)>501)throw new \RuntimeException(__('Use up to 500 SKUs, one per line.','pc-order-import-export'));}}finally{fclose($f);}}
        elseif($ext==='xlsx'){
            $z=new \ZipArchive();if($z->open($file['tmp_name'])!==true)throw new \RuntimeException(__('Invalid XLSX file.','pc-order-import-export'));
            try{$size=0;for($i=0;$i<$z->numFiles;$i++){$size+=$z->statIndex($i)['size'];if($size>20*1024*1024)throw new \RuntimeException(__('The workbook is too large. Use a single SKU column.','pc-order-import-export'));}}finally{$z->close();}
            $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();$reader->setReadDataOnly(true);
            $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {public function readCell($columnAddress,$row,$worksheetName=''): bool {return $columnAddress==='A' && $row<=502;}});
            $info=$reader->listWorksheetInfo($file['tmp_name']);if(($info[0]['totalRows']??0)>501)throw new \RuntimeException(__('Use up to 500 SKUs, one per line.','pc-order-import-export'));
            $sheets=$reader->listWorksheetNames($file['tmp_name']);$reader->setLoadSheetsOnly($sheets[0]);$book=$reader->load($file['tmp_name']);
            try{foreach($book->getActiveSheet()->getRowIterator(1,min(502,$book->getActiveSheet()->getHighestDataRow())) as $r){$v=$book->getActiveSheet()->getCell('A'.$r->getRowIndex())->getValue();if(is_string($v)&&str_starts_with($v,'='))throw new \RuntimeException(__('Use SKU values, not formulas.','pc-order-import-export'));$rows[]=trim((string)$v);}}finally{$book->disconnectWorksheets();}
        }else throw new \RuntimeException(__('Upload a CSV or XLSX file up to 5 MB.','pc-order-import-export'));
        if(in_array(mb_strtolower($rows[0]??''),['sku','артикул','артикль'],true))array_shift($rows);
        return implode("\n",PartnerCatalogData::skus(implode("\n",$rows)));
    }
}
