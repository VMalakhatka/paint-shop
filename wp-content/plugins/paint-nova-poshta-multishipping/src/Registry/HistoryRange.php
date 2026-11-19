<?php

namespace Paint\NovaPoshta\Registry;

defined('ABSPATH') || exit;

/** Read one carrier page per request; only the completed range can be confirmed. */
final class HistoryRange
{
    public function __construct(private readonly Store $store, private readonly Carrier $carrier) {}

    public function start(array $input): array
    {
        $from=(string)($input['from']??''); $to=(string)($input['to']??'');
        Carrier::period($from,$to);
        $state=['account'=>$this->carrier->fingerprint(),'parts'=>[],'seen'=>[],'month_seen'=>[],
            'bytes'=>0,'next_from'=>$from,'next_page'=>1,
            'scope'=>['from'=>$from,'to'=>$to,'pages_read'=>0,'months_read'=>0,'count'=>0,'returned_count'=>0,
                'duplicates'=>0,'range_state'=>'range_reading','coverage'=>'account_document_list_only']];
        return ['cursor'=>$this->store->preview('history_range',$state),'step'=>0,'done'=>false,'scope'=>$state['scope']];
    }

    public function step(string $token, int $step): array
    {
        $initial=$this->store->staged($token,'history_range',false);
        $this->account($initial); $count=count($initial['parts']); $page=null;
        if ($step===$count && $initial['next_from']) {
            if ($count>=500) throw new \RuntimeException('This period is too large for one preview. Split it into shorter periods; nothing was imported.');
            $from=$initial['next_from']; $to=min($initial['scope']['to'],(new \DateTimeImmutable($from))->format('Y-m-t'));
            // Never hold a SQL transaction or registry row lock across carrier HTTP.
            $page=$this->carrier->documents($from,$to,$initial['next_page']);
        } elseif (!$count || $step!==$count-1) throw new \RuntimeException('Start a new import for this period.');
        return $this->store->advanceHistory($token,function ($state) use ($token,$step,$page) {
            $this->account($state);
            $count=count($state['parts']);
            // A deliberate resume after a lost response returns that exact page, never skips ahead.
            if ($count && $step===$count-1) {
                $page=$this->store->staged($state['parts'][$count-1],'history_part');
                return [$state,$this->response($token,$state,$page)];
            }
            if ($step!==$count || !$state['next_from'] || !$page) throw new \RuntimeException('Start a new import for this period.');
            $from=$state['next_from']; $to=min($state['scope']['to'],(new \DateTimeImmutable($from))->format('Y-m-t'));
            $ttns=array_column($page['rows'],'ttn');
            // Any repeated TTN within the same month signals overlapping/ignored pagination.
            if (array_intersect($ttns,array_keys($state['month_seen']))) throw new \RuntimeException('Duplicate carrier page. Stop and check coverage.');
            foreach ($ttns as $ttn) { $state['seen'][$ttn]=true; $state['month_seen'][$ttn]=true; }
            $state['bytes']+=strlen(wp_json_encode($page));
            if (count($state['seen'])>5000 || $state['bytes']>33554432) throw new \RuntimeException('This period is too large for one preview. Split it into shorter periods; nothing was imported.');
            $state['scope']['pages_read']++;
            $state['scope']['returned_count']+=count($ttns);
            $state['scope']['count']=count($state['seen']);
            $state['scope']['duplicates']=$state['scope']['returned_count']-$state['scope']['count'];
            $state['scope']['window_from']=$from; $state['scope']['window_to']=$to;
            $state['scope']['page']=$state['next_page'];
            $state['next_page']++;
            if (!$ttns) {
                $state['scope']['months_read']++;
                $state['next_from']=$to<$state['scope']['to'] ? (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') : null;
                $state['next_page']=1; $state['month_seen']=[];
                if (!$state['next_from']) $state['scope']['range_state']='range_complete';
            }
            $state['parts'][]=$this->store->preview('history_part',$page);
            return [$state,$this->response($token,$state,$page)];
        });
    }

    /** Called inside the final token's transaction and the ecosystem synchronization lock. */
    public function apply(array $state): array
    {
        $this->account($state);
        if ($state['scope']['range_state']!=='range_complete' || $state['next_from']!==null || !$state['parts']) throw new \RuntimeException('Wait until the entire date range has been read before saving.');
        $windows=[]; $observed='';
        foreach ($state['parts'] as $token) {
            $page=$this->store->staged($token,'history_part');
            $this->account($page);
            foreach ($page['rows'] as $row) $this->store->observe($row['ttn'],'np_documents',$row['data'],$page['observed_at']);
            $windows[]=$page['scope']; $observed=$page['observed_at'];
            $this->store->consumeHistoryPart($token);
        }
        return ['id'=>0,'count'=>$state['scope']['count'],'source'=>'np_documents','scope'=>$state['scope'],
            'observed_at'=>$observed,'account'=>$state['account'],'ttns'=>array_map('strval',array_keys($state['seen'])),
            'pages'=>$windows];
    }

    private function response(string $token,array $state,array $page): array
    {
        $done=$state['scope']['range_state']==='range_complete';
        return ['cursor'=>$token,'step'=>count($state['parts']),'done'=>$done,'token'=>$done?$token:'',
            'scope'=>$state['scope'],'rows'=>$page['rows'],'source'=>'np_documents'];
    }

    private function account(array $state): void
    {
        if (!hash_equals($state['account'],$this->carrier->fingerprint())) throw new \RuntimeException('The Nova Poshta account changed. Check again.');
    }
}
