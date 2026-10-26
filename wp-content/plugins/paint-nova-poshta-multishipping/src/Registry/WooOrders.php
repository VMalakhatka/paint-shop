<?php

namespace Paint\NovaPoshta\Registry;

defined('ABSPATH') || exit;

/** HPOS-compatible reads only; associations belong to the registry, not order meta. */
final class WooOrders
{
    public function __construct(private readonly ?\Closure $read=null, private readonly ?\Closure $query=null) {}

    private function order(int $id): \WC_Order
    {
        $order=$this->read ? ($this->read)($id) : wc_get_order($id);
        if (!$order instanceof \WC_Order || $order->get_type()!=='shop_order' || $order->get_status()==='trash' || !$order->get_date_created()) {
            throw new \RuntimeException('WooCommerce order is unavailable. Reload the selection.');
        }
        return $order;
    }

    private function snapshot(\WC_Order $order): array
    {
        return [
            'order_id'=>$order->get_id(),'order_number'=>(string)$order->get_order_number(),
            'order_date'=>$order->get_date_created()->date('Y-m-d'),
            'order_modified'=>$order->get_date_modified()?->getTimestamp(),
            'order_status'=>$order->get_status(),'order_total'=>$order->get_total(),'order_currency'=>$order->get_currency(),
            'customer_id'=>$order->get_customer_id(),'customer_name'=>$order->get_formatted_billing_full_name(),
            'recipient_name'=>$order->get_formatted_shipping_full_name(),'recipient_city'=>$order->get_shipping_city(),
            'parent_order_id'=>$order->get_parent_id(),
            'folio_documents'=>$this->folioDocuments($order),
        ];
    }

    /** Saved headers only, never raw Java payloads or inferred links to this TTN. */
    private function folioDocuments(\WC_Order $order): array
    {
        $result=$order->get_meta('_folio_documents_result',true);
        $documents=is_array($result) && is_array($result['documents']??null) ? $result['documents'] : [];
        if (!$documents) {
            $legacy=[];
            foreach (['id','number','type','date','status'] as $key) $legacy['document_'.$key]=$order->get_meta('_folio_document_'.$key,true);
            $documents=[$legacy];
        }
        $headers=[];
        foreach ($documents as $document) {
            if (!is_array($document)) continue;
            $header=[];
            foreach (['id'=>['document_id','documentId'],'number'=>['document_number','documentNumber'],
                'type'=>['document_type','documentType'],'date'=>['document_date','documentDate'],
                'warehouse'=>['folio_warehouse_id','warehouseId']] as $key=>$names) {
                $value=$document[$names[0]]??$document[$names[1]]??'';
                $header[$key]=is_scalar($value) ? Record::text((string)$value,100) : '';
            }
            if ($header['id']==='' && $header['number']==='') continue;
            $header['type']=['account'=>'ACCOUNT','expense'=>'EXPENSE','receipt'=>'RECEIPT','missing_stock_account'=>'missing_stock_account'][$header['type']]??$header['type'];
            $headers[]=$header;
        }
        return $headers;
    }

    public function listing(array $input): array
    {
        $id=trim((string)($input['woo_id']??''));
        if ($id!=='') return ['items'=>[$this->snapshot($this->order($this->id($id)))],'next_page'=>null];
        $from=Record::date((string)($input['woo_from']??'')); $to=Record::date((string)($input['woo_to']??''));
        if ($from>$to || $to>current_time('Y-m-d') || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days>365) {
            throw new \InvalidArgumentException('Select a valid period of up to 366 days.');
        }
        $page=max(1,(int)($input['woo_page']??1));
        if ($page>10000) throw new \InvalidArgumentException('Invalid document page cursor.');
        $args=['type'=>'shop_order','limit'=>20,'page'=>$page,'paginate'=>true,'orderby'=>'ID','order'=>'DESC','date_created'=>$from.'...'.$to,
            'status'=>array_keys(wc_get_order_statuses())];
        $email=trim((string)($input['woo_email']??''));
        if ($email!=='') {
            if (!is_email($email)) throw new \InvalidArgumentException('Enter a valid customer email.');
            $args['billing_email']=$email;
        }
        $result=$this->query ? ($this->query)($args) : wc_get_orders($args);
        if (!is_object($result) || !is_array($result->orders??null)) throw new \RuntimeException('WooCommerce order is unavailable. Reload the selection.');
        $items=[];
        foreach ($result->orders as $order) if ($order instanceof \WC_Order && $order->get_type()==='shop_order' && $order->get_status()!=='trash' && $order->get_date_created()) $items[]=$this->snapshot($order);
        return ['items'=>$items,'next_page'=>$page<(int)$result->max_num_pages ? $page+1 : null];
    }

    public function preview(array $input): array
    {
        $snapshot=$this->snapshot($this->order($this->id((string)($input['woo_order_id']??''))));
        $evidence=Record::text($input['evidence']??'',1000);
        return ['type'=>'WOO_ORDER','role'=>'woo_order','id'=>(string)$snapshot['order_id'],'number'=>$snapshot['order_number'],
            'date'=>$snapshot['order_date'],'partner'=>'','warehouse'=>'','verification'=>'manager_confirmation','evidence'=>$evidence,
            'order_snapshot'=>$snapshot,'order_fingerprint'=>hash('sha256',wp_json_encode($snapshot))];
    }

    public function verify(array $document): void
    {
        $snapshot=$this->snapshot($this->order($this->id((string)$document['id'])));
        if (!hash_equals((string)($document['order_fingerprint']??''),hash('sha256',wp_json_encode($snapshot)))) {
            throw new \RuntimeException('The WooCommerce order changed. Preview the link again.');
        }
    }

    private function id(string $id): int
    {
        if (!preg_match('/^[1-9]\d{0,9}$/D',$id)) throw new \InvalidArgumentException('Enter a valid WooCommerce order ID.');
        return (int)$id;
    }
}
