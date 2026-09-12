<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

/** Pure rules, independent of WordPress storage and the current request. */
final class WaitlistModel
{
    public const LIMIT = 200;

    public static function quantity($raw): int {
        if (!is_scalar($raw) || !preg_match('/^[0-9]{1,6}$/D', (string) $raw)) return 0;
        $value = (int) $raw;
        return $value >= 1 && $value <= 100000 ? $value : 0;
    }

    /** Overlapping sources represent alternative evidence, not additive demand. */
    public static function group(array $entries, int $now): array {
        $groups = [];
        foreach ($entries as $key => $entry) {
            if (($entry['status'] ?? '') !== 'active' || (int) ($entry['snooze_until'] ?? 0) > $now) continue;
            $quantity = max(0, (int) ($entry['quantity'] ?? 0));
            $product = (int) ($entry['product_id'] ?? 0);
            if ($quantity === 0 || $product <= 0) continue;
            if (!isset($groups[$product])) $groups[$product] = ['quantity' => 0, 'sources' => [], 'intents' => []];
            // Only explicitly partitioned pieces of the same request are summed.
            $intent = $entry['intent'] ?? $key;
            $groups[$product]['intents'][$intent] = ($groups[$product]['intents'][$intent] ?? 0) + $quantity;
            $groups[$product]['quantity'] = max($groups[$product]['intents']);
            $groups[$product]['sources'][$key] = $entry;
        }
        return $groups;
    }

    public static function remainder_reason(bool $purchasable, ?float $stock, float $requested, float $planned, float $added): string {
        if ($added >= $requested) return 'none';
        if (!$purchasable) return 'not_purchasable';
        if ($added < $planned) return $added > 0 ? 'cart_adjusted' : 'cart_rejected';
        if ($stock === null) return 'stock_unknown';
        if ($stock < $requested) return 'stock_shortage';
        if ($planned < $requested) return 'allocation_restricted';
        return 'none';
    }
}
