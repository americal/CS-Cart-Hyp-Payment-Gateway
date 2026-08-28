<?php
/*****************************************************************************
 * Hypay: order details page adjustments
 * Author: Michael Shapar (micshap100@gmail.com)
 *****************************************************************************/

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Payment info holds finished strings in whatever language - and whatever
 * encoding - the payment was taken in. The J5 lines are composed again from
 * ?:hypay_transactions in the reader's language, the "J5 hold" line is dropped
 * because the panel further down the page already prints the hold in full, and
 * a payment status the gateway sent in windows-1255 is repaired so that it
 * prints at all instead of escaping to nothing.
 *
 * The get_order_info_post hook does the same for every other order read, but on
 * this page it does not take: payment_info is not on $order by the time that
 * hook fires. Here the controller has the finished array the templates are about
 * to render, which is the one place it is certain to be there.
 */
if ($mode === 'details') {
    $view = Registry::get('view');

    if (is_object($view) && method_exists($view, 'getTemplateVars')) {
        $order_info = $view->getTemplateVars('order_info');

        if (fn_hypay_localize_payment_info($order_info)) {
            $view->assign('order_info', $order_info);
        }
    }
}
