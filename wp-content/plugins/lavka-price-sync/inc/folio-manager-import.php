<?php
if (!defined('ABSPATH')) exit;

function lps_manager_columns(string $operation): array {
    $columns = [
        'NAME'=>['COD_ARTIC','NAME_ARTIC'],
        'GROUPS'=>['CODE_ARTIC','NGROUP_TVR','NGROUP_TV2','NGROUP_TV3','NGROUP_TV4','NGROUP_TV5','NGROUP_TV6'],
        'UNIT'=>['COD_ARTIC','EDIN_IZMER'],
        'DESCRIPTION'=>['COD_ARTIC','DESCRIPTION'],
        'BASE'=>['ARTIC_BAZA'],
        'ASSEMBLY'=>['ARTIC_roditel','ARTIC_rebenok','KOL_rod_razob','kol_reb_pol','CENA_REB','SLOG'],
    ];
    if (!isset($columns[$operation])) throw new RuntimeException('OPERATION');
    return $columns[$operation];
}
function lps_manager_rows(array $rows, string $operation): array {
    $columns=lps_manager_columns($operation);$result=[];$seen=[];$first=true;
    $limits=['COD_ARTIC'=>20,'CODE_ARTIC'=>20,'ARTIC_BAZA'=>20,'ARTIC_roditel'=>20,'ARTIC_rebenok'=>20,'NAME_ARTIC'=>200,'EDIN_IZMER'=>10,'DESCRIPTION'=>5000,'KOL_rod_razob'=>64,'kol_reb_pol'=>64,'CENA_REB'=>64,'SLOG'=>1];
    foreach ($rows as $number=>$row) {
        $row=array_map(static fn($v)=>trim((string)$v),$row);
        if (!array_filter($row,static fn($v)=>$v!=='')) continue;
        if ($first) {
            $first=false;$row[0]=preg_replace('/^\xEF\xBB\xBF/','',$row[0]);
            if (array_map('strtoupper',$row)===array_map('strtoupper',$columns)) continue;
        }
        if (count($row)!==count($columns)) throw new RuntimeException('ROW_'.($number+1));
        $item=array_combine($columns,$row);
        foreach ($item as $field=>&$value) {
            $pattern=$field==='DESCRIPTION'?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u':'/[\x00-\x1f\x7f]/u';
            if (mb_strlen($value)>($limits[$field]??30)||preg_match($pattern,$value)) throw new RuntimeException('ROW_'.($number+1));
            if ($operation==='ASSEMBLY'&&in_array($field,['KOL_rod_razob','kol_reb_pol','CENA_REB'],true)&&$value!=='') {
                $value=str_replace(',','.',$value);
                if (!preg_match('/^[+-]?\d+(?:\.\d+)?$/D',$value)||!is_finite((float)$value)) throw new RuntimeException('ROW_'.($number+1));
            }
            if ($value==='') $value=null;
        } unset($value);
        if ($operation==='ASSEMBLY') {
            if (!in_array($item['SLOG'],['0','1'],true)) throw new RuntimeException('SLOG_REQUIRED');
        } elseif ($item[$columns[0]]===null) throw new RuntimeException('ARTICLE_REQUIRED');
        if (!in_array($operation,['BASE','ASSEMBLY'],true)) {
            $key=mb_strtolower($item[$columns[0]],'UTF-8');
            if (isset($seen[$key])) throw new RuntimeException('DUPLICATE_'.($number+1));$seen[$key]=true;
        }
        $result[]=$item;if(count($result)>500)throw new RuntimeException('TOO_MANY_ROWS');
    }
    if (!$result) throw new RuntimeException('EMPTY_FILE');
    return $result;
}
function lps_manager_text(string $text,string $operation): array {
    if (strlen($text)>5*1024*1024||!mb_check_encoding($text,'UTF-8'))throw new RuntimeException('CSV_UTF8');
    $candidates=[];
    foreach (["\t",';',','] as $delimiter) {
        $stream=fopen('php://temp','r+');fwrite($stream,$text);rewind($stream);$rows=[];
        try {
            while(($row=fgetcsv($stream,0,$delimiter,'"',''))!==false){$rows[]=$row;if(count($rows)>10000)throw new RuntimeException('TOO_MANY_ROWS');}
            $candidate=lps_manager_rows($rows,$operation);$candidates[json_encode($candidate)]=$candidate;
        } catch(RuntimeException $e) {} finally {fclose($stream);}
    }
    if(count($candidates)!==1)throw new RuntimeException('CSV_FORMAT');
    return reset($candidates);
}

function lps_manager_file(string $path, string $extension, string $operation): array {
    if (!is_file($path) || filesize($path) > 5 * 1024 * 1024) throw new RuntimeException('FILE_TOO_LARGE');
    if ($extension === 'csv') return lps_manager_text(file_get_contents($path), $operation);
    $width=count(lps_manager_columns($operation));
    if (!in_array($extension, ['xls','xlsx'], true) || !class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) throw new RuntimeException('EXCEL_FORMAT');
    if (file_get_contents($path, false, null, 0, 2) === 'PK') {
        $zip = new ZipArchive(); if ($zip->open($path) !== true) throw new RuntimeException('EXCEL_FORMAT');
        try {
            $size = 0;
            if ($zip->numFiles > 10000) throw new RuntimeException('FILE_TOO_LARGE');
            for ($i=0; $i<$zip->numFiles; $i++) {
                $entry = $zip->statIndex($i); if (!$entry) throw new RuntimeException('EXCEL_FORMAT');
                $size += $entry['size']; if ($size>64*1024*1024) throw new RuntimeException('FILE_TOO_LARGE');
            }
        } finally { $zip->close(); }
    }
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path, ['Xls','Xlsx']);
    $names = $reader->listWorksheetNames($path); if (!$names) throw new RuntimeException('EXCEL_FORMAT');
    $info = $reader->listWorksheetInfo($path);
    if (($info[0]['totalRows'] ?? 10001)>10000 || ($info[0]['totalColumns'] ?? 100)>$width) throw new RuntimeException('EXCEL_RANGE');
    $reader->setLoadSheetsOnly($names[0]); $book = $reader->load($path);
    try {
        $rows = []; $sheet = $book->getSheet(0);
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            if (in_array($cell->getDataType(), ['f','e'], true)) throw new RuntimeException('EXCEL_FORMULA');
            $r = $cell->getRow()-1; $c = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cell->getColumn())-1;
            if ($c >= $width || $r>9999) throw new RuntimeException('EXCEL_RANGE');
            if (!isset($rows[$r])) $rows[$r] = array_fill(0,$width,'');
            // Preserve article display masks; numbers use raw values (no currency/group formatting).
            $rows[$r][$c] = ($operation!=='ASSEMBLY' || $c<2) ? $cell->getFormattedValue() : $cell->getValue();
        }
        ksort($rows); return lps_manager_rows($rows,$operation);
    } finally { $book->disconnectWorksheets(); }
}
