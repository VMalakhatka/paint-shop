<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

/** Bounded per-customer state; the primary key and revision prevent lost updates. */
final class WaitlistStore
{
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'pcoe_waitlist';
    }

    /** Only called by the explicit, nonce-protected manager setup action. */
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta("CREATE TABLE $table (
            user_id bigint(20) unsigned NOT NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            PRIMARY KEY  (user_id)
        ) " . $wpdb->get_charset_collate() . ';');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            throw new \RuntimeException('Waitlist storage could not be created.');
        }
        update_option('pcoe_waitlist_schema', 1, false);
    }

    public static function read(int $user): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT revision, payload FROM ' . self::table() . ' WHERE user_id = %d', $user), ARRAY_A);
        if ($wpdb->last_error) throw new \Exception('Waitlist storage unavailable.');
        if (!$row) return ['revision' => 0, 'entries' => [], 'pending' => null];
        $data = json_decode($row['payload'], true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            throw new \Exception('Waitlist state is invalid.');
        }
        $data['revision'] = (int) $row['revision'];
        return $data;
    }

    /** Compare-and-swap also protects the first write via the unique customer key. */
    public static function save(int $user, array $data): bool {
        global $wpdb;
        $revision = (int) $data['revision'];
        unset($data['revision']);
        $json = wp_json_encode($data);
        if (!is_string($json)) return false;
        if ($revision === 0) {
            return $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::table() . ' (user_id, revision, payload) VALUES (%d, 1, %s)', $user, $json)) === 1;
        }
        return $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET payload = %s, revision = revision + 1 WHERE user_id = %d AND revision = %d', $json, $user, $revision)) === 1;
    }
}
