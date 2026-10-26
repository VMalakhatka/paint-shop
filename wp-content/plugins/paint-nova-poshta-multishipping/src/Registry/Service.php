<?php

namespace Paint\NovaPoshta\Registry;

defined('ABSPATH') || exit;

final class Service
{
    public function __construct(private readonly Store $store = new Store(), private readonly Carrier $carrier = new Carrier(), private readonly Folio $folio = new Folio(), private readonly WooOrders $woo = new WooOrders()) {}

    public static function authorize(): void
    {
        if (!current_user_can('manage_woocommerce') || !current_user_can('manage_pnpm_shipments')) throw new \RuntimeException('Registry access denied.');
    }

    public function run(string $command, array $input): array
    {
        self::authorize();
        if (!Schema::ready()) throw new \RuntimeException('Install the registry tables first.');
        // Directory reads never create previews, consume tokens or clean registry data.
        if ($command==='partners') return (new Catalog())->partners($input);
        if ($command==='warehouses') return (new Catalog())->warehouses();
        if ($command==='woo_orders') return $this->woo->listing($input);
        if ($command==='documents') {
            $row=$this->store->find((int)($input['id']??0));
            if ((int)($input['revision']??0)!==(int)$row['revision']) throw new \RuntimeException('The record changed. Reload before saving.');
            return (new Catalog())->documents($input,$row);
        }
        $this->store->cleanup();
        if ($command==='classify') return $this->store->classify((int)($input['id']??0),(int)($input['revision']??0),$input);
        if ($command==='unlink') return $this->store->unlink((int)($input['id']??0),(int)($input['revision']??0),(int)($input['link']??0),(string)($input['reason']??''));
        if ($command==='confirm') {
            if (($input['confirmed']??'')!=='1') throw new \InvalidArgumentException('Confirm the preview before saving.');
            if (!function_exists('lavka_ecosystem_lock_acquire')) throw new \RuntimeException('The site synchronization lock is unavailable.');
            $lock=lavka_ecosystem_lock_acquire('paint-nova-poshta-multishipping','np_registry','manual','Nova Poshta registry',600);
            if (empty($lock['ok'])) throw new \RuntimeException('Another synchronization is running. Try saving after it finishes.');
            try {
                return $this->store->apply((string)($input['token']??''),function($kind,$payload) {
                    if ($kind==='link') return $this->store->link($payload);
                    if ($kind==='woo_link') {
                        $this->woo->verify($payload['document']);
                        return $this->store->link($payload);
                    }
                    if ($kind!=='import') throw new \RuntimeException('Preview not found.');
                    if (isset($payload['account']) && !hash_equals($payload['account'],$this->carrier->fingerprint())) throw new \RuntimeException('The Nova Poshta account changed. Check again.');
                    $ids=[];
                    foreach ($payload['rows'] as $row) $ids[]=$this->store->observe($row['ttn'],$payload['source'],$row['data'],$payload['observed_at']);
                    return ['id'=>count($ids)===1 ? $ids[0] : 0, 'count'=>count($ids),'source'=>$payload['source'],
                        'scope'=>$payload['scope'],'observed_at'=>$payload['observed_at'],
                        'account'=>$payload['account']??'', 'ttns'=>array_column($payload['rows'],'ttn')];
                });
            } finally { lavka_ecosystem_lock_release($lock['token']); }
        }
        if ($command==='link_preview' || $command==='woo_preview') {
            $row=$this->store->find((int)($input['id']??0));
            if ((int)($input['revision']??0)!==(int)$row['revision']) throw new \RuntimeException('The record changed. Reload before saving.');
            $document=$command==='woo_preview' ? $this->woo->preview($input) : $this->folio->preview($input,$row);
            if ($command==='woo_preview') $document['registry_context']=$this->store->folioContext($row);
            $payload=['id'=>(int)$row['id'],'revision'=>(int)$row['revision'],'document'=>$document];
            $visible=$document; unset($visible['order_fingerprint']);
            return ['token'=>$this->store->preview($command==='woo_preview'?'woo_link':'link',$payload),'document'=>$visible];
        }
        if ($command==='history') {
            $page=1; $previous=[];
            $from=(string)($input['from']??''); $to=(string)($input['to']??'');
            Carrier::period($from,$to);
            $windowFrom=$from;
            if (!empty($input['previous'])) {
                $previous=$this->store->result((string)$input['previous']);
                if (($previous['source']??'')!=='np_documents' || empty($previous['scope']['next_page'])
                    || ($previous['scope']['from']??'')!==($input['from']??'') || ($previous['scope']['to']??'')!==($input['to']??'')
                    || empty($previous['scope']['next_from'])
                    || !hash_equals($previous['account']??'', $this->carrier->fingerprint())) throw new \RuntimeException('Start a new import for this period.');
                $page=(int)$previous['scope']['next_page'];
                $windowFrom=$previous['scope']['next_from'];
            }
            $windowTo=min($to,(new \DateTimeImmutable($windowFrom))->format('Y-m-t'));
            $payload=$this->carrier->documents($windowFrom,$windowTo,$page);
            if ($previous && ($previous['scope']['window_from']??null)===$windowFrom
                && array_intersect($previous['ttns'],array_column($payload['rows'],'ttn'))) throw new \RuntimeException('Duplicate carrier page. Stop and check coverage.');
            $nextFrom=$windowFrom;
            if ($payload['scope']['empty_page']) {
                $nextFrom=$windowTo<$to ? (new \DateTimeImmutable($windowTo))->modify('+1 day')->format('Y-m-d') : null;
                $payload['scope']['next_page']=$nextFrom ? 1 : null;
            }
            $payload['scope']=array_merge($payload['scope'],['from'=>$from,'to'=>$to,'window_from'=>$windowFrom,'window_to'=>$windowTo,'next_from'=>$nextFrom]);
        } elseif ($command==='tracking') {
            $payload=$this->carrier->tracking((string)($input['ttn']??''),(string)($input['phone']??''));
        } elseif ($command==='csv') {
            $payload=['rows'=>Record::csv((string)($input['csv']??'')),'source'=>'csv','scope'=>['coverage'=>'manual_csv_only'], 'observed_at'=>gmdate('Y-m-d H:i:s')];
        } elseif ($command==='manual') {
            $ttn=Record::ttn((string)($input['ttn']??''));
            $evidence=Record::text($input['evidence']??'',1000);
            if (mb_strlen($evidence)<10) throw new \InvalidArgumentException('Explain the manual verification (at least 10 characters).');
            $payload=['rows'=>[['ttn'=>$ttn,'data'=>['Number'=>$ttn,'evidence'=>$evidence]]], 'source'=>'manual',
                'scope'=>['coverage'=>'manual_reference_only'],'observed_at'=>gmdate('Y-m-d H:i:s')];
        } else throw new \InvalidArgumentException('Unknown registry action.');
        return ['token'=>$this->store->preview('import',$payload),'source'=>$payload['source'],'scope'=>$payload['scope'],'rows'=>$payload['rows']];
    }
}
