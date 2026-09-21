<?php
if (!defined('ABSPATH')) { exit; }
?>
<div class="wrap lpmu-suppliers" id="lpmu-suppliers">
    <header class="lsc-hero">
        <span class="lsc-eyebrow"><?php esc_html_e('SUPPLIER MEDIA', 'lavka-product-media-upload'); ?></span>
        <h1><?php esc_html_e('Supplier catalogues', 'lavka-product-media-upload'); ?></h1>
        <p><?php esc_html_e('Find our products, compare photos and prepare a checked image batch.', 'lavka-product-media-upload'); ?></p>
        <ol class="lsc-steps">
            <li><?php esc_html_e('Choose a source', 'lavka-product-media-upload'); ?></li>
            <li><?php esc_html_e('Match our products', 'lavka-product-media-upload'); ?></li>
            <li><?php esc_html_e('Select photos', 'lavka-product-media-upload'); ?></li>
            <li><?php esc_html_e('Check and upload', 'lavka-product-media-upload'); ?></li>
        </ol>
    </header>
    <div class="lsc-panel lsc-source">
        <label for="lsc-source"><?php esc_html_e('Supplier or media folder', 'lavka-product-media-upload'); ?></label>
        <div class="lsc-toolbar">
            <select id="lsc-source"></select>
            <button type="button" class="button button-primary" id="lsc-refresh"><?php esc_html_e('Refresh catalogue', 'lavka-product-media-upload'); ?></button>
            <a id="lsc-open" class="button" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e('Open source', 'lavka-product-media-upload'); ?></a>
        </div>
        <p><a href="<?php echo esc_url(admin_url('upload.php?page=lavka-product-media-upload')); ?>"><?php esc_html_e('Upload photos downloaded to your computer', 'lavka-product-media-upload'); ?></a></p>
        <p id="lsc-updated" class="description"></p>
        <details id="lsc-file-panel">
            <summary><?php esc_html_e('Upload a saved XML file', 'lavka-product-media-upload'); ?></summary>
            <p><?php esc_html_e('If the supplier blocks automatic downloading, save the XML in your browser and select it here. Maximum: 60 MiB and the server upload limit.', 'lavka-product-media-upload'); ?></p>
            <input type="file" id="lsc-xml" accept=".xml,text/xml,application/xml" aria-label="<?php esc_attr_e('XML catalogue file', 'lavka-product-media-upload'); ?>">
            <button type="button" class="button" id="lsc-import"><?php esc_html_e('Read selected XML', 'lavka-product-media-upload'); ?></button>
        </details>
        <?php if (current_user_can('manage_options')) : ?>
        <details class="lsc-settings">
            <summary><?php esc_html_e('Configure sources', 'lavka-product-media-upload'); ?></summary>
            <form id="lsc-settings">
                <p><?php esc_html_e('Save a supplier once. XML field mapping can be adjusted for each format. Drive folders require a server API key for automatic reading.', 'lavka-product-media-upload'); ?></p>
                <div class="lsc-form-grid">
                    <label><?php esc_html_e('Source name', 'lavka-product-media-upload'); ?><input name="name" required maxlength="100"></label>
                    <label><?php esc_html_e('Source type', 'lavka-product-media-upload'); ?><select name="type"><option value="xml">XML</option><option value="drive">Google Drive</option></select></label>
                    <label class="lsc-wide"><?php esc_html_e('Source URL', 'lavka-product-media-upload'); ?><input type="url" name="url" autocomplete="off"></label>
                </div>
                <label><input type="checkbox" name="match_sku"> <?php esc_html_e('Supplier SKUs exactly match our SKUs. Also allow SKU matching.', 'lavka-product-media-upload'); ?></label>
                <p><label><input type="checkbox" name="daily"> <?php esc_html_e('Refresh this catalogue daily without publishing product changes.', 'lavka-product-media-upload'); ?></label></p>
                <details><summary><?php esc_html_e('XML field mapping', 'lavka-product-media-upload'); ?></summary>
                    <p><?php esc_html_e('Use / for nested elements and | for alternatives. Namespace prefixes are optional. Product element: offer for YML, item for Google RSS feeds.', 'lavka-product-media-upload'); ?></p>
                    <div id="lsc-mapping" class="lsc-form-grid"></div>
                </details>
                <p><button type="submit" class="button button-primary"><?php esc_html_e('Save source', 'lavka-product-media-upload'); ?></button> <button type="button" class="button" id="lsc-new"><?php esc_html_e('Add another source', 'lavka-product-media-upload'); ?></button></p>
            </form>
        </details>
        <?php endif; ?>
    </div>
    <div id="lsc-status" role="status" aria-live="polite"></div>
    <form id="lsc-search" class="lsc-panel lsc-form-grid">
        <label class="lsc-wide"><?php esc_html_e('Name, supplier SKU, barcode or a list of supplier SKUs', 'lavka-product-media-upload'); ?><textarea name="q" rows="2"></textarea></label>
        <label><?php esc_html_e('Brand', 'lavka-product-media-upload'); ?><select name="brand" id="lsc-brand"></select></label>
        <label><?php esc_html_e('Category', 'lavka-product-media-upload'); ?><select name="category" id="lsc-category"></select></label>
        <label><?php esc_html_e('Show', 'lavka-product-media-upload'); ?><select name="filter">
            <option value="all"><?php esc_html_e('All supplier products', 'lavka-product-media-upload'); ?></option>
            <option value="matched"><?php esc_html_e('Matched to our products', 'lavka-product-media-upload'); ?></option>
            <option value="missing"><?php esc_html_e('Our products without a main photo', 'lavka-product-media-upload'); ?></option>
            <option value="unmatched"><?php esc_html_e('Need a product match', 'lavka-product-media-upload'); ?></option>
        </select></label>
        <div class="lsc-bottom"><button type="submit" class="button button-primary"><?php esc_html_e('Find products', 'lavka-product-media-upload'); ?></button></div>
    </form>
    <div class="lsc-toolbar lsc-basket"><strong id="lsc-selected"></strong><button type="button" id="lsc-prepare" class="button button-primary" disabled><?php esc_html_e('Prepare selected photos', 'lavka-product-media-upload'); ?></button><button type="button" id="lsc-clear" class="button"><?php esc_html_e('Clear selection', 'lavka-product-media-upload'); ?></button></div>
    <div id="lsc-results"></div>
    <nav class="lsc-toolbar" aria-label="<?php esc_attr_e('Catalogue pages', 'lavka-product-media-upload'); ?>"><button id="lsc-prev" type="button" class="button"><?php esc_html_e('Previous', 'lavka-product-media-upload'); ?></button><span id="lsc-page"></span><button id="lsc-next" type="button" class="button"><?php esc_html_e('Next', 'lavka-product-media-upload'); ?></button></nav>
    <details class="lsc-panel"><summary><?php esc_html_e('How to choose photos safely', 'lavka-product-media-upload'); ?></summary>
        <p><?php esc_html_e('Compare the exact product, colour, volume and packaging. The first supplier photo is not automatically the main image. Select its role explicitly.', 'lavka-product-media-upload'); ?></p>
        <p><?php esc_html_e('A barcode match must be unique. For ambiguous matches or lifestyle photos, confirm the exact SKU of our product. Saved manual matches are reused when the supplier ID stays the same.', 'lavka-product-media-upload'); ?></p>
        <p><?php esc_html_e('Existing photos remain visible for comparison. Identical files can be reused; different content under an occupied filename is blocked by the image checker.', 'lavka-product-media-upload'); ?></p>
        <p><?php esc_html_e('Prices and descriptions are supplier reference information. Catalogue refresh does not update our products. Videos open at the source and are not sent to the image uploader.', 'lavka-product-media-upload'); ?></p>
    </details>
</div>
<section id="lsc-uploader" hidden>
    <?php \Lavka\ProductMediaUpload\Plugin::instance()->render_page(); ?>
</section>
