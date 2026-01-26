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
*
*  All rights reserved.
*****************************************************************************/

use Tygh\Http;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/** global debug switch (filled from payment settings later) */
$GLOBALS['HYPAY_DEBUG'] = false;

/** canonical log file path (also referenced in settings hint) */
if (!function_exists('hypay_log_path')) {
    function hypay_log_path() {
        return rtrim(Registry::get('config.dir.var'), '/\\') . '/log/hypay_ezcount.log';
    }
}

/* ============================================================================
 * Debug / logging helpers
 * ==========================================================================*/

/** dumb, safe logger: single signature, token-safe, opt-in via $HYPAY_DEBUG */
if (!function_exists('hypay_log')) {
    function hypay_log($order_id, $label, $data = null) {
        if (empty($GLOBALS['HYPAY_DEBUG'])) { return; }
        try {
            $dir = Registry::get('config.dir.var') . 'log/';
            if (!is_dir($dir)) {
                if (function_exists('fn_mkdir')) { @fn_mkdir($dir); } else { @mkdir($dir, 0755, true); }
            }
            $file = hypay_log_path();
            $line = '[' . date('Y-m-d H:i:s') . "] order {$order_id} | {$label}";
            if ($data !== null) {
                if (!is_string($data)) {
                    $data = @json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($data === false) { $data = '[unencodable payload]'; }
                }
                $line .= ' | ' . $data;
            }
            $line .= PHP_EOL;
            @file_put_contents($file, $line, FILE_APPEND);
        } catch (\Throwable $e) {
            @error_log('[hypay_ezcount] ' . $e->getMessage());
        }
    }
}

/** one-shot cURL JSON POST with headers/response capture (fallback to Tygh\Http) */
if (!function_exists('hypay_curl_json')) {
    function hypay_curl_json($order_id, $url, array $payload, array $extra_headers = []) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = array_merge([
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ], $extra_headers);

        $code = 0; $errno = 0; $err = ''; $resp_headers = ''; $resp_body = '';

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => 45,
            ]);

            $raw         = curl_exec($ch);
            $errno       = curl_errno($ch);
            $err         = curl_error($ch);
            $code        = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            $resp_headers = substr((string) $raw, 0, $header_size);
            $resp_body    = substr((string) $raw, $header_size);
        } else {
            // backup: Tygh\Http (no response headers, but good enough)
            $opts = ['timeout' => 45, 'headers' => []];
            foreach ($headers as $h) {
                if (stripos($h, 'content-type:') === 0)      { $opts['headers']['Content-Type']  = trim(substr($h, 13)); }
                elseif (stripos($h, 'accept:') === 0)        { $opts['headers']['Accept']        = trim(substr($h, 7)); }
                elseif (stripos($h, 'authorization:') === 0) { $opts['headers']['Authorization'] = trim(substr($h, 14)); }
            }
            $resp_body = Http::post($url, $json, $opts);
        }

        hypay_log($order_id, '[cURL] POST',        ['url' => $url, 'code' => $code, 'errno' => $errno, 'err' => $err]);
        hypay_log($order_id, '[cURL] req.headers', $headers);
        hypay_log($order_id, '[cURL] req.body',    $json);
        if ($resp_headers !== '') { hypay_log($order_id, '[cURL] resp.headers', $resp_headers); }
        hypay_log($order_id, '[cURL] resp.body',   $resp_body);

        $obj = json_decode($resp_body);
        return [
            'http_code' => $code,
            'errno'     => $errno,
            'error'     => $err,
            'body'      => $resp_body,
            'json'      => is_object($obj) ? $obj : (object) [],
        ];
    }
}

/* ============================================================================
 * Small helpers (tiny but mighty)
 * ==========================================================================*/

function hypay_allow_for_order($order_id) {
    $script_ok  = fn_check_payment_script('hypay.php', $order_id);
    $payment_ok = (isset($_REQUEST['payment']) && $_REQUEST['payment'] === 'hypay');
    return ($order_id && ($script_ok || $payment_ok));
}

/** store/load a tiny "back marker" (admin|front) in order_data (type 'H') */
function hypay_set_back_marker($order_id, $value) {
    $data = ['hypay_back' => $value];
    db_query("REPLACE INTO ?:order_data (order_id, type, data) VALUES (?i, 'H', ?s)", $order_id, serialize($data));
}
function hypay_get_back_marker($order_id) {
    $row = db_get_row("SELECT data FROM ?:order_data WHERE order_id = ?i AND type = 'H'", $order_id);
    if (!empty($row['data'])) {
        $data = @unserialize($row['data']);
        if (is_array($data) && !empty($data['hypay_back'])) {
            return (string) $data['hypay_back'];
        }
    }
    return '';
}
function hypay_clear_back_marker($order_id) {
    db_query("DELETE FROM ?:order_data WHERE order_id = ?i AND type = 'H'", $order_id);
}

/** push a clean redirect (JS replace + meta refresh + noscript link) */
function hypay_clean_redirect($url) {
    $url_js   = str_replace(['\\', '"'], ['\\\\', '\"'], $url);
    $url_html = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

    echo '<!doctype html><html><head>';
    echo '<meta charset="utf-8">';
    echo '<meta http-equiv="refresh" content="0;url=' . $url_html . '">';
    echo '</head><body>';
    echo '<script>try{window.location.replace("' . $url_js . '");}catch(e){window.location.href="' . $url_js . '";}</script>';
    echo '<noscript><a href="' . $url_html . '">Continue</a></noscript>';
    echo '</body></html>';
    exit;
}

/** checkbox → "True"/"False" strings per Hypay API taste */
function hypay_bool($v) {
    return (!empty($v) && $v !== 'N' && $v !== '0') ? 'True' : 'False';
}

/** put non-empty scalar into assoc array */
function hypay_put(&$arr, $key, $val) {
    if ($val === '' || $val === null) { return; }
    $arr[$key] = $val;
}

/** language helper */
function hypay_lang2_from_order($order_info) {
    $lang_code = strtolower((string) ($order_info['lang_code'] ?? (defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en')));
    return substr($lang_code, 0, 2);
}

/** sanitize name for heshDesc */
function hypay_sanitize_name($s) {
    return str_replace(['[',']','~'], '', (string) $s);
}

/** Build heshDesc so sum(positions) == order_total including discounts/surcharges/rounding */
function hypay_build_heshdesc($order_info) {
    $lang2    = hypay_lang2_from_order($order_info);
    $force_en = ($lang2 === 'ru');

    $heshDesc  = '';
    $sum_items = 0.0;

    // 1) products (use per-line subtotal / qty to embed item-level discounts)
    if (!empty($order_info['products'])) {
        foreach ($order_info['products'] as $p) {
            $qty = max(1, (int) ($p['amount'] ?? 1));

            $name = (string) ($p['product'] ?? 'Item');
            if ($force_en && !empty($p['product_id'])) {
                $name_en = fn_get_product_name((int) $p['product_id'], 'EN');
                if ($name_en === '' || $name_en === null) { $name_en = fn_get_product_name((int) $p['product_id'], 'en'); }
                if ($name_en !== '' && $name_en !== null) { $name = $name_en; }
            }
            $name = hypay_sanitize_name($name);

            $subtotal = (float) ($p['subtotal'] ?? ($p['price'] ?? 0) * $qty);
            $unit     = $qty > 0 ? round($subtotal / $qty, 2) : round((float) ($p['price'] ?? 0), 2);

            $heshDesc  .= "[0~{$name}~{$qty}~{$unit}]";
            $sum_items += round($unit * $qty, 2);
        }
    }

    // 2) shipping (net of shipping_discount)
    $shipping_cost     = round((float) ($order_info['shipping_cost'] ?? 0), 2);
    $shipping_discount = round((float) ($order_info['shipping_discount'] ?? 0), 2);
    $shipping_net      = round($shipping_cost - $shipping_discount, 2);
    if ($shipping_net != 0.0) {
        $ship_word = ($lang2 === 'he') ? 'משלוח' : 'Shipping';
        $ship_name = hypay_sanitize_name($ship_word);
        $heshDesc  .= "[0~{$ship_name}~1~" . number_format($shipping_net, 2, '.', '') . "]";
        $sum_items += $shipping_net;
    }
    if ($shipping_discount > 0) {
        $label = hypay_sanitize_name(($lang2 === 'he') ? 'הנחת משלוח' : 'Shipping discount');
        $heshDesc  .= "[0~{$label}~1~-" . number_format($shipping_discount, 2, '.', '') . "]";
        $sum_items -= $shipping_discount;
    }

    // 3) payment surcharge
    $payment_surcharge = round((float) ($order_info['payment_surcharge'] ?? 0), 2);
    if ($payment_surcharge != 0.0) {
        $label = hypay_sanitize_name(($lang2 === 'he') ? 'עמלת תשלום' : 'Payment surcharge');
        $heshDesc  .= "[0~{$label}~1~" . number_format($payment_surcharge, 2, '.', '') . "]";
        $sum_items += $payment_surcharge;
    }

    // 4) order-level discount (subtotal_discount) with coupon codes (if any)
    $subtotal_discount = round((float) ($order_info['subtotal_discount'] ?? 0), 2);
    if ($subtotal_discount > 0.0) {
        $codes = [];
        if (!empty($order_info['coupons']) && is_array($order_info['coupons'])) {
            foreach ($order_info['coupons'] as $c) {
                if (!empty($c['coupon'])) { $codes[] = $c['coupon']; }
            }
        }
        $suffix = $codes ? ' (' . implode(',', $codes) . ')' : '';
        $label  = hypay_sanitize_name(($lang2 === 'he') ? ('הנחה' . $suffix) : ('Discount' . $suffix));

        $heshDesc  .= "[0~{$label}~1~-" . number_format($subtotal_discount, 2, '.', '') . "]";
        $sum_items -= $subtotal_discount;
    }

    // 4.1) gift certificates applied (redeem)
    if (!empty($order_info['use_gift_certificates']) && is_array($order_info['use_gift_certificates'])) {
        foreach ($order_info['use_gift_certificates'] as $code => $gc) {
            $amt = round((float)($gc['amount'] ?? $gc['cost'] ?? 0), 2);
            if ($amt > 0) {
                $label = hypay_sanitize_name(($lang2 === 'he') ? ('שובר מתנה ' . $code) : ('Gift certificate ' . $code));
                $heshDesc  .= "[0~{$label}~1~-" . number_format($amt, 2, '.', '') . "]";
                $sum_items -= $amt;
            }
        }
    }


    // 5) rounding adjustment to match order total exactly
    $order_total = round((float) $order_info['total'], 2);
    $delta       = round($order_total - $sum_items, 2);
    if ($delta != 0.0) {
        $label = hypay_sanitize_name(($lang2 === 'he') ? 'עיגול סכום' : 'Rounding adjustment');
        $heshDesc  .= "[0~{$label}~1~" . number_format($delta, 2, '.', '') . "]";
        $sum_items = round($sum_items + $delta, 2);
    }

    return [$heshDesc, $sum_items];
}

/** Build EzCount items so sum == order_total including discounts/surcharges/rounding */
function hypay_build_ez_items($order_info) {
    $lang2    = hypay_lang2_from_order($order_info);
    $force_en = ($lang2 === 'ru');

    $items    = [];
    $sum_items = 0.0;

    // products
    if (!empty($order_info['products'])) {
        foreach ($order_info['products'] as $p) {
            $qty = max(1, (int) ($p['amount'] ?? 1));

            $name = (string) ($p['product'] ?? 'Item');
            if ($force_en && !empty($p['product_id'])) {
                $name_en = fn_get_product_name((int) $p['product_id'], 'EN');
                if ($name_en === '' || $name_en === null) { $name_en = fn_get_product_name((int) $p['product_id'], 'en'); }
                if ($name_en !== '' && $name_en !== null) { $name = $name_en; }
            }

            $subtotal = (float) ($p['subtotal'] ?? ($p['price'] ?? 0) * $qty);
            $unit     = $qty > 0 ? round($subtotal / $qty, 2) : round((float) ($p['price'] ?? 0), 2);

            $items[] = [
                'details'  => $name,
                'price'    => $unit,
                'amount'   => $qty,
                'vat_type' => 'INC',
            ];
            $sum_items += round($unit * $qty, 2);
        }
    }

    // shipping (net)
    $shipping_cost     = round((float) ($order_info['shipping_cost'] ?? 0), 2);
    $shipping_discount = round((float) ($order_info['shipping_discount'] ?? 0), 2);
    $shipping_net      = round($shipping_cost - $shipping_discount, 2);
    if ($shipping_net != 0.0) {
        $ship_word = ($lang2 === 'he') ? 'משלוח' : 'Shipping';
        $items[] = [
            'details'  => $ship_word,
            'price'    => $shipping_net,
            'amount'   => 1,
            'vat_type' => 'INC',
        ];
        $sum_items += $shipping_net;
    }
    if ($shipping_discount > 0) {
        $items[] = [
            'details'  => ($lang2 === 'he') ? 'הנחת משלוח' : 'Shipping discount',
            'price'    => -$shipping_discount,
            'amount'   => 1,
            'vat_type' => 'INC',
        ];
        $sum_items -= $shipping_discount;
    }

    // payment surcharge
    $payment_surcharge = round((float) ($order_info['payment_surcharge'] ?? 0), 2);
    if ($payment_surcharge != 0.0) {
        $items[] = [
            'details'  => ($lang2 === 'he') ? 'עמלת תשלום' : 'Payment surcharge',
            'price'    => $payment_surcharge,
            'amount'   => 1,
            'vat_type' => 'INC',
        ];
        $sum_items += $payment_surcharge;
    }

    // subtotal_discount (with coupons)
    $subtotal_discount = round((float) ($order_info['subtotal_discount'] ?? 0), 2);
    if ($subtotal_discount > 0.0) {
        $codes = [];
        if (!empty($order_info['coupons']) && is_array($order_info['coupons'])) {
            foreach ($order_info['coupons'] as $c) {
                if (!empty($c['coupon'])) { $codes[] = $c['coupon']; }
            }
        }
        $suffix = $codes ? ' (' . implode(',', $codes) . ')' : '';

        $items[] = [
            'details'  => ($lang2 === 'he') ? ('הנחה' . $suffix) : ('Discount' . $suffix),
            'price'    => -$subtotal_discount,
            'amount'   => 1,
            'vat_type' => 'INC',
        ];
        $sum_items -= $subtotal_discount;
    }

    // gift certificates applied (redeem)
    if (!empty($order_info['use_gift_certificates']) && is_array($order_info['use_gift_certificates'])) {
        foreach ($order_info['use_gift_certificates'] as $code => $gc) {
            $amt = round((float)($gc['amount'] ?? $gc['cost'] ?? 0), 2);
            if ($amt > 0) {
                $items[] = [
                    'details'  => ($lang2 === 'he') ? ('שובר מתנה ' . $code) : ('Gift certificate ' . $code),
                    'price'    => -$amt,
                    'amount'   => 1,
                    'vat_type' => 'INC',
                ];
                $sum_items -= $amt;
            }
        }
    }

    // rounding adjustment
    $order_total = round((float) $order_info['total'], 2);
    $delta       = round($order_total - $sum_items, 2);
    if ($delta != 0.0) {
        $items[] = [
            'details'  => ($lang2 === 'he') ? 'עיגול סכום' : 'Rounding adjustment',
            'price'    => $delta,
            'amount'   => 1,
            'vat_type' => 'INC',
        ];
        $sum_items = round($sum_items + $delta, 2);
    }

    return [$items, $sum_items];
}

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

    // success flag (Hypay: CCode=0)
    $is_success = (isset($_REQUEST['CCode']) && (string) $_REQUEST['CCode'] === '0');
    hypay_log($order_id, 'payment result', ['CCode' => ($_REQUEST['CCode'] ?? null), 'is_success' => $is_success]);

    // status mapping
    $success_status = !empty($pp['success_status']) ? $pp['success_status'] : 'O';
    $fail_status    = !empty($pp['fail_status'])    ? $pp['fail_status']    : 'D';

    // brand mapping (Hypay codes → human names)
    $brand_code = isset($_REQUEST['Brand']) ? (string) $_REQUEST['Brand'] : '';
    $brand_map  = [
        '0' => 'PL',
        '1' => 'MasterCard',
        '2' => 'Visa',
        '3' => 'Diners',
        '4' => 'Amex',
        '5' => 'Isracard',
    ];
    $brand_name = $brand_map[$brand_code] ?? $brand_code;

    // sanitize personal ID
    $raw_user_id   = (string) ($_REQUEST['UserId'] ?? '');
    $clean_user_id = preg_replace('/\D+/', '', ltrim($raw_user_id, "L"));
    if ($clean_user_id === '4258784304') {
        $clean_user_id = '000000000';
    }

    // reason text
    $reason_text = $is_success ? '🟢 Success' : '🔴 Failure';
    if (!empty($_REQUEST['errMsg'])) {
        $reason_text .= ' — ' . (string) $_REQUEST['errMsg'];
    }

    // finalize payment in CS-Cart
    $pp_response = [
        'transaction_id' => $_REQUEST['Id']       ?? $_REQUEST['ACode'] ?? '',
        'reason_text'    => $reason_text,
        'brand'          => $brand_name,
        'card_number'    => $_REQUEST['L4digit']  ?? '',
        'payments'       => $_REQUEST['Payments'] ?? '',
        'personal_id'    => $clean_user_id,
        'order_status'   => $is_success ? $success_status : $fail_status,
    ];
    hypay_log($order_id, 'fn_finish_payment payload', $pp_response);
    fn_finish_payment($order_id, $pp_response);
    hypay_log($order_id, 'fn_finish_payment done');

    /* ----------------------------------------------------------------------
     * EzCount: direct API (optional, based on settings)
     * --------------------------------------------------------------------*/
    if ($is_success) {
        $ez_mode = $pp['ez_mode'] ?? 'none'; // none | integrated | direct
        if ($ez_mode !== 'direct') {
            hypay_log($order_id, 'ezcount skipped (mode != direct)', ['ez_mode' => $ez_mode]);
        } else {
            // 1) EzCount params (direct)
            $ez_env                 = $pp['ez_environment'] ?? 'demo'; // demo|live
            $ez_api_key             = trim((string) ($pp['ez_api_key'] ?? ''));
            $ez_developer_mail      = trim((string) ($pp['ez_developer_email'] ?? ''));
            $ez_ua_uuid             = trim((string) ($pp['ez_ua_uuid'] ?? ''));
            $created_by_api_key     = trim((string) ($pp['ez_created_by_api_key'] ?? '')); // optional, not hashed
            $doc_type_param         = (int) ($pp['ez_doc_type'] ?? 320);                    // 320/400
            $doc_type               = in_array($doc_type_param, [320, 400], true) ? $doc_type_param : 320;
            $show_inc_vat           = isset($pp['ez_show_items_including_vat']) ? (int) (!empty($pp['ez_show_items_including_vat'])) : 1;
            $doc_lang               = ($pp['ez_doc_lang'] ?? 'he') === 'en' ? 'en' : 'he';
            $auto_calc              = isset($pp['ez_auto_calc_payments']) ? (int) (!empty($pp['ez_auto_calc_payments'])) : 0;

            // 2) line items (vat_type=INC), using unified builder so totals match
            list($items, $items_sum) = hypay_build_ez_items($order_info);

            // 3) customer address (optionally appending building number from custom field)
            $lang2 = hypay_lang2_from_order($order_info);
            $building_id  = (int) ($pp['building_field_id'] ?? 0);
            $building     = '';
            if ($building_id > 0) {
                $building = trim((string) ($order_info['fields'][$building_id] ?? ''));
                if ($building === '' && !empty($order_info['user_id'])) {
                    $uinfo    = fn_get_user_info($order_info['user_id']);
                    $building = trim((string) ($uinfo['fields'][$building_id] ?? ''));
                }
            }
            $street = trim((string) ($order_info['s_address'] ?: $order_info['b_address'] ?: ''));
            $city   = trim((string) ($order_info['s_city']    ?: $order_info['b_city']    ?: ''));
            $customer_address = trim(
                $street !== ''
                    ? trim($street . ($building !== '' ? ' ' . $building : '')) . ($city !== '' ? ', ' . $city : '')
                    : $city
            );

            // 4) payments section (credit card)
            $num_payments = (int) ($_REQUEST['Payments'] ?? 1);
            if ($num_payments < 1) { $num_payments = 1; }

            $payment_item = [
                'payment_type'       => 3,
                'payment_sum'        => (float) $order_info['total'],
                'cc_type_name'       => (string) ($brand_name ?? ''),
                'cc_num_of_payments' => $num_payments,
                'cc_deal_type'       => ($num_payments > 1) ? '2' : '1',
                'auto_calc_payments' => $auto_calc,
                'comment'            => 'מזהה עסקה בחברת האשראי: ' . (string) ($_REQUEST['Id'] ?? ''),
            ];
            $last4 = isset($_REQUEST['L4digit']) ? preg_replace('/\D+/', '', (string) $_REQUEST['L4digit']) : '';
            if ($last4 !== '') { $payment_item['cc_number'] = $last4; }

            // 5) payload
            $base = ($ez_env === 'live') ? 'https://api.ezcount.co.il' : 'https://demo.ezcount.co.il';
            $payload = [
                'api_key'                  => $ez_api_key,
                'developer_email'          => $ez_developer_mail,
                'type'                     => $doc_type,           // 320/400
                'ua_uuid'                  => $ez_ua_uuid ?: null, // dropped if empty
                'lang'                     => $doc_lang,           // he/en
                'description'              => 'Order #' . $order_id,
                'customer_name'            => trim(($order_info['lastname'] ?? '') . ' ' . ($order_info['firstname'] ?? '')),
                'customer_email'           => (string) ($order_info['email'] ?? ''),
                'customer_phone'           => (string) ($order_info['phone'] ?? ''),
                'customer_address'         => $customer_address,
                'transaction_id'           => (string) ($_REQUEST['Id'] ?? ''),
                'forceItems'               => 1,
                'show_items_including_vat' => $show_inc_vat,
                'item'                     => $items,
                'price_total'              => (float) $order_info['total'],
                'payment'                  => [ $payment_item ],
            ];
            if ($created_by_api_key !== '') {
                $payload['created_by_api_key'] = $created_by_api_key; // distributors only; plain text, server hashes
            }
            if (empty($payload['ua_uuid'])) { unset($payload['ua_uuid']); }

            // tax exempt toggle
            if (!empty($order_info['user_data']['tax_exempt']) && $order_info['user_data']['tax_exempt'] === 'Y') {
                $payload['vat'] = '0';
            }

            // 6) endpoint (strict HTTPS; no access_token in query)
            $create_url = 'https://' . (($ez_env === 'live') ? 'api' : 'demo') . '.ezcount.co.il/api/createDoc';

            // 7) logging (mask only for display)
            hypay_log($order_id, 'ezcount.key.check', [
                'env'  => $ez_env,
                'key6' => substr($ez_api_key, 0, 6) . '***',
                'len'  => strlen($ez_api_key),
            ]);
            hypay_log($order_id, 'ezcount.createDoc url', $create_url);
            $payload_log = $payload;
            if (!empty($payload_log['api_key']))            { $payload_log['api_key']            = substr($ez_api_key, 0, 6) . '***'; }
            if (!empty($payload_log['created_by_api_key'])) { $payload_log['created_by_api_key'] = substr($payload['created_by_api_key'], 0, 6) . '***'; }
            hypay_log($order_id, 'ezcount.createDoc payload', $payload_log);

            // 8) fire in the hole
            $resp = hypay_curl_json($order_id, $create_url, $payload, []);
            $create_response = $resp['json'];

            // 9) handle result, with single smart retry (strip ua_uuid on relevant error)
            $ok = (!empty($create_response->success) && !empty($create_response->doc_number));
            $last_error = '';
            if (!$ok) {
                $last_error = isset($create_response->errMsg) ? (string) $create_response->errMsg : ('HTTP ' . $resp['http_code'] . '; body=' . $resp['body']);
                fn_set_notification('E', __('error'), 'EzCount createDoc failed: ' . $last_error);
                hypay_log($order_id, 'ezcount.createDoc FAILED', $last_error);

                if (!empty($payload['ua_uuid']) && stripos($last_error, 'ua_uuid') !== false) {
                    hypay_log($order_id, 'ezcount.retry without ua_uuid');
                    $payload2 = $payload;
                    unset($payload2['ua_uuid']);
                    $resp2 = hypay_curl_json($order_id, $create_url, $payload2, []);
                    $create_response = $resp2['json'];
                    $ok = (!empty($create_response->success) && !empty($create_response->doc_number));
                    if (!$ok) {
                        $last_error = isset($create_response->errMsg) ? (string) $create_response->errMsg : ('HTTP ' . $resp2['http_code'] . '; body=' . $resp2['body']);
                        fn_set_notification('E', __('error'), 'EzCount createDoc failed (retry): ' . $last_error);
                        hypay_log($order_id, 'ezcount.createDoc FAILED (retry)', $last_error);
                    }
                }
            }

            // 10) persist doc info once (or drop helpful hint)
            if ($ok) {
                $doc_log = [
                    'ezcount_invoice_id'       => $create_response->doc_number,
                    'ezcount_invoice_url'      => $create_response->pdf_link ?? '',
                    'ezcount_invoice_doc_uuid' => $create_response->doc_uuid ?? '',
                    'invoice_type'             => (string) $doc_type, // 320/400
                ];
                db_query("REPLACE INTO ?:order_data (order_id, type, data) VALUES (?i, 'X', ?s)", $order_id, serialize($doc_log));
                hypay_log($order_id, 'ezcount.createDoc SUCCESS', $doc_log);
            } else {
                hypay_log($order_id, 'ezcount.createDoc HINT', [
                    'env'        => $ez_env,
                    'has_ua_uuid'=> (bool) ($ez_ua_uuid !== ''),
                    'tip'        => 'Check env (demo/live), api_key↔ua_uuid pair, and created_by_api_key (if set) belongs to the distributor account.',
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

// tag the source (admin|front) to redirect accordingly later
$back = (defined('AREA') && AREA === 'A') ? 'admin' : 'front';
hypay_set_back_marker($order_id, $back);

$base = 'https://pay.hyp.co.il/p/?';

// RU storefronts show ENG Hypay page
$lang2     = hypay_lang2_from_order($order_info);
$force_en  = ($lang2 === 'ru');

// === build heshDesc that exactly matches order total ===
list($heshDesc, $sum_items) = hypay_build_heshdesc($order_info);

// Info template
$info_tpl = trim((string) ($pp['info'] ?? 'Order #{order_id}'));
$info_val = str_replace('{order_id}', (string) $order_id, $info_tpl);

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

    // customer meta
    'ClientName'  => (string) ($order_info['firstname'] ?? ''),
    'ClientLName' => (string) ($order_info['lastname']  ?? ''),
    'email'       => (string) ($order_info['email']     ?? ''),
    'phone'       => (string) ($order_info['phone']     ?? ''),
    'cell'        => (string) ($order_info['phone']     ?? $order_info['s_phone'] ?? ''),
    'street'      => (string) ($order_info['s_address'] ?? $order_info['b_address'] ?? ''),
    'city'        => (string) ($order_info['s_city']    ?? $order_info['b_city']    ?? ''),

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
hypay_put($params_sign, 'J5',               hypay_bool($pp['j5']        ?? 'N'));

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
