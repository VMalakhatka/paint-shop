<?php

namespace Paint\NovaPoshta\Infrastructure;

use WP_Error;

defined('ABSPATH') || exit;

final class WarehouseDirectory
{
    private const CACHE_TTL = 15 * MINUTE_IN_SECONDS;
    private const RESULT_LIMIT = 50;

    public function __construct(private readonly ApiClient $api)
    {
    }

    /** @return array<int,array<string,string>>|WP_Error */
    public function search(string $city_ref, string $query)
    {
        $city_ref = sanitize_text_field($city_ref);
        $query = sanitize_text_field($query);
        if ($city_ref === '' || $query === '') {
            return new WP_Error(
                'pnpm_warehouse_search_missing_input',
                __('Choose a registered sender address and enter a branch number or address.', 'paint-nova-poshta-multishipping')
            );
        }

        $cache_key = 'pnpm_warehouses_' . md5($city_ref . '|' . mb_strtolower($query));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->api->call('Address', 'getWarehouses', [
            'CityRef' => $city_ref,
            'FindByString' => $query,
            'Page' => 1,
            'Limit' => self::RESULT_LIMIT,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        if (($response['success'] ?? false) !== true) {
            $messages = array_merge((array) ($response['errors'] ?? []), (array) ($response['warnings'] ?? []));
            return new WP_Error(
                'pnpm_warehouse_directory_rejected',
                $messages
                    ? implode('; ', array_map('sanitize_text_field', $messages))
                    : __('Nova Poshta rejected the handover point search.', 'paint-nova-poshta-multishipping')
            );
        }

        $points = [];
        foreach ((array) ($response['data'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ref = sanitize_text_field((string) ($row['Ref'] ?? ''));
            $row_city_ref = sanitize_text_field((string) ($row['CityRef'] ?? ''));
            $status = sanitize_text_field((string) ($row['WarehouseStatus'] ?? ''));
            $deny_to_select = (string) ($row['DenyToSelect'] ?? '0');
            if ($ref === '' || $row_city_ref !== $city_ref || $deny_to_select === '1') {
                continue;
            }
            if ($status !== '' && strcasecmp($status, 'Working') !== 0) {
                continue;
            }

            $description = sanitize_text_field((string) ($row['Description'] ?? ''));
            $short_address = sanitize_text_field((string) ($row['ShortAddress'] ?? ''));
            $category = sanitize_text_field((string) ($row['CategoryOfWarehouse'] ?? ''));
            $points[] = [
                'ref' => $ref,
                'label' => $description !== '' ? $description : $short_address,
                'shortAddress' => $short_address,
                'number' => sanitize_text_field((string) ($row['Number'] ?? '')),
                'kind' => strcasecmp($category, 'Postomat') === 0 ? 'postomat' : 'branch',
            ];
        }

        set_transient($cache_key, $points, self::CACHE_TTL);
        return $points;
    }
}
