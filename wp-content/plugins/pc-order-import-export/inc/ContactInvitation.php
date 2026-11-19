<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

final class ContactInvitation {
    public const META = '_pcoe_contact_invitation';

    public static function blocked(array $row): bool {
        $status = mb_strtolower(trim($row['Статус обращения'] ?? ''));
        return in_array($status,['не писать','не писати','do not contact'],true);
    }

    /** Called under the import lock after verifying the new account and its card. */
    public static function send(int $card, \WP_User $customer, array $source, bool $addressBlocked): string {
        $previous = get_post_meta($card,self::META,true);
        if ($previous !== '') return $previous;
        $status = 'blocked';
        if (!$addressBlocked && !self::blocked($source)) {
            if (!add_post_meta($card,self::META,'review',true)) throw new \RuntimeException(__('Could not save import progress. Reload the page before continuing.', 'pc-order-import-export'));
            // Persist an uncertain outcome before calling the mail transport.
            $status = 'failed';
            $key = get_password_reset_key($customer);
            if (!is_wp_error($key)) {
                $reset = network_site_url('wp-login.php?action=rp&key='.rawurlencode($key).'&login='.rawurlencode($customer->user_login),'login');
                $account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
                $message = sprintf("Вітаємо, %s!\n\nДля вашої організації ми створили кабінет у магазині матеріалів для творчості «Лавка художника». У кабінеті можна переглянути доступні вам ціни та замовити матеріали для занять.\n\nУстановити пароль для першого входу:\n%s\n\nНадалі заходьте до кабінету за посиланням:\n%s\n\nЯкщо маєте запитання, дайте відповідь на цей лист.\nКоманда «Лавки художника»", $customer->display_name, $reset, $account);
                if (wp_mail($customer->user_email,'Запрошення до кабінету «Лавки художника»',$message,['Content-Type: text/plain; charset=UTF-8'])) $status = 'accepted';
            }
        }
        update_post_meta($card,self::META,$status);
        $saved = get_post_meta($card,self::META,true);
        return $saved ?: 'review';
    }

    public static function label(string $status): string {
        switch ($status) {
            case 'accepted': return __('Accepted by the mail service','pc-order-import-export');
            case 'blocked': return __('Invitation skipped: do not contact','pc-order-import-export');
            case 'failed': return __('Invitation failed; send it manually from the customer profile.','pc-order-import-export');
            case 'review': return __('Invitation outcome needs review; check the mail log.','pc-order-import-export');
            default: return '';
        }
    }
}
