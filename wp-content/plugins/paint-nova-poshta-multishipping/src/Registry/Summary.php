<?php

namespace Paint\NovaPoshta\Registry;

defined('ABSPATH') || exit;

/** Display only: never infer partner, payment settlement or physical handover. */
final class Summary
{
    public static function fromObservations(array $observations): array
    {
        // Carrier evidence precedes manual CSV; among carrier sources, prefer the newer read.
        usort($observations,static function($a,$b) {
            $priority=static fn($o)=>in_array($o['source'],['np_documents','np_tracking'],true)?1:0;
            return ($priority($b)<=>$priority($a)) ?: strcmp($b['observed_at'],$a['observed_at']) ?: ((int)$b['id']<=>(int)$a['id']);
        });
        $out=array_fill_keys(['recipient','contact','city','date','delivery','declared','payer'],'');
        $out['csv']=false;
        foreach ($observations as $observation) {
            $data=json_decode($observation['payload'],true);
            if (!is_array($data)) continue;
            $csv=$observation['source']==='csv';
            $tracking=$observation['source']==='np_tracking';
            $values=[
                'recipient'=>self::first($data,$csv?['recipient']:($tracking?['CounterpartyRecipientDescription','RecipientFullNameEW','RecipientFullName','RecipientDescription','RecipientContactPerson']:['RecipientDescription','RecipientFullName','RecipientContactPerson'])),
                'contact'=>$csv?'':self::first($data,$tracking?['RecipientFullNameEW','RecipientFullName','RecipientContactPerson']:['RecipientContactPerson','RecipientFullName']),
                'city'=>$csv?'':self::first($data,['CityRecipientDescription','CityRecipient','RecipientSettlementDescription']),
                'date'=>self::date(self::first($data,$csv?['date']:['DateTime','DateCreated','CreateTime'])),
                // Tracking DocumentCost is the delivery tariff; document-list Cost is declared value.
                'delivery'=>self::amount($csv?($data['delivery_cost']??null):($tracking?($data['DocumentCost']??$data['CostOnSite']??null):($data['CostOnSite']??null))),
                'declared'=>$csv?'':self::amount($data[$tracking?'AnnouncedPrice':'Cost']??null),
                'payer'=>self::first($data,$csv?['payer']:['PayerType']),
            ];
            if ($values['city']==='' && is_array($data['SettlmentAddressData']??null)) $values['city']=self::first($data['SettlmentAddressData'],['RecipientSettlementDescription']);
            if ($out['recipient']==='' && $values['recipient']!=='') $out['contact']=$values['contact'];
            foreach ($values as $key=>$value) if ($key!=='contact' && $out[$key]==='' && $value!=='') {
                $out[$key]=$value;
                if ($csv) $out['csv']=true;
            }
        }
        if ($out['contact']===$out['recipient']) $out['contact']='';
        return $out;
    }

    private static function first(array $data,array $keys): string
    {
        foreach ($keys as $key) {
            $value=$data[$key]??null;
            if (!is_scalar($value) || is_bool($value)) continue;
            $value=trim((string)$value);
            if ($value==='' || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD',$value)) continue;
            return $value;
        }
        return '';
    }

    private static function amount($value): string
    {
        if (!is_scalar($value) || is_bool($value)) return '';
        $value=str_replace(',','.',trim((string)$value));
        return preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D',$value) ? $value : '';
    }

    private static function date(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]|$)/',$value,$m)) [$year,$month,$day]=[(int)$m[1],(int)$m[2],(int)$m[3]];
        elseif (preg_match('/^(\d{2})([.\-])(\d{2})\2(\d{4})(?:[ T]|$)/',$value,$m)) [$year,$month,$day]=[(int)$m[4],(int)$m[3],(int)$m[1]];
        else return '';
        return $year>=1900 && checkdate($month,$day,$year) ? sprintf('%02d.%02d.%04d',$day,$month,$year) : '';
    }
}
