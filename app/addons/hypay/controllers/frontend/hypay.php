<?php
/*****************************************************************************
 * hypay.pay - open the Hyp payment form again for an order the customer left
 * unpaid (the button in the "order not placed" popup).
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($mode === 'pay') {
    $order_id = (int) ($_REQUEST['order_id'] ?? 0);

    if (fn_hypay_order_awaits_payment($order_id)) {
        // redirects to Hyp; comes back only if the payment could not start
        fn_hypay_restart_payment($order_id);
    }

    // paid in the meantime, not this visitor's, or not payable here
    if ($order_id > 0 && !empty(Tygh::$app['session']['auth']['user_id'])) {
        return [CONTROLLER_STATUS_REDIRECT, 'orders.details?order_id=' . $order_id];
    }

    return [CONTROLLER_STATUS_REDIRECT, 'checkout.cart'];
}

return [CONTROLLER_STATUS_NO_PAGE];
