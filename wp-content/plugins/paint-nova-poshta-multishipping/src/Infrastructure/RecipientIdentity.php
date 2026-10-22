<?php
namespace Paint\NovaPoshta\Infrastructure;

defined('ABSPATH') || exit;

/** Suggestions are not carrier identity verification; never split a free-text full name. */
final class RecipientIdentity
{
    public static function phone(string $value): string
    {
        $value = preg_replace('/\D/', '', $value);
        return strlen($value) === 10 && str_starts_with($value, '0') ? '38'.$value : $value;
    }

    private static function name(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim(str_replace(['’','ʼ'], "'", $value))));
    }

    public static function names(array $input): array
    {
        $result = [];
        foreach (['FirstName','LastName','MiddleName'] as $key) {
            $name = $input[$key] ?? ($key === 'MiddleName' ? '' : null);
            if (!is_string($name)) $name = null;
            else $name = trim($name);
            if ($name === null || !($key === 'MiddleName' && $name === '')
                && !preg_match('/^[А-ЩЬЮЯЄІЇҐа-щьюяєіїґ\x{2019}\x{02BC}\x{0027} -]{1,36}$/uD', $name)) {
                throw new \RuntimeException(__('Enter the recipient first and last name in Ukrainian. Patronymic is optional; each name must be at most 36 characters.', 'paint-nova-poshta-multishipping'));
            }
            $result[$key] = $name;
        }
        return $result;
    }

    public static function defaults(\WC_Order $order, array $recipient): array
    {
        $empty = ['FirstName'=>'','LastName'=>'','MiddleName'=>''];
        $phone = self::phone((string)($recipient['phone'] ?? ''));
        $full = self::name((string)($recipient['name'] ?? ''));
        if (!preg_match('/^380\d{9}$/D', $phone) || $full === '') return $empty;
        $candidates = [
            [$order->get_shipping_first_name(), $order->get_shipping_last_name(), $order->get_shipping_phone() ?: $order->get_billing_phone()],
            [$order->get_billing_first_name(), $order->get_billing_last_name(), $order->get_billing_phone()],
        ];
        if ($order->get_customer_id() && get_userdata($order->get_customer_id())) {
            $customer = new \WC_Customer($order->get_customer_id());
            $candidates[] = [$customer->get_shipping_first_name(), $customer->get_shipping_last_name(), $customer->get_shipping_phone() ?: $customer->get_billing_phone()];
            $candidates[] = [$customer->get_billing_first_name(), $customer->get_billing_last_name(), $customer->get_billing_phone()];
            $candidates[] = [$customer->get_first_name(), $customer->get_last_name(), $customer->get_billing_phone()];
        }
        foreach ($candidates as [$first,$last,$candidatePhone]) {
            if (trim($first) === '' || trim($last) === '' || self::phone($candidatePhone) !== $phone) continue;
            if (!in_array($full, [self::name($first.' '.$last), self::name($last.' '.$first)], true)) continue;
            return ['FirstName'=>$first,'LastName'=>$last,'MiddleName'=>''];
        }
        return $empty;
    }

    /** Compare the returned contact, not a supposed global phone-owner registry. */
    public static function matches($response, array $expected): bool
    {
        if (!ShipmentLifecycle::recipientRefs($response)) return false;
        $contact = $response['data'][0]['ContactPerson'];
        if (isset($contact[0])) $contact = $contact[0];
        return self::differences($contact['data'][0], $expected) === [];
    }

    /** Safe diagnostic codes only; never return received values or raw provider errors. */
    public static function differences(array $person, array $expected): array
    {
        $errors = [];
        foreach (['FirstName','LastName'] as $key) {
            if (!is_string($person[$key] ?? null) || trim($person[$key]) === '') $errors[] = $key.'_missing';
            elseif (self::name($person[$key]) !== self::name($expected[$key])) $errors[] = $key.'_mismatch';
        }
        if (($expected['MiddleName'] ?? '') !== '' && (!is_string($person['MiddleName'] ?? null)
            || self::name($person['MiddleName']) !== self::name($expected['MiddleName']))) $errors[] = 'MiddleName_mismatch';
        if (!is_string($person['Phones'] ?? null) || trim($person['Phones']) === '') $errors[] = 'Phones_missing';
        else {
            $phones = array_map([self::class,'phone'], preg_split('/[,;]/', $person['Phones']));
            if (!in_array(self::phone($expected['Phone']), $phones, true)) $errors[] = 'Phones_mismatch';
        }
        return $errors;
    }

    /** Read the exact contact under the returned counterparty; save does not promise Phones. */
    public static function verify(ApiClient $api, array $refs, array $expected): array
    {
        if (count($refs)!==2 || !ShipmentLifecycle::ref($refs['Recipient'] ?? null) || !ShipmentLifecycle::ref($refs['ContactRecipient'] ?? null)) return ['refs_invalid'];
        for ($page=1; $page<=5; $page++) {
            $response = $api->call('Counterparty', 'getCounterpartyContactPersons', ['Ref'=>$refs['Recipient'], 'Page'=>$page]);
            if (is_wp_error($response)) return ['contact_read_unavailable'];
            if (($response['success'] ?? false) !== true || !is_array($response['data'] ?? null) || count($response['data'])>100) return ['contact_read_invalid'];
            $matches = array_values(array_filter($response['data'], static fn($p)=>is_array($p) && ($p['Ref'] ?? null)===$refs['ContactRecipient']));
            if (count($matches)>1) return ['contact_not_unique'];
            if (count($matches)===1) return self::differences($matches[0], $expected);
            if (count($response['data'])<100) return ['contact_not_found'];
        }
        return ['contact_page_limit'];
    }
}
