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
    // the payment link settings moved to the add-on settings: the template
    // shows them here only while those are not installed
    fn_hypay_ensure_addon_settings();

    $lang_code = defined('DESCR_SL') ? DESCR_SL : CART_LANGUAGE;

    Tygh::$app['view']->assign('hypay_usergroups', fn_get_usergroups(['type' => 'C'], $lang_code));

    // Empty unless the eCom Labs "Additional Order Statuses" add-on is active:
    // the template hides the whole setting rather than offer a status that has
    // nowhere to be stored.
    Tygh::$app['view']->assign('hypay_additional_statuses', fn_hypay_get_additional_statuses($lang_code));
}
