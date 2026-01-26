<?php
/*****************************************************************************
 * Hypay Addon Functions
 * Author: Michael Shapar (micshap100@gmail.com)
 * Version: 1.0 | 2025-10-20
 *****************************************************************************/
use Tygh\Http;
use Tygh\Registry;

function fn_hypay_install()
{
    db_query("INSERT INTO ?:payment_processors ?e", [
        'processor'           => 'Hypay',
        'processor_script'    => 'hypay.php',
        'processor_template' => '',
        'admin_template' => 'hypay.tpl',
        'admin_template'      => 'hypay.tpl',
        'callback'            => 'Y',
        'type'                => 'P',
        'addon'               => 'hypay'
    ]);
    fn_set_notification('N', __('notice'), 'Hypay payment processor registered.');
}

function fn_hypay_uninstall()
{
    db_query("DELETE FROM ?:payment_processors WHERE processor_script = ?s", 'hypay.php');
    fn_set_notification('W', __('notice'), 'Hypay processor removed.');
}


