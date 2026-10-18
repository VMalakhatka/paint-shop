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
        $person = $contact['data'][0];
        foreach (['FirstName','LastName'] as $key) {
            if (!is_string($person[$key] ?? null) || self::name($person[$key]) !== self::name($expected[$key])) return false;
        }
        if (($expected['MiddleName'] ?? '') !== '' && (!is_string($person['MiddleName'] ?? null)
            || self::name($person['MiddleName']) !== self::name($expected['MiddleName']))) return false;
        if (!is_string($person['Phones'] ?? null)) return false;
        $phones = array_map([self::class,'phone'], preg_split('/[,;]/', $person['Phones']));
        return in_array(self::phone($expected['Phone']), $phones, true);
    }
}
