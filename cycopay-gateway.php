<?php

/**
 * Plugin Name: CycoPay Payment Gateway
 * Plugin URI: https://cycopay.com/
 * Description: Provides a CycoPay payment gateway for woocommerce.
 * Requires at least: 5.0
 *
 * Version:     1.0.0
 * @class       CycoPay_Gateway
 * @extends     WC_Payment_Gateway
 * @package     WooCommerce/Classes/Payment
 * @author      CycoPay
 */

// require composer files
// require_once plugin_dir_path(__FILE__) . '/lib/autoload.php';

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

require plugin_dir_path(__FILE__) . '/includes/update_wc_status.php';

// initialize plugin
add_action('plugins_loaded', 'cycopay_gateway_init', 11);

function cycopay_gateway_init()
{

    class CycoPay_Gateway extends WC_Payment_Gateway
    {

        public function __construct()
        {
            $this->id = 'cycopay-gateway';
            $this->method_title = 'CycoPay Payment';
            $this->method_description = 'Pay with Cycopay payment gateway';
            $this->init_settings();
            $this->init_form_fields();

            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->apikey = $this->get_option('apikey');
            $this->instructions = $this->get_option('instructions');

            // save changes made to edit fields
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // webhook setup
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'payment_callback'));
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {

            $this->form_fields = apply_filters('cycopay_form_fields', array(

                'enabled' => array(
                    'title' => __('Enable/Disable', 'cycopay-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable CycoPay Payment', 'cycopay-gateway'),
                    'default' => 'yes',
                ),

                'apikey' => array(
                    'title' => __('Api Key', 'cycopay-gateway'),
                    'type' => 'text',
                    'description' => __('Api key generated from CycoPay.', 'cycopay-gateway'),
                    'default' => __('', 'cycopay-gateway'),
                    'desc_tip' => true,
                ),

                'title' => array(
                    'title' => __('Title', 'cycopay-gateway'),
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'cycopay-gateway'),
                    'default' => __('CycoPay Woocommerce gateway', 'cycopay-gateway'),
                    'desc_tip' => true,
                ),

                // for testing purposes
                'instructions' => array(
                    'title' => __('Instructions', 'cycopay-gateway'),
                    'type' => 'textarea',
                    'description' => __('Instructions that will be added to the thank you page and emails.', 'cycopay-gateway'),
                    'default' => 'Sample instructions',
                    'desc_tip' => true,
                ),
            ));
        }

        // process the payment
        public function process_payment($order_id)
        {

            $order = wc_get_order($order_id);

            // create payment link
            $linkResponse = $this->create_payment_link($order);
            $responseObj = json_decode(json_encode($linkResponse), true);

            if ($linkResponse->status == "fail") {

                wc_add_notice('Error while generating payment link; ' . $linkResponse->message, 'error');
                return array(
                    'result' => 'failure',
                );
            }

            $popup_url =  $linkResponse->url;

            $data = [
                'url' => $linkResponse->url,
                'key' => rand(5, 100),
            ];

            $queryString = http_build_query($data);


            // redirect to Cycopay gateway
            return array(
                'result' => 'success',
                'redirect' => $linkResponse->url,
            );
        }

        // create payment link
        public function create_payment_link($order)
        {
            $currency = $order->currency;
            $total = $order->total;
            $apikey = $this->apikey;
            $metadata = array(
                'route' => 'woocommerce',
                'order-id' => $order->id,
                //'type' => 'popup',
            );

          
            $cycopayApiUrl = "https://api.cycopay.com/api";
            $cycopayUrl = $cycopayApiUrl . "/public/payment/create";

            $payment_description = '';
            // generate payment description by concatenating product names
            foreach ($order->get_items() as $item_id => $item) {
                $product = $item->get_product();
                $product_name = $item->get_name();
                $quantity = $item->get_quantity();
                $subtotal = $item->get_subtotal();
                $item_total = $item->get_total();

                $payment_description = $payment_description . $product_name . ' x ' . $quantity . ' \n ';
            }

            $payload = array(
                'amount' => $total,
                'failureURL' => wc_get_checkout_url(),
                "successURL" => $this->get_return_url($order), //return to thank you page.
                "apiKey" => $apikey,
                'webhookURL' => get_site_url() . "/" . "wc-api/" . strtolower(get_class($this)),
                "description" => $payment_description,
                'metadata' => $metadata,
                'currency' => get_woocommerce_currency(),
            );

            // make post request to generate payment link
            $response = wp_remote_post($cycopayUrl, array(
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($payload),
                'method' => 'POST',
                'timeout' => 45,
                'data_format' => 'body',
            ));

            // Retrieve the body's response if no errors found
            $response_body = json_decode(wp_remote_retrieve_body($response));

            return ($response_body);
        }

        /**
         * Webhook
         * callback function
         */
        public function payment_callback()
        {
            $raw_post = file_get_contents('php://input');

            $decoded = json_decode($raw_post);

            // compare apiKey and ensure callback is authorized to make call
            $callback_apikey = $decoded->apiKey;
            if ($callback_apikey !== $this->apikey) {
                $data = array(
                    'error' => 'Unauthorized',
                    'status' => 401,
                );

                echo json_encode((object) $data);
                die;
            }

            if (isset($decoded->metadata)) {
                $metadata = $decoded->metadata;
                $status = $decoded->status;

                if (isset($metadata->{'order-id'})) {
                    $order_id = $metadata->{'order-id'};
                    $order = wc_get_order($order_id);

                    // for extra security
                    // callback can only modify orders that were payed using CycoPay gateway
                    if ($order->payment_method !== 'cycopay-gateway') {
                        $data = array(
                            'error' => 'Unauthorized, order was not made with this gateway',
                            'status' => 401,
                        );

                        echo json_encode((object) $data);
                        die;
                    }

                    // payment successful
                    if ($status == "completed") {
                        $order->payment_complete();

                        // Reduce stock levels
                        $order->reduce_order_stock();

                        // Remove cart
                        WC()->cart->empty_cart();
                    }
                    // payment failed
                    else if ($status == "failed") {
                        wc_add_notice(__('Payment error:', 'cycopay-gateway') . 'CycoPay payment failed', 'error');

                        $order->update_status('failed', __('Payment Failed', 'cycopay-gateway'));
                    }
                    // else if($status == "cancelled"){
                    //     $order->update_status('cancelled', __('Order cancelled', 'cycopay-gateway'));
                    // }
                }
            }

            $data = array(
                'message' => 'order status updated',
                'status' => 200,
            );

            echo json_encode((object) $data);

            die();
        }

        /**
         * Output for the order received page.
         */
        // currently unused
        public function thankyou_page()
        {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions));
            }
        }

    } // end \CycoPay_Gateway class
}

// enqueue js scripts
add_action('wp_enqueue_scripts', 'wpb_adding_scripts');

function wpb_adding_scripts()
{

    wp_register_script('main_js_script', plugins_url('/js/main.js', __FILE__), array('jquery'), true);
    wp_enqueue_script('main_js_script');
    wp_localize_script('main_js_script', 'script_data', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'fail_message' => __('Connection to server failed. Check the mail credentials.', 'script-checker'),
        'success_message' => __('Connection successful. ', 'script-checker'),
    )
    );
}

// add to payment gateway list
add_filter('woocommerce_payment_gateways', 'add_your_gateway_class');

function add_your_gateway_class($methods)
{
    $methods[] = 'CycoPay_Gateway';
    return $methods;
}
