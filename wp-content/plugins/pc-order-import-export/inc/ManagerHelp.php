<?php
namespace PaintCore\PCOE;
defined('ABSPATH') || exit;

/** Read-only manager documentation. No customer data or business commands. */
final class ManagerHelp {
    public static function support_assets(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if (in_array($_GET['page'] ?? '', ['pcoe-approvals', 'pc-folio-customer-balance', 'pc-folio-customer-debtors'], true)) self::assets();
    }
    public static function load(): void {
        $locale = determine_locale();
        if (in_array($locale, ['uk', 'ru_RU'], true)) {
            load_textdomain('pcoe-manager-help', PCOE_DIR . '/languages/pcoe-manager-help-' . $locale . '.mo');
        }
    }
    public static function url(string $anchor = 'overview'): string {
        return add_query_arg(['page' => ManagerWorkspace::PAGE, 'view' => 'help'], admin_url('admin.php')) . '#' . sanitize_key($anchor);
    }
    public static function link(string $anchor = 'overview'): void {
        if (!current_user_can('manage_woocommerce')) return;
        self::load();
        echo '<a class="pcoe-help-link" target="_blank" rel="noopener noreferrer" href="' . esc_url(self::url($anchor)) . '" title="' . esc_attr__('Open help in a new tab', 'pcoe-manager-help') . '">' . esc_html__('? How it works', 'pcoe-manager-help') . '</a>';
    }
    public static function sections(): array {
        return [
            'debtors' => [__('Debtors: filter, export and data freshness', 'pcoe-manager-help'), [
                __('Set the minimum payable amount, customer search and type, then generate the report. It includes amounts strictly greater than the threshold. Export XLSX for the selected report; use the individual customer balance to investigate a disputed amount.', 'pcoe-manager-help'),
                __('The list uses a prepared snapshot, not a fresh calculation for each viewed customer. Check its status and calculation date. Refresh debt data starts a recalculation, not just a screen refresh: wait for completion and do not repeatedly click it. Check database activity helps diagnose a busy database; report a failure to the administrator without restarting it blindly.', 'pcoe-manager-help'),
            ]],
            'balance-export' => [__('Generate, export or print a balance', 'pcoe-manager-help'), [
                __('Choose the start date or All time and generate the report. Wait until the report is ready; then use Excel export or Print. Check the selected customer and report date before sharing. These files contain customer financial data and should be sent only to the authorised recipient.', 'pcoe-manager-help'),
            ]],
            'overview' => [__('Customer workspace: start here', 'pcoe-manager-help'), [
                __('Open Customers to work with one customer, Orders for the common order list, or Conversations for messages. Help opens in a separate tab and does not submit forms.', 'pcoe-manager-help'),
                __('Always check the selected customer before saving a draft, assigning managers or creating documents. Work under your own manager account; do not sign in as the customer.', 'pcoe-manager-help'),
            ]],
            'customers' => [__('Find and check a customer', 'pcoe-manager-help'), [
                __('Search by name, company or email, optionally select a role and city, then apply the filter. Clear filters if the customer is missing. City is taken from the billing address on the website.', 'pcoe-manager-help'),
                __('Open the customer name. Check the email, price role and linked Folio customer (our accounting database). Without this link you can prepare drafts, but cannot prepare accounting documents; ask an administrator to check the user profile.', 'pcoe-manager-help'),
            ]],
            'team' => [__('Primary and additional managers', 'pcoe-manager-help'), [
                __('In Customer managers select a primary manager and, if needed, a different additional manager. Save. New conversations inherit this pair; the primary manager remains responsible.', 'pcoe-manager-help'),
                __('The checkbox for existing open conversations replaces their managers too. Without it, only the defaults change. Closed conversations keep their assignment. Both connected managers receive customer messages and public replies from their colleague in Telegram.', 'pcoe-manager-help'),
            ]],
            'draft' => [__('Create and edit a draft', 'pcoe-manager-help'), [
                __('In the customer card enter a draft title and create an empty draft. Add products by SKU and quantity, then save. Change quantities in the table; quantity 0 removes a line when you save.', 'pcoe-manager-help'),
                __('A draft is a working list, not a reservation or a shipment. It belongs to the selected customer; the customer\'s website cart remains separate. Save changes before previewing. A processed or locked draft cannot be edited here.', 'pcoe-manager-help'),
            ]],
            'import' => [__('Import an order file', 'pcoe-manager-help'), [
                __('Download the Excel template, fill product identifiers and quantities, then choose CSV, XLSX or XLS and import into a customer draft. Limits: 10 MB and 2000 rows. Keep barcodes as text to preserve leading zeroes.', 'pcoe-manager-help'),
                __('Read the import report and verify the resulting items and quantities; do not assume every row was accepted. File prices are reference values. Preparation uses current customer prices, so check the preview total.', 'pcoe-manager-help'),
            ]],
            'orders' => [__('Orders, export and repeat order', 'pcoe-manager-help'), [
                __('Orders shows the newest orders first. In a customer card the list contains only that customer\'s drafts and orders. Open a number to inspect it; use page navigation for older records.', 'pcoe-manager-help'),
                __('CSV or Excel downloads the selected order. Copy to a new draft creates a separate working copy, not a second reservation. Check quantities and current customer prices before preparing it. Open WooCommerce order leads to the full editor.', 'pcoe-manager-help'),
            ]],
            'delivery' => [__('Delivery, comments and order history', 'pcoe-manager-help'), [
                __('Review the recipient, contacts, address, delivery method, customer comment and saved shipment numbers. For a split order, delivery may be shown from the original order; also inspect the child orders.', 'pcoe-manager-help'),
                __('A saved shipment number does not prove dispatch. Check the displayed tracking time and carrier status. Order history is not the conversation inbox: internal order notes stay internal, while a customer order note may send an email.', 'pcoe-manager-help'),
            ]],
            'prepare' => [__('Preview prices, warehouses and documents', 'pcoe-manager-help'), [
                __('Save the draft and open Place customer order from this draft. Automatic allocation uses the available warehouse plan; priority prefers the selected website warehouse group, while selected-only restricts allocation to that group. A group can contain several Folio warehouses: Kyiv includes Kyiv OPT and Kyiv, following their configured priorities and current stock.', 'pcoe-manager-help'),
                __('Click Preview customer order and available stock. Check each SKU, quantity, customer price, warehouse and document type. Preview creates no documents or reservations and expires after 15 minutes. If nothing can be reserved, order creation is unavailable. Shortages stay in separate non-accounting documents without reservation or stock movement. Creating accounts does not create an expense invoice.', 'pcoe-manager-help'),
                __('Separate action: save the entire list without reservation is a collapsed section for saving only a non-accounting document. It uses the configured non-accounting warehouse without a warehouse group selector and does not check availability. The entire list remains a Woo draft. Use this only when a document without reservation is intended.', 'pcoe-manager-help'),
            ]],
            'apply' => [__('Confirm creation and verify the result', 'pcoe-manager-help'), [
                __('After checking the order preview, tick the confirmation and click Create customer order with Folio reservation once. This records the documents. Folio checks stock again at creation, so verify the actual result. If the preview expires or prices, allocation or draft change, generate a new preview. The separate non-accounting action has its own confirmation and Save only the non-accounting Folio document button.', 'pcoe-manager-help'),
                __('Verify Saved Folio documents: numbers, warehouses, items and amounts. Customer orders and confirmation lists the actual linked Woo orders. Open Request customer confirmation for each reserved order; shortage orders are marked as not ready for assembly. This link opens the confirmation screen and does not send a request automatically. The source draft is locked against duplicate creation.', 'pcoe-manager-help'),
                __('If a completed operation saved only a non-accounting document but you need an order with reservation, use Prepare customer order in a new draft. Review the copied quantities and current prices, select the warehouse group and preview the order. The previous document and its operation history are preserved. For a pending or uncertain result, follow the error instructions before creating another draft.', 'pcoe-manager-help'),
            ]],
            'errors' => [__('If an operation stalls or needs review', 'pcoe-manager-help'), [
                __('Reload the workspace and check the saved status, operation ID and documents. For sending, unknown or needs_review, do not repeat creation and do not create a replacement draft until the accounting result is checked.', 'pcoe-manager-help'),
                __('Send the administrator the order number, operation ID, time and error text through an approved private channel. A timeout can happen after the database saved documents; repeating the operation may duplicate them.', 'pcoe-manager-help'),
            ]],
            'documents' => [__('Documents in our database', 'pcoe-manager-help'), [
                __('In the linked customer card open Folio documents, set the period and available filters, and open the required document. Check its type, number, date, warehouse and items. A website order and an accounting document have different numbers; one order can have several documents.', 'pcoe-manager-help'),
                __('Use only the actions offered in this manager screen. Customer cart and payment-invoice actions may differ from the customer account. For a repeat order from a website order, use Copy to a new draft; viewing a database document does not create a new order.', 'pcoe-manager-help'),
            ]],
            'balance' => [__('Balance and reconciliation', 'pcoe-manager-help'), [
                __('Open Customer balance from the customer card. Verify the customer and period, then compare the opening balance, movements and closing balance with the underlying documents. Read the sign and labels shown by the report; an order total is not the customer\'s debt.', 'pcoe-manager-help'),
                __('For the common debtor list use the debtors report in the Lavka customer-work menu and check its snapshot date. Open an individual balance for reconciliation. Viewing reports does not record a payment or correct an accounting document.', 'pcoe-manager-help'),
            ]],
            'approval' => [__('Request customer confirmation', 'pcoe-manager-help'), [
                __('Open Customer confirmation from the order. Review the current version and send it for customer confirmation. On the saved request, click Email confirmation link to customer. Check the displayed profile email and mail status. Accepted by the mail service does not prove that the message reached the inbox. You can also share the same link in your usual conversation.', 'pcoe-manager-help'),
                __('Open the customer-account link exactly as generated. The pcoe_approval number identifies the confirmation request, not the Woo order. The customer must sign in with the account that owns the request. After the redirect fix, existing links work without recreating the request.', 'pcoe-manager-help'),
                __('The customer can choose saved contact details from their profile or orders and edit them for this confirmation. Payment remains a preference for the manager. After confirmation, the requesting manager and the current assigned pair receive email notices; connected managers also receive a Telegram notice with a link. Review the saved status, time and conditions in Customer confirmations. If the order changes, request a new confirmation.', 'pcoe-manager-help'),
            ]],
            'queue' => [__('Conversation queue and statuses', 'pcoe-manager-help'), [
                __('Open Conversations. Use Assigned to me, Unassigned or the status filters. Open a conversation to see its customer, order, history and managers. Unread indicates unseen messages; it is not the same as a task status.', 'pcoe-manager-help'),
                __('Manager reply needed means the customer is waiting; Waiting for customer means you expect their reply; Closed means the conversation is finished. Save assignment and state after changes. A new customer reply can reopen a closed conversation. Closing a conversation does not close or cancel the order.', 'pcoe-manager-help'),
            ]],
            'reply' => [__('Write to a customer or add an internal note', 'pcoe-manager-help'), [
                __('In the customer card click Write to customer. Select a related order or General question, enter a subject and message, then save. If the order already has a conversation, open it instead of creating a duplicate.', 'pcoe-manager-help'),
                __('Before saving a reply, check visibility: Send to customer is public; Internal note is staff-only and is not sent to the customer or Telegram. Messages are text, up to 5000 characters. After an error check the conversation before retrying.', 'pcoe-manager-help'),
            ]],
            'telegram-connect' => [__('Connect your Telegram: four steps', 'pcoe-manager-help'), [
                __('1. Sign in with your own manager account and open Conversations. In My Telegram for customer conversations click Connect my Telegram. This is your personal connection to the existing store bot; do not create another bot.', 'pcoe-manager-help'),
                __('2. Open the one-time link in your own Telegram and press Start. 3. Return to the website and refresh Conversations. 4. Check the displayed Telegram identity and confirm your account. Start alone does not finish the connection. The link expires in 15 minutes; never forward it.', 'pcoe-manager-help'),
            ]],
            'telegram-work' => [__('Work with conversations in Telegram', 'pcoe-manager-help'), [
                __('Use /threads for your open conversations and /queue for unassigned requests. Take an unassigned request before replying. Use Reply on the specific bot message, not a separate message without a reply target. Check the bot\'s saved-reply confirmation.', 'pcoe-manager-help'),
                __('Telegram replies are public. Both assigned managers may work on the conversation; the primary remains responsible. Files and voice messages are not supported. The bot lists up to 10 recent matching conversations; use the website for the full list and internal notes.', 'pcoe-manager-help'),
            ]],
            'telegram-errors' => [__('Telegram delivery and disconnection', 'pcoe-manager-help'), [
                __('If a message is missing, first check the website conversation, assignment, your connection and whether the bot is blocked or delivery paused. Delivery is asynchronous. Accepted by Telegram does not mean read by the customer. Unknown delivery is not automatically resent; ask the administrator to investigate.', 'pcoe-manager-help'),
                __('Disconnect Telegram on the website or send /stop to the bot to disconnect your account. Website conversation history remains. If the wrong Telegram identity is shown during connection, do not confirm it; create a new link and open it with your own account.', 'pcoe-manager-help'),
            ]],
            'admin' => [__('Administrator: shared chat and bot settings', 'pcoe-manager-help'), [
                __('Chat settings and the bot token are store-wide administrator settings, not personal manager connection. Do not pause the bot or disable new messages to disconnect just yourself: these controls affect other users.', 'pcoe-manager-help'),
                __('Only for initial setup, an administrator creates a dedicated bot using /newbot in BotFather, enters its token in the website\'s Telegram settings and verifies/enables it. Do not reuse another service\'s bot or publish its token. For an already configured bot, managers use the personal connection above.', 'pcoe-manager-help'),
            ]],
        ];
    }
    public static function assets(): void {
        self::load();
        wp_enqueue_style('pcoe-manager-help', PCOE_URL . 'assets/manager-help.css', [], filemtime(PCOE_DIR . '/assets/manager-help.css'));
        wp_enqueue_script('pcoe-manager-help', PCOE_URL . 'assets/manager-help.js', [], filemtime(PCOE_DIR . '/assets/manager-help.js'), true);
        wp_localize_script('pcoe-manager-help', 'pcoeManagerHelp', [
            'page' => sanitize_key($_GET['page'] ?? ''),
            'url' => self::url(''), 'label' => __('? How it works', 'pcoe-manager-help'),
            'title' => __('Open help in a new tab', 'pcoe-manager-help'),
        ]);
    }
    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
        self::load();
        $sections = self::sections();
        // Keep the entry workflow first; detailed report chapters follow it.
        $reports = array_intersect_key($sections, array_flip(['debtors', 'balance-export']));
        $sections = array_diff_key($sections, $reports) + $reports;
        echo '<div class="wrap pcoe-manager pcoe-help"><h1>' . esc_html__('Manager guide', 'pcoe-manager-help') . '</h1>';
        echo '<p><a class="button" href="' . esc_url(ManagerWorkspace::url(0)) . '">' . esc_html__('Back to workspace', 'pcoe-manager-help') . '</a></p>';
        echo '<nav class="pcoe-help-toc" aria-label="' . esc_attr__('Contents', 'pcoe-manager-help') . '"><h2>' . esc_html__('Contents', 'pcoe-manager-help') . '</h2><ol>';
        foreach ($sections as $id => [$title, $paragraphs]) echo '<li><a href="#' . esc_attr($id) . '">' . esc_html($title) . '</a></li>';
        echo '</ol></nav>';
        foreach ($sections as $id => [$title, $paragraphs]) {
            echo '<section class="pcoe-card" id="' . esc_attr($id) . '"><h2>' . esc_html($title) . '</h2>';
            foreach ($paragraphs as $paragraph) echo '<p>' . esc_html($paragraph) . '</p>';
            echo '</section>';
        }
        echo '</div>';
    }
}
