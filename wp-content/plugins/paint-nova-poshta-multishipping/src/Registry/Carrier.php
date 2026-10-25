<?php

namespace Paint\NovaPoshta\Registry;

use Paint\NovaPoshta\Infrastructure\ApiClient;

defined('ABSPATH') || exit;

/** Read-only carrier boundary. No save, recipient creation, printing or notification calls. */
final class Carrier
{
    public function __construct(private readonly ApiClient $api = new ApiClient()) {}

    public function fingerprint(): string { return $this->api->accountFingerprint(); }

    public function documents(string $from, string $to, int $page): array
    {
        Record::date($from); Record::date($to);
        if ($from < Record::START || $to < $from || $to > wp_date('Y-m-d')
            || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 365
            || $page < 1 || $page > 10000) throw new \InvalidArgumentException('Invalid import period or page.');
        $data = $this->read('InternetDocument', 'getDocumentList', [
            'DateTimeFrom'=>date('d.m.Y', strtotime($from)), 'DateTimeTo'=>date('d.m.Y', strtotime($to)),
            'GetFullList'=>'0', 'Page'=>(string)$page,
        ]);
        if (count($data) > 100) throw new \RuntimeException('Unexpected carrier page. Nothing imported.');
        $rows=[]; $seen=[];
        foreach ($data as $item) {
            $ttn=Record::ttn((string)($item['IntDocNumber'] ?? ''));
            if (isset($seen[$ttn])) throw new \RuntimeException('Duplicate carrier page. Stop and check coverage.');
            $seen[$ttn]=true;
            $rows[]=['ttn'=>$ttn, 'data'=>Record::clean($item)];
        }
        return ['source'=>'np_documents', 'rows'=>$rows, 'scope'=>[
            'from'=>$from,'to'=>$to,'page'=>$page,'count'=>count($rows),
            // A short page alone is not evidence of complete incoming or event history.
            'empty_page'=>$rows===[], 'next_page'=>$rows ? $page+1 : null,
            'coverage'=>'account_document_list_only',
        ], 'account'=>$this->fingerprint(), 'observed_at'=>gmdate('Y-m-d H:i:s')];
    }

    public function tracking(string $ttn, string $phone = ''): array
    {
        $ttn=Record::ttn($ttn);
        $document=['DocumentNumber'=>$ttn];
        if ($phone !== '') {
            if (!preg_match('/^380\d{9}$/D', $phone)) throw new \InvalidArgumentException('Use a phone in 380XXXXXXXXX format.');
            $document['Phone']=$phone;
        }
        $data=$this->read('TrackingDocument','getStatusDocuments',['Documents'=>[$document]]);
        if (count($data)!==1 || (string)($data[0]['Number']??'')!==$ttn
            || !preg_match('/^\d{1,5}$/D',(string)($data[0]['StatusCode']??'')) || (string)($data[0]['StatusCode']??'')==='3') {
            throw new \RuntimeException('The carrier did not return this TTN. No saved data changed.');
        }
        return ['source'=>'np_tracking','rows'=>[['ttn'=>$ttn,'data'=>Record::clean($data[0])]],
            'scope'=>['coverage'=>'known_ttn_only','count'=>1], 'account'=>$this->fingerprint(), 'observed_at'=>gmdate('Y-m-d H:i:s')];
    }

    private function read(string $model, string $method, array $properties): array
    {
        $result=$this->api->call($model,$method,$properties);
        if (is_wp_error($result) || ($result['success']??null)!==true || !empty($result['errors'])
            || !isset($result['data']) || !is_array($result['data']) || !array_is_list($result['data'])) {
            throw new \RuntimeException('The carrier read failed. No saved data changed.');
        }
        foreach ($result['data'] as $row) if (!is_array($row)) throw new \RuntimeException('Unexpected carrier page. Nothing imported.');
        return $result['data'];
    }
}
