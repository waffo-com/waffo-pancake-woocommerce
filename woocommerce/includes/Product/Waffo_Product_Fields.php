<?php
namespace WaffoPancake\Product;

if (!defined('ABSPATH')) {
    exit;
}

class Waffo_Product_Fields
{
    public static function register(): void
    {
        add_action('woocommerce_product_options_general_product_data', [self::class, 'render_field']);
        add_action('woocommerce_process_product_meta', [self::class, 'save_field']);
    }

    public static function render_field(): void
    {
        global $post;

        woocommerce_wp_text_input([
            'id'          => '_waffo_product_id',
            'label'       => 'Waffo Product ID',
            'description' => 'The corresponding product ID (PROD_xxx) created in your Waffo Dashboard. Required to accept payments for this product via Waffo Pancake.',
            'desc_tip'    => true,
            'value'       => get_post_meta($post->ID, '_waffo_product_id', true),
        ]);
    }

    public static function save_field(int $post_id): void
    {
        if (isset($_POST['_waffo_product_id'])) {
            update_post_meta($post_id, '_waffo_product_id', sanitize_text_field(wp_unslash($_POST['_waffo_product_id'])));
        }
    }
}
