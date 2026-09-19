<?php
if (!defined('ABSPATH')) exit;

final class PSU_Category_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct('psu_category_menu', __('Lavka categories', 'paint-shop-ux'), [
            'classname' => 'widget_psu_category_menu',
        ]);
    }

    public static function sort_options() {
        return [
            'name' => __('Name', 'paint-shop-ux'),
            'slug' => __('Slug', 'paint-shop-ux'),
            'term_id' => __('Category ID', 'paint-shop-ux'),
            'count' => __('Product count', 'paint-shop-ux'),
            'menu_order' => __('Category order', 'paint-shop-ux'),
        ];
    }

    public static function settings($instance) {
        $instance = is_array($instance) ? $instance : [];
        $orderby = $instance['orderby'] ?? 'name';
        if ($orderby === 'id') $orderby = 'term_id';
        return [
            'title' => isset($instance['title']) && is_scalar($instance['title']) ? sanitize_text_field($instance['title']) : '',
            'orderby' => is_string($orderby) && in_array($orderby, ['name', 'slug', 'term_id', 'count', 'menu_order'], true) ? $orderby : 'name',
            'order' => ($instance['order'] ?? '') === 'DESC' ? 'DESC' : 'ASC',
            'hide_empty' => !array_key_exists('hide_empty', $instance) || !empty($instance['hide_empty']),
            'show_count' => !empty($instance['show_count']),
        ];
    }

    public function widget($args, $instance) {
        $instance = self::settings($instance);
        PSU_Category_Menu::render($instance, $this, $args, array_merge($instance, ['widget' => $this->id]));
    }

    public function update($new_instance, $old_instance) {
        $new_instance = is_array($new_instance) ? $new_instance : [];
        $new_instance['hide_empty'] = !empty($new_instance['hide_empty']);
        $new_instance['show_count'] = !empty($new_instance['show_count']);
        return self::settings($new_instance);
    }

    public function form($instance) {
        $instance = self::settings($instance);
        $id = $this->get_field_id('title');
        echo '<p><label for="' . esc_attr($id) . '">' . esc_html__('Title', 'paint-shop-ux') . '</label>';
        echo '<input class="widefat" id="' . esc_attr($id) . '" name="' . esc_attr($this->get_field_name('title')) . '" value="' . esc_attr($instance['title']) . '"></p>';
        foreach (['orderby' => [__('Sort by', 'paint-shop-ux'), self::sort_options()],
            'order' => [__('Sort direction', 'paint-shop-ux'), ['ASC' => __('Ascending', 'paint-shop-ux'), 'DESC' => __('Descending', 'paint-shop-ux')]]] as $key => $field) {
            echo '<p><label for="' . esc_attr($this->get_field_id($key)) . '">' . esc_html($field[0]) . '</label>';
            echo '<select class="widefat" id="' . esc_attr($this->get_field_id($key)) . '" name="' . esc_attr($this->get_field_name($key)) . '">';
            foreach ($field[1] as $value => $label) echo '<option value="' . esc_attr($value) . '"' . selected($instance[$key], $value, false) . '>' . esc_html($label) . '</option>';
            echo '</select></p>';
        }
        foreach (['hide_empty' => __('Hide empty categories', 'paint-shop-ux'), 'show_count' => __('Show product counts', 'paint-shop-ux')] as $key => $label) {
            echo '<p><label><input type="checkbox" name="' . esc_attr($this->get_field_name($key)) . '" value="1"' . checked($instance[$key], true, false) . '> ' . esc_html($label) . '</label></p>';
        }
    }
}

add_action('widgets_init', static function () { register_widget(PSU_Category_Widget::class); });
