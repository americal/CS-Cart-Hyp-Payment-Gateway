<?php
/*****************************************************************************
 * Hypay Addon Functions
 * Author: Michael Shapar (micshap100@gmail.com)
 * Version: 1.2 | 2026-08-25
 *
 * Shared helpers for the Hypay processor: logging, HTTP, EzCount documents
 * and the J5 (two-phase commit) authorization / capture / void flow.
 *****************************************************************************/
use Tygh\Http;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/** Hypay payment page / API entry point */
if (!defined('HYPAY_API_URL')) { define('HYPAY_API_URL', 'https://pay.hyp.co.il/p/'); }

/** CCode returned in the redirect when a J5 authorization was granted */
if (!defined('HYPAY_CCODE_J5_AUTHORIZED')) { define('HYPAY_CCODE_J5_AUTHORIZED', '700'); }

/** global debug switch (filled from payment settings later) */
if (!isset($GLOBALS['HYPAY_DEBUG'])) { $GLOBALS['HYPAY_DEBUG'] = false; }

/* ============================================================================
 * Install / uninstall
 * ==========================================================================*/

function fn_hypay_install()
{
    db_query("INSERT INTO ?:payment_processors ?e", [
        'processor'           => 'Hypay',
        'processor_script'    => 'hypay.php',
        'processor_template' => '',
        'admin_template'      => 'hypay.tpl',
        'callback'            => 'Y',
        'type'                => 'P',
        'addon'               => 'hypay'
    ]);
    fn_hypay_ensure_schema();
    fn_set_notification('N', __('notice'), 'Hypay payment processor registered.');
}

function fn_hypay_uninstall()
{
    db_query("DELETE FROM ?:payment_processors WHERE processor_script = ?s", 'hypay.php');
    // ?:hypay_transactions is intentionally kept: it holds financial records
    // (authorizations / captures) that must survive an addon re-install.
    fn_set_notification('W', __('notice'), 'Hypay processor removed. The hypay_transactions table was kept.');
}

/** Create the J5 transaction table if it is not there yet (also covers upgrades) */
function fn_hypay_ensure_schema()
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    db_query(
        "CREATE TABLE IF NOT EXISTS ?:hypay_transactions ("
        . " transaction_id int(11) unsigned NOT NULL auto_increment,"
        . " order_id mediumint(8) unsigned NOT NULL default '0',"
        . " status varchar(16) NOT NULL default '',"
        . " hyp_id varchar(64) NOT NULL default '',"
        . " acode varchar(64) NOT NULL default '',"
        . " uid varchar(64) NOT NULL default '',"
        . " personal_id varchar(32) NOT NULL default '',"
        . " client_name varchar(128) NOT NULL default '',"
        . " client_lname varchar(128) NOT NULL default '',"
        . " card_token varchar(64) NOT NULL default '',"
        . " card_tokef varchar(8) NOT NULL default '',"
        . " brand varchar(32) NOT NULL default '',"
        . " last4 varchar(8) NOT NULL default '',"
        . " payments smallint(5) unsigned NOT NULL default '1',"
        . " coin tinyint(3) unsigned NOT NULL default '1',"
        . " amount_authorized decimal(12,2) NOT NULL default '0.00',"
        . " amount_captured decimal(12,2) NOT NULL default '0.00',"
        . " capture_hyp_id varchar(64) NOT NULL default '',"
        . " capture_acode varchar(64) NOT NULL default '',"
        . " authorized_at int(11) unsigned NOT NULL default '0',"
        . " expires_at int(11) unsigned NOT NULL default '0',"
        . " captured_at int(11) unsigned NOT NULL default '0',"
        . " voided_at int(11) unsigned NOT NULL default '0',"
        . " void_state varchar(16) NOT NULL default '',"
        . " last_error text,"
        . " PRIMARY KEY (transaction_id),"
        . " KEY order_id (order_id),"
        . " KEY hyp_id (hyp_id)"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    // columns added after the first release: add them to existing installations
    $columns = db_get_fields("SHOW COLUMNS FROM ?:hypay_transactions");
    if (!$columns) { return; }

    $added = [
        'payments_captured' => "smallint(5) unsigned NOT NULL default '0'",
        'client_lname'      => "varchar(128) NOT NULL default ''",
        'void_state'        => "varchar(16) NOT NULL default ''",
    ];
    foreach ($added as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            db_query('ALTER TABLE ?:hypay_transactions ADD ' . $column . ' ' . $definition);
        }
    }
}

/* ============================================================================
 * Debug / logging helpers
 * ==========================================================================*/

/** canonical log file path (also referenced in settings hint) */
function hypay_log_path()
{
    return rtrim(Registry::get('config.dir.var'), '/\\') . '/log/hypay_ezcount.log';
}

/** dumb, safe logger: single signature, token-safe, opt-in via $HYPAY_DEBUG */
function hypay_log($order_id, $label, $data = null)
{
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
                $data = print_r($data, true);
            }
            $line .= ' | ' . $data;
        }
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
    } catch (\Exception $e) {
        // logging must never break a payment
    }
}

/** hide secrets before they reach the log file */
function hypay_mask_params(array $params)
{
    $secret_keys = ['KEY', 'PassP', 'CC', 'api_key', 'created_by_api_key', 'card_token'];
    foreach ($secret_keys as $key) {
        if (!empty($params[$key])) {
            $params[$key] = substr((string) $params[$key], 0, 4) . '***';
        }
    }
    return $params;
}

/** one-shot cURL JSON POST with headers/response capture (fallback to Tygh\Http) */
function hypay_curl_json($order_id, $url, array $payload, array $extra_headers = [])
{
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

/**
 * Server-to-server GET against the Hypay API.
 * Hypay answers with a query string (Id=...&CCode=0&...), so it is parsed back
 * into an array. Secrets are masked before anything is written to the log.
 */
function fn_hypay_api_request($order_id, array $params, $label = 'api')
{
    $url = HYPAY_API_URL . '?' . http_build_query($params);

    hypay_log($order_id, $label . ' request', hypay_mask_params($params));

    $response = Http::get($url, ['timeout' => 45]);

    hypay_log($order_id, $label . ' response', $response);

    $parsed = [];
    parse_str(trim((string) $response), $parsed);

    return [
        'raw'    => (string) $response,
        'params' => is_array($parsed) ? $parsed : [],
    ];
}

/* ============================================================================
 * Small helpers (tiny but mighty)
 * ==========================================================================*/

function hypay_allow_for_order($order_id)
{
    $script_ok  = fn_check_payment_script('hypay.php', $order_id);
    $payment_ok = (isset($_REQUEST['payment']) && $_REQUEST['payment'] === 'hypay');
    return ($order_id && ($script_ok || $payment_ok));
}

/** store/load a tiny marker (back destination + J5 intent) in order_data (type 'H') */
function hypay_set_back_marker($order_id, $value, $is_j5 = false)
{
    $data = ['hypay_back' => $value, 'hypay_j5' => $is_j5 ? 'Y' : 'N'];
    db_query("REPLACE INTO ?:order_data (order_id, type, data) VALUES (?i, 'H', ?s)", $order_id, serialize($data));
}

function hypay_get_marker_data($order_id)
{
    $row = db_get_row("SELECT data FROM ?:order_data WHERE order_id = ?i AND type = 'H'", $order_id);
    if (!empty($row['data'])) {
        $data = @unserialize($row['data']);
        if (is_array($data)) { return $data; }
    }
    return [];
}

function hypay_get_back_marker($order_id)
{
    $data = hypay_get_marker_data($order_id);
    return !empty($data['hypay_back']) ? (string) $data['hypay_back'] : '';
}

function hypay_clear_back_marker($order_id)
{
    db_query("DELETE FROM ?:order_data WHERE order_id = ?i AND type = 'H'", $order_id);
}

/** push a clean redirect (JS replace + meta refresh + noscript link) */
function hypay_clean_redirect($url)
{
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

/** checkbox -> "True"/"False" strings per Hypay API taste */
function hypay_bool($v)
{
    return (!empty($v) && $v !== 'N' && $v !== '0') ? 'True' : 'False';
}

/** put non-empty scalar into assoc array */
function hypay_put(&$arr, $key, $val)
{
    if ($val === '' || $val === null) { return; }
    $arr[$key] = $val;
}

/** language helper */
function hypay_lang2_from_order($order_info)
{
    $lang_code = strtolower((string) ($order_info['lang_code'] ?? (defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en')));
    return substr($lang_code, 0, 2);
}

/** sanitize name for heshDesc */
function hypay_sanitize_name($s)
{
    return str_replace(['[', ']', '~'], '', (string) $s);
}

/**
 * Strip the characters Hyp cannot echo back safely.
 *
 * Hyp copies the free-text parameters it was given (Info, ClientName, street,
 * city, ...) into the redirect URL the customer comes back on, and it does NOT
 * url-encode them. A "#" therefore turns the rest of that URL into a fragment,
 * and a fragment is never sent to the server: every parameter Hyp listed after
 * it - UID, Hesh, errMsg and the signature among them - is silently lost.
 * "&", "?", "=" and "%" corrupt the same URL in less visible ways.
 *
 * Non-ASCII is safe (the browser percent-encodes it), so Hebrew is untouched.
 */
function hypay_sanitize_url_echo($s)
{
    $s = (string) $s;

    $stripped = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
    if ($stripped !== null) { $s = $stripped; }

    $s = str_replace(['#', '&', '?', '=', '%', '+'], ' ', $s);

    $collapsed = preg_replace('/\s+/u', ' ', $s);
    if ($collapsed !== null) { $s = $collapsed; }

    return trim($s);
}

/** Info parameter of an order, from the configured template */
function hypay_build_info($order_id, array $pp)
{
    $tpl = trim((string) ($pp['info'] ?? ''));
    if ($tpl === '') { $tpl = 'Order {order_id}'; }

    return hypay_sanitize_url_echo(str_replace('{order_id}', (string) (int) $order_id, $tpl));
}

/**
 * Value of a redirect parameter, looked up case-insensitively and under every
 * spelling Hyp is known to use. $_REQUEST keys are case-sensitive in PHP, and
 * the gateway does not always spell parameters the way the docs do.
 *
 * @param array $names candidate parameter names, most preferred first
 *
 * @return string empty when none of them came back
 */
function hypay_request_value(array $names)
{
    foreach ($names as $name) {
        if (isset($_REQUEST[$name]) && $_REQUEST[$name] !== '') {
            return (string) $_REQUEST[$name];
        }
    }

    $lookup = [];
    foreach ($_REQUEST as $key => $value) {
        $lookup[strtolower($key)] = $value;
    }

    foreach ($names as $name) {
        $key = strtolower($name);
        if (isset($lookup[$key]) && $lookup[$key] !== '' && !is_array($lookup[$key])) {
            return (string) $lookup[$key];
        }
    }

    return '';
}

/** Hypay brand code -> human name */
function hypay_brand_name($brand_code)
{
    $brand_map = [
        '0' => 'PL',
        '1' => 'MasterCard',
        '2' => 'Visa',
        '3' => 'Diners',
        '4' => 'Amex',
        '5' => 'Isracard',
    ];
    $brand_code = (string) $brand_code;

    return $brand_map[$brand_code] ?? $brand_code;
}

/** Israeli ID as Hypay wants it in the capture call (000000000 when unknown) */
function hypay_clean_personal_id($raw_user_id)
{
    $clean = preg_replace('/\D+/', '', ltrim((string) $raw_user_id, 'L'));
    if ($clean === '' || $clean === '4258784304') {
        $clean = '000000000';
    }

    return $clean;
}

/** payment method settings of an order */
function fn_hypay_get_processor_params($order_info)
{
    if (empty($order_info['payment_id'])) { return []; }
    $processor_data = fn_get_payment_method_data($order_info['payment_id']);

    return $processor_data['processor_params'] ?? [];
}

/* ============================================================================
 * Document / line-item builders (shared by the payment page and EzCount)
 * ==========================================================================*/

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
 * EzCount (Direct API) document
 * ==========================================================================*/

/**
 * Create an EzCount document for an order through the direct API.
 *
 * $ctx: transaction_id (Hypay Id), brand, last4, payments, amount (charged sum).
 * The document is issued for $ctx['amount']; the line items are rebuilt from the
 * order, so the two must match — otherwise nothing is issued at all.
 *
 * @return array|false document info on success
 */
function fn_hypay_create_ezcount_doc($order_id, $order_info, array $pp, array $ctx)
{
    $ez_env             = $pp['ez_environment'] ?? 'demo'; // demo|live
    $ez_api_key         = trim((string) ($pp['ez_api_key'] ?? ''));
    $ez_developer_mail  = trim((string) ($pp['ez_developer_email'] ?? ''));
    $ez_ua_uuid         = trim((string) ($pp['ez_ua_uuid'] ?? ''));
    $created_by_api_key = trim((string) ($pp['ez_created_by_api_key'] ?? '')); // optional, not hashed
    $doc_type_param     = (int) ($pp['ez_doc_type'] ?? 320);                   // 320/400
    $doc_type           = in_array($doc_type_param, [320, 400], true) ? $doc_type_param : 320;
    $show_inc_vat       = isset($pp['ez_show_items_including_vat']) ? (int) (!empty($pp['ez_show_items_including_vat'])) : 1;
    $doc_lang           = ($pp['ez_doc_lang'] ?? 'he') === 'en' ? 'en' : 'he';
    $auto_calc          = isset($pp['ez_auto_calc_payments']) ? (int) (!empty($pp['ez_auto_calc_payments'])) : 0;

    $amount = round((float) ($ctx['amount'] ?? $order_info['total']), 2);

    // 1) line items (vat_type=INC), using unified builder so totals match
    list($items, $items_sum) = hypay_build_ez_items($order_info);

    // The document must never disagree with the money actually charged.
    if (abs(round($items_sum, 2) - $amount) > 0.01) {
        $msg = __('hypay_ez_amount_mismatch', ['[items]' => $items_sum, '[charged]' => $amount]);
        fn_set_notification('E', __('error'), $msg);
        hypay_log($order_id, 'ezcount.createDoc ABORTED (amount mismatch)', ['items_sum' => $items_sum, 'charged' => $amount]);

        return false;
    }

    // 2) customer address (optionally appending building number from custom field)
    $building_id = (int) ($pp['building_field_id'] ?? 0);
    $building    = '';
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

    // 3) payments section (credit card)
    $num_payments = (int) ($ctx['payments'] ?? 1);
    if ($num_payments < 1) { $num_payments = 1; }

    $payment_item = [
        'payment_type'       => 3,
        'payment_sum'        => $amount,
        'cc_type_name'       => (string) ($ctx['brand'] ?? ''),
        'cc_num_of_payments' => $num_payments,
        'cc_deal_type'       => ($num_payments > 1) ? '2' : '1',
        'auto_calc_payments' => $auto_calc,
        'comment'            => 'מזהה עסקה בחברת האשראי: ' . (string) ($ctx['transaction_id'] ?? ''),
    ];
    $last4 = preg_replace('/\D+/', '', (string) ($ctx['last4'] ?? ''));
    if ($last4 !== '') { $payment_item['cc_number'] = $last4; }

    // 4) payload
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
        'transaction_id'           => (string) ($ctx['transaction_id'] ?? ''),
        'forceItems'               => 1,
        'show_items_including_vat' => $show_inc_vat,
        'item'                     => $items,
        'price_total'              => $amount,
        'payment'                  => [$payment_item],
    ];
    if ($created_by_api_key !== '') {
        $payload['created_by_api_key'] = $created_by_api_key; // distributors only; plain text, server hashes
    }
    if (empty($payload['ua_uuid'])) { unset($payload['ua_uuid']); }

    // tax exempt toggle
    if (!empty($order_info['user_data']['tax_exempt']) && $order_info['user_data']['tax_exempt'] === 'Y') {
        $payload['vat'] = '0';
    }

    // 5) endpoint (strict HTTPS; no access_token in query)
    $create_url = 'https://' . (($ez_env === 'live') ? 'api' : 'demo') . '.ezcount.co.il/api/createDoc';

    // 6) logging (mask only for display)
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

    // 7) fire in the hole
    $resp = hypay_curl_json($order_id, $create_url, $payload, []);
    $create_response = $resp['json'];

    // 8) handle result, with single smart retry (strip ua_uuid on relevant error)
    $ok = (!empty($create_response->success) && !empty($create_response->doc_number));
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

    // 9) persist doc info once (or drop helpful hint)
    if ($ok) {
        $doc_log = [
            'ezcount_invoice_id'       => $create_response->doc_number,
            'ezcount_invoice_url'      => $create_response->pdf_link ?? '',
            'ezcount_invoice_doc_uuid' => $create_response->doc_uuid ?? '',
            'invoice_type'             => (string) $doc_type,
        ];
        db_query("REPLACE INTO ?:order_data (order_id, type, data) VALUES (?i, 'X', ?s)", $order_id, serialize($doc_log));
        hypay_log($order_id, 'ezcount.createDoc SUCCESS', $doc_log);

        return $doc_log;
    }

    hypay_log($order_id, 'ezcount.createDoc HINT', [
        'env'         => $ez_env,
        'has_ua_uuid' => (bool) ($ez_ua_uuid !== ''),
        'tip'         => 'Check env (demo/live), api_key<->ua_uuid pair, and created_by_api_key (if set) belongs to the distributor account.',
    ]);

    return false;
}

/* ============================================================================
 * Response codes
 * ==========================================================================*/

/**
 * Human-readable meaning of a Hyp / Shva CCode.
 *
 * @return string empty when the code is unknown
 */
function fn_hypay_ccode_message($ccode)
{
    static $codes = [
        0     => 'Approved',
        1     => 'Blocked card',
        2     => 'Stolen card, confiscate',
        3     => 'Call the credit card company',
        4     => 'Transaction not approved',
        5     => 'Forged card, confiscate',
        6     => 'Transaction declined: incorrect CVV2 (may also indicate a missing Israeli ID number)',
        7     => 'Transaction declined: incorrect CAVV/UCAF',
        8     => 'Transaction declined: incorrect AVS',
        9     => 'Declined: communication disconnection',
        10    => 'Partial approval',
        11    => 'Transaction declined: lack of points/stars/miles/other benefit',
        12    => 'Card not permitted in the terminal',
        13    => 'Request declined: incorrect balance code',
        14    => 'Declined: card not associated with the network',
        15    => 'Transaction declined: card is not valid',
        16    => 'Declined: no permission for currency type (may also indicate missing required request fields)',
        17    => 'Declined: no permission for credit type in the transaction',
        26    => 'Transaction declined: incorrect ID',
        33    => 'You can credit the entire transaction or a small amount of the transaction amount only',
        41    => 'Query required for ceiling only for a transaction with J2 parameter',
        42    => 'Query required not only for ceiling for a transaction with J2 parameter',
        51    => 'Missing vector file 1',
        52    => 'Missing vector file 4',
        53    => 'Missing vector file 6',
        55    => 'Missing vector file 11',
        56    => 'Missing vector file 12',
        57    => 'Missing vector file 15',
        58    => 'Missing vector file 18',
        59    => 'Missing vector file 31',
        60    => 'Missing vector file 34',
        61    => 'Missing vector file 41',
        62    => 'Missing vector file 44',
        63    => 'Missing vector file 64',
        64    => 'Missing vector file 80',
        65    => 'Missing vector file 81',
        66    => 'Missing vector file 82',
        67    => 'Missing vector file 83',
        68    => 'Missing vector file 90',
        69    => 'Missing vector file 91',
        70    => 'Missing vector file 92',
        71    => 'Missing vector file 93',
        73    => 'Missing file PARAM_3_1',
        74    => 'Missing file PARAM_3_2',
        75    => 'Missing file PARAM_3_3',
        76    => 'Missing file PARAM_3_4',
        77    => 'Missing file PARAM_361',
        78    => 'Missing file PARAM_363',
        79    => 'Missing file PARAM_364',
        80    => 'Missing file PARAM_61',
        81    => 'Missing file PARAM_62',
        82    => 'Missing file PARAM_63',
        83    => 'Missing file CEIL_41',
        84    => 'Missing file CEIL_42',
        85    => 'Missing file CEIL_43',
        86    => 'Missing file CEIL_44',
        87    => 'Missing file DATA',
        88    => 'Missing file JENR',
        89    => 'Missing file Start',
        101   => 'Missing entry in vector 1',
        103   => 'Missing entry in vector 4',
        104   => 'Missing entry in vector 6',
        106   => 'Missing entry in vector 11',
        107   => 'Missing entry in vector 12',
        108   => 'Missing entry in vector 15',
        110   => 'Missing entry in vector 18',
        111   => 'Missing entry in vector 31',
        112   => 'Missing entry in vector 34',
        113   => 'Missing entry in vector 41',
        114   => 'Missing entry in vector 44',
        116   => 'Missing entry in vector 64',
        117   => 'Missing entry in vector 81',
        118   => 'Missing entry in vector 82',
        119   => 'Missing entry in vector 83',
        120   => 'Missing entry in vector 90',
        121   => 'Missing entry in vector 91',
        122   => 'Missing entry in vector 92',
        123   => 'Missing entry in vector 93',
        141   => 'Missing appropriate entry in parameters file 3.2',
        142   => 'Missing appropriate entry in parameters file 3.3',
        143   => 'Missing entry in club range file 3.6.1',
        144   => 'Missing entry in club range file 3.6.3',
        145   => 'Missing entry in club range file 3.6.4',
        146   => 'Missing entry in card ceilings file 4.1 PL',
        147   => 'Missing entry in card ceilings file for Israeli cards that are not PL method 4.2 0',
        148   => 'Missing entry in card ceilings file for Israeli cards that are not PL method 4.3 1',
        149   => 'Missing entry in card ceilings file for tourist cards 4.4',
        150   => 'Missing entry in valid cards file - Isracard',
        151   => 'Missing entry in valid cards file - Cal',
        152   => 'Missing entry in valid cards file - future issuer',
        182   => 'Error in vector 4 values',
        183   => 'Error in vector 6/12 values',
        186   => 'Error in vector 18 values',
        187   => 'Error in vector 34 values',
        188   => 'Error in vector 64 values',
        190   => 'Error in vector 90 values',
        191   => 'Invalid data in issuer authorization vector',
        192   => 'Invalid data in parameters set',
        193   => 'Invalid data in terminal-level parameters file',
        200   => 'Missing one or more parameters from the payment completion redirect',
        250   => 'Transaction or payment link not found',
        300   => 'No permission for transaction type - acquirer permission',
        301   => 'No permission for currency - acquirer permission',
        303   => 'No acquirer permission to perform a transaction when the card is not present',
        304   => 'No permission for credit - acquirer permission',
        308   => 'No permission for linkage - acquirer permission',
        309   => 'No acquirer permission for credit on a fixed date',
        310   => 'No permission to type pre-approval number',
        311   => 'No permission to perform transactions for service code 587',
        312   => 'No acquirer permission for postponed credit',
        313   => 'No acquirer permission for benefits',
        314   => 'No acquirer permission for promotions',
        315   => 'No acquirer permission for a specific promotion code',
        316   => 'No acquirer permission for a loading transaction',
        317   => 'No acquirer permission for loading/unloading in the payment method code combined with currency code',
        318   => 'No acquirer permission for currency in this credit type',
        319   => 'No acquirer permission for tips',
        322   => 'No appropriate permission to perform a request for approval without a transaction J5',
        341   => 'No permission for transaction - issuer permission',
        342   => 'No permission for currency - issuer permission',
        343   => 'No issuer permission to perform a transaction when the card is not present',
        344   => 'No permission for credit - issuer permission',
        348   => 'No permission to perform approval of a request initiated by a retailer',
        349   => 'No appropriate permission to perform a request for approval without a transaction J5',
        350   => 'No issuer permission for benefits',
        351   => 'No issuer permission for postponed credit',
        352   => 'No issuer permission for a loading transaction',
        353   => 'No issuer permission for loading/unloading in the payment method code',
        354   => 'No issuer permission for currency in this credit type',
        381   => 'No permission to perform a contactless transaction above maximum amount',
        382   => 'In a terminal defined as self-service, only self-service transactions can be performed',
        384   => 'Terminal defined as multi-supplier/beneficiary - supplier/beneficiary number missing',
        385   => 'In a terminal defined as an e-commerce terminal, eci must be passed',
        400   => 'Sum of items differs from transaction amount',
        401   => 'First or last name is required / Number of installments is too high',
        402   => 'Transaction information is required / Number of installments is too low',
        403   => 'Transaction amount is smaller than the minimum amount for payment',
        404   => 'Number of payments field was not entered',
        405   => 'Missing data for first/fixed payment amount',
        406   => 'Total transaction amount is different from first payment amount + fixed payment amount * number of payments',
        408   => 'Channel 2 is shorter than 37 characters',
        410   => 'Rejection for dcode reason',
        414   => 'In a transaction with a fixed date charge, a date later than a year from transaction performance was entered',
        415   => 'Invalid data entered',
        416   => 'Expiration date is not in a valid format',
        417   => 'Terminal number is incorrect',
        418   => 'Essential parameters are missing',
        419   => 'Error in passing clientInputPan attribute',
        420   => 'Invalid card number - in a situation of entering channel 2 in a transaction without a card present',
        421   => 'General error - invalid data',
        422   => 'Error in building ISO message',
        424   => 'Non-numeric field',
        425   => 'Duplicate record',
        426   => 'The amount was increased after performing Ashrayit checks',
        428   => 'Missing service code on the card',
        429   => 'Card is not valid according to the valid cards file',
        431   => 'General error',
        432   => 'No permission for passing card through magnetic reader',
        433   => 'Must pass in PinPad',
        434   => 'Forbidden to pass card in the PinPad device',
        435   => 'The device is not defined for magnetic card passing CTL',
        436   => 'The device is not defined for EMV card passing CTL',
        439   => 'No permission for credit type according to transaction type',
        440   => 'Tourist card is not permitted for this credit type',
        441   => 'No permission for performing transaction type - card exists in vector 80',
        442   => 'Stand-in for approval verification for this acquirer should not be performed',
        443   => 'Cannot perform a cancellation transaction - card was not found in the existing transactions file in the terminal',
        445   => 'In an immediate debit card, only immediate debit credit can be performed',
        447   => 'Incorrect card number (for a tokenization request, this may also indicate a missing Token=True parameter)',
        448   => 'Must type customer address (ZIP code, house number, and city)',
        449   => 'Must type ZIP code',
        450   => 'Promotion code out of range, should be in 1-12 range',
        451   => 'Error during transaction record building',
        452   => 'In a loading/unloading/balance inquiry transaction, the payment method code field must be entered',
        453   => 'Cannot cancel an unloading transaction 7.9.3',
        455   => 'Cannot perform a forced debit transaction when an approval request is required (except for ceilings)',
        456   => 'Card found in the transactions file with response code \'confiscate card\'',
        457   => 'In an immediate debit card, regular debit/credit/cancellation transaction is allowed',
        458   => 'Club code not in range',
        470   => 'In a standing order transaction, the sum of payments is higher than the transaction amount field',
        471   => 'In a standing order transaction, the current payment number is greater than the total number of payments',
        472   => 'In a debit transaction with cash, a cash amount must be entered',
        473   => 'In a debit transaction with cash, the cash amount must be smaller than the transaction amount',
        474   => 'Initialization transaction in a standing order requires J5 parameter',
        475   => 'Standing order transaction requires one of the fields: number of payments or total amount',
        476   => 'Current payment transaction in a standing order requires payment number field',
        477   => 'Current payment transaction in a standing order requires identification number of the initialization transaction',
        478   => 'Current payment transaction in a standing order requires approval number of the initialization transaction',
        479   => 'Current payment transaction in a standing order requires date and time fields of the initialization transaction',
        480   => 'Missing field for original transaction approver',
        481   => 'Missing number of units field when the transaction is performed in a payment method code different from currency',
        482   => 'In a loaded card, regular debit/credit/cancellation/unloading/loading/balance inquiry transaction is allowed',
        483   => 'Transaction with a fuel card in a fuel terminal requires entering a vehicle number',
        484   => 'Typed vehicle number differs from the one on the magnetic stripe / bank number different from 012 / leftmost digits of the branch number different from 44',
        485   => 'Vehicle number shorter than 6 digits / differs from the vehicle number on channel 2',
        486   => 'Must type odometer reading',
        487   => 'Only in a terminal defined as two-stage fuel can obligo update be used',
        489   => 'In a Dalkan card, only regular debit transaction is allowed (cancellation transaction is forbidden)',
        490   => 'In fuel/Dalkan/fuel club cards, transactions can be performed only in fuel terminals',
        491   => 'Transaction involving conversion must contain all conversion rate and currency fields',
        492   => 'No conversion on NIS/USD transactions',
        493   => 'In a transaction involving a benefit, only one of the discount amount/units/percentage fields must be present',
        494   => 'Different terminal number',
        495   => 'No fallback permission',
        496   => 'Cannot link credit other than credit/payments',
        497   => 'Cannot link to USD/index in a currency other than NIS',
        498   => 'Local Isracard card, the separator should be in position 18',
        500   => 'Transaction stopped by the user',
        504   => 'Mismatch between card data source field and card number field',
        505   => 'Invalid value in transaction type field',
        506   => 'Invalid value in eci field',
        507   => 'Actual transaction amount is higher than the approved amount',
        509   => 'Error during writing to transactions file',
        512   => 'Cannot enter an approval received from voice response for this transaction',
        551   => 'Response message does not match the request message',
        552   => 'Error in field 55',
        553   => 'Error received from the Tandem',
        554   => 'mcc_18 field is missing in the response message',
        555   => 'response_code_25 field is missing in the response message',
        556   => 'rrn_37 field is missing in the response message',
        557   => 'comp_retailer_num_42 field is missing in the response message',
        558   => 'auth_code_43 field is missing in the response message',
        559   => 'f39_response_39 field is missing in the response message',
        560   => 'authorization_no_38 field is missing in the response message',
        561   => 'additional_data_48.solek_auth_no field is missing or empty in the response message',
        562   => 'One of the conversion fields is missing in the response message',
        563   => 'Field value does not match the received approval numbers auth_code_43',
        564   => 'additional_amounts54.cashback_amount field is missing or empty in the response message',
        565   => 'Mismatch between field 25 and field 43',
        566   => 'In a terminal defined as supporting two-stage fuel, fields 90 and 119 must be returned',
        567   => 'Fields 25 and 127 are invalid in the obligo update message in a terminal defined as two-stage fuel',
        598   => 'Error in negative file',
        599   => 'General error',
        600   => 'Transaction details received (J2)',
        700   => 'Authorization (J5) / Transaction declined by PinPad device',
        701   => 'Error in PinPad device',
        702   => 'Invalid COM port',
        703   => 'PinPad transaction error',
        704   => 'PinPad transaction cancelled',
        705   => 'PinPad user cancelled',
        706   => 'PinPad user timeout',
        707   => 'PinPad user card removed',
        708   => 'PinPad user retries exceeded',
        709   => 'PinPad timeout',
        710   => 'PinPad communications error',
        711   => 'PinPad message error',
        712   => 'PinPad not initialized',
        713   => 'PinPad card read error',
        714   => 'Reader timeout',
        715   => 'Reader communications error',
        716   => 'Reader message error',
        717   => 'Host message error',
        718   => 'Host config error',
        719   => 'Host key error',
        720   => 'Host connect error',
        721   => 'Host transmit error',
        722   => 'Host receive error',
        723   => 'Host timeout',
        724   => 'PIN verification not supported by card',
        725   => 'PIN verification failed',
        726   => 'Error in receiving config.xml file',
        730   => 'Device approved transaction contrary to Ashrayit decision',
        731   => 'Card not inserted',
        777   => 'OK, you can proceed',
        800   => 'Postponed transaction',
        901   => 'Terminal is not permitted to use this method or a wrong zPass is provided',
        902   => 'Authentication error',
        903   => 'The number of payments configured in the terminal has been exceeded',
        904   => 'Missing What parameter',
        905   => 'Unsupported payment agreement state',
        906   => 'Recurring payment agreement does not exist',
        910   => 'Invalid transaction for tokenization (the transaction was not successful and allowFalse=True was not included in the tokenization request)',
        920   => 'Transaction cannot be cancelled (it was already transmitted or it does not exist)',
        990   => 'Card details are not fully readable, please pass the card again',
        995   => 'Payment link cannot be deleted because it has already been paid',
        996   => 'Terminal is not permitted to use tokens',
        997   => 'Token is not valid',
        998   => 'Transaction cancelled',
        999   => 'Communication error',
    ];

    $ccode = (string) $ccode;
    if ($ccode === '' || !ctype_digit($ccode)) {
        return '';
    }

    return $codes[(int) $ccode] ?? '';
}

/**
 * Error text for a failed Hyp call: the code, what it means, and whatever the
 * gateway itself said. Falls back to the raw response when nothing else is
 * available, so a bare code is still diagnosable.
 */
function fn_hypay_format_error($ccode, $err_msg = '', $raw = '')
{
    $ccode   = trim((string) $ccode);
    $err_msg = trim((string) $err_msg);

    $parts = [];
    if ($ccode !== '') {
        $meaning = fn_hypay_ccode_message($ccode);
        $parts[] = 'CCode=' . $ccode . ($meaning !== '' ? ' — ' . $meaning : '');
    }
    if ($err_msg !== '') {
        $parts[] = $err_msg;
    }
    if (!$parts) {
        $raw = trim((string) $raw);
        $parts[] = $raw !== '' ? $raw : 'no response';
    }

    return implode(' | ', $parts);
}

/* ============================================================================
 * J5 (two-phase commit): authorization -> card token -> capture / void
 *
 * Flow per https://developers.hyp.co.il/pay/advanced-features/two-phase-commits
 *   1. payment page with J5=True&MoreData=True  -> redirect with CCode=700,
 *      Id, ACode, UserId, UID (funds are held, nothing is charged yet)
 *   2. action=getToken&TransId=<Id>             -> Token + Tokef
 *   3. action=soft&Token=True&CC=<Token>...     -> the actual charge (J4),
 *      Amount may be equal to or lower than the authorized amount
 * ==========================================================================*/

/** Usergroups the order's customer belongs to */
function fn_hypay_get_order_usergroups($order_info)
{
    $ids = [];

    $user_id = (int) ($order_info['user_id'] ?? 0);
    if ($user_id > 0) {
        $ids = db_get_fields(
            "SELECT usergroup_id FROM ?:usergroup_links WHERE user_id = ?i AND status = 'A'",
            $user_id
        );
    }

    if (!empty($order_info['user_data']['usergroup_ids']) && is_array($order_info['user_data']['usergroup_ids'])) {
        $ids = array_merge($ids, $order_info['user_data']['usergroup_ids']);
    }

    return array_values(array_unique(array_map('intval', $ids)));
}

/** Usergroup ids configured to pay with J5 */
function fn_hypay_get_j5_usergroups($pp)
{
    $pp = (array) $pp;
    $groups = $pp['j5_usergroups'] ?? [];
    if (!is_array($groups)) {
        $groups = array_filter(explode(',', (string) $groups), 'strlen');
    }

    return array_values(array_unique(array_map('intval', $groups)));
}

/**
 * Should this order be paid with a J5 authorization?
 * payment_type: regular | j5 | usergroup (legacy: the old "j5" checkbox).
 */
function fn_hypay_is_j5_order($order_info, array $pp)
{
    $type = (string) ($pp['payment_type'] ?? '');
    if ($type === '') {
        $type = (!empty($pp['j5']) && $pp['j5'] === 'Y') ? 'j5' : 'regular';
    }

    if ($type === 'j5')      { return true; }
    if ($type !== 'usergroup') { return false; }

    $j5_groups = fn_hypay_get_j5_usergroups($pp);
    if (empty($j5_groups)) { return false; }

    $order_groups = fn_hypay_get_order_usergroups($order_info);

    return (bool) array_intersect($j5_groups, $order_groups);
}

/** How long the issuer holds the funds, in days (Hypay: typically ~5) */
function fn_hypay_hold_days(array $pp)
{
    $days = (int) ($pp['j5_hold_days'] ?? 5);

    return ($days > 0) ? $days : 5;
}

/** Highest number of instalments allowed for a capture */
function fn_hypay_max_payments(array $pp, array $tx = [])
{
    $configured = (isset($pp['tash']) && $pp['tash'] !== '') ? (int) $pp['tash'] : 0;
    $authorized = isset($tx['payments']) ? (int) $tx['payments'] : 0;

    $max = max(1, $configured, $authorized);

    return min($max, 36);
}

/** Latest Hypay transaction of an order */
function fn_hypay_get_transaction($order_id)
{
    fn_hypay_ensure_schema();

    $tx = db_get_row(
        "SELECT * FROM ?:hypay_transactions WHERE order_id = ?i ORDER BY transaction_id DESC LIMIT 1",
        (int) $order_id
    );

    return is_array($tx) ? $tx : [];
}

/** Store a fresh J5 authorization (one row per authorization attempt) */
function fn_hypay_store_authorization($order_id, array $data)
{
    fn_hypay_ensure_schema();

    $data['order_id'] = (int) $order_id;
    $data['status']   = 'authorized';

    return db_query("INSERT INTO ?:hypay_transactions ?e", $data);
}

function fn_hypay_update_transaction($transaction_id, array $data)
{
    fn_hypay_ensure_schema();

    return db_query("UPDATE ?:hypay_transactions SET ?u WHERE transaction_id = ?i", $data, (int) $transaction_id);
}

/** Tokef (YYMM, e.g. 3105 = 05/31) -> ['month' => '05', 'year' => '31'] */
function fn_hypay_split_tokef($tokef)
{
    $digits = preg_replace('/\D+/', '', (string) $tokef);
    if (strlen($digits) !== 4) {
        return ['month' => '', 'year' => ''];
    }

    $first = substr($digits, 0, 2);
    $last  = substr($digits, 2, 2);

    // documented format is YYMM
    if ((int) $last >= 1 && (int) $last <= 12) {
        return ['month' => $last, 'year' => $first];
    }
    // defensive: some terminals answer MMYY
    if ((int) $first >= 1 && (int) $first <= 12) {
        return ['month' => $first, 'year' => $last];
    }

    return ['month' => '', 'year' => ''];
}

/**
 * Step 2: exchange the authorization Id for a card token.
 *
 * @return array|false ['token' => ..., 'tokef' => ...]
 */
function fn_hypay_fetch_card_token($order_id, array $pp, $trans_id, &$error = '')
{
    $result = fn_hypay_api_request($order_id, [
        'action'  => 'getToken',
        'Masof'   => trim((string) ($pp['masof'] ?? '')),
        'PassP'   => trim((string) ($pp['passp'] ?? '')),
        'TransId' => (string) $trans_id,
        // a J5 authorization is not a completed charge, and tokenization
        // rejects those with CCode=910 unless allowFalse says otherwise. Our
        // terminal happens to answer without it, but the reference is explicit
        // that a card that was only verified needs it.
        'allowFalse' => 'True',
    ], 'j5.getToken');

    $params = $result['params'];

    if ((string) ($params['CCode'] ?? '') === '0' && !empty($params['Token'])) {
        return [
            'token' => (string) $params['Token'],
            'tokef' => (string) ($params['Tokef'] ?? ''),
        ];
    }

    $error = fn_hypay_format_error($params['CCode'] ?? '', $params['errMsg'] ?? '', $result['raw']);
    hypay_log($order_id, 'j5.getToken FAILED', $error);

    return false;
}

/** Merge extra fields into the order's payment information block */
function fn_hypay_update_payment_info($order_id, array $extra)
{
    if (!function_exists('fn_update_order_payment_info')) {
        hypay_log($order_id, 'payment_info update skipped (fn_update_order_payment_info missing)', $extra);

        return false;
    }

    fn_update_order_payment_info($order_id, $extra);
    hypay_log($order_id, 'payment_info updated', $extra);

    return true;
}

/**
 * Step 3: capture (charge) a J5 authorization.
 *
 * The amount is always the current order total: to charge less, the order has
 * to be edited first, otherwise the EzCount document would not match the money
 * actually taken. Capturing more than authorized is rejected by design.
 *
 * @return bool
 */
function fn_hypay_capture_j5($order_id, $amount = null, $payments = null)
{
    fn_hypay_ensure_schema();

    $order_id   = (int) $order_id;
    $order_info = fn_get_order_info($order_id);
    if (empty($order_info)) {
        fn_set_notification('E', __('error'), __('hypay_j5_error_no_order'));

        return false;
    }

    $pp = fn_hypay_get_processor_params($order_info);
    $GLOBALS['HYPAY_DEBUG'] = (!empty($pp['debug_mode']) && $pp['debug_mode'] === 'Y');

    $tx = fn_hypay_get_transaction($order_id);
    if (empty($tx) || $tx['status'] !== 'authorized') {
        fn_set_notification('E', __('error'), __('hypay_j5_error_not_authorized'));

        return false;
    }

    $order_total = round((float) $order_info['total'], 2);
    $amount      = ($amount === null) ? $order_total : round((float) $amount, 2);
    $authorized  = round((float) $tx['amount_authorized'], 2);

    if ($amount <= 0) {
        fn_set_notification('E', __('error'), __('hypay_j5_error_zero_amount'));

        return false;
    }
    if ($amount > $authorized + 0.009) {
        fn_set_notification('E', __('error'), __('hypay_j5_error_exceeds_authorized'));

        return false;
    }
    if (abs($amount - $order_total) > 0.009) {
        fn_set_notification('E', __('error'), __('hypay_j5_error_amount_mismatch'));

        return false;
    }

    // number of instalments: the one the customer picked, unless the admin
    // changed it before capturing
    $max_payments = fn_hypay_max_payments($pp, $tx);
    $payments     = ($payments === null) ? (int) $tx['payments'] : (int) $payments;
    if ($payments < 1) { $payments = 1; }
    if ($payments > $max_payments) {
        fn_set_notification('E', __('error'), __('hypay_j5_error_payments_range', ['[max]' => $max_payments]));

        return false;
    }

    // everything the capture needs must have come back with the authorization
    $missing = [];
    if ((string) $tx['acode'] === '') { $missing[] = 'ACode'; }
    if ((string) $tx['uid'] === '')   { $missing[] = 'UID'; }
    if ($missing) {
        fn_set_notification('E', __('error'), __('hypay_j5_error_missing_auth_data', ['[fields]' => implode(', ', $missing)]));
        hypay_log($order_id, 'j5.capture ABORTED (incomplete authorization)', $missing);

        return false;
    }

    // claim the row so a double click cannot charge the customer twice
    $claimed = db_query(
        "UPDATE ?:hypay_transactions SET status = 'capturing' WHERE transaction_id = ?i AND status = 'authorized'",
        $tx['transaction_id']
    );
    if (empty($claimed)) {
        fn_set_notification('W', __('warning'), __('hypay_j5_error_in_progress'));

        return false;
    }

    // the token may be missing if getToken failed right after the authorization
    $token = (string) $tx['card_token'];
    $tokef = (string) $tx['card_tokef'];
    if ($token === '') {
        $token_error = '';
        $fetched = fn_hypay_fetch_card_token($order_id, $pp, $tx['hyp_id'], $token_error);
        if ($fetched === false) {
            fn_hypay_update_transaction($tx['transaction_id'], ['status' => 'authorized', 'last_error' => 'getToken: ' . $token_error]);
            fn_set_notification('E', __('error'), __('hypay_j5_error_no_token') . ' ' . $token_error);

            return false;
        }
        $token = $fetched['token'];
        $tokef = $fetched['tokef'];
        fn_hypay_update_transaction($tx['transaction_id'], ['card_token' => $token, 'card_tokef' => $tokef]);
    }

    $expiry = fn_hypay_split_tokef($tokef);
    if ($expiry['month'] === '' || $expiry['year'] === '') {
        fn_hypay_update_transaction($tx['transaction_id'], ['status' => 'authorized', 'last_error' => 'bad Tokef: ' . $tokef]);
        fn_set_notification('E', __('error'), __('hypay_j5_error_bad_tokef', ['[tokef]' => $tokef]));

        return false;
    }

    // The capture is a transaction of its own (Hyp has no "commit the held one"
    // call): action=soft charges the card token and points Shva back at the
    // authorization through AuthNum + inputObj.originalUid + originalAmount.
    // That is why the acquirer shows a second row next to the CCode=700 one -
    // the hold stays as the authorization record and the new row is the charge.
    $params = [
        'action'                          => 'soft',
        'Masof'                           => trim((string) ($pp['masof'] ?? '')),
        'PassP'                           => trim((string) ($pp['passp'] ?? '')),
        'UserId'                          => $tx['personal_id'] !== '' ? $tx['personal_id'] : '000000000',
        // both halves of the name, exactly as the authorization was made: with
        // ClientName alone the acquirer shows the charge under the first name
        // only, which does not match the CCode=700 row next to it
        'ClientName'                      => (string) ($tx['client_name']  ?: ($order_info['firstname'] ?? '')),
        'ClientLName'                     => (string) ($tx['client_lname'] ?? '') ?: (string) ($order_info['lastname'] ?? ''),
        'Token'                           => 'True',
        'CC'                              => $token,
        'Tmonth'                          => $expiry['month'],
        'Tyear'                           => $expiry['year'],
        'AuthNum'                         => (string) $tx['acode'],
        // same representation the payment page request uses (Amount=150, not 150.00)
        'Amount'                          => round($amount, 2),
        'Info'                            => hypay_build_info($order_id, $pp),
        'Coin'                            => max(1, (int) $tx['coin']),
        // the same encoding flags the payment page request was signed with, so
        // a Hebrew name is not mangled and errMsg comes back readable
        'UTF8'                            => hypay_bool($pp['utf8']    ?? 'Y'),
        'UTF8out'                         => hypay_bool($pp['utf8out'] ?? 'Y'),
        // the original authorization, in currency subunits (agorot)
        'inputObj.originalAmount'         => (int) round($authorized * 100),
        'inputObj.originalUid'            => (string) $tx['uid'],
        'inputObj.authorizationCodeManpik' => 7,
    ];
    if ($payments > 1) {
        // charge in the same number of instalments the customer agreed to
        $params['Tash'] = $payments;
        if (isset($pp['tashtype']) && $pp['tashtype'] !== '') {
            $params['tashType'] = (int) $pp['tashtype'];
        }
    }

    // the authorization this capture points back at - the three values Shva
    // matches against the held transaction, spelled out so a refusal (CCode=4)
    // can be compared with the CCode=700 row in the Hyp control panel
    hypay_log($order_id, 'j5.capture references authorization', [
        'hyp_id'                  => $tx['hyp_id'],
        'AuthNum'                 => $tx['acode'],
        'inputObj.originalUid'    => $tx['uid'],
        'inputObj.originalAmount' => $params['inputObj.originalAmount'],
        'Amount'                  => $params['Amount'],
        'Tmonth/Tyear'            => $expiry['month'] . '/' . $expiry['year'],
        'Tokef'                   => $tokef,
    ]);

    $result   = fn_hypay_api_request($order_id, $params, 'j5.capture');
    $response = $result['params'];
    $ccode    = isset($response['CCode']) ? (string) $response['CCode'] : '';

    if ($ccode === '') {
        // No readable answer: the charge may or may not have happened. The row is
        // deliberately left in 'capturing' so nobody can charge the customer twice
        // before the transaction has been checked in the Hyp control panel.
        fn_hypay_update_transaction($tx['transaction_id'], ['last_error' => 'capture: no response from Hyp']);
        fn_set_notification('E', __('error'), __('hypay_j5_capture_unknown'));
        fn_hypay_order_note($order_id, __('hypay_j5_capture_unknown'));

        return false;
    }

    if ($ccode !== '0') {
        $error = fn_hypay_format_error($ccode, $response['errMsg'] ?? '', $result['raw']);
        fn_hypay_update_transaction($tx['transaction_id'], ['status' => 'authorized', 'last_error' => 'capture: ' . $error]);
        fn_set_notification('E', __('error'), __('hypay_j5_capture_failed') . ' ' . $error);
        fn_hypay_order_note($order_id, __('hypay_j5_capture_failed') . ' ' . $error);

        return false;
    }

    $capture_id    = (string) ($response['Id'] ?? '');
    $capture_acode = (string) ($response['ACode'] ?? $tx['acode']);

    fn_hypay_update_transaction($tx['transaction_id'], [
        'status'            => 'captured',
        'payments_captured' => $payments,
        'amount_captured' => $amount,
        'capture_hyp_id'  => $capture_id,
        'capture_acode'   => $capture_acode,
        'captured_at'     => TIME,
        'last_error'      => '',
    ]);

    fn_hypay_update_payment_info($order_id, [
        'transaction_id' => $capture_id !== '' ? $capture_id : $tx['hyp_id'],
        'reason_text'    => '🟢 ' . __('hypay_j5_pi_captured', ['[amount]' => number_format($amount, 2, '.', '')]),
        'hypay_j5'       => __('hypay_j5_pi_captured_on', [
            '[amount]'   => number_format($amount, 2, '.', ''),
            '[date]'     => date('d.m.Y H:i', TIME),
            '[payments]' => $payments,
        ]),
    ]);

    $captured_status = !empty($pp['j5_captured_status']) ? $pp['j5_captured_status'] : ($pp['success_status'] ?? 'P');
    fn_change_order_status($order_id, $captured_status);

    fn_hypay_order_note($order_id, __('hypay_j5_note_captured', [
        '[amount]' => number_format($amount, 2, '.', ''),
        '[id]'     => $capture_id,
    ]));

    hypay_log($order_id, 'j5.capture SUCCESS', ['amount' => $amount, 'capture_id' => $capture_id]);
    fn_set_notification('N', __('notice'), __('hypay_j5_capture_ok', ['[amount]' => number_format($amount, 2, '.', '')]));

    // the document is issued now, for the amount that was actually charged
    if (($pp['ez_mode'] ?? 'none') === 'direct') {
        $order_info = fn_get_order_info($order_id); // re-read: the status has changed
        fn_hypay_create_ezcount_doc($order_id, $order_info, $pp, [
            'transaction_id' => $capture_id,
            'brand'          => $tx['brand'],
            'last4'          => $tx['last4'],
            'payments'       => $payments,
            'amount'         => $amount,
        ]);
    } else {
        hypay_log($order_id, 'ezcount skipped after capture (ez_mode != direct)', ['ez_mode' => $pp['ez_mode'] ?? 'none']);
    }

    return true;
}

/**
 * One CancelTrans call.
 *
 * ReversalStatus is reported raw. It is tempting to read it through the CCode
 * table, where the documented success value 777 is "OK, you can proceed" and
 * the 404 a held authorization comes back with is "Number of payments field
 * was not entered" - but repeating the payment count on the request changed
 * nothing, so 404 here means the reversal found nothing to act on, matching
 * CCode=920. Decoding it that way only produced a misleading message.
 *
 * @param array $extra additional lookup keys beyond the documented four
 *
 * @return array [state, detail] where state is confirmed | not_cancellable | failed
 */
function fn_hypay_cancel_trans($order_id, array $pp, $trans_id, array $extra = [])
{
    $result = fn_hypay_api_request($order_id, array_merge([
        'action'  => 'CancelTrans',
        'Masof'   => trim((string) ($pp['masof'] ?? '')),
        'PassP'   => trim((string) ($pp['passp'] ?? '')),
        'TransId' => (string) $trans_id,
    ], $extra), 'j5.cancel');

    $response = $result['params'];
    $ccode    = isset($response['CCode']) ? (string) $response['CCode'] : '';
    $reversal = isset($response['ReversalStatus']) ? (string) $response['ReversalStatus'] : '';

    if ($ccode === '0' && $reversal === '777') {
        return ['confirmed', ''];
    }

    $detail = fn_hypay_format_error($ccode, '', $result['raw']);
    if ($reversal !== '') {
        $detail .= ' | ReversalStatus=' . $reversal;
    }

    $state = ($ccode === '920') ? 'not_cancellable' : 'failed';

    hypay_log($order_id, 'j5.cancel not confirmed by Hyp', ['state' => $state, 'detail' => $detail]);

    return [$state, $detail];
}

/**
 * Void a J5 authorization.
 *
 * Hypay documents no server-to-server release call for a held authorization,
 * so this marks the hold as abandoned on our side: it is never captured and
 * the issuer releases the funds when the authorization window expires.
 *
 * @return bool
 */
function fn_hypay_void_j5($order_id)
{
    fn_hypay_ensure_schema();

    $order_id   = (int) $order_id;
    $order_info = fn_get_order_info($order_id);
    if (empty($order_info)) {
        fn_set_notification('E', __('error'), __('hypay_j5_error_no_order'));

        return false;
    }

    $pp = fn_hypay_get_processor_params($order_info);
    $GLOBALS['HYPAY_DEBUG'] = (!empty($pp['debug_mode']) && $pp['debug_mode'] === 'Y');

    $tx = fn_hypay_get_transaction($order_id);
    if (empty($tx) || $tx['status'] !== 'authorized') {
        fn_set_notification('E', __('error'), __('hypay_j5_error_not_authorized'));

        return false;
    }

    // claim the row first so a double click cannot fire CancelTrans twice
    $claimed = db_query(
        "UPDATE ?:hypay_transactions SET status = 'voiding' WHERE transaction_id = ?i AND status = 'authorized'",
        $tx['transaction_id']
    );
    if (empty($claimed)) {
        fn_set_notification('W', __('warning'), __('hypay_j5_error_in_progress'));

        return false;
    }

    // action=CancelTrans asks Hyp to reverse the deal, so the customer sees the
    // hold drop off their card instead of waiting out the authorization window.
    // On terminal 0010334524 the documented call comes back CCode=920 with
    // ReversalStatus=404 for every hold, which reads as "nothing found to
    // reverse".
    //
    // The Enterprise reference explains why that is plausible: there, a
    // reversal looked up by tranId "will search for the original debit
    // transaction by this ID", while cgUid - the identifier shared by every
    // step of one financial transaction, two-phase commits included - finds the
    // latest state of the transaction itself. A J5 hold is not a debit
    // transaction, so a lookup meant for one can miss it. Pay's TransId is the
    // step identifier; the Shva UID is the value that spans the whole
    // transaction, and Pay already names it inputObj.originalUid in the capture
    // request.
    //
    // So a hold refused with CCode=920 is retried once with the UID as the
    // lookup key, under both spellings that value goes by here. This is not in
    // the Pay reference for CancelTrans - it is a hypothesis that matches the
    // symptom - and it only ever runs after the documented call was refused.
    $cancel_state = 'not_attempted';
    $hyp_detail   = '';

    if ($tx['hyp_id'] !== '') {
        list($cancel_state, $hyp_detail) = fn_hypay_cancel_trans($order_id, $pp, $tx['hyp_id']);

        if ($cancel_state === 'not_cancellable' && (string) $tx['uid'] !== '') {
            hypay_log($order_id, 'j5.cancel retrying with the Shva UID as the lookup key', ['uid' => $tx['uid']]);

            list($cancel_state, $hyp_detail) = fn_hypay_cancel_trans($order_id, $pp, $tx['hyp_id'], [
                'inputObj.originalUid' => (string) $tx['uid'],
                'UID'                  => (string) $tx['uid'],
            ]);
        }
    } else {
        $cancel_state = 'failed';
        $hyp_detail   = 'no authorization Id stored, CancelTrans was not attempted';
    }

    $confirmed = ($cancel_state === 'confirmed');

    // the hold is abandoned on our side either way: even if Hyp could not
    // confirm the cancellation (already expired, or CCode=920), it must
    // never be captured again, and the issuer releases it when the
    // authorization window runs out.
    fn_hypay_update_transaction($tx['transaction_id'], [
        'status'     => 'voided',
        'voided_at'  => TIME,
        'void_state' => $cancel_state,
        'last_error' => $confirmed ? '' : $hyp_detail,
    ]);

    $status_text = $confirmed ? __('hypay_j5_pi_voided_confirmed') : __('hypay_j5_pi_voided');
    fn_hypay_update_payment_info($order_id, [
        'reason_text' => '⚪ ' . $status_text,
        'hypay_j5'    => __('hypay_j5_pi_voided_on', [
            '[amount]' => number_format(round((float) $tx['amount_authorized'], 2), 2, '.', ''),
            '[date]'   => date('d.m.Y H:i', TIME),
        ]),
    ]);

    $void_status = !empty($pp['j5_void_status']) ? $pp['j5_void_status'] : 'I';
    fn_change_order_status($order_id, $void_status);

    if ($confirmed) {
        $note   = __('hypay_j5_note_voided_confirmed');
        $notice = __('hypay_j5_void_ok_confirmed');
    } elseif ($cancel_state === 'not_cancellable') {
        $note   = __('hypay_j5_note_voided_not_cancellable');
        $notice = __('hypay_j5_void_ok_not_cancellable', ['[days]' => fn_hypay_hold_days($pp)]);
    } else {
        $note   = __('hypay_j5_note_voided_unconfirmed', ['[detail]' => $hyp_detail]);
        $notice = __('hypay_j5_void_ok_unconfirmed', ['[days]' => fn_hypay_hold_days($pp)]);
    }

    fn_hypay_order_note($order_id, $note);

    hypay_log($order_id, 'j5.void', ['hyp_id' => $tx['hyp_id'], 'state' => $cancel_state]);

    fn_set_notification('N', __('notice'), $notice);

    return true;
}

/** Append a line to the order log (best effort, never fatal) */
function fn_hypay_order_note($order_id, $text)
{
    hypay_log($order_id, 'note', $text);
}

/**
 * Everything the admin order page needs to render the J5 block.
 *
 * @return array empty array when the order has no Hypay transaction
 */
function fn_hypay_get_j5_panel_data($order_id)
{
    $order_id = (int) $order_id;
    if ($order_id <= 0) { return []; }

    $tx = fn_hypay_get_transaction($order_id);
    if (empty($tx)) { return []; }

    $order_info = fn_get_order_info($order_id);
    if (empty($order_info)) { return []; }

    $pp = fn_hypay_get_processor_params($order_info);

    $order_total = round((float) $order_info['total'], 2);
    $authorized  = round((float) $tx['amount_authorized'], 2);
    $expires_at  = (int) $tx['expires_at'];

    $is_open = in_array($tx['status'], ['authorized', 'capturing'], true);

    return [
        'order_id'          => $order_id,
        'status'            => $tx['status'],
        'hyp_id'            => $tx['hyp_id'],
        'acode'             => $tx['acode'],
        // shown in the panel so the values the capture sends back to Shva can be
        // compared with the CCode=700 row in the Hyp control panel
        'uid'               => (string) $tx['uid'],
        'has_token'         => ($tx['card_token'] !== ''),
        'amount_authorized' => $authorized,
        'amount_captured'   => round((float) $tx['amount_captured'], 2),
        'payments'          => max(1, (int) $tx['payments']),
        'payments_captured' => max(1, (int) ($tx['payments_captured'] ?: $tx['payments'])),
        'max_payments'      => fn_hypay_max_payments($pp, $tx),
        'order_total'       => $order_total,
        'capture_hyp_id'    => $tx['capture_hyp_id'],
        'authorized_at'     => (int) $tx['authorized_at'],
        'captured_at'       => (int) $tx['captured_at'],
        'voided_at'         => (int) $tx['voided_at'],
        'expires_at'        => $expires_at,
        'is_expired'        => ($is_open && $expires_at > 0 && $expires_at < TIME),
        'can_capture'       => ($tx['status'] === 'authorized' && $order_total > 0 && $order_total <= $authorized + 0.009),
        'can_void'          => ($tx['status'] === 'authorized'),
        'amount_mismatch'   => ($tx['status'] === 'authorized' && $order_total > $authorized + 0.009),
        'hold_days'         => fn_hypay_hold_days($pp),
        'void_state'        => (string) ($tx['void_state'] ?? ''),
        'last_error'        => (string) $tx['last_error'],
    ];
}
