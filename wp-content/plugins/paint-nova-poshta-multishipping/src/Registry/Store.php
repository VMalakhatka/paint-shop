<?php

namespace Paint\NovaPoshta\Registry;

defined('ABSPATH') || exit;

final class Store
{
    private function table(string $suffix = ''): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pnpm_registry' . $suffix;
    }

    public function transaction(callable $work)
    {
        global $wpdb;
        if (!Schema::ready()) throw new \RuntimeException('Install the registry tables first.');
        $this->must($wpdb->query('START TRANSACTION'));
        try {
            $value = $work();
            $this->must($wpdb->query('COMMIT'));
            return $value;
        } catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }

    public function must($result): void
    {
        if ($result === false) throw new \RuntimeException('Registry storage failed. Reload and inspect the saved result.');
    }

    public function preview(string $kind, array $payload): string
    {
        global $wpdb;
        if (!Schema::ready()) throw new \RuntimeException('Install the registry tables first.');
        $token = wp_generate_password(40, false, false);
        $json=wp_json_encode($payload);
        if (!$json || strlen($json)>2097152) throw new \InvalidArgumentException('Import is too large.');
        $this->must($wpdb->insert($this->table('_batches'), ['token_hash'=>hash('sha256', $token),
            'actor_id'=>get_current_user_id(), 'kind'=>$kind, 'payload'=>$json,
            'expires_at'=>gmdate('Y-m-d H:i:s', time()+600), 'created_at'=>gmdate('Y-m-d H:i:s')]));
        return $token;
    }

    /** Lock/consume and final changes share a transaction. A repeated confirmation returns the saved result. */
    public function apply(string $token, callable $work): array
    {
        global $wpdb;
        return $this->transaction(function () use ($token, $work, $wpdb) {
            $batch = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table('_batches').' WHERE token_hash=%s FOR UPDATE', hash('sha256', $token)), ARRAY_A);
            if (!$batch || (int)$batch['actor_id'] !== get_current_user_id()) throw new \RuntimeException('Preview not found.');
            if ($batch['status'] === 'applied') return json_decode($batch['result'], true);
            if ($batch['status']!=='preview' || $batch['expires_at'] <= gmdate('Y-m-d H:i:s')) throw new \RuntimeException('Preview expired. Check again.');
            $result = $work($batch['kind'], json_decode($batch['payload'], true));
            $this->must($wpdb->update($this->table('_batches'), ['status'=>'applied', 'payload'=>'{}', 'result'=>wp_json_encode($result)], ['id'=>$batch['id']]));
            return $result;
        });
    }

    public function result(string $token): array
    {
        global $wpdb;
        $result=$wpdb->get_var($wpdb->prepare('SELECT result FROM '.$this->table('_batches').' WHERE token_hash=%s AND actor_id=%d AND status=%s', hash('sha256',$token),get_current_user_id(),'applied'));
        if (!$result) throw new \RuntimeException('Preview not found.');
        return json_decode($result,true);
    }

    public function observe(string $ttn, string $source, array $data, string $observed): int
    {
        global $wpdb;
        $ttn = Record::ttn($ttn); $now = gmdate('Y-m-d H:i:s');
        if (!in_array($source, ['np_documents','np_tracking','manual','csv'], true)) throw new \InvalidArgumentException('Invalid source.');
        $json = wp_json_encode(Record::clean($data));
        if (!$json || strlen($json) > 131072) throw new \InvalidArgumentException('Response is too large.');
        $this->must($wpdb->query($wpdb->prepare('INSERT INTO '.$this->table().' (ttn,created_at,updated_at) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', $ttn,$now,$now)));
        $id = (int)$wpdb->insert_id;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table().' WHERE id=%d FOR UPDATE', $id), ARRAY_A);
        if (!$row) throw new \RuntimeException('Registry storage failed. Reload and inspect the saved result.');
        $latest = $wpdb->get_row($wpdb->prepare('SELECT payload_hash,observed_at FROM '.$this->table('_observations').' WHERE registry_id=%d AND source=%s ORDER BY observed_at DESC,id DESC LIMIT 1', $id,$source), ARRAY_A);
        $hash = hash('sha256',$json);
        if (!$latest || $latest['payload_hash'] !== $hash) {
            $this->must($wpdb->insert($this->table('_observations'), ['registry_id'=>$id,'source'=>$source,'payload_hash'=>$hash,
                'payload'=>$json,'observed_at'=>$observed,'recorded_at'=>$now]));
        }
        // Carrier observations never change manager classification, links or create-lifecycle state.
        $this->must($wpdb->update($this->table(), ['updated_at'=>$now], ['id'=>$id]));
        return $id;
    }

    public function find(int $id, bool $lock = false): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table().' WHERE id=%d'.($lock?' FOR UPDATE':''),$id), ARRAY_A);
        if (!$row) throw new \RuntimeException('Shipment not found.');
        return $row;
    }

    public function classify(int $id, int $revision, array $input): array
    {
        global $wpdb;
        return $this->transaction(function () use ($id,$revision,$input,$wpdb) {
            $row = $this->find($id, true);
            if ((int)$row['revision'] !== $revision) throw new \RuntimeException('The record changed. Reload before saving.');
            $metadata = Record::metadata($input);
            if ($metadata['related_ttn'] === $row['ttn']) throw new \InvalidArgumentException('A TTN cannot refer to itself.');
            if (($metadata['partner_code']!==$row['partner_code'] || $metadata['direction']!==$row['direction'])
                && $wpdb->get_var($wpdb->prepare('SELECT id FROM '.$this->table('_links')." WHERE registry_id=%d AND active=1 AND document_type<>'WOO_ORDER' LIMIT 1",$id))) {
                throw new \InvalidArgumentException('Revoke existing document links before changing the partner or direction.');
            }
            $metadata['revision'] = $revision+1;
            $metadata['updated_at'] = gmdate('Y-m-d H:i:s');
            $this->must($wpdb->update($this->table(),$metadata,['id'=>$id]));
            $this->audit($id,'classified',['before'=>array_intersect_key($row,$metadata),'after'=>$metadata]);
            return ['id'=>$id,'classification'=>array_intersect_key($metadata,array_flip(['revision','partner_code','direction','purpose','related_ttn','note']))];
        });
    }

    public function link(array $payload): array
    {
        global $wpdb;
        $id = (int)$payload['id']; $row = $this->find($id,true);
        if ((int)$row['revision'] !== (int)$payload['revision']) throw new \RuntimeException('The record changed. Reload before saving.');
        $doc = $payload['document'];
        $woo=$doc['type']==='WOO_ORDER' && $doc['role']==='woo_order';
        if (!$woo && $doc['partner']!==$row['partner_code']) throw new \RuntimeException('The record changed. Reload before saving.');
        if ($doc['role']==='sale_expense' && !empty($doc['account_link_id'])) {
            $account=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table('_links').' WHERE id=%d AND registry_id=%d AND active=1 AND role=%s',
                $doc['account_link_id']??0,$id,'sale_account'),ARRAY_A);
            if (!$account || $account['partner_code']!==$doc['partner']) throw new \InvalidArgumentException('Confirm and select the related account before linking an expense document.');
        }
        $identity = $woo ? ['WooCommerce',get_current_blog_id(),'shop_order',$doc['id']]
            : [($doc['source']??'')==='ARCHIVE_LEDGER' ? 'Paint_Ua_ARCHIVE' : 'Paint_Ua', $doc['type'], $doc['id'] ?: ($doc['number'].'|'.$doc['date'].'|'.$doc['partner'].'|'.$doc['warehouse'])];
        $key = hash('sha256',wp_json_encode($identity));
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM '.$this->table('_links').' WHERE registry_id=%d AND document_key=%s AND role=%s', $id,$key,$doc['role']));
        $fields = ['registry_id'=>$id,'document_key'=>$key,'document_type'=>$doc['type'],'document_id'=>$doc['id'],
            'document_number'=>$doc['number'],'document_date'=>$doc['date'],'partner_code'=>$doc['partner'],
            'warehouse_id'=>$doc['warehouse'],'role'=>$doc['role'],'verification'=>$doc['verification'],
            'evidence'=>$doc['evidence'],'snapshot'=>wp_json_encode($doc),'active'=>1,
            'confirmed_by'=>get_current_user_id(),'confirmed_at'=>gmdate('Y-m-d H:i:s')];
        if ($existing) $this->must($wpdb->update($this->table('_links'),$fields,['id'=>$existing]));
        else { $this->must($wpdb->insert($this->table('_links'),$fields)); $existing=(int)$wpdb->insert_id; }
        $this->must($wpdb->update($this->table(), ['revision'=>(int)$row['revision']+1,'updated_at'=>gmdate('Y-m-d H:i:s')], ['id'=>$id]));
        $this->audit($id,'link_confirmed',$doc);
        return ['id'=>$id,'link_id'=>(int)$existing];
    }

    public function linkBundle(array $payload): array
    {
        $account=$payload['document']['related_account']??null;
        if ($account) {
            $saved=$this->link(['id'=>$payload['id'],'revision'=>$payload['revision'],'document'=>$account]);
            $payload['revision']++;
            $payload['document']['account_link_id']=$saved['link_id'];
        }
        return $this->link($payload);
    }

    public function linkMany(array $payload): array
    {
        $result=['id'=>$payload['id']];
        foreach ($payload['documents'] as $document) {
            $result=$this->linkBundle(['id'=>$payload['id'],'revision'=>$payload['revision'],'document'=>$document]);
            $payload['revision']=(int)$this->find((int)$payload['id'])['revision'];
        }
        return $result;
    }

    public function unlink(int $id, int $revision, int $link, string $reason): array
    {
        global $wpdb;
        if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason)>1000) throw new \InvalidArgumentException('Explain the correction (10–1000 characters).');
        return $this->transaction(function () use ($id,$revision,$link,$reason,$wpdb) {
            $row=$this->find($id,true);
            if ((int)$row['revision']!==$revision) throw new \RuntimeException('The record changed. Reload before saving.');
            $active=$wpdb->get_results($wpdb->prepare('SELECT snapshot FROM '.$this->table('_links').' WHERE registry_id=%d AND active=1',$id),ARRAY_A);
            foreach ($active as $item) if ((int)(json_decode($item['snapshot'],true)['account_link_id']??0)===$link) {
                throw new \InvalidArgumentException('Revoke the related expense link before revoking its account.');
            }
            $changed=$wpdb->update($this->table('_links'),['active'=>0],['id'=>$link,'registry_id'=>$id,'active'=>1]);
            if ($changed!==1) throw new \RuntimeException('Link not found. Reload the record.');
            $this->must($wpdb->update($this->table(),['revision'=>$revision+1,'updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]));
            $this->audit($id,'link_revoked',['link_id'=>$link,'reason'=>sanitize_textarea_field($reason)]);
            return ['id'=>$id];
        });
    }

    private function audit(int $id, string $event, array $context): void
    {
        global $wpdb;
        $this->must($wpdb->insert($this->table('_audit'),['registry_id'=>$id,'event_type'=>$event,'actor_id'=>get_current_user_id(),
            'context'=>wp_json_encode($context),'created_at'=>gmdate('Y-m-d H:i:s')]));
    }

    public function listing(array $filter, int $page = 1): array
    {
        global $wpdb;
        $where = '1=1'; $args=[];
        foreach (['direction'=>Record::DIRECTIONS,'purpose'=>Record::PURPOSES] as $key=>$allowed) {
            if (in_array($filter[$key]??'', $allowed,true)) { $where.=" AND $key=%s"; $args[]=$filter[$key]; }
        }
        if (!empty($filter['ttn'])) { $where.=' AND ttn=%s'; $args[]=Record::ttn($filter['ttn']); }
        $args[]=max(0,$page-1)*30;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$this->table()." WHERE $where ORDER BY id DESC LIMIT 31 OFFSET %d",...$args),ARRAY_A) ?: [];
        if (!$rows) return [];
        $ids=implode(',',array_map('intval',array_column($rows,'id')));
        $observations=[];
        // Three bounded queries, one indexed latest-row lookup per shipment/source; never fetch full histories.
        foreach (['np_documents','np_tracking','csv'] as $source) {
            $sql='SELECT o.* FROM '.$this->table().' r JOIN '.$this->table('_observations').' o ON o.id=(SELECT newer.id FROM '.$this->table('_observations').
                " newer WHERE newer.registry_id=r.id AND newer.source=%s ORDER BY newer.observed_at DESC,newer.id DESC LIMIT 1) WHERE r.id IN ($ids)";
            foreach ($wpdb->get_results($wpdb->prepare($sql,$source),ARRAY_A)?:[] as $observation) $observations[$observation['registry_id']][]=$observation;
        }
        foreach ($rows as &$row) $row['summary']=Summary::fromObservations($observations[$row['id']]??[]);
        unset($row);
        return $rows;
    }

    public function detail(int $id): array
    {
        global $wpdb;
        $row=$this->find($id);
        foreach (['_observations'=>'observations','_links'=>'links','_audit'=>'audit'] as $suffix=>$key) {
            $sort=$key==='observations' ? 'observed_at DESC,id DESC' : 'id DESC';
            $row[$key]=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$this->table($suffix).' WHERE registry_id=%d ORDER BY '.$sort.' LIMIT 100',$id),ARRAY_A) ?: [];
        }
        return $row;
    }

    /** Only confirmed active Folio links; an unsaved picker selection is never evidence. */
    public function folioContext(array $row): array
    {
        global $wpdb;
        $links=$wpdb->get_results($wpdb->prepare('SELECT document_type,document_id,document_number,document_date,partner_code,warehouse_id FROM '.$this->table('_links')." WHERE registry_id=%d AND active=1 AND document_type<>'WOO_ORDER' ORDER BY id",$row['id']),ARRAY_A)?:[];
        return ['partner'=>$row['partner_code'],'documents'=>array_map(static fn($link)=>[
            'type'=>$link['document_type'],'id'=>$link['document_id'],'number'=>$link['document_number'],
            'date'=>$link['document_date'],'partner'=>$link['partner_code'],'warehouse'=>$link['warehouse_id'],
        ],$links)];
    }

    public function recentImports(): array
    {
        global $wpdb;
        return $wpdb->get_results("SELECT id,kind,status,result,created_at FROM ".$this->table('_batches')." WHERE kind='import' AND status='applied' ORDER BY id DESC LIMIT 30",ARRAY_A) ?: [];
    }

    public function cleanup(): void
    {
        global $wpdb;
        // Only expired staging payloads; final evidence and audit are retained.
        $this->must($wpdb->query("UPDATE ".$this->table('_batches')." SET payload='{}',status='expired' WHERE status='preview' AND expires_at<UTC_TIMESTAMP()"));
    }
}
