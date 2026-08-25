<?php
/*****************************************************************************
 * Hypay: extra data for the payment method settings screen
 * Author: Michael Shapar (micshap100@gmail.com)
 * Version: 1.2 | 2026-08-25
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($mode, ['update', 'add'], true)) {
    // customer usergroups for the "J5 by usergroup" selector
    Tygh::$app['view']->assign('hypay_usergroups', fn_get_usergroups(['type' => 'C'], DESCR_SL));
}
