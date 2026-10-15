<?php
if (!defined('ABSPATH')) exit;

function lps_purchase_draft_table(): string { global $wpdb; return $wpdb->prefix . 'lps_purchase_drafts'; }
function lps_purchase_draft_schema(): void {
    if (get_option('lps_purchase_drafts_schema') === '1') return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = lps_purchase_draft_table(); $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $table (
        token char(32) NOT NULL,
        owner_id bigint(20) unsigned NOT NULL,
        revision bigint(20) unsigned NOT NULL DEFAULT 1,
        title varchar(255) NOT NULL,
        state_json longtext NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (token),
        KEY owner_updated (owner_id,updated_at)
    ) $charset;");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table)
        throw new RuntimeException(__('Could not save the order draft. Please try again.', 'lavka-price-sync'));
    update_option('lps_purchase_drafts_schema', '1', false);
}
function lps_purchase_draft_load(string $token): ?array {
    lps_purchase_session_key($token); // Validate token and bind all accesses to current user.
    if (get_option('lps_purchase_drafts_schema') !== '1') return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT state_json,revision,updated_at FROM ' . lps_purchase_draft_table() . ' WHERE token=%s AND owner_id=%d', $token, get_current_user_id()), ARRAY_A);
    if (!$row) return null;
    $state = json_decode($row['state_json'], true);
    if (!is_array($state) || empty($state['complete']) || !is_array($state['draftResults'] ?? null))
        throw new RuntimeException(__('The saved draft is incomplete. Start a new calculation.', 'lavka-price-sync'));
    $state['draftRevision'] = (int)$row['revision']; $state['draftUpdatedAt'] = $row['updated_at'];
    return $state;
}
function lps_purchase_draft_save(string $token, array $state, array $results, int $revision): int {
    lps_purchase_session_key($token);
    if (empty($state['complete'])) throw new InvalidArgumentException(__('Complete the preview before saving a draft.', 'lavka-price-sync'));
    lps_purchase_draft_schema();
    global $wpdb;
    $state['draftResults'] = $results;
    unset($state['draftRevision'], $state['draftUpdatedAt']);
    $json = wp_json_encode($state, JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) throw new RuntimeException(__('Could not save the order draft. Please try again.', 'lavka-price-sync'));
    $now = current_time('mysql', true); $table = lps_purchase_draft_table();
    if ($revision === 0) {
        $ok = $wpdb->insert($table, ['token'=>$token,'owner_id'=>get_current_user_id(),'revision'=>1,
            'title'=>$state['scenario']['name'],'state_json'=>$json,'created_at'=>$now,'updated_at'=>$now]);
    } else {
        // Atomic compare-and-swap: a stale browser tab may never overwrite newer edits.
        $ok = $wpdb->query($wpdb->prepare("UPDATE $table SET state_json=%s,revision=revision+1,updated_at=%s WHERE token=%s AND owner_id=%d AND revision=%d",
            $json, $now, $token, get_current_user_id(), $revision));
        if ($ok === 0) throw new InvalidArgumentException(__('This draft changed in another tab. Reopen it before saving.', 'lavka-price-sync'));
    }
    if (!$ok) throw new RuntimeException(__('Could not save the order draft. Please try again.', 'lavka-price-sync'));
    return $revision + 1;
}
function lps_purchase_drafts_list(): array {
    if (get_option('lps_purchase_drafts_schema') !== '1') return [];
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT token,title,created_at,updated_at FROM ' . lps_purchase_draft_table() . ' WHERE owner_id=%d ORDER BY updated_at DESC LIMIT 100', get_current_user_id()), ARRAY_A) ?: [];
}
function lps_purchase_draft_open(string $token): array {
    $state = lps_purchase_draft_load($token);
    if (!$state) throw new InvalidArgumentException(__('Saved order draft not found.', 'lavka-price-sync'));
    // Current calculation version is checked by the same gate as adjustment/export.
    $state = lps_purchase_session($token);
    return ['token'=>$token,'items'=>$state['draftResults'],'revision'=>$state['draftRevision'],
        'scenario'=>$state['scenario'],'query'=>$state['query'],'groups'=>$state['groups'],
        'createdAt'=>$state['createdAt'],'updatedAt'=>$state['draftUpdatedAt'],
        'currentStockCapture'=>$state['currentStockCapture'] ?? null];
}
