<?php
if (!defined('ABSPATH')) exit;

// Data-only XLSX reader. Never evaluate Excel formulas, macros or external links.
function lps_sp_xml(string $data): SimpleXMLElement {
    if (stripos($data, '<!DOCTYPE') !== false || stripos($data, '<!ENTITY') !== false) throw new RuntimeException('UNSAFE_XML');
    $old = libxml_use_internal_errors(true);
    try { $xml = simplexml_load_string($data, SimpleXMLElement::class, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    if (!$xml) throw new RuntimeException('INVALID_XLSX');
    return $xml;
}

function lps_sp_xlsx(string $path, ?string $sheet = null): array {
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) throw new RuntimeException('XLSX_SUPPORT_REQUIRED');
    if (filesize($path) > 8 * 1024 * 1024) throw new RuntimeException('FILE_TOO_LARGE');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('INVALID_XLSX');
    try {
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); $size += $stat['size'];
            if ($size > 64 * 1024 * 1024 || $zip->numFiles > 2000) throw new RuntimeException('FILE_TOO_LARGE');
            if (preg_match('~vbaProject|externalLinks/~i', $stat['name'])) throw new RuntimeException('EXTERNAL_CONTENT');
        }
        $read = static function ($name) use ($zip) {
            $value = $zip->getFromName($name);
            if ($value === false) throw new RuntimeException('INVALID_XLSX');
            return lps_sp_xml($value);
        };
        $rels = [];
        foreach ($read('xl/_rels/workbook.xml.rels')->xpath('/*[local-name()="Relationships"]/*[local-name()="Relationship"]') as $rel) {
            if ((string)$rel['TargetMode'] === 'External') throw new RuntimeException('EXTERNAL_CONTENT');
            $target = (string)$rel['Target'];
            if (strpos($target, '..') !== false) throw new RuntimeException('INVALID_XLSX');
            $rels[(string)$rel['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        }
        $sheets = [];
        foreach ($read('xl/workbook.xml')->sheets->sheet as $node) {
            $id = (string)$node->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $sheets[(string)$node['name']] = $rels[$id] ?? '';
        }
        if ($sheet === null) return ['sheets' => array_keys($sheets)];
        if (!isset($sheets[$sheet])) throw new RuntimeException('SHEET_NOT_FOUND');
        $strings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            foreach ($read('xl/sharedStrings.xml')->si as $si) {
                $text = ''; foreach ($si->xpath('.//*[local-name()="t"]') as $t) $text .= (string)$t;
                $strings[] = $text;
            }
        }
        $rows = []; $cells = 0; $shared = [];
        foreach ($read($sheets[$sheet])->sheetData->row as $row) {
            $number = (int)$row['r'];
            if ($number > 15000) throw new RuntimeException('TOO_MANY_ROWS');
            foreach ($row->c as $cell) {
                if (++$cells > 300000) throw new RuntimeException('FILE_TOO_LARGE');
                preg_match('/^([A-Z]+)[0-9]+$/', (string)$cell['r'], $m);
                if (!$m) throw new RuntimeException('INVALID_XLSX');
                $type = (string)$cell['t']; $value = (string)$cell->v;
                if ($type === 's') $value = $strings[(int)$value] ?? '';
                if ($type === 'inlineStr') {
                    $value = ''; foreach ($cell->is->xpath('.//*[local-name()="t"]') as $t) $value .= (string)$t;
                }
                $formula = isset($cell->f) ? (string)$cell->f : null;
                $sharedId = isset($cell->f) && (string)$cell->f['t']==='shared' ? (string)$cell->f['si'] : null;
                if ($sharedId!==null && $formula!=='') $shared[$sharedId]=['formula'=>$formula,'row'=>$number,'column'=>$m[1]];
                $rows[$number][$m[1]] = ['value' => $value, 'type' => $type, 'formula' => $formula, 'sharedId'=>$sharedId];
            }
        }
        foreach ($rows as $number=>&$row) foreach ($row as $col=>&$cell) {
            if ($cell['sharedId']!==null && $cell['formula']==='' && isset($shared[$cell['sharedId']])) {
                $master=$shared[$cell['sharedId']]; $cell['formulaRaw']='';
                // Only expand same-column shared formulas here; other formulas remain for review.
                if ($master['column']===$col) $cell['formula']=preg_replace_callback('/(\$?[A-Z]+)(\$?)([0-9]+)/',
                    static fn($m)=>$m[1].$m[2].($m[2]!==''?$m[3]:(string)((int)$m[3]+$number-$master['row'])), $master['formula']);
            }
        }
        unset($row,$cell);
        return ['sheets' => array_keys($sheets), 'rows' => $rows];
    } finally { $zip->close(); }
}

function lps_sp_gtin($value): ?string {
    $s = trim((string)$value);
    if (!preg_match('/^(?:[0-9]{8}|[0-9]{12,14})$/D', $s)) return null;
    $sum = 0; $weight = 3;
    for ($i = strlen($s) - 2; $i >= 0; $i--) { $sum += (int)$s[$i] * $weight; $weight = 4 - $weight; }
    if ((10 - $sum % 10) % 10 !== (int)substr($s, -1)) return null;
    return str_pad($s, 14, '0', STR_PAD_LEFT);
}

function lps_sp_decimal($value): ?string {
    $s = trim((string)$value);
    if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $s) || (float)$s > 1000000000) return null;
    return rtrim(rtrim(sprintf('%.6F', (float)$s), '0'), '.') ?: '0';
}

function lps_sp_config(array $input, array $sheets): array {
    $config = [];
    $config['sheet'] = (string)($input['sheet'] ?? '');
    if (!in_array($config['sheet'], $sheets, true)) throw new RuntimeException('SHEET_NOT_FOUND');
    $config['header'] = (int)($input['header'] ?? 7);
    if ($config['header'] < 1 || $config['header'] > 100) throw new RuntimeException('INVALID_SETTINGS');
    foreach (['article'=>'A','description'=>'C','gtin'=>'J','pack'=>'H','listPrice'=>'D','discount'=>'E','price'=>'F','net'=>'I'] as $key=>$default) {
        $v = strtoupper(trim((string)($input[$key] ?? $default)));
        if (!preg_match('/^[A-Z]{1,2}$/D', $v)) throw new RuntimeException('INVALID_SETTINGS');
        $config[$key] = $v;
    }
    $config['currency'] = strtoupper(trim((string)($input['currency'] ?? 'EUR')));
    if (!in_array($config['currency'], ['EUR','USD','UAH','GBP','PLN','CHF'], true)) throw new RuntimeException('INVALID_SETTINGS');
    $config['priceBasis'] = ($input['priceBasis'] ?? '') === 'PACK' ? 'PACK' : 'UNIT';
    $config['full'] = !empty($input['full']);
    $config['netFallback'] = !empty($input['netFallback']);
    $config['validFrom'] = (string)($input['validFrom'] ?? '');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $config['validFrom']);
    if (!$date || $date->format('Y-m-d') !== $config['validFrom']) throw new RuntimeException('INVALID_SETTINGS');
    return $config;
}

// Presence is separate from price validity: #N/A cannot discontinue a product.
function lps_sp_preview(array $rows, array $config, array $catalog): array {
    $index = []; $skuGtins = []; $offers = []; $seen = []; $blockers = [];
    foreach ($catalog as $card) {
        $sku = (string)$card['sku']; $gtin = lps_sp_gtin($card['gtin']);
        $skuGtins[$sku][$gtin ?? 'INVALID'] = true;
        if ($gtin) $index[$gtin][$sku] = true;
    }
    $presence = []; 
    foreach ($rows as $number => $raw) {
        if ($number <= $config['header']) continue;
        $values = [];
        foreach (['article','description','gtin','pack','listPrice','discount','price','net'] as $field) $values[$field] = trim((string)($raw[$config[$field]]['value'] ?? ''));
        if ($values['article'] === '' && $values['gtin'] === '') continue;
        $gtin = lps_sp_gtin($values['gtin']); $issues = [];
        if (!$gtin) { $issues[] = 'INVALID_GTIN'; $blockers[] = 'INVALID_GTIN'; }
        if ($values['article'] === '') $issues[] = 'MISSING_ARTICLE';
        $sku = $gtin && count($index[$gtin] ?? []) === 1 ? array_key_first($index[$gtin]) : null;
        if ($gtin) $presence[$gtin] = true;
        if (!$sku) $issues[] = count($index[$gtin] ?? []) > 1 ? 'AMBIGUOUS_GTIN' : 'UNMATCHED_GTIN';
        $pack = lps_sp_decimal($values['pack']);
        if ($pack === null || (float)$pack <= 0) $issues[] = 'INVALID_PACK';
        $price = lps_sp_decimal($values['price']); $priceSource = 'INVOICE';
        $priceCell = $raw[$config['price']] ?? [];
        if (($priceCell['type'] ?? '') === 'e') $price = null;
        $formula = $priceCell['formula'] ?? null;
        if ($formula !== null) {
            $expected = $config['listPrice'] . $number . '*(1-' . $config['discount'] . $number . ')';
            $normalized = str_replace(['$',' ','='], '', strtoupper($formula));
            $normalized = ltrim($normalized, '+');
            $list = lps_sp_decimal($values['listPrice']); $discount = lps_sp_decimal($values['discount']);
            if ($normalized !== $expected || $list === null || $discount === null || (float)$discount > 1
                || $price === null || abs((float)$price - (float)$list * (1-(float)$discount)) > 0.000001) {
                $price = null; $issues[] = 'FORMULA_REVIEW';
            }
        }
        if ($price === null && $values['price'] === '' && $formula === null && $config['netFallback'] && strcasecmp($values['net'], 'Netto') === 0) {
            $price = lps_sp_decimal($values['listPrice']); $priceSource = 'NET_LIST';
        }
        if ($price === null) $issues[] = 'INVALID_PRICE';
        $key = hash('sha256', json_encode([$values['article'],$gtin,$pack]));
        if (isset($seen[$key])) {
            $issues[] = 'DUPLICATE_VARIANT'; $offers[$seen[$key]]['issues'][] = 'DUPLICATE_VARIANT';
        }
        $seen[$key] = count($offers);
        $offers[] = ['row'=>(int)$number,'article'=>$values['article'],'description'=>$values['description'],
            'gtin'=>$gtin,'originalGtin'=>$values['gtin'],'sku'=>$sku,'pack'=>$pack,'price'=>$price,
            'currency'=>$config['currency'],'validFrom'=>$config['validFrom'],'priceBasis'=>$config['priceBasis'],'priceSource'=>$priceSource,
            'listPrice'=>$values['listPrice'],'discount'=>$values['discount'],'net'=>$values['net'],'issues'=>$issues,'raw'=>$raw];
    }
    if (!$offers) throw new RuntimeException('NO_ROWS');
    $statuses = [];
    foreach ($skuGtins as $sku=>$gtins) {
        $status = 'NOT_IN_PARTIAL';
        $valid = count($gtins) === 1 && !isset($gtins['INVALID']);
        $gtin = $valid ? array_key_first($gtins) : null;
        if (!$valid || count($index[$gtin] ?? []) !== 1) $status = 'REVIEW';
        elseif (isset($presence[$gtin])) $status = 'PRESENT';
        elseif ($config['full']) $status = $blockers ? 'REVIEW' : 'DISCONTINUED';
        $statuses[$sku] = $status;
    }
    return ['offers'=>$offers,'statuses'=>$statuses,'blockers'=>array_values(array_unique($blockers)),
        'counts'=>['rows'=>count($offers),'matched'=>count(array_filter($offers,static fn($r)=>$r['sku'] !== null)),
        'review'=>count(array_filter($offers,static fn($r)=>!empty($r['issues']))),
        'discontinued'=>count(array_filter($statuses,static fn($s)=>$s==='DISCONTINUED'))]];
}
