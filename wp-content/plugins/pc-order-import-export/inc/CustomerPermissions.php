<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Runtime permissions: no persistent role migration, no account deletion. */
final class CustomerPermissions {
    private const ROLES = ['customer', 'opt', 'partner', 'opt_osn', 'schule'];

    public static function hooks(): void {
        add_filter('user_has_cap', [self::class, 'capabilities'], 100, 4);
        add_filter('map_meta_cap', [self::class, 'target_caps'], 100, 4);
        add_filter('woocommerce_shop_manager_editable_roles', [self::class, 'woo_roles'], 100);
        add_filter('editable_roles', [self::class, 'editable_roles'], 100);
    }

    private static function manager($user): bool {
        return $user instanceof \WP_User && !is_multisite()
            && in_array('shop_manager', $user->roles, true)
            && !in_array('administrator', $user->roles, true)
            && !empty($user->allcaps['manage_woocommerce']);
    }

    /** Fail closed for mixed staff/customer roles or individually elevated caps. */
    public static function customer($user): bool {
        if (!$user instanceof \WP_User || !$user->exists() || !$user->roles
            || array_diff($user->roles, self::ROLES)) return false;
        foreach ($user->allcaps as $cap => $enabled) {
            if ($enabled && !in_array($cap, array_merge(self::ROLES, ['read', 'level_0']), true)) return false;
        }
        return true;
    }

    public static function capabilities(array $allcaps, array $caps, array $args, $user): array {
        if (!self::manager($user)) return $allcaps;
        // WP core validates target edit/promote_user and its editable_roles on writes.
        $allcaps['list_users'] = true;
        $allcaps['edit_users'] = true;
        $allcaps['promote_users'] = true;
        foreach (['create_users', 'delete_users', 'remove_users'] as $cap) $allcaps[$cap] = false;
        return $allcaps;
    }

    public static function target_caps(array $caps, string $cap, int $actor_id, array $args): array {
        if (!self::manager(get_userdata($actor_id))) return $caps;
        if (in_array($cap, ['delete_user', 'remove_user'], true)) return ['do_not_allow'];
        if (!in_array($cap, ['edit_user', 'promote_user'], true)) return $caps;
        $target_id = (int) ($args[0] ?? 0);
        // Preserve ordinary self-profile editing, never allow self-promotion.
        if ($target_id === $actor_id && $cap === 'edit_user') return $caps;
        if ($target_id === $actor_id || !self::customer(get_userdata($target_id))) return ['do_not_allow'];
        return $caps; // Preserve denials made by core or other security plugins.
    }

    public static function woo_roles(array $roles): array {
        return self::manager(wp_get_current_user()) ? self::ROLES : $roles;
    }

    public static function editable_roles(array $roles): array {
        if (!self::manager(wp_get_current_user())) return $roles;
        $safe = [];
        foreach (self::ROLES as $role) {
            if (!isset($roles[$role])) continue;
            $caps = $roles[$role]['capabilities'] ?? [];
            // Do not offer a formerly customer role if it was elevated in settings.
            if (array_diff(array_keys(array_filter($caps)), ['read', 'level_0'])) continue;
            $safe[$role] = $roles[$role];
        }
        return $safe;
    }
}
