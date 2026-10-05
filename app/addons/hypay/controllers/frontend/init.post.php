<?php
/*****************************************************************************
 * Runs on every storefront request: a customer who left the Hyp payment page
 * without paying is told that the order was NOT placed.
 * See fn_hypay_check_unpaid_checkout().
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
