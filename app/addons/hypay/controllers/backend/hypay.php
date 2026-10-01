<?php
/*****************************************************************************
 * Hypay backend controller: J5 (two-phase commit) actions
 * Author: Michael Shapar (micshap100@gmail.com)
 * Version: 1.2 | 2026-08-25
 *
 * dispatch[hypay.capture] — charge a held authorization (order total)
 * dispatch[hypay.void]    — abandon the hold, nothing is charged
 *
 * dispatch[hypay.link_create] — create a payment link and send it (payRequest),
 *                               J4 (charge) or J5 (hold, deal=j5)
 * dispatch[hypay.link_cancel] — cancel the order's active payment link
 * dispatch[hypay.link_check]  — ask Hyp whether the link has been paid
 * (each of them, and the window, first expires a link past its lifetime)
 * hypay.link_panel (GET)      — the payment link window (dialog content)
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// money operations belong to whoever is allowed to manage orders
if (function_exists('fn_check_permissions') && !fn_check_permissions('orders', 'update_status', 'admin')) {
    return [CONTROLLER_STATUS_DENIED];
}

// The payment link window, opened from the order's tools menu and loaded into
// a dialog (cm-dialog-opener + cm-ajax). The only GET here: it reads, and the
// buttons inside it post to the modes below.
if ($mode === 'link_panel' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $order_id = (int) ($_REQUEST['order_id'] ?? 0);
    if ($order_id <= 0) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    // a link past its lifetime opens as expired, with a new one on offer
    fn_hypay_link_expire_if_due($order_id);

    Tygh::$app['view']->assign('hypay_link', fn_hypay_get_link_panel_data($order_id));
    Tygh::$app['view']->assign('hypay_link_order_id', $order_id);

    return [CONTROLLER_STATUS_OK];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return [CONTROLLER_STATUS_NO_PAGE];
}

$order_id = (int) ($_REQUEST['order_id'] ?? 0);
if ($order_id <= 0) {
    return [CONTROLLER_STATUS_NO_PAGE];
}

if ($mode === 'capture') {
    $amount   = (isset($_REQUEST['amount']) && $_REQUEST['amount'] !== '') ? (float) $_REQUEST['amount'] : null;
    $payments = (isset($_REQUEST['payments']) && $_REQUEST['payments'] !== '') ? (int) $_REQUEST['payments'] : null;
    // only sent when the merchant typed one in: null leaves the authorization's
    // own ID (or the lack of one) alone
    $personal_id = (isset($_REQUEST['personal_id']) && $_REQUEST['personal_id'] !== '')
        ? (string) $_REQUEST['personal_id']
        : null;
    // a fresh authorization number, typed in only after a capture was refused;
    // null leaves the one that came back with the hold in place
    $acode = (isset($_REQUEST['acode']) && $_REQUEST['acode'] !== '')
        ? (string) $_REQUEST['acode']
        : null;
    fn_hypay_capture_j5($order_id, $amount, $payments, $personal_id, $acode);

    // hypay_result tells the order page it was reached from a J5 action, so the
    // notification carrying the outcome is pinned instead of fading away
    return [CONTROLLER_STATUS_OK, 'orders.details?order_id=' . $order_id . '&hypay_result=capture'];
}

// The payment link buttons. The window posts them with hypay_ajax=1 and gets
// itself back, redrawn with the outcome, in place of the old one - the page
// does not reload and the dialog is not reopened. Without the flag (JavaScript
// off, an old cached window) the order page is shown again, plainly.
if (in_array($mode, ['link_create', 'link_cancel', 'link_check'], true)) {
    // A link that ran out while the window stood open: there is nothing left
    // to check or cancel, and the redrawn window says it expired. Creating
    // goes ahead - the expired link no longer stands in the way.
    // What was queued before the action (CS-Cart's own warnings, such as the
    // changed core files one) is not the window's to show.
    $queued = isset(Tygh::$app['session']['notifications'])
        ? array_keys((array) Tygh::$app['session']['notifications'])
        : [];

    $expired = fn_hypay_link_expire_if_due($order_id);

    if ($mode === 'link_create') {
        // the other orders ticked in the window, as "1001,1002" or order_ids[]
        $order_ids = $_REQUEST['order_ids'] ?? [];
        if (!is_array($order_ids)) {
            $order_ids = explode(',', (string) $order_ids);
        }

        fn_hypay_link_create(
            $order_id,
            (string) ($_REQUEST['email'] ?? ''),
            (string) ($_REQUEST['cell'] ?? ''),
            array_filter(array_map('intval', $order_ids)),
            // J4 (charge now) unless J5 (hold) was picked
            ($_REQUEST['deal'] ?? 'j4') === 'j5'
        );
    } elseif ($expired) {
        // nothing left to cancel or check
    } elseif ($mode === 'link_cancel') {
        fn_hypay_link_cancel($order_id);
    } else {
        fn_hypay_link_check($order_id);
    }

    if (!empty($_REQUEST['hypay_ajax'])) {
        // the notifications the action raised belong in the window, not on the
        // next page the admin happens to open; the rest stay queued for it
        $notices = [];
        if (isset(Tygh::$app['session']['notifications'])) {
            foreach ((array) Tygh::$app['session']['notifications'] as $key => $notice) {
                if (in_array($key, $queued, true) || !is_array($notice)) {
                    continue;
                }
                $notices[] = $notice;
                unset(Tygh::$app['session']['notifications'][$key]);
            }
        }

        $view = Tygh::$app['view'];
        $view->assign('hypay_link', fn_hypay_get_link_panel_data($order_id));
        $view->assign('hypay_link_order_id', $order_id);
        $view->assign('hypay_link_notices', $notices);
        $view->display('addons/hypay/views/hypay/link_panel.tpl');
        exit;
    }

    return [CONTROLLER_STATUS_OK, 'orders.details?order_id=' . $order_id];
}

if ($mode === 'void') {
    fn_hypay_void_j5($order_id);

    return [CONTROLLER_STATUS_OK, 'orders.details?order_id=' . $order_id . '&hypay_result=void'];
}

return [CONTROLLER_STATUS_NO_PAGE];
