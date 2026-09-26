<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;
if (!current_user_can('manage_woocommerce')) return;
$page = max(1, absint($_GET['orders_page'] ?? 1));
$result = wc_get_orders(['type' => 'shop_order', 'limit' => 25, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC']);
$url = add_query_arg('view', 'orders', ManagerWorkspace::url(0));
?>
<section class="pcoe-card">
<details><summary><strong><?php esc_html_e('Where to find order details', 'pc-order-import-export'); ?></strong></summary>
<p><?php esc_html_e('Open an order number to see delivery, saved TTNs, recipient contacts, customer comments and order notes here. The WooCommerce button opens the full editor for additional actions.', 'pc-order-import-export'); ?></p>
<p><?php esc_html_e('The customer checkout comment is shown with the order details. Order notes contain the status history and manager notes. A private note stays internal; a note to the customer may send an email. These notes are not an inbox for customer email replies.', 'pc-order-import-export'); ?></p>
<p><?php esc_html_e('Review Nova Poshta shipment panels for saved TTNs and delivery status. A saved TTN does not by itself confirm dispatch. Review Folio document panels for linked warehouse documents. For a split order, also open its child orders.', 'pc-order-import-export'); ?></p>
</details>
<p><?php esc_html_e('Newest orders first. Open an order to review its items and linked Folio documents.', 'pc-order-import-export'); ?></p>
<div class="pcoe-scroll"><table class="widefat striped"><thead><tr>
<?php foreach ([__('Order', 'pc-order-import-export'), __('Date', 'pc-order-import-export'), __('Customer', 'pc-order-import-export'), __('Customer role', 'pc-order-import-export'), __('Status', 'pc-order-import-export'), __('Amount', 'pc-order-import-export'), __('Contact', 'pc-order-import-export')] as $heading): ?><th><?php echo esc_html($heading); ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ($result->orders as $entry):
$customer = $entry->get_customer_id() ? get_userdata($entry->get_customer_id()) : false;
$roles = wp_roles()->get_names();
$link = $entry->get_edit_order_url();
if ($customer) { try { ManagerWorkspace::customer($customer->ID); $link = ManagerWorkspace::url($customer->ID, $entry->get_id()); } catch (\Throwable $e) {} }
?>
<tr><td><a href="<?php echo esc_url($link); ?>">#<?php echo esc_html($entry->get_order_number()); ?></a></td>
<td><?php echo esc_html($entry->get_date_created() ? wc_format_datetime($entry->get_date_created(), get_option('date_format') . ' H:i') : '—'); ?></td>
<td><?php echo esc_html($entry->get_formatted_billing_full_name()); ?><br><?php echo esc_html($entry->get_billing_company()); ?><br><?php echo esc_html($entry->get_billing_email()); ?></td>
<td><?php echo esc_html($customer ? implode(', ', array_map(static fn($role) => translate_user_role($roles[$role] ?? $role), $customer->roles)) : __('Guest', 'pc-order-import-export')); ?></td>
<td><?php echo esc_html(wc_get_order_status_name($entry->get_status())); ?></td><td><?php echo wp_kses_post($entry->get_formatted_order_total()); ?></td>
<td><?php if (ManagerNotifications::reply_url($entry)): ?><a href="<?php echo esc_url(ManagerNotifications::reply_url($entry)); ?>"><?php esc_html_e('Reply to customer', 'pc-order-import-export'); ?></a><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$result->orders): ?><tr><td colspan="7"><?php esc_html_e('No orders found.', 'pc-order-import-export'); ?></td></tr><?php endif; ?>
</tbody></table></div>
<nav class="pcoe-actions">
<?php if ($page > 1): ?><a class="button" href="<?php echo esc_url(add_query_arg('orders_page', $page - 1, $url)); ?>"><?php esc_html_e('Previous page', 'pc-order-import-export'); ?></a><?php endif; ?>
<?php if ($page < $result->max_num_pages): ?><a class="button" href="<?php echo esc_url(add_query_arg('orders_page', $page + 1, $url)); ?>"><?php esc_html_e('Next page', 'pc-order-import-export'); ?></a><?php endif; ?>
</nav></section>
