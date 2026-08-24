<?php

namespace Paint\NovaPoshta\Infrastructure;

use WP_Error;

defined('ABSPATH') || exit;

final class SenderDirectory
{
    private const CACHE_KEY = 'pnpm_sender_directory_v1';
    private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

    public function __construct(private readonly ApiClient $api)
    {
    }

    /** @return array<string,mixed>|WP_Error */
    public function load(bool $refresh = false)
    {
        if (!$refresh) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = $this->api->call('Counterparty', 'getCounterparties', [
            'CounterpartyProperty' => 'Sender',
            'Page' => 1,
        ]);
        $error = $this->responseError($response, 'counterparties');
        if ($error) {
            return $error;
        }

        $counterparties = [];
        foreach ((array) ($response['data'] ?? []) as $counterparty) {
            if (!is_array($counterparty)) {
                continue;
            }
            $ref = sanitize_text_field((string) ($counterparty['Ref'] ?? ''));
            if ($ref === '') {
                continue;
            }

            $addresses = $this->api->call('Counterparty', 'getCounterpartyAddresses', [
                'Ref' => $ref,
                'CounterpartyProperty' => 'Sender',
            ]);
            $error = $this->responseError($addresses, 'addresses');
            if ($error) {
                return $error;
            }

            $contacts = $this->api->call('Counterparty', 'getCounterpartyContactPersons', [
                'Ref' => $ref,
                'Page' => 1,
            ]);
            $error = $this->responseError($contacts, 'contacts');
            if ($error) {
                return $error;
            }

            $counterparties[] = [
                'ref' => $ref,
                'description' => $this->firstText($counterparty, ['CounterpartyFullName', 'Description']),
                'cityDescription' => $this->firstText($counterparty, ['CityDescription']),
                'addresses' => $this->normalizeAddresses((array) ($addresses['data'] ?? [])),
                'contacts' => $this->normalizeContacts((array) ($contacts['data'] ?? [])),
            ];
        }

        if ($counterparties === []) {
            return new WP_Error(
                'pnpm_sender_not_found',
                __('No sender was found for this Nova Poshta business account.', 'paint-nova-poshta-multishipping')
            );
        }

        $directory = [
            'counterparties' => $counterparties,
            'loadedAt' => current_time('mysql'),
        ];
        set_transient(self::CACHE_KEY, $directory, self::CACHE_TTL);

        return $directory;
    }

    /** @param array<int,mixed> $rows */
    private function normalizeAddresses(array $rows): array
    {
        $addresses = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ref = sanitize_text_field((string) ($row['Ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $addresses[] = [
                'ref' => $ref,
                'description' => $this->firstText($row, ['AddressName', 'Description']),
                'cityRef' => sanitize_text_field((string) ($row['CityRef'] ?? '')),
                'cityDescription' => $this->firstText($row, ['CityDescription']),
            ];
        }
        return $addresses;
    }

    /** @param array<int,mixed> $rows */
    private function normalizeContacts(array $rows): array
    {
        $contacts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ref = sanitize_text_field((string) ($row['Ref'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $contacts[] = [
                'ref' => $ref,
                'description' => $this->firstText($row, ['Description']),
                'phones' => preg_replace('/[^0-9,; +]/', '', (string) ($row['Phones'] ?? '')) ?? '',
            ];
        }
        return $contacts;
    }

    /** @param array<string,mixed>|WP_Error $response */
    private function responseError($response, string $part): ?WP_Error
    {
        if (is_wp_error($response)) {
            return $response;
        }
        if (($response['success'] ?? false) === true) {
            return null;
        }

        $messages = array_merge((array) ($response['errors'] ?? []), (array) ($response['warnings'] ?? []));
        $message = $messages
            ? implode('; ', array_map('sanitize_text_field', $messages))
            : __('Nova Poshta rejected the sender directory request.', 'paint-nova-poshta-multishipping');

        return new WP_Error('pnpm_sender_directory_' . $part, $message);
    }

    /** @param array<string,mixed> $row @param string[] $keys */
    private function firstText(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = sanitize_text_field((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}
