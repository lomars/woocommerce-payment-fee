<?php
/*
 * Plugin Name: WooCommerce Payment Fee
 * Plugin URI: https://github.com/lomars/woocommerce_payment_fee/
 * Description: Adds a percentage payment fee to WooCommerce Checkout for selected payment methods and allows excluding products from the fee calculation.
 * Version: 1.0
 * Authors: LoicTheAztec, Paul Vek
 * Author URI: https://stackoverflow.com/users/3730754/loictheaztec
 * Author URI: https://stackoverflow.com/users/23079162/paul-vek
 * Copyright: (c) 2024
 * License: GPLv3
 * License URL: https://www.gnu.org/licenses/gpl-3.0.html#license-text
 * Text Domain: wc-payment-fee
*/

// Exit if accessed directly.
defined( 'ABSPATH' ) or exit;

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

###########################################################
## 1. Plugin general settings: WooCommerce > Payment Fee ##
###########################################################

/**
 * Add submenu page to Admin "Woocommerce" menu
 */
function add_payment_fee_submenu() {
    add_submenu_page( 
        'woocommerce', 
        __('Payment Fee Settings', 'wc-payment-fee'), 
        __('Payment Fee', 'wc-payment-fee'),  
        'manage_options', 
        'payment-fee-settings', 
        'payment_fee_settings_callback' 
    );
}
add_action( 'admin_menu', 'add_payment_fee_submenu', 100 );

/**
 * Settings page callback function
 */
function payment_fee_settings_callback() {
    ?>
    <div class="wrap">
        <h1><?php _e('Payment Fee Settings', 'wc-payment-fee'); ?></h1>
        <form method="post" action="options.php">
            <p><?php __('To apply the additional fee.'); ?></p><?php
            settings_fields('payment_fee_settings_group');
            do_settings_sections('payment-fee-settings'); 
            submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Register and define settings
 */
function payment_fee_settings() {
    register_setting('payment_fee_settings_group', 'payment_fee_payment_methods');
    register_setting('payment_fee_settings_group', 'payment_fee_percentage_rate');

    add_settings_section('selected_payment_methods_section', __('Select Payment Gateways', 'wc-payment-fee'), 'selected_payment_methods_section_callback', 'payment-fee-settings');
    add_settings_field('selected_payment_methods_field', __('Payment Gateways', 'wc-payment-fee'), 'selected_payment_methods_field_callback', 'payment-fee-settings', 'selected_payment_methods_section');

    add_settings_section('percentage_fee_section', __('Percentage Fee Settings', 'wc-payment-fee'), 'percentage_rate_fee_section_callback', 'payment-fee-settings');
    add_settings_field('percentage_fee_field',  __('Percentage Fee (%)', 'wc-payment-fee'), 'percentage_rate_fee_field_callback', 'payment-fee-settings', 'percentage_fee_section');
}
add_action('admin_init', 'payment_fee_settings');

/**
 * Callback functions for settings section
 */
function selected_payment_methods_section_callback() {
    echo '<p>' . __('Select the payment gateways that will enable the payment fee:', 'wc-payment-fee') . '</p>';
}

/**
 * Callback functions for settings fields (checkboxes)
 */
function selected_payment_methods_field_callback() {
    $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
    $selected_gateway   = get_option('payment_fee_payment_methods', array());

    foreach ($available_gateways as $gateway) {
        printf(
            '<label><input type="checkbox" name="payment_fee_payment_methods[]" value="%s" %s> %s</label><br>',
            esc_attr($gateway->id),
            in_array($gateway->id, $selected_gateway) ? 'checked' : '',
            esc_html($gateway->get_title())
        );
    }
}

/**
 * Callback functions for settings section
 */
function percentage_rate_fee_section_callback() {
    echo '<p>' . __('Set the percentage rate for the payment fee:', 'wc-payment-fee') . '</p>';
}

/**
 * Callback functions for settings fields (input type number)
 */
function percentage_rate_fee_field_callback() {
    $value = get_option('payment_fee_percentage_rate');
    echo '<input type="number" step="0.1" min="0" name="payment_fee_percentage_rate" value="' . esc_attr($value) . '">';
}

#################################################################################
## 2. Product settings: Exclude specific products from payment fee calculation ##
#################################################################################

/**
 * Display a custom checkbox in admin edit product
 */
function add_admin_product_custom_field() {
    woocommerce_wp_checkbox(array(
        'id'            => '_payment_fee_excl',
        'wrapper_class' => 'show_if_simple',
        'label'         => __('Payment Fee excluded', 'wc-payment-fee'),
        'description'   => __('Exclude product from payment fee calculation.', 'wc-payment-fee'),
    ));
}
add_action('woocommerce_product_options_general_product_data', 'add_admin_product_custom_field');

/**
 * Save custom checkbox value from admin edit product
 * 
 * @param object $product the WC_Product Object
 */
function save_admin_product_custom_field_value($product) {
    $product->update_meta_data('_payment_fee_excl', isset($_POST['_excl_payment_fee']) ? 'yes' : 'no');
}
add_action('woocommerce_admin_process_product_object', 'save_admin_product_custom_field_value');

###########################################
## WooCommerce Checkout: Add payment fee ##
###########################################

/**
 * Add a payment fee for specific payment methods
 * 
 * @param object $cart the WC_Cart Object
 */
function add_checkout_payment_fee( $cart ) {
    if ( is_admin() && ! defined('DOING_AJAX') ) {
        return;
    }

    $payment_methods = get_option('payment_fee_payment_methods', array()); // Get selected payment gateways
    $percentage      = floatval(get_option('payment_fee_percentage_rate')); // Get additional cost percentage

    if ( ! empty($payment_methods) && $percentage > 0 && is_checkout() && ! is_wc_endpoint_url() 
    && in_array(WC()->session->get('chosen_payment_method'), $payment_methods)) {
        $subtotal = 0; // Initializing

        foreach ($cart->get_cart() as $item) {
            $product = wc_get_product($item['product_id']);
            if ($product->get_meta('_payment_fee_excl') !== 'yes') {
                $subtotal += (float)$item['line_subtotal'];
            }
        }

        if ( $subtotal > 0 ) {
            $fee_amount = $subtotal * $percentage / 100;
            $cart->add_fee(__('Payment Fee', 'wc-payment-fee'), $fee_amount, true, '');
        }
    }
}
add_action('woocommerce_cart_calculate_fees', 'add_checkout_payment_fee');

/**
 * Update/refresh checkout on payment change
 */
function update_checkout_on_payment_method_change() {
    wc_enqueue_js("$('form.checkout').on( 'change', 'input[name=payment_method]', function(){
        $(document.body).trigger('update_checkout');
    });");
}
add_action('woocommerce_checkout_init', 'update_checkout_on_payment_method_change');