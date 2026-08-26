<?php
/*****************************************************************************
 * Hypay Addon Initialization
 * Author: Michael Shapar (micshap100@gmail.com)
 * Version: 1.0 | 2025-10-20
 *****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Payment info stores whatever language the customer paid in. Re-render the J5
// lines from ?:hypay_transactions every time an order is read, so the reader
// sees them in their own language instead of the buyer's.
// 'get_order_info_post', not 'get_order_info': CS-Cart derives the handler name
// from the hook name verbatim, so registering the latter makes it look for
// fn_hypay_get_order_info - and finding only fn_hypay_get_order_info_post, it
// throws "Hook is not callable" on every order page rather than skipping it.
fn_register_hooks('get_order_info_post');

