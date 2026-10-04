<?php
/*****************************************************************************
 * The "thank you" page never stands for an unpaid Hypay order: the payment
 * form opens instead. See fn_hypay_complete_unpaid_order().
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($mode === 'complete' && !empty($_REQUEST['order_id'])) {
    fn_hypay_complete_unpaid_order((int) $_REQUEST['order_id']);
}
