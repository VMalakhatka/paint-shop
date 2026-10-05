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
<?php ManagerHelp::link(); ?>
<p><?php esc_html_e('Select a customer, prepare a draft and review Folio accounts by warehouse.', 'pc-order-import-export'); ?></p>
<?php $messages_tab = ($_GET['view'] ?? '') === 'messages'; $mailings_tab = ($_GET['view'] ?? '') === 'mailings'; $orders_tab = !$customer_id && ($_GET['view'] ?? '') === 'orders'; ?>
<nav class="nav-tab-wrapper">
<a class="nav-tab <?php echo !$orders_tab && !$messages_tab && !$mailings_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(ManagerWorkspace::url(0)); ?>"><?php esc_html_e('Customers', 'pc-order-import-export'); ?></a>
<a class="nav-tab <?php echo $orders_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('view', 'orders', ManagerWorkspace::url(0))); ?>"><?php esc_html_e('Orders', 'pc-order-import-export'); ?></a>
<a class="nav-tab <?php echo $messages_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(Conversations::url([],true)); ?>"><?php esc_html_e('Conversations', 'pc-order-import-export'); ?></a>
<a class="nav-tab <?php echo $mailings_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(BroadcastUi::url()); ?>"><?php esc_html_e('Mailings', 'pc-order-import-export'); ?></a>
</nav>
<p><a class="button" href="<?php echo esc_url(ManagerHelp::url()); ?>" target="_blank" rel="noopener noreferrer"><?php ManagerHelp::load(); esc_html_e('Manager guide', 'pcoe-manager-help'); ?></a></p>
<?php if ($mailings_tab) { BroadcastUi::render(); echo '</div>'; return; } ?>
<?php if ($messages_tab) { Conversations::render(); echo '</div>'; return; } ?>
<?php if ($orders_tab) { require __DIR__ . '/manager-orders-view.php'; echo '</div>'; return; } ?>
<?php if ($error): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<div id="pcoe-manager-status" role="status" aria-live="polite"></div>
<?php if (!$user):
$role = sanitize_key($_GET['customer_role'] ?? '');
$city = sanitize_text_field(wp_unslash($_GET['customer_city'] ?? ''));
$directory_page = max(1, absint($_GET['customers_page'] ?? 1));
$directory = ManagerWorkspace::directory($search, $role, $city, $directory_page);
$role_names = wp_roles()->get_names();
$directory_url = add_query_arg(['page' => ManagerWorkspace::PAGE, 'customer_search' => $search, 'customer_role' => $role, 'customer_city' => $city], admin_url('admin.php'));
?>
<section class="pcoe-card">
<form method="get" class="pcoe-directory-filters">
<input type="hidden" name="page" value="<?php echo esc_attr(ManagerWorkspace::PAGE); ?>">
<label for="pcoe-search"><?php esc_html_e('Find customer by name, company or email', 'pc-order-import-export'); ?><input id="pcoe-search" name="customer_search" type="search" value="<?php echo esc_attr($search); ?>"></label>
<label for="pcoe-role"><?php esc_html_e('Customer role', 'pc-order-import-export'); ?><select id="pcoe-role" name="customer_role"><option value=""><?php esc_html_e('All roles', 'pc-order-import-export'); ?></option><?php foreach (ManagerWorkspace::customer_roles() as $key): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($role, $key); ?>><?php echo esc_html(translate_user_role($role_names[$key] ?? $key)); ?></option><?php endforeach; ?></select></label>
<label for="pcoe-city"><?php esc_html_e('City', 'pc-order-import-export'); ?><select id="pcoe-city" name="customer_city"><option value=""><?php esc_html_e('All cities', 'pc-order-import-export'); ?></option><?php foreach ($directory['cities'] as $value): ?><option <?php selected($city, $value); ?> value="<?php echo esc_attr($value); ?>"><?php echo esc_html($value); ?></option><?php endforeach; ?></select></label>
<div class="pcoe-actions"><button class="button button-primary"><?php esc_html_e('Filter customers', 'pc-order-import-export'); ?></button><a class="button" href="<?php echo esc_url(ManagerWorkspace::url(0)); ?>"><?php esc_html_e('Reset filters', 'pc-order-import-export'); ?></a></div>
</form>
<p><?php echo esc_html(sprintf(__('Customers found: %s', 'pc-order-import-export'), number_format_i18n($directory['total']))); ?></p>
<p class="description"><?php esc_html_e('City comes from the customer billing address in WooCommerce.', 'pc-order-import-export'); ?></p>
<?php if (!$directory['users']): ?><p><?php esc_html_e('No customers found.', 'pc-order-import-export'); ?></p><?php else: ?>
<div class="pcoe-scroll"><table class="widefat striped"><thead><tr>
<th scope="col"><?php esc_html_e('WooCommerce customer', 'pc-order-import-export'); ?></th><th scope="col"><?php esc_html_e('Folio customer:', 'pc-order-import-export'); ?></th><th scope="col"><?php esc_html_e('Customer role', 'pc-order-import-export'); ?></th><th scope="col"><?php esc_html_e('City', 'pc-order-import-export'); ?></th>
</tr></thead><tbody>
<?php foreach ($directory['users'] as $match):
$folio_name = trim((string) get_user_meta($match->ID, '_folio_partner_name', true));
$folio_short = trim((string) get_user_meta($match->ID, '_folio_partner_short_name', true));
?><tr><td><a href="<?php echo esc_url(ManagerWorkspace::url($match->ID)); ?>"><strong><?php echo esc_html($match->display_name); ?></strong></a><br><?php echo esc_html(get_user_meta($match->ID, 'billing_company', true)); ?><br><?php echo esc_html($match->user_email); ?></td>
<td><?php echo esc_html($folio_name ?: ($folio_short ?: __('Not linked to Folio', 'pc-order-import-export'))); ?><?php if ($folio_short && $folio_short !== $folio_name): ?><br><span class="description"><?php echo esc_html($folio_short); ?></span><?php endif; ?></td>
<td><?php echo esc_html(implode(', ', array_map(static fn($r) => translate_user_role($role_names[$r] ?? $r), array_intersect($match->roles, ManagerWorkspace::customer_roles())))); ?></td><td><?php echo esc_html(get_user_meta($match->ID, 'billing_city', true) ?: '—'); ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<nav class="pcoe-actions">
<?php if ($directory_page > 1): ?><a class="button" href="<?php echo esc_url(add_query_arg('customers_page', $directory_page - 1, $directory_url)); ?>"><?php esc_html_e('Previous page', 'pc-order-import-export'); ?></a><?php endif; ?>
<?php if ($directory_page * 25 < $directory['total']): ?><a class="button" href="<?php echo esc_url(add_query_arg('customers_page', $directory_page + 1, $directory_url)); ?>"><?php esc_html_e('Next page', 'pc-order-import-export'); ?></a><?php endif; ?></nav>
<?php endif; ?></section>
<?php else: ?><a class="button" href="<?php echo esc_url(ManagerWorkspace::url(0)); ?>"><?php esc_html_e('All customers', 'pc-order-import-export'); ?></a><?php endif; ?>
<?php if ($user):
$context = function_exists('pc_folio_balance_user_context') ? pc_folio_balance_user_context($customer_id, false) : [];
?>
<section class="pcoe-card pcoe-customer-heading">
<h2><?php echo esc_html($user->display_name); ?></h2>
<?php if (current_user_can('edit_user', $user->ID)): ?>
<p><a class="button" href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>"><?php esc_html_e('Edit User'); ?></a> <?php ManagerHelp::link('register-wholesale'); ?></p>
<?php endif; ?>
<p><a class="button" href="<?php echo esc_url(Conversations::url(['new_customer'=>$customer_id,'new_order'=>$order?$order->get_id():0],true)); ?>"><?php esc_html_e('Write to customer', 'pc-order-import-export'); ?></a></p>
<p><?php echo esc_html(get_user_meta($customer_id, 'billing_company', true)); ?> · <?php echo esc_html($user->user_email); ?></p>
<p><?php esc_html_e('Folio customer:', 'pc-order-import-export'); ?> <strong><?php echo esc_html($context['name'] ?? ''); ?> <?php echo esc_html($context['short_name'] ?? ''); ?></strong></p>
<p><?php esc_html_e('Price role:', 'pc-order-import-export'); ?> <?php $roles = wp_roles()->get_names(); echo esc_html(translate_user_role($roles[$user->roles[0] ?? ''] ?? '')); ?></p>
<p><?php esc_html_e('The draft belongs to this customer. Manager actions are recorded separately.', 'pc-order-import-export'); ?></p>
<nav class="pcoe-actions"><a href="<?php echo esc_url(ManagerWorkspace::url($customer_id)); ?>" class="button"><?php esc_html_e('Customer drafts and orders', 'pc-order-import-export'); ?></a>
<?php if ($context): ?><a href="#pcoe-folio-documents" class="button"><?php esc_html_e('Folio documents', 'pc-order-import-export'); ?></a>
<a class="button" href="<?php echo esc_url(pc_folio_balance_admin_url($customer_id)); ?>"><?php esc_html_e('Customer balance', 'pc-order-import-export'); ?></a><?php endif; ?></nav>
<?php if (!$context): ?><p class="pcoe-warning"><?php esc_html_e('Link the customer to Folio in the user profile before preparing Folio documents. Drafts are available now.', 'pc-order-import-export'); ?></p><?php endif; ?>
</section>
<?php CustomerManagers::render($customer_id); ?>
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
<section class="pcoe-card"><h2 data-pcoe-help="orders"><?php esc_html_e('Customer drafts and orders', 'pc-order-import-export'); ?></h2>
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
$non_accounting_only = ManagerOrderFlow::non_accounting_only($order);
?>
<?php if ($editable && $context): ?><p class="pcoe-actions"><a class="button button-primary" href="#pcoe-place-order"><?php esc_html_e('Place customer order from this draft','pc-order-import-export'); ?></a></p><?php endif; ?>
<?php ManagerOrderDetails::render($order); ?>
<section class="pcoe-card"><h2><?php echo esc_html(sprintf(__('Order #%s', 'pc-order-import-export'), $order->get_order_number())); ?></h2>
<div class="pcoe-actions"><a class="button" href="<?php echo esc_url($order->get_edit_order_url()); ?>"><?php esc_html_e('Open WooCommerce order', 'pc-order-import-export'); ?></a>
<?php foreach (['csv' => 'CSV', 'xlsx' => 'Excel'] as $fmt => $label): ?><a class="button" href="<?php echo esc_url(add_query_arg(['action' => 'pcoe_export', 'type' => 'order', 'order_id' => $order->get_id(), 'fmt' => $fmt, '_wpnonce' => wp_create_nonce('pcoe_export')], admin_url('admin-ajax.php'))); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?>
<form data-pcoe-manager><?php $form_fields('copy'); ?><button class="button"><?php echo esc_html($non_accounting_only ? __('Prepare customer order in a new draft','pc-order-import-export') : __('Copy to a new draft', 'pc-order-import-export')); ?></button></form></div>
<?php if ($non_accounting_only): ?><p class="pcoe-warning"><?php esc_html_e('Only a non-accounting document was saved. To place a customer order with stock reservation, prepare a new draft using the button above. The saved Folio document and this operation remain unchanged.','pc-order-import-export'); ?></p><?php endif; ?>
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
<?php if ($editable && $context): ?>
<section id="pcoe-place-order" class="pcoe-card"><h2 data-pcoe-help="prepare"><?php esc_html_e('Place customer order from this draft','pc-order-import-export'); ?></h2>
<p><?php esc_html_e('Prepare the available quantities as Woo orders with accounting Folio accounts and reservation, then request customer confirmation. Shortages are shown separately.','pc-order-import-export'); ?></p>
<form data-pcoe-manager data-preview-form><?php $form_fields('preview'); ?><input type="hidden" name="mode" value="accounts">
<div class="pcoe-actions"><label><?php esc_html_e('Warehouse mode', 'pc-order-import-export'); ?><select name="warehouse_mode"><option value="auto"><?php esc_html_e('Automatic allocation', 'pc-order-import-export'); ?></option><option value="manual"><?php esc_html_e('Prioritize selected warehouse', 'pc-order-import-export'); ?></option><option value="single"><?php esc_html_e('Selected warehouse only', 'pc-order-import-export'); ?></option></select></label>
<label><?php esc_html_e('Website warehouse group','pc-order-import-export'); ?><select name="warehouse_id" disabled><option value="">—</option><?php $terms=get_terms(['taxonomy'=>'location','hide_empty'=>false]); if (!is_wp_error($terms)) foreach ($terms as $term):
$mapped=pc_folio_get_location_warehouses_for_preview($term->term_id);
$mapped_names=array_map(static fn($row)=>pc_folio_warehouse_label($row['id']),$mapped);
?><option value="<?php echo esc_attr($term->term_id); ?>" data-folio-warehouses="<?php echo esc_attr(implode(' → ',$mapped_names)); ?>"><?php echo esc_html($term->name); ?></option><?php endforeach; ?></select></label></div>
<p data-warehouse-description><?php esc_html_e('A website warehouse group can include several Folio warehouses. Allocation follows their configured priorities and current Folio stock.','pc-order-import-export'); ?></p>
<p><?php esc_html_e('Available quantities become accounts with reservation. Shortages become separate non-accounting documents. Creating accounts does not create an expense invoice.', 'pc-order-import-export'); ?></p>
<button class="button button-primary"><?php esc_html_e('Preview customer order and available stock','pc-order-import-export'); ?></button></form>
<details class="pcoe-non-accounting"><summary><?php esc_html_e('Separate action: save the entire list without reservation','pc-order-import-export'); ?></summary>
<p><?php esc_html_e('This action saves the entire list in one non-accounting document on the configured warehouse. It does not check stock, split by availability or create a reserved Woo order.','pc-order-import-export'); ?></p>
<p><?php echo esc_html(sprintf(__('Non-accounting Folio warehouse: %s','pc-order-import-export'),pc_folio_warehouse_label(DraftFolioWorkflow::default_warehouse_id()))); ?></p>
<form data-pcoe-manager data-preview-form><?php $form_fields('preview'); ?><input type="hidden" name="mode" value="non_accounting"><input type="hidden" name="warehouse_mode" value="auto"><input type="hidden" name="warehouse_id" value="0">
<button class="button"><?php esc_html_e('Preview only a non-accounting document','pc-order-import-export'); ?></button></form></details>
<div data-preview-result hidden></div>
<form data-pcoe-manager data-apply-form hidden><?php $form_fields('apply'); ?><input type="hidden" name="token" value=""><input type="hidden" name="mode" value=""><label><input type="checkbox" name="confirmation" value="1" required> <span data-confirmation-label></span></label><button class="button button-primary" data-apply-button><?php esc_html_e('Confirm the reviewed action','pc-order-import-export'); ?></button></form>
</section><?php endif; ?>
<?php $result=pc_folio_get_order_documents_result($order); if ($result): ?><section class="pcoe-card"><h2 data-pcoe-help="apply"><?php esc_html_e('Saved Folio documents','pc-order-import-export'); ?></h2><?php ManagerWorkspace::documents_table($result); ManagerOrderFlow::orders($order,$result); ?></section>
<?php elseif (!$order->has_status('pc-draft')): CustomerApproval::manager_link($order); endif; ?>
<?php endif; ?>
<?php if ($context && function_exists('pc_folio_documents_render_endpoint')): ?><section id="pcoe-folio-documents" class="pcoe-card"><?php pc_folio_documents_render_endpoint($customer_id); ?></section><?php endif; ?>
<?php endif; ?>
</div>
