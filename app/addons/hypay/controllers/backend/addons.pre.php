<?php
/*****************************************************************************
 * Hypay: before the add-on manager is built
 * Author: Michael Shapar (micshap100@gmail.com)
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// The payment link settings are add-on settings now. An installation updated
// from a version without them gets them here, before its settings are shown -
// see fn_hypay_ensure_addon_settings().
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    fn_hypay_ensure_addon_settings();
}
