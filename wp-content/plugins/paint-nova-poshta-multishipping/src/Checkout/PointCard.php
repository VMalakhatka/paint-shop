<?php

namespace Paint\NovaPoshta\Checkout;

defined('ABSPATH') || exit;

/** Text belongs to PHP gettext; directory values are rendered as text, never HTML. */
final class PointCard
{
    public static function labels(): array
    {
        return [
            'title' => __('Point restrictions and hours', 'paint-nova-poshta-multishipping'),
            'more' => __('Load more points', 'paint-nova-poshta-multishipping'),
            'emptyPage' => __('No points of this type on this page. Load more or refine your search.', 'paint-nova-poshta-multishipping'),
            'retry' => __('Try again', 'paint-nova-poshta-multishipping'),
            'unknown' => __('Not specified by Nova Poshta — check with the manager', 'paint-nova-poshta-multishipping'),
            'shortUnknown' => __('Not specified', 'paint-nova-poshta-multishipping'),
            'placeWeight' => __('Maximum weight of one place (box)', 'paint-nova-poshta-multishipping'),
            'totalWeight' => __('Maximum total shipment weight', 'paint-nova-poshta-multishipping'),
            'declaredValue' => __('Maximum declared value', 'paint-nova-poshta-multishipping'),
            'receivingDimensions' => __('Receiving dimensions (length × width × height)', 'paint-nova-poshta-multishipping'),
            'sendingDimensions' => __('Sending dimensions (length × width × height)', 'paint-nova-poshta-multishipping'),
            'status' => __('Directory status', 'paint-nova-poshta-multishipping'),
            'working' => __('Working (not a live open/closed indicator)', 'paint-nova-poshta-multishipping'),
            'unavailable' => __('Not available for selection. Choose another point.', 'paint-nova-poshta-multishipping'),
            'kg' => __('kg', 'paint-nova-poshta-multishipping'),
            'cm' => __('cm', 'paint-nova-poshta-multishipping'),
            'currency' => __('UAH', 'paint-nova-poshta-multishipping'),
            'hoursTitle' => __('Weekly schedule from Nova Poshta', 'paint-nova-poshta-multishipping'),
            'day' => __('Day', 'paint-nova-poshta-multishipping'),
            'Schedule' => __('Opening hours', 'paint-nova-poshta-multishipping'),
            'Reception' => __('Acceptance', 'paint-nova-poshta-multishipping'),
            'Delivery' => __('Delivery / issue', 'paint-nova-poshta-multishipping'),
            'days' => [
                'Monday' => __('Mon', 'paint-nova-poshta-multishipping'),
                'Tuesday' => __('Tue', 'paint-nova-poshta-multishipping'),
                'Wednesday' => __('Wed', 'paint-nova-poshta-multishipping'),
                'Thursday' => __('Thu', 'paint-nova-poshta-multishipping'),
                'Friday' => __('Fri', 'paint-nova-poshta-multishipping'),
                'Saturday' => __('Sat', 'paint-nova-poshta-multishipping'),
                'Sunday' => __('Sun', 'paint-nova-poshta-multishipping'),
            ],
            'note' => __('Directory data may be cached for up to 15 minutes. Missing or zero limits do not mean unlimited. Hours are local to the point, not a promised delivery date.', 'paint-nova-poshta-multishipping'),
            'warning' => __('These are directory restrictions, not confirmation that your order fits. The manager must check packed boxes, actual and volumetric weight, goods, access conditions and current Nova Poshta rules.', 'paint-nova-poshta-multishipping'),
            'official' => __('Check current Nova Poshta conditions', 'paint-nova-poshta-multishipping'),
            'help' => __('How to choose a delivery point', 'paint-nova-poshta-multishipping'),
            'helpUrl' => function_exists('pc_wholesale_help_url') && function_exists('pc_wholesale_customer_can_access') && pc_wholesale_customer_can_access()
                ? pc_wholesale_help_url('np-point-limits') : '',
        ];
    }
}
