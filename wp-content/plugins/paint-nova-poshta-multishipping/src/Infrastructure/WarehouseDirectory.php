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
        // Preserve the sender-settings contract. Checkout uses explicit pagination below.
        $page = $this->searchPage($city_ref, $query, 1);
        if (is_wp_error($page)) { return $page; }
        return array_values(array_filter($page['items'], static fn(array $item): bool => $item['selectable']));
    }

    /** One bounded API request. Pagination refers to RAW API rows, before type filtering. */
    public function searchPage(string $city_ref, string $query, int $page = 1, string $kind = '')
    {
        $city_ref = sanitize_text_field($city_ref);
        $query = sanitize_text_field($query);
        if ($city_ref === '' || $page < 1 || $page > 10000 || strlen($query) > 250) {
            return new WP_Error(
                'pnpm_warehouse_search_missing_input',
                __('Choose a registered sender address and enter a branch number or address.', 'paint-nova-poshta-multishipping')
            );
        }

        $cache_key = 'pnpm_points_v2_' . md5($city_ref . '|' . mb_strtolower($query) . '|' . $page);
        $cached = get_transient($cache_key);
        $response = is_array($cached) ? $cached : $this->api->call('Address', 'getWarehouses', [
            'CityRef' => $city_ref,
            'FindByString' => $query,
            'Page' => $page,
            'Limit' => self::RESULT_LIMIT,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        if (($response['success'] ?? false) !== true || !is_array($response['data'] ?? null)) {
            return new WP_Error('pnpm_warehouse_directory_rejected', __('Nova Poshta directory could not be loaded.', 'paint-nova-poshta-multishipping'));
        }
        if (!is_array($cached)) { set_transient($cache_key, $response, self::CACHE_TTL); }
        $points = [];
        foreach ($response['data'] as $row) {
            if (!is_array($row) || ($row['CityRef'] ?? '') !== $city_ref || empty($row['Ref'])) { continue; }
            $point = $this->normalize($row);
            if ($kind !== '' && $point['kind'] !== $kind) { continue; }
            $points[$point['ref']] = $point;
        }
        $raw_count = count($response['data']);
        $total = $response['info']['totalCount'] ?? null;
        $more = $raw_count > 0 && (is_numeric($total) ? $page * self::RESULT_LIMIT < (int) $total : $raw_count >= self::RESULT_LIMIT);
        return ['items' => array_values($points), 'nextPage' => $more ? $page + 1 : null];
    }

    /** Restore a card after reload using an exact, server-verified Ref, never posted limits. */
    public function find(string $city_ref, string $ref)
    {
        if ($city_ref === '' || !preg_match('/^[a-f0-9-]{36}$/i', $ref)) {
            return new WP_Error('pnpm_point_invalid', __('Choose a Nova Poshta branch or parcel locker from the list.', 'paint-nova-poshta-multishipping'));
        }
        $response = $this->api->call('Address', 'getWarehouses', ['Ref' => $ref, 'CityRef' => $city_ref, 'Limit' => 1]);
        if (is_wp_error($response)) { return $response; }
        if (($response['success'] ?? false) === true) {
            foreach ((array) ($response['data'] ?? []) as $row) {
                if (is_array($row) && ($row['Ref'] ?? '') === $ref && ($row['CityRef'] ?? '') === $city_ref) { return $this->normalize($row); }
            }
        }
        return new WP_Error('pnpm_point_unavailable', __('The selected point could not be verified. Search and select it again.', 'paint-nova-poshta-multishipping'));
    }

    /** Whitelisted public directory data only; zero/absent limits are unknown, not unlimited. */
    private function normalize(array $row): array
    {
        $text = static fn($value): string => is_scalar($value) ? sanitize_text_field((string) $value) : '';
        $number = static fn($value): ?float => is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? (float) $value : null;
        $dimensions = static function ($value) use ($number): array {
            $value = is_array($value) ? $value : [];
            return array_map(static fn($key) => $number($value[$key] ?? null), ['Length', 'Width', 'Height']);
        };
        $hours = [];
        foreach (['Schedule', 'Reception', 'Delivery'] as $field) {
            foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
                $hours[$field][$day] = $text($row[$field][$day] ?? '');
            }
        }
        $status = $text($row['WarehouseStatus'] ?? '');
        return [
            'ref' => $text($row['Ref'] ?? ''),
            'label' => $text($row['Description'] ?? '') ?: $text($row['ShortAddress'] ?? ''),
            'shortAddress' => $text($row['ShortAddress'] ?? ''),
            'number' => $text($row['Number'] ?? ''),
            'kind' => strcasecmp($text($row['CategoryOfWarehouse'] ?? ''), 'Postomat') === 0 ? 'postomat' : 'branch',
            'status' => $status,
            'selectable' => strcasecmp($status, 'Working') === 0 && (string) ($row['DenyToSelect'] ?? '0') !== '1',
            'placeWeight' => $number($row['PlaceMaxWeightAllowed'] ?? null),
            'totalWeight' => $number($row['TotalMaxWeightAllowed'] ?? null),
            'declaredValue' => $number($row['MaxDeclaredCost'] ?? null),
            'receivingDimensions' => $dimensions($row['ReceivingLimitationsOnDimensions'] ?? []),
            'sendingDimensions' => $dimensions($row['SendingLimitationsOnDimensions'] ?? []),
            'hours' => $hours,
        ];
    }
}
