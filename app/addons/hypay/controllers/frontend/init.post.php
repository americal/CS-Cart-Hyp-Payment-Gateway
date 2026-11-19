<?php
/*****************************************************************************
 * Runs on every storefront request: a customer who left the Hyp payment page
 * without paying is told that the order was NOT placed.
 * See fn_hypay_check_unpaid_checkout().
 * Also issues the documents of paid payment links that are due - see
 * fn_hypay_link_doc_sweep().
 *****************************************************************************/

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

$hypay_controller = (string) Registry::get('runtime.controller');
$hypay_mode       = (string) Registry::get('runtime.mode');
if ($hypay_controller === '' && !empty($_REQUEST['dispatch'])) {
    list($hypay_controller, $hypay_mode) = array_pad(explode('.', (string) $_REQUEST['dispatch']), 2, '');
}

// The add-on's functions (func.php) are loaded only while it is active, and
// CS-Cart can still run this file without them - around an install, an
// uninstall or a status change of the add-on. Nothing to do then.
if (function_exists('fn_hypay_check_unpaid_checkout')) {
    fn_hypay_check_unpaid_checkout($hypay_controller, $hypay_mode);
}

// The EzCount document of a paid payment link that is due and not there yet
// (its return never came, or EzCount refused it and it is due for another
// try): issued on the back of any visitor's request, at most one sweep a
// minute - a paid order must not wait for somebody to open it in the admin
// panel. One cheap query when there is nothing to do. The cron URL
// (hypay.cron) does the same without waiting for visitors.
// Not on the return from Hyp itself: that request brings the card details
// and issues the document with them.
if (function_exists('fn_hypay_link_doc_sweep')
    && !in_array($hypay_controller, ['hypay', 'payment_notification'], true)
) {
    fn_hypay_link_doc_sweep();
}
