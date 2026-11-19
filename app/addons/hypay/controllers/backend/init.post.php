<?php
/*****************************************************************************
 * Runs on every admin panel request: the EzCount document of a paid payment
 * link that is due and not there yet is issued, whatever page is open - see
 * fn_hypay_link_doc_sweep(). At most one sweep a minute, one cheap query when
 * there is nothing to do.
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// The add-on's functions (func.php) are loaded only while it is active, and
// CS-Cart can still run this file without them - around an install, an
// uninstall or a status change of the add-on. Nothing to do then.
if (function_exists('fn_hypay_link_doc_sweep')) {
    fn_hypay_link_doc_sweep();
}
