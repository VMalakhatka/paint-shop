<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;
$id=absint($_GET['mailing']??0);
if($id):
$d=BroadcastStore::get($id);$counts=array_count_values(array_column($d['recipients'],'status'));
?>
<section class="pcoe-card">
<h3><?php echo esc_html($d['subject']); ?></h3>
<p><strong><?php echo esc_html(BroadcastUi::label($d['status'])); ?></strong> · <?php echo esc_html(sprintf(__('Recipients: %s','pc-order-import-export'),count($d['recipients']))); ?></p>
<?php if($d['error']): ?><p role="alert"><?php echo esc_html($d['error']); ?></p><?php endif; ?>
<div style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo esc_html($d['message']); ?></div>
<?php if($d['kind']!=='text'): ?><p><?php echo esc_html(CommercialOffer::format_label($d['format']??'price')); ?><?php if(($d['format']??'price')==='offer')echo ' · '.esc_html(CommercialOffer::quantity_label($d['quantity']??'one')); ?></p><?php endif; ?>
<?php if($d['source']): ?><p><?php echo esc_html(sprintf(__('Document selection: warehouse %1$s, date %2$s, document ID %3$s','pc-order-import-export'),$d['source']['warehouse'],$d['source']['date'],$d['source']['document'])); ?></p><?php endif; ?>
<p><?php echo esc_html(sprintf(__('Prepared price groups: %s. Customers with the same pricing conditions share one file.','pc-order-import-export'),count($d['groups']))); ?></p>
<?php foreach($d['groups'] as $key=>$group): ?>
<p><?php echo esc_html($group['label'].' · '.$group['count'].' · '.sprintf(__('Price example: %s','pc-order-import-export'),get_userdata($group['user'])->display_name??'')); ?> —
<?php if($group['ready']): $url=wp_nonce_url(add_query_arg(['action'=>'pcoe_broadcast_file','mailing'=>$id,'group'=>$key],admin_url('admin-post.php')),'pcoe_broadcast_file_'.$id); ?>
<a href="<?php echo esc_url($url); ?>"><?php esc_html_e('Download price preview','pc-order-import-export'); ?></a> · <?php echo esc_html(sprintf(__('Products: %1$s. Prepared: %2$s','pc-order-import-export'),$group['products'],wp_date(get_option('date_format').' H:i',strtotime($group['at'])))); ?>
<?php if(($d['format']??'price')==='offer')echo ' · '.esc_html(sprintf(__('Embedded photos: %1$s of %2$s. Other rows contain product links.','pc-order-import-export'),$group['photos']??0,$group['products'])); ?>
<?php else: esc_html_e('Preparing files','pc-order-import-export'); endif; ?></p>
<?php endforeach; ?>
<?php if($d['missing']): ?><p role="alert"><?php esc_html_e('These document SKUs are missing or not published on the site and will not be included:','pc-order-import-export'); ?> <?php echo esc_html(implode(', ',$d['missing'])); ?></p><?php endif; ?>
<?php if($d['skipped']): ?><p><?php echo esc_html(sprintf(__('Excluded before preparation (invalid/duplicate email or unsubscribed): %s','pc-order-import-export'),$d['skipped'])); ?></p><?php endif; ?>
<p><?php esc_html_e('Each customer receives a separate email. Prices are fixed at preparation time. Accepted by the mail service does not prove inbox delivery. Unknown results are never retried automatically.','pc-order-import-export'); ?></p>
<?php
if($d['status']==='preparing')BroadcastUi::action($id,'prepare',__('Continue preparing files','pc-order-import-export'));
if($d['status']==='ready')BroadcastUi::action($id,'start',__('Start mailing','pc-order-import-export'),true);
if($d['status']==='paused')BroadcastUi::action($id,'resume',__('Resume mailing','pc-order-import-export'),true);
if($d['status']==='running')BroadcastUi::action($id,'pause',__('Pause mailing','pc-order-import-export'));
if($d['status']==='finished' && !empty($counts['failed']))BroadcastUi::action($id,'retry',__('Retry only failed emails','pc-order-import-export'),true);
if(!in_array($d['status'],['finished','cancelled'],true))BroadcastUi::action($id,'cancel',__('Cancel remaining emails','pc-order-import-export'));
?>
<p><a class="button" href="<?php echo esc_url(BroadcastUi::url($id)); ?>"><?php esc_html_e('Refresh status','pc-order-import-export'); ?></a> <a class="button" href="<?php echo esc_url(BroadcastUi::url()); ?>"><?php esc_html_e('New mailing','pc-order-import-export'); ?></a></p>
<div class="pcoe-scroll" style="max-height:420px"><table class="widefat striped"><thead><tr><th><?php esc_html_e('Customer','pc-order-import-export'); ?></th><th>Email</th><th><?php esc_html_e('Status','pc-order-import-export'); ?></th></tr></thead><tbody>
<?php foreach($d['recipients'] as $r): ?><tr><td><?php echo esc_html($r['name']); ?></td><td><?php echo esc_html($r['email']); ?></td><td><?php echo esc_html(BroadcastUi::label($r['status'])); ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php return; endif;
$search=sanitize_text_field(wp_unslash($_GET['search']??''));$role=sanitize_key($_GET['role']??'');$city=sanitize_text_field(wp_unslash($_GET['city']??''));
$directory=ManagerWorkspace::directory($search,$role,$city,1,100);$roles=wp_roles()->get_names();
?>
<section class="pcoe-card">
<form method="get" class="pcoe-directory-filters">
<?php BroadcastUi::hidden('page',ManagerWorkspace::PAGE);BroadcastUi::hidden('view','mailings'); ?>
<label><?php esc_html_e('Find customer by name, company or email','pc-order-import-export'); ?><br><input type="text" class="regular-text" name="search" value="<?php echo esc_attr($search); ?>"></label>
<label><?php esc_html_e('Customer role','pc-order-import-export'); ?><select name="role"><option value=""><?php esc_html_e('All roles','pc-order-import-export'); ?></option><?php foreach(ManagerWorkspace::customer_roles() as $r): ?><option value="<?php echo esc_attr($r); ?>" <?php selected($role,$r); ?>><?php echo esc_html(translate_user_role($roles[$r]??$r)); ?></option><?php endforeach; ?></select></label>
<label><?php esc_html_e('City','pc-order-import-export'); ?><select name="city"><option value=""><?php esc_html_e('All cities','pc-order-import-export'); ?></option><?php foreach($directory['cities'] as $c): ?><option value="<?php echo esc_attr($c); ?>" <?php selected($city,$c); ?>><?php echo esc_html($c); ?></option><?php endforeach; ?></select></label>
<button class="button"><?php esc_html_e('Filter customers','pc-order-import-export'); ?></button>
</form>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="pcoe-broadcast-compose">
<?php wp_nonce_field('pcoe_broadcast');foreach(['action'=>'pcoe_broadcast','operation'=>'create','request_key'=>wp_generate_uuid4(),'search'=>$search,'role'=>$role,'city'=>$city] as $k=>$v)BroadcastUi::hidden($k,$v); ?>
<p><?php echo esc_html(sprintf(__('Customers found: %s','pc-order-import-export'),$directory['total'])); ?></p>
<label><input type="checkbox" name="all_filtered" value="1"> <?php esc_html_e('Select all matching customers (up to 2000), or tick individual recipients below.','pc-order-import-export'); ?></label>
<div class="pcoe-scroll" style="max-height:280px"><table class="widefat striped"><tbody>
<?php foreach($directory['users'] as $u): $allowed=Broadcasts::allowed($u->ID); ?><tr><td><label><input type="checkbox" name="users[]" value="<?php echo esc_attr($u->ID); ?>" <?php disabled(!$allowed); ?>> <?php echo esc_html($u->display_name); ?></label></td><td><?php echo esc_html($u->user_email); ?></td><td><?php if(!$allowed)esc_html_e('Excluded from mailings','pc-order-import-export'); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p><?php esc_html_e('The first 100 matches are shown. Use filters to select individual customers outside this list.','pc-order-import-export'); ?></p>
<p><label><?php esc_html_e('Mailing type','pc-order-import-export'); ?><br><select name="kind" id="pcoe-broadcast-kind">
<?php foreach(['text'=>__('Message only','pc-order-import-export'),'price'=>__('Full price list','pc-order-import-export'),'arrival'=>__('Products from a Folio document','pc-order-import-export')] as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label></p>
<div id="pcoe-broadcast-file-options" hidden>
<p><label><?php esc_html_e('Excel format','pc-order-import-export'); ?><br><select name="format" id="pcoe-broadcast-format"><?php foreach(['price','offer'] as $format): ?><option value="<?php echo esc_attr($format); ?>"><?php echo esc_html(CommercialOffer::format_label($format)); ?></option><?php endforeach; ?></select></label></p>
<div id="pcoe-broadcast-offer-options" hidden>
<p><label><?php esc_html_e('Quantity in the offer','pc-order-import-export'); ?><br><select name="quantity"><?php foreach(['one','stock'] as $quantity): ?><option value="<?php echo esc_attr($quantity); ?>"><?php echo esc_html(CommercialOffer::quantity_label($quantity)); ?></option><?php endforeach; ?></select></label></p>
<p><?php esc_html_e('One flat table: SKU, barcode, name, description, customer price, quantity and photo. Missing photos are replaced with product links. Stock quantities use Kyiv + Odesa, not the quantities in the source document.','pc-order-import-export'); ?></p>
</div></div>
<div id="pcoe-broadcast-receipt" hidden>
<p><label><?php esc_html_e('Document type','pc-order-import-export'); ?><br><select name="document_type" id="pcoe-broadcast-document-type">
<?php foreach(['all'=>__('Receipts and invoices','pc-order-import-export'),'receipt'=>__('Receipts','pc-order-import-export'),'invoice'=>__('Invoices','pc-order-import-export')] as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></p>
<p><?php esc_html_e('Choose an accounted receipt or an invoice, including a non-accounting promotional invoice. Only its products are used; prices and stock come from the site.','pc-order-import-export'); ?></p>
<p><label><?php esc_html_e('Warehouse','pc-order-import-export'); ?><br><select name="warehouse" id="pcoe-broadcast-warehouse" style="min-width:220px;max-width:100%"><option value="">—</option></select></label> <button type="button" class="button" id="pcoe-broadcast-reload"><?php esc_html_e('Reload warehouses','pc-order-import-export'); ?></button></p>
<p><label><?php esc_html_e('Document date','pc-order-import-export'); ?><br><input type="date" name="date" id="pcoe-broadcast-date" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>"></label></p>
<p><label><?php esc_html_e('Receipt or invoice','pc-order-import-export'); ?><br><select name="document" id="pcoe-broadcast-document" style="min-width:220px;max-width:100%"><option value="">—</option></select></label> <button class="button" type="button" id="pcoe-broadcast-more" hidden><?php esc_html_e('Load more documents','pc-order-import-export'); ?></button></p>
<p id="pcoe-broadcast-source-status" role="status"></p></div>
<p><label><?php esc_html_e('Email subject','pc-order-import-export'); ?><br><input type="text" name="subject" maxlength="180" class="large-text" required></label></p>
<p><label><?php esc_html_e('Message to customers','pc-order-import-export'); ?><br><textarea name="message" rows="8" maxlength="10000" class="large-text" required></textarea></label></p>
<p><?php esc_html_e('Prepare a preview first. No email is sent until you review the recipients and files and explicitly start the mailing.','pc-order-import-export'); ?></p>
<button class="button button-primary"><?php esc_html_e('Prepare mailing preview','pc-order-import-export'); ?></button>
</form></section>
<h3><?php esc_html_e('Recent mailings','pc-order-import-export'); ?></h3><ul>
<?php foreach(get_posts(['post_type'=>BroadcastStore::TYPE,'post_status'=>'private','numberposts'=>30]) as $p):$d=BroadcastStore::get($p->ID); ?>
<li><a href="<?php echo esc_url(BroadcastUi::url($p->ID)); ?>"><?php echo esc_html($p->post_title); ?></a> — <?php echo esc_html(BroadcastUi::label($d['status'])); ?></li>
<?php endforeach; ?></ul>
<?php
wp_enqueue_script('pcoe-broadcast',PCOE_URL.'assets/broadcast.js',[],filemtime(PCOE_DIR.'/assets/broadcast.js'),true);
wp_localize_script('pcoe-broadcast','pcoeBroadcast',['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('pcoe_broadcast'),
    'loading'=>__('Loading documents…','pc-order-import-export'),'loadingWarehouses'=>__('Loading warehouses…','pc-order-import-export'),
    'empty'=>__('No matching receipts or invoices found for this warehouse and date.','pc-order-import-export'),
    'emptyWarehouses'=>__('Folio returned no available warehouses. Reload or contact the administrator.','pc-order-import-export'),
    'receipt'=>__('Receipt','pc-order-import-export'),'invoice'=>__('Invoice','pc-order-import-export'),'nonAccounting'=>__('Non-accounting','pc-order-import-export'),
    'error'=>__('Could not load Folio documents. Try loading again.','pc-order-import-export')]);
