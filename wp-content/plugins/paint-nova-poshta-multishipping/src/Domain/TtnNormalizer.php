<?php

namespace Paint\NovaPoshta\Domain;

use WP_Error;

defined('ABSPATH') || exit;

final class TtnNormalizer
{
    /** @return string|WP_Error */
    public function normalize(string $input)
    {
        $input = trim(wp_strip_all_tags($input));
        if ($input === '') {
            return new WP_Error('pnpm_ttn_empty', __('Enter the shipment number or official tracking link.', 'paint-nova-poshta-multishipping'));
        }

        if (filter_var($input, FILTER_VALIDATE_URL)) {
            $host = strtolower((string) wp_parse_url($input, PHP_URL_HOST));
            $allowed = apply_filters('pnpm_official_tracking_hosts', [
                'tracking.novaposhta.ua',
                'novaposhta.ua',
                'www.novaposhta.ua',
                'novapost.com',
                'www.novapost.com',
            ]);
            if (!in_array($host, $allowed, true)) {
                return new WP_Error('pnpm_ttn_untrusted_url', __('Only an official Nova Poshta tracking link is allowed.', 'paint-nova-poshta-multishipping'));
            }
        }

        if (!preg_match('/(?<!\d)(\d{14})(?!\d)/', $input, $matches)) {
            return new WP_Error('pnpm_ttn_invalid', __('A canonical 14-digit shipment number was not found.', 'paint-nova-poshta-multishipping'));
        }

        return (string) $matches[1];
    }
}

