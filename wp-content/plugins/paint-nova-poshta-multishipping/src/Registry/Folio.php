<?php

namespace Paint\NovaPoshta\Registry;

defined('ABSPATH') || exit;

final class Folio
{
    public const TYPES = ['ACCOUNT','EXPENSE','RECEIPT','RETURN'];
    public const ROLES = ['sale_account','sale_expense','purchase','customer_return','supplier_return','other'];

    public function preview(array $input, array $shipment): array
    {
        $type=Record::text($input['type']??'',24); $role=Record::text($input['role']??'',24);
        $mode=Record::text($input['verification']??'',24);
        if (!in_array($type,self::TYPES,true) || !in_array($role,self::ROLES,true)
            || !in_array($mode,['api','manual'],true)) throw new \InvalidArgumentException('Invalid document selection.');
        $expected=['sale_account'=>'ACCOUNT','sale_expense'=>'EXPENSE','purchase'=>'RECEIPT','customer_return'=>'RETURN','supplier_return'=>'RETURN'];
        if (isset($expected[$role]) && $expected[$role]!==$type) throw new \InvalidArgumentException('Document type and role do not match.');
        if (($role==='purchase' || $role==='customer_return') && $shipment['direction']!=='incoming'
            || ($role==='sale_account' || $role==='sale_expense' || $role==='supplier_return') && $shipment['direction']!=='outgoing') {
            throw new \InvalidArgumentException('Classify shipment direction before linking this document.');
        }
        $doc=['type'=>$type,'role'=>$role,'id'=>Record::text($input['document_id']??'',32),
            'number'=>Record::text($input['number']??'',100),'date'=>Record::date((string)($input['date']??'')),
            'partner'=>Record::text($input['partner']??'',32),'warehouse'=>Record::text($input['warehouse']??'',32),
            'verification'=>$mode,'evidence'=>Record::text($input['evidence']??'',1000)];
        if ($role==='sale_expense') $doc['account_link_id']=(int)($input['account_link_id']??0);
        if (!$doc['number'] || !$doc['partner'] || $doc['partner']!==$shipment['partner_code']) {
            throw new \InvalidArgumentException('Document number and the shipment partner code must match your selection.');
        }
        if ($doc['id']!=='' && !preg_match('/^[1-9]\d{0,9}$/D',$doc['id'])) throw new \InvalidArgumentException('Use the internal Folio document ID, not its printed number.');
        if ($mode==='manual') {
            if (mb_strlen($doc['evidence'])<10) throw new \InvalidArgumentException('Explain the manual verification (at least 10 characters).');
            return $doc;
        }
        if ($doc['id']==='' || !in_array($type,['ACCOUNT','EXPENSE','RECEIPT'],true)) throw new \InvalidArgumentException('This document requires manual verification with evidence.');
        if ($type==='RECEIPT') {
            if (!preg_match('/^[1-9]\d{0,8}$/D',$doc['warehouse'])) throw new \InvalidArgumentException('Enter the Folio warehouse code.');
            // One bounded page immediately preceding the exact ID, never a broad supplier scan.
            $data=$this->get('/admin/folio/receipt-catalogue', ['warehouseId'=>$doc['warehouse'],'date'=>$doc['date'],
                'afterId'=>(int)$doc['id']-1,'documentType'=>'receipt']);
            if ((string)($data['warehouseId']??'')!==$doc['warehouse'] || ($data['date']??'')!==$doc['date']) throw new \RuntimeException('Folio returned a different document.');
            $found=null;
            foreach (($data['documents']??[]) as $candidate) if ((string)($candidate['id']??'')===$doc['id']) $found=$candidate;
            if (!$found || ($found['type']??'')!=='receipt' || ($found['accounted']??null)!==true
                || (string)($found['warehouseId']??'')!==$doc['warehouse']
                || self::date($found['date']??'')!==$doc['date'] || (string)($found['number']??'')!==$doc['number']) {
                throw new \RuntimeException('Folio returned a different document.');
            }
            if (mb_strlen($doc['evidence'])<10) throw new \InvalidArgumentException('Confirm the supplier manually: the receipt API does not return the supplier.');
            $doc['verification']='api_header_manual_party';
            $doc['api_snapshot']=Record::clean($found);
        } else {
            $data=$this->get('/admin/folio/customer-documents/'.$type.'/'.$doc['id'], ['partnerShortName'=>$doc['partner']]);
            $found=$data['document']??[];
            if (($data['partner']['shortName']??'')!==$doc['partner'] || (string)($found['documentId']??'')!==$doc['id']
                || ($found['documentType']??'')!==$type || self::date($found['documentDate']??'')!==$doc['date']
                || trim((string)($found['documentNumber']??'').(string)($found['documentNumberSuffix']??''))!==$doc['number']
                || ($found['returnDocument']??null)!==false
                || ($doc['warehouse']!=='' && (string)($found['warehouseId']??'')!==$doc['warehouse'])) throw new \RuntimeException('Folio returned a different document.');
            $doc['warehouse']=Record::text($found['warehouseId']??'',32);
            // Retain header evidence only; no products/quantities are needed for this association.
            $doc['api_snapshot']=Record::clean(array_intersect_key($found,array_flip([
                'documentType','documentId','documentNumber','documentNumberSuffix','documentDate',
                'warehouseId','accounted','returnDocument','correctedAt','source','basis',
            ])));
        }
        $doc['values']=DocumentValues::capture($doc,$found);
        if ($role==='sale_expense' && empty($doc['account_link_id'])) $doc=$this->basisAccount($doc);
        return $doc;
    }

    private function basisAccount(array $doc): array
    {
        $doc['basis']=$doc['api_snapshot']['basis']??'';
        $doc['basis_lookup_status']='UNAVAILABLE';
        try {
            $result=$this->get('/admin/folio/customer-documents/EXPENSE/'.$doc['id'].'/basis-account',['partnerShortName'=>$doc['partner']]);
            $status=$result['status']??'';
            if (($result['partnerShortName']??'')!==$doc['partner'] || (string)($result['expenseId']??'')!==$doc['id']
                || (string)($result['basis']??'')!==(string)$doc['basis']
                || !in_array($status,['MATCHED','NO_BASIS','UNRECOGNIZED_BASIS','NOT_FOUND','AMBIGUOUS','INCOMPLETE_REFERENCE','UNRECOGNIZED_REFERENCE','CONFLICTING_REFERENCE'],true)) return $doc;
            $doc['basis_lookup_status']=$status;
            if (in_array($result['referenceSource']??'',['HEADER_FIELDS','BASIS_TEXT'],true)) $doc['account_reference_source']=$result['referenceSource'];
            if (!empty($result['accountNumber'])) $doc['account_reference_number']=Record::text($result['accountNumber'],100);
            if (!empty($result['accountDate'])) $doc['account_reference_date']=self::date($result['accountDate']);
            if ($status!=='MATCHED') return $doc;
            $a=$result['account']??[];
            if (($a['documentType']??'')!=='ACCOUNT' || ($a['partnerShortName']??'')!==$doc['partner']
                || !preg_match('/^[1-9]\d{0,9}$/D',(string)($a['documentId']??''))
                || !in_array($a['source']??'',['ACTIVE_LEDGER','ARCHIVE_LEDGER'],true)) throw new \RuntimeException('Invalid basis account');
            $number=Record::text(trim((string)($a['documentNumber']??'').(string)($a['documentNumberSuffix']??'')),100);
            if ($number==='') throw new \RuntimeException('Invalid basis account');
            $doc['related_account']=['type'=>'ACCOUNT','role'=>'sale_account','id'=>(string)$a['documentId'],
                'number'=>$number,'date'=>self::date($a['documentDate']??''),'partner'=>$doc['partner'],
                'warehouse'=>Record::text($a['warehouseId']??'',32),'verification'=>'api_basis','evidence'=>'',
                'source'=>$a['source'],'api_snapshot'=>Record::clean($a)];
        } catch (\RuntimeException|\InvalidArgumentException $e) { $doc['basis_lookup_status']='UNAVAILABLE'; unset($doc['related_account']); }
        return $doc;
    }

    private function get(string $path,array $query): array
    {
        if (!function_exists('pc_folio_documents_java_get')) throw new \RuntimeException('Folio is unavailable. Use explicit manual verification if you have the document.');
        $result=pc_folio_documents_java_get($path,$query);
        if (is_wp_error($result) || !is_array($result) || ($result['ok']??null)!==true) throw new \RuntimeException('Folio is unavailable. Use explicit manual verification if you have the document.');
        return $result;
    }

    private static function date($value): string
    {
        if (is_array($value) && count($value)===3) $value=sprintf('%04d-%02d-%02d',...$value);
        return is_string($value) ? Record::date(substr($value,0,10)) : '';
    }
}
