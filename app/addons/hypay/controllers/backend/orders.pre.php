<?php
/*****************************************************************************
 * Hypay: before the order list and the order details page are built
 * Author: Michael Shapar (micshap100@gmail.com)
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Orders with a payment link out ask Hyp whether it has been paid before the
 * page is put together - so a payment whose return never reached the store is
 * on the page the first time anyone looks, status and all. Here rather than in
 * orders.post.php: the orders are read after this, and read what was recorded.
 *
 * The order page asks about its own order's link every time it is opened; the
 * order list about every link still out, at most once per the interval the
 * payment link settings give it (5 minutes by default) and only while one is
 * still payable. One LIST per terminal answers for all of them, with a short
 * timeout: see fn_hypay_link_auto_check_all().
 */
// The add-on's functions (func.php) are loaded only while it is active, and
// CS-Cart can still run this file without them - around an install, an
// uninstall or a status change of the add-on. Nothing to do then.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && function_exists('fn_hypay_ensure_addon_settings')) {
    fn_hypay_ensure_addon_settings();

    if ($mode === 'details' && !empty($_REQUEST['order_id'])) {
        fn_hypay_link_auto_check((int) $_REQUEST['order_id']);
    } elseif ($mode === 'manage') {
        fn_hypay_link_auto_check_all();
    }
}
