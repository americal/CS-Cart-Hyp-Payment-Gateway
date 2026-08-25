<?php
/*****************************************************************************
 * Hypay backend controller: J5 (two-phase commit) actions
 * Author: Michael Shapar (micshap100@gmail.com)
 * Version: 1.2 | 2026-08-25
 *
 * dispatch[hypay.capture] — charge a held authorization (order total)
 * dispatch[hypay.void]    — abandon the hold, nothing is charged
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return [CONTROLLER_STATUS_NO_PAGE];
}

// money operations belong to whoever is allowed to manage orders
if (function_exists('fn_check_permissions') && !fn_check_permissions('orders', 'update_status', 'admin')) {
    return [CONTROLLER_STATUS_DENIED];
}

$order_id = (int) ($_REQUEST['order_id'] ?? 0);
if ($order_id <= 0) {
    return [CONTROLLER_STATUS_NO_PAGE];
}

if ($mode === 'capture') {
    $amount   = (isset($_REQUEST['amount']) && $_REQUEST['amount'] !== '') ? (float) $_REQUEST['amount'] : null;
    $payments = (isset($_REQUEST['payments']) && $_REQUEST['payments'] !== '') ? (int) $_REQUEST['payments'] : null;
    fn_hypay_capture_j5($order_id, $amount, $payments);

    // hypay_result tells the order page it was reached from a J5 action, so the
    // notification carrying the outcome is pinned instead of fading away
    return [CONTROLLER_STATUS_OK, 'orders.details?order_id=' . $order_id . '&hypay_result=capture'];
}

if ($mode === 'void') {
    fn_hypay_void_j5($order_id);

    return [CONTROLLER_STATUS_OK, 'orders.details?order_id=' . $order_id . '&hypay_result=void'];
}

return [CONTROLLER_STATUS_NO_PAGE];
