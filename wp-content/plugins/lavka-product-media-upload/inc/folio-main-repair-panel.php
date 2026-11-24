<?php if (!defined('ABSPATH')) { exit; } ?>
<details class="lpmu-panel lpmu-folio-repair" id="lpmu-folio-repair">
    <summary><?php esc_html_e('Correct the main photo filename in Folio', 'lavka-product-media-upload'); ?></summary>
    <p><?php esc_html_e('Enter the SKU to compare the filename in Folio with the photo currently used on the product page.', 'lavka-product-media-upload'); ?></p>
    <form>
        <label for="lpmu-repair-sku"><?php esc_html_e('SKU', 'lavka-product-media-upload'); ?></label>
        <input type="text" id="lpmu-repair-sku" name="sku" maxlength="100" required>
        <button class="button" type="submit"><?php esc_html_e('Preview the Folio filename correction', 'lavka-product-media-upload'); ?></button>
    </form>
    <div class="lpmu-folio-repair-preview" data-preview hidden>
        <strong data-product></strong>
        <dl>
            <dt><?php esc_html_e('Current name in Folio', 'lavka-product-media-upload'); ?></dt>
            <dd><code data-before></code></dd>
            <dt><?php esc_html_e('Website filename to save in Folio', 'lavka-product-media-upload'); ?></dt>
            <dd><code data-after></code></dd>
        </dl>
        <p><a data-photo target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open the current website photo', 'lavka-product-media-upload'); ?></a></p>
        <button class="button button-primary" type="button" data-apply><?php esc_html_e('Confirm filename correction in Folio', 'lavka-product-media-upload'); ?></button>
    </div>
    <p data-status role="status" aria-live="polite"></p>
</details>
