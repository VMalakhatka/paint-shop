<?php
namespace Paint\NovaPoshta\Registry;
defined('ABSPATH') || exit;

/** Immutable value evidence captured with a link, never an allocation to this parcel. */
final class DocumentValues
{
    public static function capture(array $doc,array $header): array
    {
        $values=['schema_version'=>1,'captured_at_utc'=>gmdate('Y-m-d H:i:s'),'currency'=>'UAH',
            'document_amount'=>self::money($header['totalAmount']??null),'shipment_amount'=>null,'accounting_amount'=>null,
            'line_count'=>null,'missing_amount_lines'=>null,'missing_accounting_lines'=>null,'source'=>'folio_header','status'=>'values_unavailable'];
        if (!function_exists('pc_folio_documents_java_get')) return $values;
        $result=pc_folio_documents_java_get('/admin/folio/customer-documents/'.$doc['type'].'/'.$doc['id'].'/values',['partnerShortName'=>$doc['partner']]);
        if (is_wp_error($result) || !is_array($result) || ($result['ok']??null)!==true) return $values;
        $v=$result['values']??[];
        if (!is_array($v) || ($v['documentType']??'')!==$doc['type'] || (string)($v['documentId']??'')!==$doc['id']
            || ($v['partnerShortName']??'')!==$doc['partner'] || ($v['currency']??'')!=='UAH' || ($v['source']??'')!=='ACTIVE_LEDGER'
            || trim((string)($v['documentNumber']??'').(string)($v['documentNumberSuffix']??''))!==$doc['number']
            || substr((string)($v['documentDate']??''),0,10)!==$doc['date'] || (string)($v['warehouseId']??'')!==$doc['warehouse']) return $values;
        $document=self::money($v['documentAmount']??null);
        // Two reads must agree on the already verified header amount, if present.
        if ($values['document_amount']!==null && $document!==$values['document_amount']) return $values;
        foreach (['lineCount','missingAmountLines','missingAccountingLines'] as $key) {
            if (!isset($v[$key]) || !is_int($v[$key]) || $v[$key]<0) return $values;
        }
        if ($v['missingAmountLines']>$v['lineCount'] || $v['missingAccountingLines']>$v['lineCount']) return $values;
        $values['document_amount']=$document;
        $values['shipment_amount']=$v['lineCount']>0 && !$v['missingAmountLines']?self::money($v['shipmentAmount']??null):null;
        $values['accounting_amount']=$v['lineCount']>0 && !$v['missingAccountingLines']?self::money($v['accountingAmount']??null):null;
        $values['line_count']=$v['lineCount']; $values['missing_amount_lines']=$v['missingAmountLines']; $values['missing_accounting_lines']=$v['missingAccountingLines'];
        $values['source']='folio_document_values'; $values['status']='values_captured';
        return $values;
    }

    public static function money($value): ?string
    {
        if (!is_scalar($value) || is_bool($value)) return null;
        $value=(string)$value;
        if (!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/D',$value,$m)) return null;
        $whole=ltrim($m[2],'0')?:'0'; $fraction=str_pad($m[3]??'',2,'0');
        return ($m[1] && ($whole!=='0'||$fraction!=='00')?'-':'').$whole.'.'.$fraction;
    }
}
