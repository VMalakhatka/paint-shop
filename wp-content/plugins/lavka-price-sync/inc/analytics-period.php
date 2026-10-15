<?php
if (!defined('ABSPATH')) exit;

/** Saved relative periods contain a duration, never stale calculated dates. */
function lps_analytics_period_profile(array $period): array {
    $mode = (string)($period['mode'] ?? 'FIXED');
    if ($mode === 'ROLLING_DAYS') {
        $days = filter_var($period['days'] ?? null, FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 3650) {
            throw new InvalidArgumentException(__('Set the analysis period between 1 and 3650 whole days.', 'lavka-price-sync'));
        }
        return ['mode' => 'ROLLING_DAYS', 'days' => $days];
    }
    if ($mode !== 'FIXED') throw new InvalidArgumentException(__('Select a valid analysis period mode.', 'lavka-price-sync'));
    $result = ['mode' => 'FIXED'];
    foreach (['from', 'to'] as $key) {
        $value = (string)($period[$key] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $result[$key] = $date && $date->format('Y-m-d') === $value ? $value : '';
    }
    return $result;
}

/** Resolve once at launch using the site's calendar date. Both boundaries are inclusive. */
function lps_analytics_period_resolve(array $period, ?string $as_of = null): array {
    $profile = lps_analytics_period_profile($period);
    if ($profile['mode'] === 'FIXED') return ['from' => $profile['from'], 'to' => $profile['to']];
    $as_of = $as_of ?? wp_date('Y-m-d');
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $as_of, new DateTimeZone('UTC'));
    if (!$end || $end->format('Y-m-d') !== $as_of) throw new InvalidArgumentException(__('Select a valid report period.', 'lavka-price-sync'));
    return ['from' => $end->modify('-' . ($profile['days'] - 1) . ' days')->format('Y-m-d'), 'to' => $as_of];
}
