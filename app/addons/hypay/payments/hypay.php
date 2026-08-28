<?php
/*****************************************************************************
*  Hypay payment processor for CS-Cart 4.18.4
*  Author: Michael Shapar (micshap100@gmail.com)
*  Date: 2025-10-20
*
*  Description:
*  This processor integrates Hypay as a payment gateway. It creates a payment
*  request using Hypay API and redirects the customer to the payment link.
*  Allows to create EzCount documents using direct or integrated API.
*
*  Docs:
*         https://hypay.docs.apiary.io/
*         https://documenter.getpostman.com/view/16363118/TzkyNLWB
*         https://developers.hyp.co.il/pay/advanced-features/two-phase-commits (J5)
*
*  Shared helpers (logging, HTTP, EzCount, J5 capture/void) live in func.php.
*
*  All rights reserved.
*****************************************************************************/

use Tygh\Http;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/* ============================================================================
 * RETURN (payment_notification)
 * ==========================================================================*/
if (defined('PAYMENT_NOTIFICATION')) {
    $mode     = $_REQUEST['mode'] ?? '';
    $order_id = (int) ($_REQUEST['Order'] ?? $_REQUEST['order_id'] ?? 0);

    if (!hypay_allow_for_order($order_id)) {
        hypay_log($order_id, 'payment_notification not allowed (script/payment mismatch)');
        return;
    }

    $order_info     = fn_get_order_info($order_id);
    $payment_id     = $order_info['payment_id'];
    $processor_data = fn_get_payment_method_data($payment_id);
    $pp             = $processor_data['processor_params'] ?? [];
    $GLOBALS['HYPAY_DEBUG'] = (!empty($pp['debug_mode']) && $pp['debug_mode'] === 'Y');

    hypay_log($order_id, 'payment_notification enter', ['mode' => $mode, 'REQUEST' => $_REQUEST]);

    // what came back: CCode=0 charged, CCode=700 J5 authorization (funds held)
    $ccode       = isset($_REQUEST['CCode']) ? (string) $_REQUEST['CCode'] : '';
    $is_success  = ($ccode === '0');
    $is_j5_auth  = ($ccode === HYPAY_CCODE_J5_AUTHORIZED);

    // what we asked for when the payment link was built
    $marker      = hypay_get_marker_data($order_id);
    $expected_j5 = (!empty($marker['hypay_j5']) && $marker['hypay_j5'] === 'Y');

    hypay_log($order_id, 'payment result', [
        'CCode'       => $ccode,
        'is_success'  => $is_success,
        'is_j5_auth'  => $is_j5_auth,
        'expected_j5' => $expected_j5,
    ]);

    if ($expected_j5 && $is_success) {
        // a J5 request that comes back charged means the terminal ignored J5
        hypay_log($order_id, 'WARNING: J5 was requested but the transaction was charged (CCode=0)');
    }

    // status mapping
    $success_status = !empty($pp['success_status']) ? $pp['success_status'] : 'O';
    $fail_status    = !empty($pp['fail_status'])    ? $pp['fail_status']    : 'D';
    $j5_auth_status = !empty($pp['j5_auth_status']) ? $pp['j5_auth_status'] : 'O';

    // brand mapping (Hypay codes → human names)
    $brand_name = hypay_brand_name($_REQUEST['Brand'] ?? '');

    // identifiers from the redirect, under any spelling Hyp may use for them
    $hyp_return_id    = hypay_request_value(['Id', 'TransId']);
    $hyp_return_acode = hypay_request_value(['ACode', 'AuthNum']);
    $hyp_return_uid   = hypay_request_value(['UID', 'Uid', 'cgUid', 'CGUID', 'uniqueId']);
    $hyp_return_user  = hypay_request_value(['UserId', 'personalId', 'Id_Number']);

    // instalment schedule: nFirstPayment is the first payment, firstPayment the
    // periodical one. Absent on a single-payment deal.
    $hyp_first_payment      = (float) hypay_request_value(['nFirstPayment']);
    $hyp_periodical_payment = (float) hypay_request_value(['firstPayment']);

    // Hyp's own note about the transaction. It rides back in whatever encoding
    // the route used - windows-1255 on an Apple Pay / Google Pay charge - so it
    // is made valid UTF-8 before it is allowed anywhere near the order page.
    $hyp_err_msg = hypay_utf8_text(hypay_request_value(['errMsg', 'ErrMsg', 'errmsg']));

    // sanitize personal ID (display value)
    $clean_user_id = hypay_clean_personal_id($hyp_return_user);

    // ...and the raw one, which is what the capture request must send back
    $raw_user_id = preg_replace('/\D+/', '', ltrim($hyp_return_user, 'L'));
    if ($raw_user_id === '') { $raw_user_id = '000000000'; }

    $last4        = isset($_REQUEST['L4digit']) ? preg_replace('/\D+/', '', (string) $_REQUEST['L4digit']) : '';
    $num_payments = max(1, (int) ($_REQUEST['Payments'] ?? 1));

    /* ----------------------------------------------------------------------
     * J5: funds are held, nothing is charged yet
     * --------------------------------------------------------------------*/
    if ($is_j5_auth) {
        $authorized = isset($_REQUEST['Amount']) && is_numeric($_REQUEST['Amount'])
            ? round((float) $_REQUEST['Amount'], 2)
            : round((float) $order_info['total'], 2);

        $hold_days  = fn_hypay_hold_days($pp);
        $hyp_id     = $hyp_return_id;

        if ($hyp_return_uid === '') {
            // the capture cannot be built without it, so make the gap loud now
            // instead of at capture time. The usual cause is a truncated return
            // URL: Hyp echoes Info/street/city/ClientName back unencoded, and a
            // "#" in any of them cuts the query string short - UID, Hesh, errMsg
            // and the signature all sit after Info in Hyp's parameter order. The
            // raw query string below shows where it stopped.
            hypay_log($order_id, 'WARNING: no UID in the J5 return', [
                'keys'         => array_keys($_REQUEST),
                'query_string' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
                'Info'         => (string) ($_REQUEST['Info'] ?? ''),
            ]);
            fn_set_notification('W', __('warning'), __('hypay_j5_warning_no_uid'));
        }

        // the customer may land on this URL more than once (refresh, back button):
        // one authorization must produce exactly one row
        $already_stored = $hyp_id !== '' && db_get_field(
            "SELECT transaction_id FROM ?:hypay_transactions WHERE order_id = ?i AND hyp_id = ?s",
            $order_id,
            $hyp_id
        );

        if (!$already_stored) {
            fn_hypay_store_authorization($order_id, [
                // the values resolved above, not $_REQUEST directly: Hyp does
                // not always spell these the way the docs do
                'hyp_id'            => $hyp_return_id,
                'acode'             => $hyp_return_acode,
                'uid'               => $hyp_return_uid,
                'personal_id'       => $raw_user_id,
                // the same spelling the authorization was made with, both halves
                'client_name'       => hypay_sanitize_url_echo($order_info['firstname'] ?? ''),
                'client_lname'      => hypay_sanitize_url_echo($order_info['lastname']  ?? ''),
                'brand'             => $brand_name,
                'last4'             => $last4,
                'payments'          => $num_payments,
                'coin'              => (int) ($pp['coin'] ?? 1),
                'amount_authorized' => $authorized,
            'first_payment'      => round($hyp_first_payment, 2),
            'periodical_payment' => round($hyp_periodical_payment, 2),
                'authorized_at'     => TIME,
                'expires_at'        => TIME + $hold_days * 86400,
            ]);

            $tx = fn_hypay_get_transaction($order_id);

            // step 2 of the two-phase commit: grab the card token right away, so the
            // capture in the admin panel has everything it needs
            $token_error = '';
            $token_data  = fn_hypay_fetch_card_token($order_id, $pp, $hyp_id, $token_error);
            if ($token_data !== false) {
                fn_hypay_update_transaction($tx['transaction_id'], [
                    'card_token' => $token_data['token'],
                    'card_tokef' => $token_data['tokef'],
                ]);
            } else {
                fn_hypay_update_transaction($tx['transaction_id'], ['last_error' => 'getToken: ' . $token_error]);
                hypay_log($order_id, 'j5 authorization stored WITHOUT card token', $token_error);
            }
        } else {
            hypay_log($order_id, 'j5 authorization already stored, skipping', ['hyp_id' => $hyp_id]);
        }

        // a replayed return (refresh) must never drag a captured or cancelled
        // order back to the "funds held" state
        $tx_now = fn_hypay_get_transaction($order_id);
        if (!empty($tx_now) && $tx_now['status'] !== 'authorized') {
            hypay_log($order_id, 'J5 return replayed after ' . $tx_now['status'] . ', order left untouched');
            $pp_response = [];
        } else {
            $pp_response = [
                'transaction_id' => $hyp_id,
                'reason_text'    => '🟡 ' . __('hypay_j5_pi_authorized', ['[amount]' => number_format($authorized, 2, '.', '')]),
                'hypay_j5'       => __('hypay_j5_pi_hold_until', [
                    '[amount]' => number_format($authorized, 2, '.', ''),
                    '[date]'   => date('d.m.Y', TIME + $hold_days * 86400),
                ]),
                'brand'          => $brand_name,
                'card_number'    => $last4,
                'payments'       => $num_payments,
                'personal_id'    => $clean_user_id,
                'order_status'   => $j5_auth_status,
            ];
            $pp_response = fn_hypay_clean_payment_info($pp_response);
            hypay_log($order_id, 'fn_finish_payment payload (J5)', $pp_response);
            fn_finish_payment($order_id, $pp_response);

            // inside this branch on purpose: a replayed return takes the other
            // one and leaves the order alone, additional status included
            if (!empty($pp['j5_auth_additional_status'])) {
                fn_hypay_set_additional_status($order_id, $pp['j5_auth_additional_status']);
            }

            // no document at this point: it is issued when the money is captured
            hypay_log($order_id, 'ezcount skipped (J5 authorization, not charged yet)');
        }
    } else {
        /* ------------------------------------------------------------------
         * Regular (J4) payment: charged right away
         * ----------------------------------------------------------------*/
        // A charge that went through says everything it has to say in one word.
        // Hyp's note adds nothing to it - "אושרה (0)", the gateway's own way of
        // repeating the CCode=0 already read above - and it is the single part
        // of this line written in the terminal's encoding rather than ours. The
        // whole return, that note included, is in the debug log; the order page
        // gets the verdict.
        $reason_text = $is_success ? '🟢 Success' : '🔴 Failure';

        if (!$is_success) {
            // a failure is worth explaining, and the explanation is mostly ours:
            // the code and what this add-on knows it to mean. The gateway's own
            // words are added when they survived the trip, and left out when
            // they came back as replacement characters.
            $reason_text .= ' — ' . fn_hypay_format_error(
                $ccode,
                hypay_text_is_lost($hyp_err_msg) ? '' : $hyp_err_msg
            );
        }

        $pp_response = [
            'transaction_id' => $_REQUEST['Id']       ?? $_REQUEST['ACode'] ?? '',
            'reason_text'    => $reason_text,
            'brand'          => $brand_name,
            'card_number'    => $last4,
            'payments'       => $_REQUEST['Payments'] ?? '',
            'personal_id'    => $clean_user_id,
            'order_status'   => $is_success ? $success_status : $fail_status,
        ];
        $pp_response = fn_hypay_clean_payment_info($pp_response);
        hypay_log($order_id, 'fn_finish_payment payload', $pp_response);
        fn_finish_payment($order_id, $pp_response);
        hypay_log($order_id, 'fn_finish_payment done');

        // EzCount: direct API (optional, based on settings)
        if ($is_success) {
            $ez_mode = $pp['ez_mode'] ?? 'none'; // none | integrated | direct
            if ($ez_mode !== 'direct') {
                hypay_log($order_id, 'ezcount skipped (mode != direct)', ['ez_mode' => $ez_mode]);
            } else {
                fn_hypay_create_ezcount_doc($order_id, $order_info, $pp, [
                    'transaction_id' => (string) ($_REQUEST['Id'] ?? ''),
                    'brand'          => $brand_name,
                    'last4'          => $last4,
                    'payments'       => $num_payments,
                    'amount'         => round((float) $order_info['total'], 2),
                    'flow'           => 'regular',
                ]);
            }
        }
    }

    // where to bounce back (admin or storefront)?
    $back = hypay_get_back_marker($order_id);
    hypay_clear_back_marker($order_id);

    if ($back === 'admin') {
        $admin_index = Registry::get('config.admin_index'); // e.g. admin.php
        $url = "{$admin_index}?dispatch=orders.details&order_id={$order_id}";
        hypay_log($order_id, 'redirect admin', $url);
        hypay_clean_redirect($url);
    } else {
        $url = fn_url("index.php?dispatch=checkout.complete&order_id={$order_id}", 'C', 'current');
        hypay_log($order_id, 'redirect customer', $url);
        hypay_clean_redirect($url);
    }
    return;
}

/* ============================================================================
 * INIT (SIGN) — generate payment link
 * ==========================================================================*/

// boot settings + debug
$pp = $processor_data['processor_params'] ?? [];
$GLOBALS['HYPAY_DEBUG'] = (!empty($pp['debug_mode']) && $pp['debug_mode'] === 'Y');

$order_id = (int) ($order_info['order_id'] ?? 0);
if (!$order_id) { return; }

$masof  = trim((string) ($pp['masof']   ?? ''));
$apiKey = trim((string) ($pp['api_key'] ?? ''));
$passP  = trim((string) ($pp['passp']   ?? ''));

// J5 (two-phase commit) or a regular charge for this customer?
$is_j5 = fn_hypay_is_j5_order($order_info, $pp);
hypay_log($order_id, 'payment type resolved', [
    'payment_type' => $pp['payment_type'] ?? ('legacy:' . ($pp['j5'] ?? 'N')),
    'usergroups'   => fn_hypay_get_order_usergroups($order_info),
    'is_j5'        => $is_j5,
]);

// tag the source (admin|front) + the J5 intent, to handle the return correctly
$back = (defined('AREA') && AREA === 'A') ? 'admin' : 'front';
hypay_set_back_marker($order_id, $back, $is_j5);

$base = HYPAY_API_URL . '?';

// RU storefronts show ENG Hypay page
$lang2     = hypay_lang2_from_order($order_info);
$force_en  = ($lang2 === 'ru');

// === build heshDesc that exactly matches order total ===
list($heshDesc, $sum_items) = hypay_build_heshdesc($order_info);

// Info template. Hyp echoes this value straight back into the redirect URL
// without url-encoding it, so it goes through the sanitizer first - see
// hypay_sanitize_url_echo().
$info_val = hypay_build_info($order_id, $pp);

// page language
$page_lang_setting = $pp['page_lang'] ?? 'auto'; // auto|ENG|HEB
if ($page_lang_setting === 'ENG' || $page_lang_setting === 'HEB') {
    $page_lang = $page_lang_setting;
} else {
    $page_lang = $force_en ? 'ENG' : ($lang2 === 'he' ? 'HEB' : 'ENG');
}

// assemble SIGN params
$params_sign = [
    'action'      => 'APISign',
    'What'        => 'SIGN',
    'Masof'       => $masof,
    'KEY'         => $apiKey,
    'PassP'       => $passP,
    'Order'       => $order_id,
    'Info'        => $info_val,
    'Amount'      => round((float) $order_info['total'], 2),
    'UTF8'        => hypay_bool($pp['utf8']    ?? 'Y'),
    'UTF8out'     => hypay_bool($pp['utf8out'] ?? 'Y'),
    'Sign'        => hypay_bool($pp['sign']    ?? 'N'),
    'PageLang'    => $page_lang,

    // customer meta. Hyp echoes ClientName/ClientLName (as Fild1), street and
    // city back in the redirect URL unencoded, so they are sanitized too: an
    // address like "Herzl 5 #12" would truncate the return exactly like Info.
    'ClientName'  => hypay_sanitize_url_echo($order_info['firstname'] ?? ''),
    'ClientLName' => hypay_sanitize_url_echo($order_info['lastname']  ?? ''),
    'email'       => (string) ($order_info['email']     ?? ''),
    'phone'       => (string) ($order_info['phone']     ?? ''),
    'cell'        => (string) ($order_info['phone']     ?? $order_info['s_phone'] ?? ''),
    'street'      => hypay_sanitize_url_echo($order_info['s_address'] ?? $order_info['b_address'] ?? ''),
    'city'        => hypay_sanitize_url_echo($order_info['s_city']    ?? $order_info['b_city']    ?? ''),

    // toggles / UX sprinkles
    'MoreData'    => hypay_bool($pp['moredata'] ?? 'Y'),
    'Pritim'      => hypay_bool($pp['pritim']   ?? 'Y'),
    'SendHesh'    => hypay_bool($pp['sendhesh'] ?? 'N'),
    'FixTash'     => hypay_bool($pp['fixtash']  ?? 'N'),
    'pageTimeOut' => hypay_bool($pp['pagetimeout'] ?? 'Y'),
    'blockItemValidation' => hypay_bool($pp['block_item_validation'] ?? 'N'),
    'Coin'        => (int) ($pp['coin'] ?? 1), // 1=ILS

    'ShowEngTashText' => hypay_bool($pp['show_eng_tash_text'] ?? 'N'),
    'hideBtns'        => hypay_bool($pp['hide_btns'] ?? 'N'),

    'tmp'         => (int) ($pp['tmp'] ?? 4),

    'heshDesc'    => $heshDesc,
];

// optional numerics / booleans
hypay_put($params_sign, 'Tash',             isset($pp['tash'])       && $pp['tash'] !== ''       ? (int)   $pp['tash']       : null);
hypay_put($params_sign, 'tashType',         isset($pp['tashtype'])   && $pp['tashtype'] !== ''   ? (int)   $pp['tashtype']   : null);
hypay_put($params_sign, 'TashFirstPayment', isset($pp['tash_first']) && $pp['tash_first'] !== '' ? (float) $pp['tash_first'] : null);
hypay_put($params_sign, 'sendemail',        hypay_bool($pp['sendemail'] ?? 'N'));
hypay_put($params_sign, 'Postpone',         hypay_bool($pp['postpone']  ?? 'N'));
if ($is_j5) {
    // Step 1 of the two-phase commit: hold the funds instead of charging them.
    // MoreData=True is mandatory here — without it Hypay does not return UID and
    // UserId in the redirect, and both are required to capture the money later.
    $params_sign['J5']       = 'True';
    $params_sign['MoreData'] = 'True';

    // no document may be issued while the money is only held
    $params_sign['SendHesh'] = 'False';

    // Tash / tashType / FixTash stay exactly as configured: the customer picks
    // the number of payments during the authorization, and the capture repeats
    // that number. Dropping them here made the payment page fall back to the
    // terminal maximum instead of the configured limit.

    // hideBtns can be set separately for a hold: an authorization page may want
    // different buttons than a charge. Empty means "leave it as configured for
    // a regular charge", which is what an existing installation expects.
    $j5_hide_btns = (string) ($pp['j5_hide_btns'] ?? '');
    if ($j5_hide_btns !== '') {
        $params_sign['hideBtns'] = hypay_bool($j5_hide_btns);
    }
} else {
    $params_sign['J5'] = 'False';
}

// masked request log
$log_params = $params_sign;
if (!empty($log_params['KEY']))   { $log_params['KEY']   = substr($log_params['KEY'], 0, 4) . '***'; }
if (!empty($log_params['PassP'])) { $log_params['PassP'] = substr($log_params['PassP'], 0, 2) . '***'; }
hypay_log($order_id, 'SIGN request params', $log_params);

// call SIGN
$sign_url = $base . http_build_query($params_sign);
$response = Http::get($sign_url, ['timeout' => 30]);
hypay_log($order_id, 'SIGN response raw', $response);

if (!$response || strpos($response, 'signature=') === false) {
    hypay_log($order_id, 'SIGN failed');
    $pp_response = [
        'order_status' => $pp['fail_status'] ?? 'D',
        'reason_text'  => '🔴 Hypay SIGN failed',
    ];
    fn_finish_payment($order_id, $pp_response);
    fn_order_placement_routines('checkout_redirect', $order_id);
    return;
}

// ready: off you go
$payment_link = $base . $response;
hypay_log($order_id, 'redirect to payment', $payment_link);
fn_create_payment_form($payment_link, [], 'Hypay', true, 'get');
return;
