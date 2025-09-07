<?php
defined('ABSPATH') || exit;

/**
 * My Account navigation template override (child theme)
 * File: wp-content/themes/generatepress-child/woocommerce/myaccount/navigation.php
 */
$items = wc_get_account_menu_items();

unset($items['dashboard']);     // прибрати «Панель»
unset($items['downloads']);     // прибрати «Завантаження»


?>
<nav class="woocommerce-MyAccount-navigation">
    <ul>
        <?php foreach ( $items as $endpoint => $label ) :
            if ( isset( $ua[ $endpoint ] ) ) {
                $label = $ua[ $endpoint ];
            }
        ?>
            <li class="<?php echo esc_attr( wc_get_account_menu_item_classes( $endpoint ) ); ?>">
                <a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>