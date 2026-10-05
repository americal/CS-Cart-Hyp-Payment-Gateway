<?php
/*****************************************************************************
 * Hypay: before the add-on manager is built
 * Author: Michael Shapar (micshap100@gmail.com)
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// The add-on's functions (func.php) are loaded only while it is active, and
// CS-Cart can still run this file without them - around an install, an
// uninstall or a status change of the add-on. Nothing to do then.
//
// The payment link settings are add-on settings now. An installation updated
// from a version without them gets them here, before its settings are shown -
// see fn_hypay_ensure_addon_settings().
if ($_SERVER['REQUEST_METHOD'] === 'GET' && function_exists('fn_hypay_ensure_addon_settings')) {
    fn_hypay_ensure_addon_settings();
}
