<?php defined('ABSPATH') || exit; $id = wp_unique_id('lavka-search-'); ?>
<form role="search" method="get" class="lavka-fallback-search" action="<?php echo esc_url(home_url('/')); ?>">
    <label for="<?php echo esc_attr($id); ?>"><span><?php esc_html_e('Search', 'lavka-storefront'); ?></span><input type="search" name="s" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr(get_search_query(false)); ?>" /></label>
    <button type="submit"><?php esc_html_e('Search', 'lavka-storefront'); ?></button>
</form>
