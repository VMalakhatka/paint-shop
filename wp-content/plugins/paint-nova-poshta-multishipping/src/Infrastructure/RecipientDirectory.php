<?php

namespace Paint\NovaPoshta\Infrastructure;

use WP_Error;

defined('ABSPATH') || exit;

final class RecipientDirectory
{
    private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

    public function __construct(private readonly ApiClient $api)
    {
    }

    /** @return array<int,array<string,string>>|WP_Error */
    public function searchCities(string $query)
    {
        $query = sanitize_text_field($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $cache_key = 'pnpm_recipient_cities_' . md5(mb_strtolower($query));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->api->call('AddressGeneral', 'searchSettlements', [
            'CityName' => $query,
            'Limit' => 20,
            'Page' => 1,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        if (($response['success'] ?? false) !== true) {
            return new WP_Error('pnpm_city_search_rejected', $this->message($response));
        }

        $cities = [];
        foreach ((array) ($response['data'][0]['Addresses'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $city_ref = sanitize_text_field((string) ($row['DeliveryCity'] ?? ''));
            $label = sanitize_text_field((string) ($row['Present'] ?? ''));
            if ($city_ref === '' || $label === '') {
                continue;
            }
            $cities[$city_ref] = [
                'ref' => $city_ref,
                'settlementRef' => sanitize_text_field((string) ($row['Ref'] ?? '')),
                'label' => $label,
            ];
        }
        $cities = array_values($cities);
        set_transient($cache_key, $cities, self::CACHE_TTL);
        return $cities;
    }

    /** @param array<string,mixed> $response */
    private function message(array $response): string
    {
        $messages = array_merge((array) ($response['errors'] ?? []), (array) ($response['warnings'] ?? []));
        return $messages
            ? implode('; ', array_map('sanitize_text_field', $messages))
            : __('Nova Poshta rejected the city search.', 'paint-nova-poshta-multishipping');
    }
}
