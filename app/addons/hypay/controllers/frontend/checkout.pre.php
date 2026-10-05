<?php
/*****************************************************************************
 * The "thank you" page never stands for an unpaid Hypay order: the payment
 * form opens instead. See fn_hypay_complete_unpaid_order().
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// The add-on's functions (func.php) are loaded only while it is active, and
// CS-Cart can still run this file without them - around an install, an
// uninstall or a status change of the add-on. Nothing to do then.
if ($mode === 'place_order' && $_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('fn_hypay_snapshot_cart')) {
    // what the customer is ordering right now - what the order must hold
    fn_hypay_snapshot_cart(0);
}

if ($mode === 'complete' && !empty($_REQUEST['order_id']) && function_exists('fn_hypay_complete_unpaid_order')) {
    $hypay_redirect = fn_hypay_complete_unpaid_order((int) $_REQUEST['order_id']);
    if ($hypay_redirect) {
        return [CONTROLLER_STATUS_REDIRECT, $hypay_redirect];
    }
}
