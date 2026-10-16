<?php
namespace PaintCore\PCOE;

defined('ABSPATH') || exit;

/** Read-only, inline help for every signed-in customer; no wholesale role expansion. */
final class DraftHelp
{
    public static function pdf_url(): string
    {
        return PCOE_URL . 'assets/draft-guide/drafts-and-preorders-uk.pdf';
    }

    public static function render(string $context = 'catalogue'): void
    {
        if (!is_user_logged_in()) return;
        $topics = self::topics();
        if ($context === 'processing') {
            $topics = ['folio' => $topics['folio'], 'cart' => $topics['cart']] + $topics;
        }
        echo '<details class="pcoe-draft-help"><summary>' . esc_html__('How to work with a draft?', 'pc-order-import-export') . '</summary>';
        echo '<div class="pcoe-draft-help__body"><p><a class="pcoe-draft-help__pdf" href="' . esc_url(self::pdf_url()) . '" target="_blank" rel="noopener">';
        echo esc_html__('Illustrated instructions (PDF, Ukrainian; new tab)', 'pc-order-import-export') . '</a></p>';
        foreach ($topics as $topic) {
            echo '<details class="pcoe-draft-help__topic"><summary>' . esc_html($topic['title']) . '</summary><ol>';
            foreach ($topic['steps'] as $step) echo '<li>' . esc_html($step) . '</li>';
            echo '</ol>';
            if ($topic['image']) {
                echo '<a href="' . esc_url(PCOE_URL . 'assets/draft-guide/' . $topic['image']) . '" target="_blank" rel="noopener">';
                echo '<img loading="lazy" src="' . esc_url(PCOE_URL . 'assets/draft-guide/' . $topic['image']) . '" alt="' . esc_attr($topic['title']) . '">';
                echo esc_html__('Training example; enlarge in a new tab', 'pc-order-import-export') . '</a>';
            }
            echo '</details>';
        }
        echo '</div></details>';
    }

    private static function topics(): array
    {
        return [
            'collect' => [
                'title' => __('Build a draft', 'pc-order-import-export'),
                'steps' => [
                    __('Sign in, open the catalogue and switch Purchase to Draft. Choose a saved draft or open New draft, enter a title and press Create draft.', 'pc-order-import-export'),
                    __('Enter a positive whole quantity and press Add to draft. Stock does not limit the quantity. For a variable product, choose every option first. Adding the same variant again increases its quantity.', 'pc-order-import-export'),
                    __('The list is saved directly to the selected draft, not to the cart. It does not reserve stock, create a Folio document or confirm payment.', 'pc-order-import-export'),
                ],
                'image' => 'collect.png',
            ],
            'edit' => [
                'title' => __('Review and edit', 'pc-order-import-export'),
                'steps' => [
                    __('Press Open draft, or find it in My account > My orders. Open Edit draft quantities, change the numbers and press Save quantities. Zero removes a line.', 'pc-order-import-export'),
                    __('Prices in the draft are provisional. An already submitted draft is not editable here. If a save is not confirmed, reopen the draft and check it before repeating the action.', 'pc-order-import-export'),
                ],
                'image' => 'edit.png',
            ],
            'cart' => [
                'title' => __('Order the available goods', 'pc-order-import-export'),
                'steps' => [
                    __('In Draft processing, choose Load available quantities into the cart. Review the requested quantity, the quantity going to the cart and the remainder going to Folio.', 'pc-order-import-export'),
                    __('After confirmation, available goods REPLACE the current cart at current prices. Save your current cart separately first if needed. The unavailable remainder stays in the draft and is recorded as a non-accounting Folio document.', 'pc-order-import-export'),
                    __('Open the cart, check warehouses and totals, then complete checkout. Moving goods to the cart is not placing an order. If everything is available, no Folio remainder document is needed.', 'pc-order-import-export'),
                ],
                'image' => '',
            ],
            'folio' => [
                'title' => __('Save to Folio / preorder', 'pc-order-import-export'),
                'steps' => [
                    __('To send the entire list, open Draft processing and select Ordinary draft or Preorder invoice in Save as. Then press Send the entire draft to a non-accounting Folio document.', 'pc-order-import-export'),
                    __('Review Preview without recording. Only Confirm non-accounting Folio document records it. Cancel leaves the Folio document uncreated. The current cart does not change.', 'pc-order-import-export'),
                    __('Ordinary draft keeps the list for coordination. Preorder invoice is marked PRD in Folio and is included in the preorder reserve for six months. Neither option reserves or writes off current warehouse stock, confirms payment or guarantees delivery.', 'pc-order-import-export'),
                    __('Agree payment and delivery dates with the manager. The automatic out-of-stock remainder is not the same as a manually selected preorder.', 'pc-order-import-export'),
                ],
                'image' => 'folio.png',
            ],
            'check' => [
                'title' => __('If something went wrong', 'pc-order-import-export'),
                'steps' => [
                    __('If the Folio warehouse is not configured, ask the manager. If the document is already linked, do not create another copy. For an error or unknown result, send the manager the draft number and message; check existing documents before repeating confirmation.', 'pc-order-import-export'),
                ],
                'image' => '',
            ],
        ];
    }
}
