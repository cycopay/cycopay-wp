<?php
function update_wc_status_ajax()
{
    if (!isset($_POST['paymentDetails'])) {
        $data = array(
            'error' => 'Payment details not set',
            'status' => 400,
        );

        echo json_encode((object) $data);
        die;
    }

    $paymentDetails = $_POST['paymentDetails'];
    $paymentDetails = (object) $paymentDetails;

    if (!isset($paymentDetails->apiKey)) {
        $data = array(
            'error' => 'Unauthorized',
            'status' => 401,
        );

        echo json_encode((object) $data);
        die;
    }

    if (!isset($paymentDetails->metadata)) {
        $data = array(
            'error' => 'No metadata supplied',
            'status' => 400,
        );

        echo json_encode((object) $data);
        die;
    }

    $metadata = (object) $paymentDetails->metadata;
    $status = $paymentDetails->status;

    if (!isset($metadata->{'order-id'})) {
        $data = array(
            'error' => 'Order not found',
            'status' => 400,
        );

        echo json_encode((object) $data);
        die;
    }

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

    $data = array(
        'message' => 'order status updated',
        'status' => 200,
    );

    echo json_encode((object) $data);

    die();

}

add_action('wp_ajax_nopriv_update_wc_status_ajax', 'update_wc_status_ajax');
add_action('wp_ajax_update_wc_status_ajax', 'update_wc_status_ajax');