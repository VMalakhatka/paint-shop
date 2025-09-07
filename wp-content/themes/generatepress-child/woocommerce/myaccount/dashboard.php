<?php
defined('ABSPATH') || exit;

/** Форс-редірект із кореня кабінету на /my-account/orders/ */
$orders_url = wc_get_endpoint_url('orders', '', wc_get_page_permalink('myaccount'));

if (!headers_sent()) {
    wp_safe_redirect($orders_url, 302);
    exit;
}
?><meta http-equiv="refresh" content="0;url=<?php echo esc_attr($orders_url); ?>">
<script>location.replace(<?php echo json_encode($orders_url); ?>);</script>
<?php
exit;