<?php
/*****************************************************************************
 * Hypay: extra data for the payment method settings screen
 * Author: Michael Shapar (micshap100@gmail.com)
 * Version: 1.2 | 2026-08-25
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// The processor settings template is rendered by several dispatches:
// payments.update / payments.add (whole method) and payments.processor
// (the "Configure" tab alone), so the usergroup list is assigned for any of
// them - without it the "J5 by usergroup" selector has nothing to show.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lang_code = defined('DESCR_SL') ? DESCR_SL : CART_LANGUAGE;

    Tygh::$app['view']->assign('hypay_usergroups', fn_get_usergroups(['type' => 'C'], $lang_code));
}
