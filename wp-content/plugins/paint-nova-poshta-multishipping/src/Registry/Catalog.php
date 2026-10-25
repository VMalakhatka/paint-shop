<?php

namespace Paint\NovaPoshta\Registry;

defined('ABSPATH') || exit;

/** Read-only selectors over the existing partner, warehouse and document APIs. */
final class Catalog
{
    public function partners(array $input): array
    {
        $q=Record::text($input['q']??'',100);
        if (mb_strlen($q)<2) return ['items'=>[]];
        $data=$this->get('/admin/folio/partners',['q'=>$q,'types'=>'all','limit'=>20,'offset'=>0]);
        if (!is_array($data['items']??null)) throw new \RuntimeException('Folio returned an invalid selection list.');
        $items=[];
        foreach (array_slice($data['items'],0,20) as $row) {
            $code=Record::text($row['shortName']??$row['id']??'',32);
            if ($code!=='') $items[]=['code'=>$code,'name'=>Record::text($row['name']??'',250)];
        }
        return ['items'=>$items];
    }

    public function warehouses(): array
    {
        $data=$this->get('/ref/warehouses',[]);
        if (!array_is_list($data)) throw new \RuntimeException('Folio returned an invalid selection list.');
        $items=[];
        foreach ($data as $row) if (preg_match('/^[1-9]\d{0,8}$/D',(string)($row['code']??''))) {
            $items[]=['code'=>(string)$row['code'],'name'=>Record::text($row['name']??'',250)];
        }
        return ['items'=>$items];
    }

    public function documents(array $input,array $shipment): array
    {
        $type=(string)($input['type']??''); $warehouse=(string)($input['warehouse']??'');
        if (!in_array($type,['ACCOUNT','EXPENSE','RECEIPT'],true)) throw new \InvalidArgumentException('This document requires manual verification with evidence.');
        if (!preg_match('/^[1-9]\d{0,8}$/D',$warehouse)) throw new \InvalidArgumentException('Select a Folio warehouse.');
        if (!$shipment['partner_code']) throw new \InvalidArgumentException('Select and save the shipment partner first.');
        $from=Record::date((string)($input['date_from']??'')); $to=Record::date((string)($input['date_to']??''));
        if ($from>$to || $to>current_time('Y-m-d') || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days>365) {
            throw new \InvalidArgumentException('Select a valid period of up to 366 days.');
        }
        $cursor=(string)($input['cursor']??'');
        if (strlen($cursor)>2048) throw new \InvalidArgumentException('Invalid document page cursor.');
        if ($type==='RECEIPT') return $this->receipts($warehouse,$from,$to,$cursor);
        $query=['partnerShortName'=>$shipment['partner_code'],'dateFrom'=>$from,'dateTo'=>$to,'types'=>$type,'limit'=>50];
        if ($cursor!=='') $query['cursor']=$cursor;
        $data=$this->get('/admin/folio/customer-documents',$query,true);
        if (($data['partner']['shortName']??'')!==$shipment['partner_code'] || !is_array($data['documents']??null)) {
            throw new \RuntimeException('Folio returned a different document.');
        }
        $items=[];
        foreach ($data['documents'] as $row) {
            if (($row['documentType']??'')!==$type || ($row['returnDocument']??null)!==false || (string)($row['warehouseId']??'')!==$warehouse) continue;
            $date=substr((string)($row['documentDate']??''),0,10);
            if ($date<$from || $date>$to) throw new \RuntimeException('Folio returned a different document.');
            $items[]=$this->document($row['documentId']??'',trim((string)($row['documentNumber']??'').(string)($row['documentNumberSuffix']??'')),$date,$warehouse,$row['totalAmount']??null);
        }
        $next=!empty($data['hasMore']) ? (string)($data['nextCursor']??'') : '';
        if (!empty($data['hasMore']) && ($next==='' || $next===$cursor || strlen($next)>2048)) throw new \RuntimeException('Invalid document page cursor.');
        return ['items'=>$items,'cursor'=>$next,'receipt'=>false];
    }

    private function receipts(string $warehouse,string $from,string $to,string $cursor): array
    {
        $date=$from; $after=0;
        if ($cursor!=='') {
            $parts=explode('|',$cursor);
            if (count($parts)!==2 || !ctype_digit($parts[1]) || strlen($parts[1])>10) throw new \InvalidArgumentException('Invalid document page cursor.');
            $date=Record::date($parts[0]); $after=(int)$parts[1];
            if ($date<$from || $date>$to) throw new \InvalidArgumentException('Invalid document page cursor.');
        }
        $data=$this->get('/admin/folio/receipt-catalogue',['warehouseId'=>$warehouse,'date'=>$date,'afterId'=>$after,'documentType'=>'receipt'],true);
        if ((string)($data['warehouseId']??'')!==$warehouse || ($data['date']??'')!==$date || !is_array($data['documents']??null)) throw new \RuntimeException('Folio returned a different document.');
        $items=[];
        foreach ($data['documents'] as $row) {
            $day=$row['date']??'';
            if (is_array($day) && count($day)===3) $day=sprintf('%04d-%02d-%02d',...$day);
            if (($row['type']??'')!=='receipt' || ($row['accounted']??null)!==true || (string)($row['warehouseId']??'')!==$warehouse || $day!==$date) throw new \RuntimeException('Folio returned a different document.');
            $items[]=$this->document($row['id']??'',$row['number']??'',$date,$warehouse,null);
        }
        $next='';
        if (!empty($data['hasMore'])) {
            $nextId=(string)($data['nextAfterId']??'');
            if (!ctype_digit($nextId) || (int)$nextId<=$after) throw new \RuntimeException('Invalid document page cursor.');
            $next=$date.'|'.$nextId;
        } elseif ($date<$to) $next=(new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d').'|0';
        return ['items'=>$items,'cursor'=>$next,'receipt'=>true,'read_date'=>$date];
    }

    private function document($id,string $number,string $date,string $warehouse,$amount): array
    {
        if (!preg_match('/^[1-9]\d{0,9}$/D',(string)$id) || $number==='') throw new \RuntimeException('Folio returned an invalid selection list.');
        return ['id'=>(string)$id,'number'=>Record::text($number,100),'date'=>Record::date($date),'warehouse'=>$warehouse,
            'amount'=>is_numeric($amount)?(string)$amount:''];
    }

    private function get(string $path,array $query,bool $requiresOk=false): array
    {
        if (!function_exists('lps_java_get')) throw new \RuntimeException('Folio selection is unavailable. Try loading again.');
        $response=lps_java_get(add_query_arg($query,$path),['timeout'=>25]);
        if (is_wp_error($response)) throw new \RuntimeException('Folio selection is unavailable. Try loading again.');
        $status=wp_remote_retrieve_response_code($response);
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if ($status<200 || $status>=300 || !is_array($data) || ($requiresOk && ($data['ok']??null)!==true)) {
            throw new \RuntimeException('Folio selection is unavailable. Try loading again.');
        }
        return $data;
    }
}
