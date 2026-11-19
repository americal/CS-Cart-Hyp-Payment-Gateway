<?php
/*****************************************************************************
 * hypay.pay  - open the Hyp payment form again for an order the customer left
 *              unpaid (the button in the "order not placed" popup).
 * hypay.cron - documents of paid payment links and the LIST lookup, for the
 *              server's cron (see below).
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

/*
 * hypay.cron - for the server's cron, every few minutes:
 *
 *   curl -s 'https://example.com/index.php?dispatch=hypay.cron'
 *
 * Issues the EzCount documents of paid payment links that are due (the
 * return never came, or EzCount refused and it is time to try again), and
 * asks Hyp about the links still out (LIST, at most once per the interval the
 * payment link settings give the order list), so a link paid while nobody
 * has the admin panel open is recorded - status, additional status, document
 * - all the same. Safe to call by anyone: it only does what is due anyway.
 */
if ($mode === 'cron') {
    $result = ['documents' => [], 'paid' => []];

    $last = function_exists('fn_get_storage_data') ? (int) fn_get_storage_data('hypay_cron_at') : 0;
    if ($last > TIME - 20 && $last <= TIME) {
        $result['skipped'] = 'called less than 20 seconds ago';
    } else {
        if (function_exists('fn_set_storage_data')) {
            fn_set_storage_data('hypay_cron_at', (string) TIME);
        }

        $result = fn_hypay_without_notifications(true, static function () {
            $out = ['documents' => [], 'paid' => []];
            try {
                $changed     = fn_hypay_link_auto_check_all();
                $out['paid'] = array_values(array_unique((array) ($changed['paid'] ?? [])));
            } catch (\Throwable $e) {
                hypay_log_always(0, 'cron: link lookup failed', get_class($e) . ': ' . $e->getMessage());
                $out['error'] = 'link lookup failed';
            }
            $out['documents'] = fn_hypay_link_doc_sweep(true);

            return $out;
        });
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $result);
    exit;
}

return [CONTROLLER_STATUS_NO_PAGE];
