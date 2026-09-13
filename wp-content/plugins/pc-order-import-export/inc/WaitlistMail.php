<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

/** Pilot-only stock notices. Durable claims precede transport; no automatic retry. */
final class WaitlistMail
{
    public const HOOK = 'pcoe_waitlist_mail_tick';
    public const LOG_LIMIT = 100;

    public static function hooks(): void {
        add_filter('cron_schedules', static function ($schedules) {
            $schedules['pcoe_quarter_hour'] = ['interval' => 900, 'display' => 'PCOE 15 minutes'];
            return $schedules;
        });
        add_action('init', [self::class, 'schedule']);
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function enabled(): bool {
        return Waitlist::enabled() && get_option('pcoe_waitlist_mail_enabled', 'no') === 'yes';
    }

    public static function schedule(): void {
        if (self::enabled()) {
            if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time() + 60, 'pcoe_quarter_hour', self::HOOK);
        } elseif (wp_next_scheduled(self::HOOK)) wp_clear_scheduled_hook(self::HOOK);
    }

    /** Known recent stock cursor; never treat an unknown/mid-sync snapshot as arrival. */
    public static function stock_ready(): bool {
        $cursor = strtotime((string) get_option('lavka_sync_last_to', ''));
        if (!$cursor || $cursor > time() + 300 || time() - $cursor > DAY_IN_SECONDS) return false;
        $lock = function_exists('lavka_ecosystem_lock_get') ? lavka_ecosystem_lock_get() : null;
        return !$lock || ($lock['owner'] ?? '') !== 'lavka-sync' || lavka_ecosystem_lock_is_stale($lock);
    }

    /** Pure episode planner; positive -> positive never sends again, even if quantity grows. */
    public static function plan(array $state, array $stocks, int $now): array {
        $items = [];
        $state['mail_observed'] = $state['mail_observed'] ?? [];
        foreach ($stocks as $id => $row) {
            if ($row['stock'] === null) continue;
            $seen = $state['mail_observed'][$id] ?? ['notified' => false, 'last_attempt' => 0];
            if ($row['stock'] <= 0) $seen['notified'] = false;
            elseif (!$seen['notified'] && (!$seen['last_attempt'] || $now - $seen['last_attempt'] >= DAY_IN_SECONDS) && count($items) < 20) {
                $items[$id] = $row;
                $seen = ['notified' => true, 'last_attempt' => $now];
            }
            $state['mail_observed'][$id] = $seen;
        }
        return [$state, $items];
    }

    public static function run(): void {
        $user = (int) get_option('pcoe_waitlist_pilot_user_id', 0);
        if (!self::enabled() || !Waitlist::customer_allowed($user)) return;
        try {
            self::scan($user);
        } catch (\Throwable $e) {
            // No message bodies, recipient addresses or provider errors in ordinary logs.
            update_option('pcoe_waitlist_mail_health', ['at' => time(), 'status' => 'error'], false);
        }
    }

    private static function scan(int $user): void {
        $state = WaitlistStore::read($user);
        $original = $state;
        $status = 'checked';
        if (empty($state['email_enabled'])) $status = 'unsubscribed';
        elseif (!empty($state['pending'])) $status = 'transfer_pending';
        elseif (!self::stock_ready()) $status = 'stock_not_ready';
        elseif (count($state['mail_log'] ?? []) >= self::LOG_LIMIT) $status = 'journal_full';
        foreach ($state['mail_log'] ?? [] as $log) {
            if (in_array($log['status'], ['claimed', 'failed'], true)) $status = 'review_required';
        }
        update_option('pcoe_waitlist_mail_health', ['at' => time(), 'status' => $status], false);
        if ($status !== 'checked') return;
        $entries = Waitlist::effective_entries($state['entries'], $user);
        $groups = WaitlistModel::group($entries, time());
        $locations = PriceList::location_ids();
        $stocks = [];
        foreach ($groups as $id => $group) {
            $product = wc_get_product($id);
            if (!$product instanceof \WC_Product || !PriceList::eligible($product)) continue;
            $stocks[$id] = ['sku' => $product->get_sku(), 'name' => $product->get_name(), 'quantity' => $group['quantity'], 'stock' => PriceList::stock($product, $locations)];
        }
        // Keep paused requests' episode markers, but bound state to currently stored products.
        $ids = array_map('intval', array_column($state['entries'], 'product_id'));
        $state['mail_observed'] = array_intersect_key($state['mail_observed'] ?? [], array_fill_keys($ids, true));
        [$next, $items] = self::plan($state, $stocks, time());
        $recipient = get_user_by('id', $user);
        if (!$recipient || !is_email($recipient->user_email)) return;
        $id = wp_generate_uuid4();
        if ($items) {
            $next['mail_log'][$id] = ['at' => time(), 'status' => 'claimed', 'items' => $items, 'recipient_hash' => hash('sha256', strtolower($recipient->user_email))];
        }
        if ($next === $original || !WaitlistStore::save($user, $next) || !$items) return;
        // A competing scanner loses CAS before reaching transport. Recheck cancellation after claim.
        $fresh = WaitlistStore::read($user);
        $current = WaitlistModel::group(Waitlist::effective_entries($fresh['entries'], $user), time());
        $still_valid = self::enabled() && Waitlist::customer_allowed($user) && !empty($fresh['email_enabled']) && empty($fresh['pending']) && self::stock_ready();
        foreach ($items as $product_id => $row) {
            if (!isset($current[$product_id]) || $current[$product_id]['quantity'] !== $row['quantity']) $still_valid = false;
        }
        $current_recipient = get_user_by('id', $user);
        if (!$current_recipient || $current_recipient->user_email !== $recipient->user_email) $still_valid = false;
        if (!$still_valid) { self::complete($user, $id, 'cancelled'); return; }
        $switched = switch_to_locale(get_user_locale($user));
        try {
            [$subject, $body] = self::message($items);
            $ok = wp_mail($recipient->user_email, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
            self::complete($user, $id, $ok ? 'accepted' : 'failed');
        } finally {
            if ($switched) restore_previous_locale();
        }
    }

    /** A failed completion leaves a durable unknown claim; never re-send to repair the journal. */
    private static function complete(int $user, string $id, string $status): void {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $state = WaitlistStore::read($user);
            if (($state['mail_log'][$id]['status'] ?? '') !== 'claimed') return;
            $state['mail_log'][$id]['status'] = $status;
            $state['mail_log'][$id]['finished_at'] = time();
            if (WaitlistStore::save($user, $state)) return;
        }
    }

    /** Explicit operator smoke test; a fixed durable key prevents uncertain test replays. */
    public static function send_test(): void {
        if (!defined('WP_CLI') || !WP_CLI) throw new \RuntimeException('CLI only');
        $user = (int) get_option('pcoe_waitlist_pilot_user_id', 0);
        if (!self::enabled() || !Waitlist::customer_allowed($user)) throw new \RuntimeException('Pilot disabled');
        $state = WaitlistStore::read($user);
        if (empty($state['email_enabled']) || isset($state['mail_log']['transport-test-v1']) || count($state['mail_log'] ?? []) >= self::LOG_LIMIT) throw new \RuntimeException('Test not authorized or already attempted');
        $recipient = get_user_by('id', $user);
        if (!$recipient || !is_email($recipient->user_email)) throw new \RuntimeException('Invalid pilot address');
        $state['mail_log']['transport-test-v1'] = ['at' => time(), 'status' => 'claimed', 'items' => [], 'recipient_hash' => hash('sha256', strtolower($recipient->user_email))];
        if (!WaitlistStore::save($user, $state)) throw new \RuntimeException('State changed');
        $switched = switch_to_locale(get_user_locale($user));
        try {
            $subject = __('Test: KREUL waiting-list email notifications', 'pc-order-import-export');
            $body = __('This is a test of your waiting-list email notifications. It does not mean that your product has arrived. We will email you when tracked products are available. You can turn these emails off in your waiting list.', 'pc-order-import-export') . "\n\n" . wc_get_account_endpoint_url(Waitlist::ENDPOINT);
            $ok = wp_mail($recipient->user_email, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
            self::complete($user, 'transport-test-v1', $ok ? 'accepted' : 'failed');
        } finally {
            if ($switched) restore_previous_locale();
        }
    }

    public static function message(array $items): array {
        $subject = __('Your waiting-list products are available — KREUL', 'pc-order-import-export');
        $lines = [__('Products from your waiting list are available now:', 'pc-order-import-export'), ''];
        foreach ($items as $row) {
            $lines[] = wp_strip_all_tags($row['name']) . ' (' . $row['sku'] . ')';
            /* translators: 1: requested quantity, 2: currently available quantity. */
            $lines[] = sprintf(__('Requested: %1$s. Available now: %2$s.', 'pc-order-import-export'), $row['quantity'], wc_format_localized_decimal($row['stock']));
            $lines[] = '';
        }
        $lines[] = __('Stock is not reserved. Review current availability in your account before ordering.', 'pc-order-import-export');
        $lines[] = wc_get_account_endpoint_url(Waitlist::ENDPOINT);
        $lines[] = '';
        $lines[] = __('To stop these emails, open your waiting list and turn off email notifications. You can also postpone or remove individual requests.', 'pc-order-import-export');
        return [$subject, implode("\n", $lines)];
    }

    public static function settings(): void {
        if (!current_user_can('manage_woocommerce')) return;
        echo '<h2>' . esc_html__('Email notification journal', 'pc-order-import-export') . '</h2>';
        echo '<p>' . esc_html__('Accepted means handed to the mail service, not confirmed delivery. Failed or unknown attempts are not retried automatically.', 'pc-order-import-export') . '</p>';
        $health = get_option('pcoe_waitlist_mail_health', []);
        echo '<p>' . esc_html__('Last background check', 'pc-order-import-export') . ': ' . esc_html(!empty($health['at']) ? wp_date('Y-m-d H:i:s', $health['at']) : '—') . ' · ' . esc_html(self::label($health['status'] ?? 'not_checked')) . '</p>';
        $user = (int) get_option('pcoe_waitlist_pilot_user_id', 0);
        if (!$user || (int) get_option('pcoe_waitlist_schema') !== 1) return;
        try { $state = WaitlistStore::read($user); } catch (\Throwable $e) { return; }
        echo '<table class="widefat"><thead><tr><th>' . esc_html__('Date', 'pc-order-import-export') . '</th><th>' . esc_html__('Status', 'pc-order-import-export') . '</th><th>' . esc_html__('SKU', 'pc-order-import-export') . '</th></tr></thead><tbody>';
        foreach (array_reverse($state['mail_log'] ?? []) as $log) {
            echo '<tr><td>' . esc_html(wp_date('Y-m-d H:i:s', $log['at'])) . '</td><td>' . esc_html(self::label($log['status'])) . '</td><td>' . esc_html(implode(', ', array_column($log['items'], 'sku'))) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function label(string $status): string {
        $labels = [
            'checked' => __('Checked', 'pc-order-import-export'),
            'unsubscribed' => __('Email notifications are off', 'pc-order-import-export'),
            'transfer_pending' => __('Waiting for cart or draft review', 'pc-order-import-export'),
            'stock_not_ready' => __('Waiting for a recent completed stock update', 'pc-order-import-export'),
            'journal_full' => __('Journal full — sending paused', 'pc-order-import-export'),
            'review_required' => __('Sending paused — review the previous attempt', 'pc-order-import-export'),
            'error' => __('Background check failed', 'pc-order-import-export'),
            'not_checked' => __('Not checked yet', 'pc-order-import-export'),
            'claimed' => __('Started — outcome not confirmed', 'pc-order-import-export'),
            'accepted' => __('Accepted by mail service', 'pc-order-import-export'),
            'failed' => __('Mail service reported failure', 'pc-order-import-export'),
            'cancelled' => __('Cancelled before sending', 'pc-order-import-export'),
        ];
        return $labels[$status] ?? $status;
    }
}
