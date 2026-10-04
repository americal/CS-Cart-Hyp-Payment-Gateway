<?php
/*****************************************************************************
 * The "thank you" page never stands for an unpaid Hypay order: the payment
 * form opens instead. See fn_hypay_complete_unpaid_order().
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($mode === 'place_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // what the customer is ordering right now - what the order must hold
    fn_hypay_snapshot_cart(0);
}

if ($mode === 'complete' && !empty($_REQUEST['order_id'])) {
    $hypay_redirect = fn_hypay_complete_unpaid_order((int) $_REQUEST['order_id']);
    if ($hypay_redirect) {
        return [CONTROLLER_STATUS_REDIRECT, $hypay_redirect];
    }
}
