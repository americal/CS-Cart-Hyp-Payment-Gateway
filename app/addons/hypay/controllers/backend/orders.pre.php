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
 * The order page asks about its own order's link, the order list about every
 * link still out: one LIST per terminal answers for all of them. Each link is
 * asked about at most once per HYPAY_LINK_AUTO_CHECK_INTERVAL, with a short
 * timeout: see fn_hypay_link_auto_check_all().
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    fn_hypay_ensure_addon_settings();

    if ($mode === 'details' && !empty($_REQUEST['order_id'])) {
        fn_hypay_link_auto_check((int) $_REQUEST['order_id']);
    } elseif ($mode === 'manage') {
        fn_hypay_link_auto_check_all();
    }
}
