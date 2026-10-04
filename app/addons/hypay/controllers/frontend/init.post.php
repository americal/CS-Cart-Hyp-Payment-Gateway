<?php
/*****************************************************************************
 * Runs on every storefront request: a customer who left the Hyp payment page
 * without paying is told that the order was NOT placed.
 * See fn_hypay_check_unpaid_checkout().
 *****************************************************************************/

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

$hypay_controller = (string) Registry::get('runtime.controller');
if ($hypay_controller === '' && !empty($_REQUEST['dispatch'])) {
    $hypay_controller = strtok((string) $_REQUEST['dispatch'], '.');
}

fn_hypay_check_unpaid_checkout($hypay_controller);
