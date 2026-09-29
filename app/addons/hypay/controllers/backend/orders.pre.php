<?php
/*****************************************************************************
 * Hypay: before the order details page is built
 * Author: Michael Shapar (micshap100@gmail.com)
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * An order with a payment link out asks Hyp whether it has been paid before the
 * page is put together - so a payment whose return never reached the store is
 * on the page the first time anyone looks, status and all. Here rather than in
 * orders.post.php: the order is read after this, and reads what was recorded.
 *
 * At most once a minute per link, and with a short timeout: see
 * fn_hypay_link_auto_check().
 */
if ($mode === 'details' && $_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_REQUEST['order_id'])) {
    fn_hypay_link_auto_check((int) $_REQUEST['order_id']);
}
