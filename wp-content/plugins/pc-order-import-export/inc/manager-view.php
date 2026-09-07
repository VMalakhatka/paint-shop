<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;
$customer_id = absint($_GET['customer_id'] ?? 0);
$order_id = absint($_GET['order_id'] ?? 0);
$search = sanitize_text_field(wp_unslash($_GET['customer_search'] ?? ''));
$user = null; $order = null; $error = '';
try {
    if ($customer_id) $user = ManagerWorkspace::customer($customer_id);
    if ($order_id && $user) $order = ManagerWorkspace::order($order_id, $customer_id);
} catch (\Throwable $e) { $error = $e->getMessage(); }
$form_fields = static function (string $operation) use ($customer_id, $order): void {
    echo '<input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="customer_id" value="' . esc_attr($customer_id) . '">';
    echo '<input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
    if ($order) echo '<input type="hidden" name="order_id" value="' . esc_attr($order->get_id()) . '"><input type="hidden" name="revision" value="' . esc_attr(ManagerWorkspace::revision($order)) . '">';
};
?>
<div class="wrap pcoe-manager">
<h1><?php esc_html_e('Customer workspace', 'pc-order-import-export'); ?></h1>
<p><?php esc_html_e('Select a customer, prepare a draft and review Folio accounts by warehouse.', 'pc-order-import-export'); ?></p>
<?php if ($error): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<div id="pcoe-manager-status" role="status" aria-live="polite"></div>
<section class="pcoe-card">
<form method="get" class="pcoe-search">
<input type="hidden" name="page" value="<?php echo esc_attr(ManagerWorkspace::PAGE); ?>">
<label for="pcoe-search"><?php esc_html_e('Find customer by name, company or email', 'pc-order-import-export'); ?></label>
<input id="pcoe-search" name="customer_search" type="search" value="<?php echo esc_attr($search); ?>" minlength="2" required>
<button class="button"><?php esc_html_e('Find customer', 'pc-order-import-export'); ?></button>
</form>
<?php if (mb_strlen($search) >= 2):
    $query = new \WP_User_Query(['role__in' => ['customer', 'opt', 'partner'], 'number' => 30, 'search' => '*' . $search . '*', 'search_columns' => ['user_login', 'user_email', 'display_name']]);
    $matches = $query->get_results();
    $by_company = get_users(['role__in' => ['customer', 'opt', 'partner'], 'number' => 30, 'meta_query' => ['relation' => 'OR', ['key' => 'billing_company', 'value' => $search, 'compare' => 'LIKE'], ['key' => '_folio_partner_name', 'value' => $search, 'compare' => 'LIKE'], ['key' => '_folio_partner_short_name', 'value' => $search, 'compare' => 'LIKE']]]);
    $found = []; foreach (array_merge($matches, $by_company) as $match) $found[$match->ID] = $match;
    if (!$found) echo '<p>' . esc_html__('No customers found.', 'pc-order-import-export') . '</p>';
    echo '<ul class="pcoe-customers">';
    foreach (array_slice($found, 0, 30) as $match) echo '<li><a href="' . esc_url(ManagerWorkspace::url($match->ID)) . '">' . esc_html($match->display_name) . '</a> · ' . esc_html($match->user_email) . ' · ' . esc_html(get_user_meta($match->ID, 'billing_company', true)) . '</li>';
    echo '</ul><p class="description">' . esc_html__('Up to 30 customers are shown. Refine the search if needed.', 'pc-order-import-export') . '</p>';
endif; ?>
</section>
<?php if ($user):
$context = function_exists('pc_folio_balance_user_context') ? pc_folio_balance_user_context($customer_id, false) : [];
?>
<section class="pcoe-card pcoe-customer-heading">
<h2><?php echo esc_html($user->display_name); ?></h2>
<p><?php echo esc_html(get_user_meta($customer_id, 'billing_company', true)); ?> · <?php echo esc_html($user->user_email); ?></p>
<p><?php esc_html_e('Folio customer:', 'pc-order-import-export'); ?> <strong><?php echo esc_html($context['name'] ?? ''); ?> <?php echo esc_html($context['short_name'] ?? ''); ?></strong></p>
<p><?php esc_html_e('Price role:', 'pc-order-import-export'); ?> <?php $roles = wp_roles()->get_names(); echo esc_html(translate_user_role($roles[$user->roles[0] ?? ''] ?? '')); ?></p>
<p><?php esc_html_e('The draft belongs to this customer. Manager actions are recorded separately.', 'pc-order-import-export'); ?></p>
<nav class="pcoe-actions"><a href="<?php echo esc_url(ManagerWorkspace::url($customer_id)); ?>" class="button"><?php esc_html_e('Customer drafts and orders', 'pc-order-import-export'); ?></a>
<?php if ($context): ?><a href="#pcoe-folio-documents" class="button"><?php esc_html_e('Folio documents', 'pc-order-import-export'); ?></a>
<a class="button" href="<?php echo esc_url(pc_folio_balance_admin_url($customer_id)); ?>"><?php esc_html_e('Customer balance', 'pc-order-import-export'); ?></a><?php endif; ?></nav>
<?php if (!$context): ?><p class="pcoe-warning"><?php esc_html_e('Link the customer to Folio in the user profile before preparing Folio documents. Drafts are available now.', 'pc-order-import-export'); ?></p><?php endif; ?>
</section>
<?php if (!$order): ?>
<section class="pcoe-card"><h2><?php esc_html_e('New customer draft', 'pc-order-import-export'); ?></h2>
<form data-pcoe-manager><?php $form_fields('new'); ?>
<label><?php esc_html_e('Draft title', 'pc-order-import-export'); ?><input name="title" type="text" maxlength="200"></label>
<button class="button button-primary"><?php esc_html_e('Create empty draft', 'pc-order-import-export'); ?></button></form>
<hr>
<form data-pcoe-manager enctype="multipart/form-data"><?php $form_fields('import'); ?>
<label><?php esc_html_e('Import Excel or CSV', 'pc-order-import-export'); ?><input type="file" name="file" accept=".csv,.xls,.xlsx" required></label>
<label><?php esc_html_e('Draft title', 'pc-order-import-export'); ?><input name="title" type="text" maxlength="200"></label>
<p class="description"><?php esc_html_e('Up to 10 MB and 2000 rows. File prices are kept for reference; Folio preparation uses current customer prices.', 'pc-order-import-export'); ?></p>
<button class="button"><?php esc_html_e('Import into customer draft', 'pc-order-import-export'); ?></button>
<a href="<?php echo esc_url(content_url('/mu-plugins/pc-wholesale-help/assets/wholesale-order-template.xlsx')); ?>"><?php esc_html_e('Download Excel template', 'pc-order-import-export'); ?></a>
</form><div data-import-report></div></section>
<section class="pcoe-card"><h2><?php esc_html_e('Customer drafts and orders', 'pc-order-import-export'); ?></h2>
<?php
$page = max(1, absint($_GET['orders_page'] ?? 1));
$list = wc_get_orders(['customer_id' => $customer_id, 'limit' => 25, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC']);
?>
<div class="pcoe-scroll"><table class="widefat striped"><thead><tr><th><?php esc_html_e('Order', 'pc-order-import-export'); ?></th><th><?php esc_html_e('Draft title', 'pc-order-import-export'); ?></th><th><?php esc_html_e('Status', 'pc-order-import-export'); ?></th><th><?php esc_html_e('Amount', 'pc-order-import-export'); ?></th></tr></thead><tbody>
<?php foreach ($list->orders as $row): ?><tr><td><a href="<?php echo esc_url(ManagerWorkspace::url($customer_id, $row->get_id())); ?>">#<?php echo esc_html($row->get_order_number()); ?></a><br><?php echo esc_html($row->get_date_created() ? wc_format_datetime($row->get_date_created()) : ''); ?></td><td><?php echo esc_html($row->get_meta('_pc_draft_title')); ?></td><td><?php echo esc_html(wc_get_order_status_name($row->get_status())); ?></td><td><?php echo wp_kses_post($row->get_formatted_order_total()); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="pcoe-actions"><?php if ($page > 1): ?><a class="button" href="<?php echo esc_url(add_query_arg('orders_page', $page - 1, ManagerWorkspace::url($customer_id))); ?>"><?php esc_html_e('Previous page', 'pc-order-import-export'); ?></a><?php endif; ?>
<?php if ($page < $list->max_num_pages): ?><a class="button" href="<?php echo esc_url(add_query_arg('orders_page', $page + 1, ManagerWorkspace::url($customer_id))); ?>"><?php esc_html_e('Next page', 'pc-order-import-export'); ?></a><?php endif; ?></div>
</section>
<?php else:
$editable = ManagerWorkspace::editable($order);
$command = ManagerWorkspace::command($order);
?>
<section class="pcoe-card"><h2><?php echo esc_html(sprintf(__('Order #%s', 'pc-order-import-export'), $order->get_order_number())); ?></h2>
<div class="pcoe-actions"><a class="button" href="<?php echo esc_url($order->get_edit_order_url()); ?>"><?php esc_html_e('Open WooCommerce order', 'pc-order-import-export'); ?></a>
<?php foreach (['csv' => 'CSV', 'xlsx' => 'Excel'] as $fmt => $label): ?><a class="button" href="<?php echo esc_url(add_query_arg(['action' => 'pcoe_export', 'type' => 'order', 'order_id' => $order->get_id(), 'fmt' => $fmt, '_wpnonce' => wp_create_nonce('pcoe_export')], admin_url('admin-ajax.php'))); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?>
<form data-pcoe-manager><?php $form_fields('copy'); ?><button class="button"><?php esc_html_e('Copy to a new draft', 'pc-order-import-export'); ?></button></form></div>
<?php if ($command): ?><p class="pcoe-warning"><?php echo esc_html(($command['status'] ?? '') === 'complete' ? __('Folio documents were created. This draft is locked against duplicate creation.', 'pc-order-import-export') : __('Operation needs review. Do not submit it again or create a replacement until its Folio result is checked.', 'pc-order-import-export')); ?></p>
<p><?php esc_html_e('Operation ID:', 'pc-order-import-export'); ?> <code><?php echo esc_html($command['token'] ?? ''); ?></code></p><?php endif; ?>
<form data-pcoe-manager><?php $form_fields('save'); ?>
<label><?php esc_html_e('Draft title', 'pc-order-import-export'); ?><input type="text" name="title" value="<?php echo esc_attr($order->get_meta('_pc_draft_title')); ?>" <?php disabled(!$editable); ?>></label>
<div class="pcoe-scroll"><table class="widefat striped"><thead><tr><th><?php esc_html_e('SKU / product', 'pc-order-import-export'); ?></th><th><?php esc_html_e('Quantity', 'pc-order-import-export'); ?></th><th><?php esc_html_e('Saved unit price', 'pc-order-import-export'); ?></th><th><?php esc_html_e('Amount', 'pc-order-import-export'); ?></th></tr></thead><tbody>
<?php foreach ($order->get_items() as $item_id => $item): $product = $item->get_product(); ?><tr><td><?php echo esc_html($product ? $product->get_sku() : ''); ?><br><?php echo esc_html($item->get_name()); ?></td><td><input aria-label="<?php echo esc_attr($item->get_name()); ?>" type="number" name="quantity[<?php echo esc_attr($item_id); ?>]" value="<?php echo esc_attr($item->get_quantity()); ?>" min="0" max="1000000" step="1" required <?php disabled(!$editable); ?>></td><td><?php echo wp_kses_post(wc_price($item->get_quantity() > 0 ? $item->get_total() / $item->get_quantity() : 0)); ?></td><td><?php echo wp_kses_post(wc_price($item->get_total())); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($editable): ?><p class="description"><?php esc_html_e('Set quantity to 0 to remove a line. Save changes before previewing.', 'pc-order-import-export'); ?></p>
<div class="pcoe-actions"><label><?php esc_html_e('Add product by SKU', 'pc-order-import-export'); ?><input name="sku" type="text"></label><label><?php esc_html_e('Quantity', 'pc-order-import-export'); ?><input name="add_quantity" type="number" value="1" min="1" max="1000000" step="1"></label></div>
<label><?php esc_html_e('Order note', 'pc-order-import-export'); ?><textarea name="note" rows="3"><?php echo esc_textarea($order->get_customer_note()); ?></textarea></label>
<button class="button button-primary"><?php esc_html_e('Save customer draft', 'pc-order-import-export'); ?></button><?php endif; ?>
</form>
<?php if ($source = $order->get_meta('_pcoe_source_order_id')): ?><p><?php esc_html_e('Source order:', 'pc-order-import-export'); ?> <a href="<?php echo esc_url(ManagerWorkspace::url($customer_id, $source)); ?>">#<?php echo esc_html($source); ?></a></p><?php endif; ?>
</section>
<?php if ($editable && $context): ?><section class="pcoe-card"><h2><?php esc_html_e('Prepare Folio documents', 'pc-order-import-export'); ?></h2>
<p><?php esc_html_e('This is the customer working basket. Preparation recalculates customer prices and stock; the customer website cart stays separate.', 'pc-order-import-export'); ?></p>
<form data-pcoe-manager data-preview-form><?php $form_fields('preview'); ?>
<div class="pcoe-actions"><label><?php esc_html_e('Operation', 'pc-order-import-export'); ?><select name="mode"><option value="accounts"><?php esc_html_e('Accounts by warehouse', 'pc-order-import-export'); ?></option><option value="non_accounting"><?php esc_html_e('Entire draft without reservation', 'pc-order-import-export'); ?></option></select></label>
<label><?php esc_html_e('Warehouse mode', 'pc-order-import-export'); ?><select name="warehouse_mode"><option value="auto"><?php esc_html_e('Automatic allocation', 'pc-order-import-export'); ?></option><option value="manual"><?php esc_html_e('Prioritize selected warehouse', 'pc-order-import-export'); ?></option><option value="single"><?php esc_html_e('Selected warehouse only', 'pc-order-import-export'); ?></option></select></label>
<label><?php esc_html_e('Warehouse', 'pc-order-import-export'); ?><select name="warehouse_id"><option value="0">—</option><?php $terms = get_terms(['taxonomy' => 'location', 'hide_empty' => false]); if (!is_wp_error($terms)) foreach ($terms as $term): ?><option value="<?php echo esc_attr($term->term_id); ?>"><?php echo esc_html($term->name); ?></option><?php endforeach; ?></select></label></div>
<p class="description"><?php esc_html_e('Available quantities become accounts with reservation. Shortages become separate non-accounting documents. Creating accounts does not create an expense invoice.', 'pc-order-import-export'); ?></p>
<button class="button"><?php esc_html_e('Preview prices and warehouses', 'pc-order-import-export'); ?></button></form>
<div data-preview-result hidden></div>
<form data-pcoe-manager data-apply-form hidden><?php $form_fields('apply'); ?><input type="hidden" name="token" value=""><label><input type="checkbox" name="confirmation" value="1" required> <?php esc_html_e('I checked this customer, quantities, prices and warehouses and confirm creation in Folio.', 'pc-order-import-export'); ?></label><button class="button button-primary"><?php esc_html_e('Confirm and create Folio documents', 'pc-order-import-export'); ?></button></form>
</section><?php endif; ?>
<?php $result = pc_folio_get_order_documents_result($order); if ($result): ?><section class="pcoe-card"><h2><?php esc_html_e('Saved Folio documents', 'pc-order-import-export'); ?></h2><?php ManagerWorkspace::documents_table($result);
$keys = pc_folio_order_documents_meta_keys(); foreach (array_filter((array) $order->get_meta($keys['child_order_ids'], true)) as $child_id): ?> <a class="button" href="<?php echo esc_url(ManagerWorkspace::url($customer_id, $child_id)); ?>">#<?php echo esc_html($child_id); ?></a> <?php endforeach; ?></section><?php endif; ?>
<?php endif; ?>
<?php if ($context && function_exists('pc_folio_documents_render_endpoint')): ?><section id="pcoe-folio-documents" class="pcoe-card"><?php pc_folio_documents_render_endpoint($customer_id); ?></section><?php endif; ?>
<?php endif; ?>
</div>
