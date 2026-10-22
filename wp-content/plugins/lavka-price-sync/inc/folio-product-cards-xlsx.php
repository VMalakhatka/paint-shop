<?php
if (!defined('ABSPATH')) exit;

function lps_card_xml(string $text): string {
    $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
    if (mb_strlen($text) > 32767) throw new RuntimeException('CARD_READ_FAILED');
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function lps_card_letter(int $n): string {
    $letter='';
    while ($n > 0) { $n--; $letter=chr(65+$n%26).$letter; $n=intdiv($n,26); }
    return $letter;
}

/** Stream worksheet XML to private temp storage, avoiding millions of cell objects. */
function lps_card_xlsx(array $data): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('CARD_READ_FAILED');
    $xmlPath = tempnam(get_temp_dir(), 'folio-card-sheet-');
    $zipPath = tempnam(get_temp_dir(), 'folio-card-xlsx-');
    if (!$xmlPath || !$zipPath) {
        if ($xmlPath) unlink($xmlPath);
        if ($zipPath) unlink($zipPath);
        throw new RuntimeException('CARD_READ_FAILED');
    }
    $stream = null; $zip = new ZipArchive(); $opened = false; $done = false;
    try {
        $stream = fopen($xmlPath, 'wb');
        if (!$stream) throw new RuntimeException('CARD_READ_FAILED');
        $write = static function(string $s) use ($stream): void {
            if (fwrite($stream, $s) !== strlen($s)) throw new RuntimeException('CARD_READ_FAILED');
        };
        $write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane xSplit="3" ySplit="1" topLeftCell="D2" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><cols>');
        for ($i=1;$i<=57;$i++) $write('<col min="'.$i.'" max="'.$i.'" width="'.(in_array($i,[4,5,54],true)?48:20).'" customWidth="1"/>');
        $write('</cols><sheetData><row r="1" ht="90" customHeight="1">');
        foreach (lps_card_export_headers() as $i=>$label) $write('<c r="'.lps_card_letter($i+1).'1" s="1" t="inlineStr"><is><t xml:space="preserve">'.lps_card_xml($label).'</t></is></c>');
        $write('</row>');
        foreach ($data['data']['rows'] as $n=>$row) {
            $r=$n+2; $write('<row r="'.$r.'">');
            foreach ($row as $i=>$value) {
                if ($value === null) continue;
                $ref=lps_card_letter($i+1).$r;
                $numeric=$data['data']['columns'][$i]['type']==='number' && $i>2;
                // Excel supports at most 15 significant decimal digits. Preserve longer values as text.
                $digits=ltrim(str_replace(['-','.'],'',$value),'0');
                if ($numeric && strlen($digits)<=15) $write('<c r="'.$ref.'"><v>'.lps_card_xml($value).'</v></c>');
                else $write('<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.lps_card_xml($value).'</t></is></c>');
            }
            $write('</row>');
        }
        $write('</sheetData><autoFilter ref="A1:BE'.(count($data['data']['rows'])+1).'"/></worksheet>');
        fclose($stream); $stream=null;
        if ($zip->open($zipPath, ZipArchive::OVERWRITE)!==true) throw new RuntimeException('CARD_READ_FAILED');
        $opened=true;
        $parts=[
            '[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="НК" sheetId="1" r:id="rId1"/><sheet name="Report" sheetId="2" r:id="rId2"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml'=>'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDDEBF7"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0" fillId="0" borderId="0" xfId="0"/><xf fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        ];
        $report=[
            [__('Generated at', 'lavka-price-sync'), gmdate('c')],
            [__('Source database', 'lavka-price-sync'), $data['sourceDatabase']],
            [__('Query', 'lavka-price-sync'), $data['mode']],
            [__('Rows returned:', 'lavka-price-sync'), (string)count($data['data']['rows'])],
            [__('Compare both queries', 'lavka-price-sync'), $data['identical']===true?'MATCH':($data['identical']===false?'DIFFERENT':'NOT_RUN')],
            [__('Requested SKUs not returned:', 'lavka-price-sync'), (string)count($data['missingSkus'])],
        ];
        foreach ($data['missingSkus'] as $sku) $report[] = ['', $sku];
        $meta='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="35" customWidth="1"/><col min="2" max="2" width="90" customWidth="1"/></cols><sheetData>';
        foreach ($report as $n=>$row) { $r=$n+1; $meta.='<row r="'.$r.'">'; foreach ($row as $i=>$value) $meta.='<c r="'.lps_card_letter($i+1).$r.'" t="inlineStr"><is><t>'.lps_card_xml($value).'</t></is></c>'; $meta.='</row>'; }
        $parts['xl/worksheets/sheet2.xml']=$meta.'</sheetData></worksheet>';
        foreach ($parts as $name=>$part) if (!$zip->addFromString($name,$part)) throw new RuntimeException('CARD_READ_FAILED');
        if (!$zip->addFile($xmlPath,'xl/worksheets/sheet1.xml')) throw new RuntimeException('CARD_READ_FAILED');
        if (!$zip->close()) throw new RuntimeException('CARD_READ_FAILED');
        $opened=false; $done=true;
        return $zipPath;
    } finally {
        if (is_resource($stream)) fclose($stream);
        if ($opened) $zip->close();
        unlink($xmlPath);
        if (!$done && is_file($zipPath)) unlink($zipPath);
    }
}

/** Keep all template positions; assembly values are unavailable from these queries. */
function lps_card_export_headers(): array {
    return array_merge(lps_card_headers(), ["Артиул\nРебенка", "Колличество\nДетей из Родителя", "Если связь неадекватная,\nперечесление всех родителей и\nколичестов их необходимое для 1-ого ребенка"]);
}
