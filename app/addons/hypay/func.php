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

/** what Shva is told when the cardholder's Israeli ID is not known */
if (!defined('HYPAY_PERSONAL_ID_UNKNOWN')) { define('HYPAY_PERSONAL_ID_UNKNOWN', '000000000'); }

/** add-on that owns ?:orders.additional_status - nothing here works without it */
if (!defined('HYPAY_ADDITIONAL_STATUSES_ADDON')) { define('HYPAY_ADDITIONAL_STATUSES_ADDON', 'ecl_additional_order_statuses'); }

/**
 * Parameter that tells Hyp which EzCount document to issue for a paid payment
 * link in integrated mode. Hyp hands EZ.* parameters on to EzCount, and "type"
 * is EzCount's own name for the document type (320 / 400) - see
 * fn_hypay_create_ezcount_doc(). Kept in one place so it is a one-line change
 * if the terminal wants it spelled differently.
 */
if (!defined('HYPAY_EZ_INT_DOC_TYPE_PARAM')) { define('HYPAY_EZ_INT_DOC_TYPE_PARAM', 'EZ.type'); }

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
        . " sp_type varchar(32) NOT NULL default '',"
        . " trans_type varchar(32) NOT NULL default '',"
        . " issuer varchar(64) NOT NULL default '',"
        . " bincard varchar(16) NOT NULL default '',"
        . " hold_release_state varchar(16) NOT NULL default '',"
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
        . " first_payment decimal(12,2) NOT NULL default '0.00',"
        . " periodical_payment decimal(12,2) NOT NULL default '0.00',"
        . " last_error text,"
        . " PRIMARY KEY (transaction_id),"
        . " KEY order_id (order_id),"
        . " KEY hyp_id (hyp_id)"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    // Payment links (action=payRequest): one row per link created for an order.
    // status: active | cancelled | expired | paid. expired: older than the
    // lifetime the payment method settings give a link. paid_via says who
    // noticed the payment first - the customer's return from the payment page ('return') or the
    // LIST lookup made from the order page ('list').
    db_query(
        "CREATE TABLE IF NOT EXISTS ?:hypay_payment_links ("
        . " link_id int(11) unsigned NOT NULL auto_increment,"
        . " order_id mediumint(8) unsigned NOT NULL default '0',"
        . " payment_id mediumint(8) unsigned NOT NULL default '0',"
        . " pay_request_id varchar(64) NOT NULL default '',"
        // a signed payment page URL runs well past 255 characters
        . " payment_url text,"
        . " amount decimal(12,2) NOT NULL default '0.00',"
        . " coin tinyint(3) unsigned NOT NULL default '1',"
        . " info varchar(255) NOT NULL default '',"
        . " sent_to varchar(255) NOT NULL default '',"
        . " status varchar(16) NOT NULL default '',"
        . " paid_via varchar(16) NOT NULL default '',"
        . " trans_id varchar(64) NOT NULL default '',"
        // the document Hyp issued itself (integrated EzCount), from Hesh
        . " doc_number varchar(64) NOT NULL default '',"
        . " kind varchar(16) NOT NULL default 'request',"
        . " created_at int(11) unsigned NOT NULL default '0',"
        . " cancelled_at int(11) unsigned NOT NULL default '0',"
        . " paid_at int(11) unsigned NOT NULL default '0',"
        . " checked_at int(11) unsigned NOT NULL default '0',"
        . " last_error text,"
        . " PRIMARY KEY (link_id),"
        . " KEY order_id (order_id),"
        . " KEY pay_request_id (pay_request_id)"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    // added after the table first appeared
    $link_columns = db_get_fields("SHOW COLUMNS FROM ?:hypay_payment_links");
    if ($link_columns && !in_array('doc_number', $link_columns, true)) {
        db_query("ALTER TABLE ?:hypay_payment_links ADD doc_number varchar(64) NOT NULL default ''");
    }
    // request: made with payRequest, Hyp sends it by e-mail / SMS;
    // sign:    a signed payment page URL (APISign), handed to the merchant to
    //          send however they like - nothing is sent, nothing to LIST
    if ($link_columns && !in_array('kind', $link_columns, true)) {
        db_query("ALTER TABLE ?:hypay_payment_links ADD kind varchar(16) NOT NULL default 'request'");
    }

    // payment_url was a varchar(255), and a signed payment page URL is longer:
    // it was stored cut off before action=pay and the signature, and Hyp
    // answered it with "Action is not good". The column is widened, and the
    // links already cut off - they cannot be paid - are cancelled with the
    // reason, so a new one can be made for their orders.
    $url_column = db_get_row("SHOW COLUMNS FROM ?:hypay_payment_links LIKE 'payment_url'");
    $url_type   = (string) ($url_column['Type'] ?? $url_column['type'] ?? '');
    if ($url_type !== '' && stripos($url_type, 'text') === false) {
        db_query("ALTER TABLE ?:hypay_payment_links MODIFY payment_url text");

        // the language variable arrives with a reinstall or a language import,
        // which may not have happened yet: the reason is kept either way
        $reason = __('hypay_link_error_truncated');
        if (strpos($reason, 'hypay_link_error_truncated') !== false) {
            $reason = 'This link was saved cut short and could not be paid (Hyp answered "Action is not good"). Create a new one.';
        }
        db_query(
            "UPDATE ?:hypay_payment_links SET status = 'cancelled', cancelled_at = ?i, last_error = ?s"
            . " WHERE status = 'active' AND kind = 'sign' AND payment_url NOT LIKE '%signature=%'",
            TIME,
            $reason
        );
    }

    // The orders a link pays for - one or several of the same customer's.
    // hypay_payment_links.order_id stays as the order the link was created
    // from; this is what every lookup by order goes through.
    db_query(
        "CREATE TABLE IF NOT EXISTS ?:hypay_payment_link_orders ("
        . " link_id int(11) unsigned NOT NULL default '0',"
        . " order_id mediumint(8) unsigned NOT NULL default '0',"
        . " amount decimal(12,2) NOT NULL default '0.00',"
        . " PRIMARY KEY (link_id, order_id),"
        . " KEY order_id (order_id)"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );

    // links created before there could be several orders to one: each pays
    // for the order it was created from
    $unlinked = (int) db_get_field(
        "SELECT COUNT(*) FROM ?:hypay_payment_links AS l"
        . " LEFT JOIN ?:hypay_payment_link_orders AS lo ON lo.link_id = l.link_id"
        . " WHERE lo.link_id IS NULL"
    );
    if ($unlinked > 0) {
        db_query(
            "INSERT IGNORE INTO ?:hypay_payment_link_orders (link_id, order_id, amount)"
            . " SELECT l.link_id, l.order_id, l.amount FROM ?:hypay_payment_links AS l"
            . " LEFT JOIN ?:hypay_payment_link_orders AS lo ON lo.link_id = l.link_id"
            . " WHERE lo.link_id IS NULL"
        );
    }

    // columns added after the first release: add them to existing installations
    $columns = db_get_fields("SHOW COLUMNS FROM ?:hypay_transactions");
    if (!$columns) { return; }

    $added = [
        'payments_captured' => "smallint(5) unsigned NOT NULL default '0'",
        'client_lname'      => "varchar(128) NOT NULL default ''",
        'void_state'        => "varchar(16) NOT NULL default ''",
        'first_payment'      => "decimal(12,2) NOT NULL default '0.00'",
        'periodical_payment' => "decimal(12,2) NOT NULL default '0.00'",
        // what the card turned out to be, as Hyp reported it with MoreData=True.
        // spType is the one that decides how the money can be taken: an
        // 'Immediate' (Direct / debit) card refuses a capture that carries a
        // pre-obtained authorization number.
        'sp_type'            => "varchar(32) NOT NULL default ''",
        'trans_type'         => "varchar(32) NOT NULL default ''",
        'issuer'             => "varchar(64) NOT NULL default ''",
        'bincard'            => "varchar(16) NOT NULL default ''",
        // what CancelTrans said about the hold after a fallback charge
        'hold_release_state' => "varchar(16) NOT NULL default ''",
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

/**
 * Empty the cart the customer has just paid from.
 *
 * CS-Cart empties the cart inside fn_order_placement_routines('route', ...),
 * which a redirecting processor is supposed to call when the gateway sends the
 * customer back. This add-on brings the customer back itself, with a redirect
 * of its own (hypay_clean_redirect), and so never reaches that call: the goods
 * that were just paid for stayed in the cart, waiting to be ordered a second
 * time. The same is true of a J5 hold - the order is placed, only the money is
 * not taken yet - so both flows end here.
 *
 * Only a completed payment gets this far. A declined one leaves the cart as it
 * is on purpose, so the customer can try again with the same products.
 *
 * Returns true when something was actually emptied.
 */
function fn_hypay_clear_cart($order_id)
{
    if (!function_exists('fn_clear_cart') || empty(\Tygh::$app['session']['cart'])) {
        hypay_log($order_id, 'cart not cleared (no cart in this session)');
        return false;
    }

    $cart = & \Tygh::$app['session']['cart'];
    $auth = isset(\Tygh::$app['session']['auth']) ? \Tygh::$app['session']['auth'] : [];

    // Where CS-Cart says which order this session has just placed, believe it:
    // a customer paying an older order from "My orders" keeps whatever is in
    // the cart right now, because that cart never produced this order. The key
    // is absent on some flows and holds either one id or a list of them, so it
    // only ever vetoes - it is not asked for permission.
    $processed = isset($cart['processed_order_id']) ? $cart['processed_order_id'] : null;
    if (!empty($processed)) {
        $processed_ids = array_map('intval', (array) $processed);
        if (!in_array((int) $order_id, $processed_ids, true)) {
            hypay_log($order_id, 'cart left alone (this session placed another order)', $processed_ids);
            return false;
        }
    }

    $products_before = !empty($cart['products']) && is_array($cart['products']) ? count($cart['products']) : 0;

    fn_clear_cart($cart);

    // checkout.complete only shows an order the session is known to have just
    // placed, and clearing the cart takes that note with it. Put back exactly
    // what was there - the shape of this value differs between flows, and this
    // is not the place to invent one.
    if ($processed !== null && !isset($cart['processed_order_id'])) {
        $cart['processed_order_id'] = $processed;
    }

    // a signed-in customer carries the cart in the database as well, and it
    // would come back on the next visit if only the session copy were emptied
    if (function_exists('fn_save_cart_content') && !empty($auth['user_id'])) {
        fn_save_cart_content($cart, $auth['user_id']);
    }

    hypay_log($order_id, 'cart cleared after a completed payment', ['products_removed' => $products_before]);

    return true;
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

/**
 * Text that came from Hyp, made safe to store and to print.
 *
 * The gateway answers in UTF-8 while UTF8out is on, but not on every route it
 * takes: an Apple Pay / Google Pay charge comes back with its errMsg in
 * windows-1255, the encoding the terminal speaks natively. PHP percent-decodes
 * that into raw 8-bit bytes, and the result is not valid UTF-8.
 *
 * One such byte is enough to erase a whole line on the order page. CS-Cart runs
 * Smarty with escape_html on, so every value is printed through
 * htmlspecialchars($v, ENT_QUOTES, 'UTF-8') - and that returns an empty string,
 * not a replacement character and not the rest of the text, when its input is
 * not valid UTF-8. The row itself kept rendering, because the template tests the
 * unescaped value and finds it perfectly non-empty; only what it said was gone.
 * That is why "Payment status" stood there blank on a wallet payment while
 * Brand, the last four digits and the personal ID - digits and ASCII, all of
 * them - came through untouched.
 *
 * Anything already valid is left exactly as it is, emoji included.
 *
 * @param string $text
 *
 * @return string valid UTF-8, free of control characters
 */
function hypay_utf8_text($text)
{
    $text = (string) $text;
    if ($text === '') { return ''; }

    // preg with /u fails outright on malformed UTF-8, which is the test we want
    if (preg_match('//u', $text) !== 1) {
        $text = hypay_repair_utf8($text);
    }

    $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $text);
    if ($stripped !== null) { $text = $stripped; }

    return trim($text);
}

/**
 * Rebuild a string that is UTF-8 in one half and 8-bit Hebrew in the other.
 *
 * Converting the whole thing from windows-1255 is the obvious move and the wrong
 * one: the line this repairs is a concatenation - "🟢 Success — " written here,
 * in UTF-8, followed by whatever the terminal sent - and running the finished
 * string through a legacy decoder turns the good half into mojibake ("נ¢" for
 * the marker, "ג€”" for the dash) while fixing the bad one.
 *
 * So each byte is judged where it stands. Anything that opens a well-formed
 * UTF-8 sequence is kept exactly as it is, sequence and all; every other byte is
 * a legacy one and is looked up in the table below. A byte with no meaning in
 * either encoding is dropped rather than left to blank the line again.
 *
 * @param string $text
 *
 * @return string
 */
function hypay_repair_utf8($text)
{
    // one alternative per well-formed UTF-8 sequence, per the encoding's own
    // definition: ASCII, then the 2-, 3- and 4-byte forms, overlongs and
    // surrogates excluded
    $utf8 = '(?:[\x00-\x7F]'
        . '|[\xC2-\xDF][\x80-\xBF]'
        . '|\xE0[\xA0-\xBF][\x80-\xBF]'
        . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
        . '|\xED[\x80-\x9F][\x80-\xBF]'
        . '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
        . '|[\xF1-\xF3][\x80-\xBF]{3}'
        . '|\xF4[\x80-\x8F][\x80-\xBF]{2})';

    $map = hypay_legacy_byte_map();

    // no /u here on purpose: the subject is not valid UTF-8, which is the point
    $repaired = preg_replace_callback(
        '/' . $utf8 . '|(.)/s',
        static function (array $m) use ($map) {
            // group 1 is only set when the sequence branch did not match, which
            // makes this byte a legacy one
            if (!isset($m[1])) { return $m[0]; }

            return isset($map[$m[1]]) ? $map[$m[1]] : '';
        },
        $text
    );

    return $repaired === null ? '' : $repaired;
}

/**
 * Every high byte of the terminal's own encoding, as UTF-8.
 *
 * windows-1255 is what it speaks. ISO-8859-8 stands in where that name is not
 * compiled in - mbstring builds without CP1255 are common - and the Hebrew
 * letters sit at the same code points in both, which is all that is at stake in
 * an errMsg. Built once per request: a byte at a time is what the repair above
 * needs, and 128 conversions is a table, not a loop worth optimising.
 *
 * @return array<string, string> byte => UTF-8 character, unmappable bytes absent
 */
function hypay_legacy_byte_map()
{
    static $map = null;

    if ($map !== null) { return $map; }

    $map = [];

    for ($i = 0x80; $i <= 0xFF; $i++) {
        $byte = chr($i);
        $char = '';

        foreach (['windows-1255', 'CP1255', 'ISO-8859-8'] as $charset) {
            if (function_exists('iconv')) {
                $try = @iconv($charset, 'UTF-8//IGNORE', $byte);
                if (is_string($try) && $try !== '') { $char = $try; break; }
            }

            if (function_exists('mb_convert_encoding')) {
                try {
                    // an unknown charset is a ValueError on PHP 8, which "@"
                    // does not silence, and a plain false on PHP 7
                    $try = @mb_convert_encoding($byte, 'UTF-8', $charset);
                } catch (\Throwable $e) {
                    $try = '';
                }
                if (is_string($try) && $try !== '') { $char = $try; break; }
            }
        }

        if ($char !== '' && preg_match('//u', $char) === 1) {
            $map[$byte] = $char;
        }
    }

    return $map;
}

/**
 * Has this text already been destroyed, rather than merely mis-encoded?
 *
 * hypay_repair_utf8() puts back what the wrong encoding hid. It cannot put back
 * what something upstream has already thrown away: once a byte has become
 * U+FFFD, the replacement character, the letter it stood for is gone and no
 * decoder gets it back. Statuses stored while the encoding was broken show
 * exactly that - a row of replacement characters where the gateway's note used
 * to be, in one case written out literally as "&#65533;" by whatever escaped
 * them on the way in.
 *
 * Both spellings count. A note that has been reduced to this says nothing, so
 * the callers drop it rather than print it.
 *
 * @param string $text
 *
 * @return bool
 */
function hypay_text_is_lost($text)
{
    $text = (string) $text;

    if ($text === '') { return false; }

    return strpos($text, "\xEF\xBF\xBD") !== false
        || stripos($text, '&#65533;') !== false
        || stripos($text, '&#xfffd;') !== false;
}

/**
 * Drop the parts of a stored payment status that no longer say anything.
 *
 * The line is built here as "<marker> <verdict>" and, on a failure, " — " and
 * what went wrong. Only the trailing notes ever came from the gateway, so only
 * they can be wreckage: the verdict is dropped from consideration and every
 * note that has been reduced to replacement characters is dropped from the line,
 * leaving the plain "🟢 Success" the row was meant to read.
 *
 * This is for text already in the database. Nothing written from now on can need
 * it - the gateway's note no longer goes into this line at all.
 *
 * @param string $text
 *
 * @return string
 */
function hypay_drop_lost_notes($text)
{
    $text = (string) $text;

    if (!hypay_text_is_lost($text)) { return $text; }

    $parts = explode(' — ', $text);
    $kept  = [];

    foreach ($parts as $i => $part) {
        // the verdict itself stays even if it is damaged: a status page with a
        // mangled line still beats one with no line
        if ($i === 0 || !hypay_text_is_lost($part)) {
            $kept[] = $part;
        }
    }

    return trim(implode(' — ', $kept));
}

/**
 * The same treatment for a whole payment_info payload.
 *
 * Applied to the finished array rather than to each field as it is read, so a
 * value that starts being taken from the gateway later cannot quietly reopen
 * this hole. It repairs and nothing else: a string that is already valid UTF-8
 * comes back byte for byte, so this is a no-op on every payment method that
 * never had the problem, and on the read path it leaves other processors'
 * payment information exactly as they wrote it.
 *
 * @param array $info
 *
 * @return array
 */
function fn_hypay_clean_payment_info(array $info)
{
    foreach ($info as $key => $value) {
        if (is_string($value) && $value !== '' && preg_match('//u', $value) !== 1) {
            $info[$key] = hypay_utf8_text($value);
        }
    }

    // the one line that ever carried the gateway's own words
    if (isset($info['reason_text']) && is_string($info['reason_text'])) {
        $info['reason_text'] = hypay_drop_lost_notes($info['reason_text']);
    }

    return $info;
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

    return $brand_map[$brand_code] ?? hypay_utf8_text($brand_code);
}

/**
 * Is this an immediate-debit (Direct) card?
 *
 * Hyp reports the special card type in spType when the payment page request
 * carried MoreData=True - 'Immediate' for a card the money leaves the account
 * on, 'Tourist' for a foreign one. It matters because Shva will not let a
 * transaction on an immediate-debit card carry a pre-obtained authorization
 * number: the J5 capture is refused with CCode=512, "cannot enter an approval
 * received from voice response for this transaction", even though the request
 * is exactly what the documentation asks for.
 *
 * The value is matched loosely rather than compared: it is documented by
 * example only, it has been seen both as the English word and in Hebrew, and
 * a card type nobody recognises must not be mistaken for an ordinary one.
 */
function hypay_is_immediate_card($sp_type)
{
    $sp_type = trim(hypay_utf8_text((string) $sp_type));
    if ($sp_type === '') {
        return false;
    }

    if (stripos($sp_type, 'immediate') !== false || stripos($sp_type, 'direct') !== false) {
        return true;
    }

    // מיידי - "immediate", the word Hyp uses when it answers in Hebrew
    return (strpos($sp_type, "\xd7\x9e\xd7\x99\xd7\x99\xd7\x93\xd7\x99") !== false);
}

/**
 * The digits of something that is meant to be an Israeli ID, or '' when there
 * are none that could be one.
 *
 * Shva's field holds at most nine digits, so anything longer is not a shortened
 * or mistyped ID - it is a different number altogether, and passing it on as
 * one is worse than admitting the ID is unknown.
 */
function hypay_personal_id_digits($value)
{
    // Hyp prefixes the value with "L" on some routes
    $digits = preg_replace('/\D+/', '', ltrim((string) $value, 'Ll'));

    if ($digits === '' || strlen($digits) > 9 || (int) $digits === 0) {
        return '';
    }

    return $digits;
}

/**
 * Does this number carry a valid Israeli ID check digit?
 *
 * A ת.ז - and a ח.פ, which is numbered the same way - is nine digits, shorter
 * ones left-padded with zeros, the last digit computed from the other eight by
 * the Luhn variant below. Every number the capture could send is put through
 * this, whether Hyp echoed it back or a person typed it on the order page:
 * Shva makes no allowance for where it came from either.
 */
function hypay_is_israeli_id($value)
{
    $digits = hypay_personal_id_digits($value);
    if ($digits === '') { return false; }

    $digits = str_pad($digits, 9, '0', STR_PAD_LEFT);

    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int) $digits[$i] * (($i % 2) + 1);
        $sum += ($n > 9) ? $n - 9 : $n;
    }

    return ($sum % 10) === 0;
}

/**
 * The Israeli ID Hyp echoed back, or the "not supplied" placeholder.
 *
 * UserId in the redirect is only the cardholder's ID when the payment page
 * actually asked for one. When it did not, Hyp still fills the parameter in -
 * with an identifier of its own, ten digits long and belonging to nobody - and
 * repeating that number to Shva as the cardholder's ID is what a CCode=6
 * refusal is made of. So the value has to look like an ID to be treated as one.
 */
function hypay_clean_personal_id($raw_user_id)
{
    return hypay_is_israeli_id($raw_user_id)
        ? hypay_personal_id_digits($raw_user_id)
        : HYPAY_PERSONAL_ID_UNKNOWN;
}

/**
 * An authorization number fit to send back to Shva as AuthNum.
 *
 * The one that came back with the hold is what the capture normally carries,
 * but a refused capture can be retried with a fresh number the merchant reads
 * off the credit company by hand. Shva's authorization numbers are digits, so
 * anything else - the spaces a copy-paste brings, a stray letter - is stripped,
 * and the result is held to the width of the column that stores it. An empty
 * string means there was nothing usable in what was typed.
 *
 * @param string $value what the merchant typed on the order page
 *
 * @return string the cleaned number, or '' when none of it was usable
 */
function fn_hypay_clean_acode($value)
{
    return substr(preg_replace('/\D+/', '', (string) $value), 0, 64);
}

/**
 * The Personal ID line of a J5 order's payment info.
 *
 * Two things worth reading, and neither of them replaces the other: what the
 * capture will actually send, and - when that is not what Hyp reported - the
 * number Hyp did report, in brackets after it.
 *
 *     000000000 (Original: 1577484600)
 *
 * The line has to answer "why is the ID a row of zeros" on an order page where
 * a capture has just been refused, and it has to keep answering it afterwards:
 * the value in brackets is the identifier Hyp fills UserId with when the
 * payment page never asked the customer for an ID, and recognising it is what
 * tells a merchant this hold needs the real number typed in rather than
 * another attempt.
 *
 * The bracketed part is deliberately the same in every language, like the
 * "Order #1234" the documents are reconciled by: it is a number and a label
 * for it, read off the Hyp control panel beside the transaction it came from.
 */
function hypay_personal_id_label($value)
{
    $clean = hypay_clean_personal_id($value);
    $raw   = preg_replace('/\D+/', '', ltrim((string) $value, 'Ll'));

    // nothing to add: either it is a real ID, or Hyp reported nothing to
    // report, or what it reported was already the placeholder
    if ($clean !== HYPAY_PERSONAL_ID_UNKNOWN || $raw === '' || $raw === $clean) {
        return $clean;
    }

    return __('hypay_j5_pi_personal_id_original', [
        '[id]'       => $clean,
        '[original]' => $raw,
    ]);
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

/**
 * Which line items a Direct API document is made of.
 *
 * list_products - one line per product, plus shipping, surcharge, discounts and
 *                 the rounding adjustment (the default).
 * list_orders   - a single line naming the order, priced at the order total.
 *
 * A J5 document is issued at capture, days after the customer checked out, and
 * often for a different audience than a regular checkout receipt - so it gets
 * its own setting. That setting starts out empty and follows the regular one
 * until somebody chooses otherwise: an install that had picked list_orders
 * before this split keeps issuing list_orders on both paths.
 *
 * @param string $flow 'j5' for the document issued after a capture
 *
 * @return bool true when the document should itemize the products
 */
function hypay_ez_is_list_products_mode(array $pp, $flow = 'regular')
{
    $mode = '';

    if ($flow === 'j5') {
        $mode = trim((string) ($pp['ez_line_items_mode_j5'] ?? ''));
    }

    if ($mode === '') {
        $mode = trim((string) ($pp['ez_line_items_mode'] ?? ''));
    }

    return $mode !== 'list_orders';
}

/**
 * Build EzCount items so sum == order_total including discounts/surcharges/rounding.
 *
 * @param bool $list_products false to collapse the whole order into one line
 */
function hypay_build_ez_items($order_info, $list_products = true) {
    $lang2    = hypay_lang2_from_order($order_info);
    $force_en = ($lang2 === 'ru');

    $items    = [];
    $sum_items = 0.0;

    // one line for the whole order: nothing to sum up, nothing to round off
    if (!$list_products) {
        $order_total = round((float) $order_info['total'], 2);

        // deliberately not translated: accounting reconciles these documents
        // against order numbers, and one wording across every order is what
        // makes that possible - a Hebrew order must read the same as the rest
        $order_label = 'Order #' . (int) $order_info['order_id'];

        $items[] = [
            'details'  => $order_label,
            'price'    => $order_total,
            'amount'   => 1,
            'vat_type' => 'INC',
        ];

        return [$items, $order_total];
    }

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
 * EzCount customer details
 * ==========================================================================*/

/**
 * The buyer's account, read once per order.
 *
 * @return array empty for a guest order
 */
function hypay_ez_user_info($order_info)
{
    static $cache = [];

    $user_id = (int) ($order_info['user_id'] ?? 0);
    if ($user_id <= 0) { return []; }

    if (!array_key_exists($user_id, $cache)) {
        $cache[$user_id] = fn_get_user_info($user_id) ?: [];
    }

    return $cache[$user_id];
}

/**
 * One profile value for the EzCount document.
 *
 * The order carries a snapshot of the profile as it stood when the order was
 * placed, and that snapshot is what the document should say. A field filled in
 * after the order - or one the snapshot never carried - is read from the
 * account itself, so switching the setting on has an effect on old orders too.
 */
function hypay_ez_profile_value($order_info, $key)
{
    $value = trim((string) ($order_info['user_data'][$key] ?? ''));
    if ($value !== '') { return $value; }

    $user_info = hypay_ez_user_info($order_info);

    return trim((string) ($user_info[$key] ?? ''));
}

/** The same, for a custom profile field addressed by id. */
function hypay_ez_profile_field($order_info, $field_id)
{
    $field_id = (int) $field_id;
    if ($field_id <= 0) { return ''; }

    foreach ([$order_info['user_data']['fields'] ?? [], $order_info['fields'] ?? []] as $fields) {
        $value = trim((string) ($fields[$field_id] ?? ''));
        if ($value !== '') { return $value; }
    }

    $user_info = hypay_ez_user_info($order_info);

    return trim((string) ($user_info['fields'][$field_id] ?? ''));
}

/**
 * The profile field holding the EzCount CC e-mail.
 *
 * An installation that knows its own field id names it in the settings; left
 * empty, the field is looked up by the name the EzCount Doc Generator add-on
 * gives it, so the two add-ons read one field without being told about it
 * twice.
 *
 * @return int 0 when there is no such field
 */
function hypay_ez_cc_email_field_id(array $pp)
{
    $configured = (int) ($pp['ez_cc_email_field_id'] ?? 0);
    if ($configured > 0) { return $configured; }

    static $by_name = null;
    if ($by_name === null) {
        $by_name = (int) db_get_field(
            "SELECT field_id FROM ?:profile_fields WHERE field_name = ?s",
            'ezcount_additional_email'
        );
    }

    return $by_name;
}

/**
 * What the settings say to tell EzCount about the customer.
 *
 * The profile fields are the ones the EzCount Doc Generator add-on reads, so a
 * document issued from here names the customer exactly as a document issued
 * from there does:
 *
 *   VAT ID        the profile "url" field  -> customer_crn
 *   EzCount name  the profile "fax" field  -> customer_name
 *   CC e-mail     a custom profile field   -> cc_emails
 *
 * Every one of them is opt-in and off by default, so an installation that
 * issued documents before these settings existed keeps issuing the same ones.
 * The two ways of producing a document are configured separately: $prefix is
 * "ez" for the direct API and "ez_int" for the document Hyp issues itself.
 *
 * The name falls back to the profile name: a customer who never filled the
 * EzCount name field is still named on the document, rather than not at all.
 *
 * @return array{name: string, ezcount_name: string, vat: string, cc_emails: array, assoc: bool}
 */
function hypay_ez_customer(array $pp, $order_info, $prefix = 'ez')
{
    $ezcount_name = '';
    if (($pp[$prefix . '_customer_name'] ?? 'N') === 'Y') {
        $ezcount_name = hypay_ez_profile_value($order_info, 'fax');
    }

    $vat = '';
    if (($pp[$prefix . '_customer_vat'] ?? 'N') === 'Y') {
        $vat = hypay_ez_profile_value($order_info, 'url');
    }

    $cc_emails = [];
    if (($pp[$prefix . '_customer_cc_emails'] ?? 'N') === 'Y') {
        $raw       = hypay_ez_profile_field($order_info, hypay_ez_cc_email_field_id($pp));
        $cc_emails = array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    $profile_name = trim(((string) ($order_info['lastname'] ?? '')) . ' ' . ((string) ($order_info['firstname'] ?? '')));

    return [
        'name'         => $ezcount_name !== '' ? $ezcount_name : $profile_name,
        'ezcount_name' => $ezcount_name,
        'vat'          => $vat,
        'cc_emails'    => $cc_emails,
        'assoc'        => ($pp[$prefix . '_customer_assoc'] ?? 'N') === 'Y',
    ];
}

/* ============================================================================
 * EzCount (Direct API) document
 * ==========================================================================*/

/**
 * Create an EzCount document for an order through the direct API.
 *
 * $ctx: transaction_id (Hypay Id), brand, last4, payments, amount (charged sum),
 *       doc_type (320/400, optional: overrides the "Document type" setting -
 *       a paid payment link has a setting of its own).
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
    $created_by_api_key = trim((string) ($pp['ez_created_by_api_key'] ?? '')); // optional, not hashed
    $doc_type_param     = (int) ($ctx['doc_type'] ?? ($pp['ez_doc_type'] ?? 320)); // 320/400
    $doc_type           = in_array($doc_type_param, [320, 400], true) ? $doc_type_param : 320;
    $show_inc_vat       = isset($pp['ez_show_items_including_vat']) ? (int) (!empty($pp['ez_show_items_including_vat'])) : 1;
    $doc_lang           = ($pp['ez_doc_lang'] ?? 'he') === 'en' ? 'en' : 'he';
    $auto_calc          = isset($pp['ez_auto_calc_payments']) ? (int) (!empty($pp['ez_auto_calc_payments'])) : 0;
    $flow               = ($ctx['flow'] ?? 'regular') === 'j5' ? 'j5' : 'regular';
    $list_products      = hypay_ez_is_list_products_mode($pp, $flow);
    $customer           = hypay_ez_customer($pp, $order_info, 'ez');

    // Unlike the line-items mode next to it, the UA UUID does not fall back to
    // the regular value: a J5 document is issued into whichever EzCount account
    // its own field names, and leaving that field empty means the document is
    // created without ua_uuid at all.
    $ez_ua_uuid = $flow === 'j5'
        ? trim((string) ($pp['ez_ua_uuid_j5'] ?? ''))
        : trim((string) ($pp['ez_ua_uuid'] ?? ''));

    $amount = round((float) ($ctx['amount'] ?? $order_info['total']), 2);

    // One document may cover several orders paid together (a payment link
    // sent for more than one order): their lines follow the first order's, and
    // the document is recorded on every one of them - the same way the EzCount
    // Doc Generator records a document it issues for several orders.
    $extra_orders = [];
    foreach ((array) ($ctx['extra_orders'] ?? []) as $extra) {
        if (is_array($extra) && !empty($extra['order_id']) && (int) $extra['order_id'] !== (int) $order_id) {
            $extra_orders[(int) $extra['order_id']] = $extra;
        }
    }
    $doc_order_ids = array_merge([(int) $order_id], array_keys($extra_orders));

    // 1) line items (vat_type=INC), using unified builder so totals match
    list($items, $items_sum) = hypay_build_ez_items($order_info, $list_products);
    foreach ($extra_orders as $extra) {
        list($extra_items, $extra_sum) = hypay_build_ez_items($extra, $list_products);
        $items     = array_merge($items, $extra_items);
        $items_sum = round($items_sum + $extra_sum, 2);
    }
    hypay_log($order_id, 'ezcount.line_items_mode', [
        'flow'  => $flow,
        'mode'  => $list_products ? 'list_products' : 'list_orders',
        'lines' => count($items),
    ]);

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
        'description'              => count($doc_order_ids) > 1
            ? 'Orders #' . implode(', #', $doc_order_ids)
            : 'Order #' . $order_id,
        'customer_name'            => $customer['name'],
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

    // Customer details, each one only when its setting asks for it. A VAT ID
    // makes the document out to the company rather than to a private buyer,
    // ASSOC_ONLY files it under the matching EzCount customer card instead of
    // creating a new one, and the CC list copies the document onward.
    if ($customer['vat'] !== '') {
        $payload['customer_crn'] = $customer['vat'];
    }
    if ($customer['assoc']) {
        $payload['customerAction'] = 'ASSOC_ONLY';
    }
    if (!empty($customer['cc_emails'])) {
        $payload['cc_emails'] = count($customer['cc_emails']) === 1
            ? reset($customer['cc_emails'])
            : $customer['cc_emails'];
    }

    hypay_log($order_id, 'ezcount.customer', [
        'customer_name'  => $customer['name'],
        'name_from'      => $customer['ezcount_name'] !== '' ? 'ezcount name' : 'profile name',
        'customer_crn'   => $customer['vat'],
        'customerAction' => $customer['assoc'] ? 'ASSOC_ONLY' : '(none)',
        'cc_emails'      => $customer['cc_emails'],
    ]);

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
        foreach ($doc_order_ids as $doc_order_id) {
            $order_doc = $doc_log;

            // The EzCount Doc Generator add-on keeps its Bank Transfers data in
            // the same row; carried over the way that add-on carries it over
            // itself, so issuing a document does not wipe the transfers out
            $existing_raw = db_get_field("SELECT data FROM ?:order_data WHERE order_id = ?i AND type = 'X'", $doc_order_id);
            $existing     = $existing_raw ? @unserialize($existing_raw) : false;
            if (is_array($existing) && !empty($existing['bank_transfers'])) {
                $order_doc['bank_transfers'] = $existing['bank_transfers'];
                $order_doc['subtype']        = 'bank_transfers';
            }

            db_query("REPLACE INTO ?:order_data (order_id, type, data) VALUES (?i, 'X', ?s)", $doc_order_id, serialize($order_doc));
        }
        hypay_log($order_id, 'ezcount.createDoc SUCCESS', $doc_log + ['orders' => $doc_order_ids]);

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
    // errMsg and the raw body are the gateway's own words, in whatever encoding
    // the route that produced them happened to use
    $err_msg = hypay_utf8_text($err_msg);

    $parts = [];
    if ($ccode !== '') {
        $meaning = fn_hypay_ccode_message($ccode);
        $parts[] = 'CCode=' . $ccode . ($meaning !== '' ? ' — ' . $meaning : '');
    }
    if ($err_msg !== '') {
        $parts[] = $err_msg;
    }
    if (!$parts) {
        $raw = hypay_utf8_text($raw);
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

    $extra = fn_hypay_clean_payment_info($extra);

    fn_update_order_payment_info($order_id, $extra);
    hypay_log($order_id, 'payment_info updated', $extra);

    return true;
}

/**
 * The J5 payment-info lines, written out in whatever language is current.
 *
 * @param array $tx a ?:hypay_transactions row
 *
 * @return array payment_info keys to overwrite, empty when there is nothing to say
 */
function fn_hypay_render_payment_info(array $tx)
{
    $authorized = number_format(round((float) ($tx['amount_authorized'] ?? 0), 2), 2, '.', '');

    // Composed on the way out like the lines below, which is what makes it
    // retroactive: an authorization taken before any of this was understood
    // still has Hyp's own identifier in the row, and still reads correctly on
    // the order page - as the placeholder the capture will send, with that
    // identifier named as what came back. Nothing is migrated.
    $common = ['personal_id' => hypay_personal_id_label($tx['personal_id'] ?? '')];

    switch ((string) ($tx['status'] ?? '')) {
        case 'authorized':
            return $common + [
                'reason_text' => '🟡 ' . __('hypay_j5_pi_authorized', ['[amount]' => $authorized]),
                'hypay_j5'    => __('hypay_j5_pi_hold_until', [
                    '[amount]' => $authorized,
                    '[date]'   => date('d.m.Y', (int) ($tx['expires_at'] ?? 0)),
                ]),
            ];

        case 'captured':
            $captured = number_format(round((float) ($tx['amount_captured'] ?? 0), 2), 2, '.', '');

            return $common + [
                'reason_text' => '🟢 ' . __('hypay_j5_pi_captured', ['[amount]' => $captured]),
                'hypay_j5'    => __('hypay_j5_pi_captured_on', [
                    '[amount]'   => $captured,
                    '[date]'     => date('d.m.Y H:i', (int) ($tx['captured_at'] ?? 0)),
                    '[payments]' => max(1, (int) ($tx['payments_captured'] ?? 1)),
                ]),
            ];

        case 'voided':
            $confirmed = ((string) ($tx['void_state'] ?? '') === 'confirmed');

            return $common + [
                'reason_text' => '⚪ ' . ($confirmed ? __('hypay_j5_pi_voided_confirmed') : __('hypay_j5_pi_voided')),
                'hypay_j5'    => __('hypay_j5_pi_voided_on', [
                    '[amount]' => $authorized,
                    '[date]'   => date('d.m.Y H:i', (int) ($tx['voided_at'] ?? 0)),
                ]),
            ];
    }

    // 'capturing' means a capture went out and the answer never came back. The
    // text stored at that moment is the only account of it, so leave it alone -
    // the ID line is not part of that account, and is worth reading precisely
    // when someone is working out what was sent.
    return $common;
}

/**
 * Is the J5 panel going to render further down this page?
 *
 * It prints the hold in full - amount through the store's price format, deadline
 * through its date format, with an expiry warning the flat line cannot show - so
 * on that page the "J5 hold" row is a second, worse copy of it. Mirrors the
 * condition at the top of hypay_j5_panel.tpl: the two have to agree, or a page
 * ends up showing the hold twice or not at all.
 *
 * @return bool
 */
function fn_hypay_j5_panel_is_rendered()
{
    return defined('AREA')
        && AREA === 'A'
        && Registry::get('runtime.controller') === 'orders'
        && Registry::get('runtime.mode') === 'details';
}

/**
 * Make an order's payment info printable: repair its encoding, and re-render the
 * J5 lines in the reader's language.
 *
 * fn_update_order_payment_info stores finished strings, and the language that
 * produced them is the one the *customer* was checking out in - so a shop whose
 * storefront is Hebrew hands its Russian-speaking admin Hebrew payment lines
 * forever. Everything those lines say is also in ?:hypay_transactions, so they
 * are composed again on the way out instead of being read back verbatim.
 *
 * Only the two keys this add-on writes are re-rendered, and the stored values
 * stay put: they remain the fallback for anything that reads payment_info
 * without going through fn_get_order_info.
 *
 * The encoding repair runs first and for every order, J4 included, because a
 * payment status that cannot be printed is not a J5 problem: see
 * hypay_utf8_text(). It touches nothing that is already valid UTF-8, so an order
 * paid through another processor entirely leaves this function unchanged.
 */
/**
 * Brand and card number as one line, in the place the first of them held.
 *
 * Stored separately because that is what Hyp reports and what fn_finish_payment
 * takes, and the order page prints one row for each - "Brand: MasterCard" above
 * "Credit card: 5956", two rows saying one thing between them, and neither
 * quite readable alone. This composes the line they add up to on the way out,
 * so nothing has to be migrated and an order paid before it reads the same as
 * one paid after.
 *
 * The special card type joins it when Hyp reported one: a Direct card is the
 * reason a capture can be refused, so it belongs beside the card rather than
 * further down the page.
 *
 * @param array $tx the J5 transaction, when the order has one
 */
function fn_hypay_merge_card_line(array $info, $order_id, array $tx = [])
{
    $brand = trim((string) ($info['brand'] ?? ''));
    $last4 = preg_replace('/\D+/', '', (string) ($info['card_number'] ?? ''));

    if ($brand === '' && $last4 === '') {
        return $info;
    }

    // brand / card_number are ordinary payment_info keys any processor may
    // write, so the order has to be one of ours before they are rewritten
    if (!fn_check_payment_script('hypay.php', $order_id)) {
        return $info;
    }

    $line = trim($brand . ($last4 !== '' ? ' ****' . $last4 : ''));

    $sp_type = trim((string) ($tx['sp_type'] ?? ''));
    if ($sp_type !== '') {
        $line .= ' — ' . $sp_type;
    }

    $merged = [];
    $placed = false;
    foreach ($info as $key => $value) {
        if ($key === 'brand' || $key === 'card_number') {
            if (!$placed) {
                $merged['hypay_card'] = $line;
                $placed = true;
            }
            continue;
        }
        $merged[$key] = $value;
    }

    return $merged;
}

function fn_hypay_localize_payment_info(&$order)
{
    if (!is_array($order)
        || empty($order['order_id'])
        || empty($order['payment_info'])
        || !is_array($order['payment_info'])
    ) {
        return false;
    }

    $before = $order['payment_info'];

    // Orders paid through a wallet before this was fixed have raw windows-1255
    // bytes sitting in their stored payment status, and print a blank line for
    // it. Nothing is migrated: the text is repaired on the way out, exactly as
    // the J5 lines below are re-rendered rather than read back verbatim.
    $order['payment_info'] = fn_hypay_clean_payment_info($order['payment_info']);

    // hypay_j5 is written for J5 orders only, so this skips the query for
    // regular charges without having to ask the database first
    $tx = [];
    if (isset($order['payment_info']['hypay_j5'])) {
        $tx = fn_hypay_get_transaction($order['order_id']);

        if (!empty($tx)) {
            $rendered = fn_hypay_render_payment_info($tx);
            if (!empty($rendered)) {
                $order['payment_info'] = array_merge($order['payment_info'], $rendered);
            }

            // Dropped after the merge, not before, so it also covers 'capturing',
            // where there is nothing to re-render but the panel still prints the
            // hold.
            if (fn_hypay_j5_panel_is_rendered()) {
                unset($order['payment_info']['hypay_j5']);
            }
        }
    }

    // "Paid by payment link on ..." - composed again for the same reason as the
    // J5 lines: the stored text is in whichever language was current when the
    // payment came in, the row it is made from is not
    if (isset($order['payment_info']['hypay_link'])) {
        $link = fn_hypay_link_get_latest($order['order_id']);
        if (!empty($link) && $link['status'] === 'paid') {
            $order['payment_info']['hypay_link'] = fn_hypay_link_paid_label($link);
        }
    }

    // last, so it sees whatever the J5 branch merged in, and so the special
    // card type it prints comes from the transaction that branch already read
    $order['payment_info'] = fn_hypay_merge_card_line(
        $order['payment_info'],
        $order['order_id'],
        is_array($tx) ? $tx : []
    );

    return ($order['payment_info'] !== $before);
}

/**
 * Best-effort pass for everywhere an order is read that is not the details page
 * - order lists, printable documents, notifications.
 *
 * It cannot be the only pass: whether payment_info is already on $order when
 * this fires is not something the add-on gets to decide, and on the details page
 * it demonstrably was not. The controller below covers that page for certain.
 */
function fn_hypay_get_order_info_post(&$order, $additional_data)
{
    fn_hypay_localize_payment_info($order);
}

/* ============================================================================
 * Additional order status (eCom Labs "Additional Order Statuses" add-on)
 * ==========================================================================*/

/**
 * Is there an add-on to write ?:orders.additional_status for?
 *
 * The column and the status type both come from the eCom Labs add-on: its
 * init.php defines STATUSES_ORDER_ADDITIONAL, and CS-Cart only loads init.php
 * of *active* add-ons. The ?:addons row is the authoritative answer - the
 * defined() check just keeps the constant safe to reference afterwards.
 *
 * @return bool
 */
function fn_hypay_additional_statuses_available()
{
    static $available = null;

    if ($available === null) {
        $available = defined('STATUSES_ORDER_ADDITIONAL')
            && db_get_field('SELECT status FROM ?:addons WHERE addon = ?s', HYPAY_ADDITIONAL_STATUSES_ADDON) === 'A';
    }

    return $available;
}

/**
 * The additional statuses an order can be marked with.
 *
 * @return array [status code => description], empty when the add-on is off
 */
function fn_hypay_get_additional_statuses($lang_code = CART_LANGUAGE)
{
    if (!fn_hypay_additional_statuses_available()) {
        return [];
    }

    return (array) fn_get_simple_statuses(STATUSES_ORDER_ADDITIONAL, false, false, $lang_code);
}

/**
 * Mark an order with an additional status.
 *
 * Written straight to the column the add-on owns: it keeps no history of its
 * own, so there is nothing else to keep in step.
 *
 * @return bool whether the order was actually marked
 */
function fn_hypay_set_additional_status($order_id, $status)
{
    $order_id = (int) $order_id;
    $status   = trim((string) $status);

    if ($status === '') {
        return false;
    }

    if (!fn_hypay_additional_statuses_available()) {
        hypay_log($order_id, 'additional_status skipped (add-on not active)', ['status' => $status]);

        return false;
    }

    // the status may have been deleted long after the payment method was
    // configured, and the column is a char(1) that would take the letter anyway
    $statuses = fn_hypay_get_additional_statuses();
    if (!isset($statuses[$status])) {
        hypay_log($order_id, 'additional_status skipped (no such status)', [
            'status' => $status,
            'known'  => array_keys($statuses),
        ]);

        return false;
    }

    db_query('UPDATE ?:orders SET additional_status = ?s WHERE order_id = ?i', $status, $order_id);
    hypay_log($order_id, 'additional_status set', [
        'status'      => $status,
        'description' => $statuses[$status],
    ]);

    return true;
}

/**
 * Move an order to another status without telling anybody about it.
 *
 * The J5 buttons change the status as a side effect of the money moving
 * (captured -> paid, cancelled -> the void status), and that is bookkeeping,
 * not news: the customer has already been told what happened by the payment
 * itself, and the admin doing the clicking is looking straight at the result.
 * So every notification receiver is switched off explicitly.
 *
 * Both spellings are passed on purpose. fn_get_notification_rules() reads
 * 'notify_user' / 'notify_department' / 'notify_vendor' when they are there and
 * the receiver codes ('C', 'A', 'V') otherwise, and which one it prefers has
 * moved between CS-Cart versions; with both set to false the answer is the same
 * either way.
 *
 * @param int    $order_id  order to move
 * @param string $status_to one-letter status code
 *
 * @return void
 */
function fn_hypay_change_order_status_silently($order_id, $status_to)
{
    $order_id  = (int) $order_id;
    $status_to = (string) $status_to;

    $force_notification = [
        'C' => false, // customer
        'A' => false, // order department / admin
        'V' => false, // vendor
        'notify_user'       => false,
        'notify_department' => false,
        'notify_vendor'     => false,
    ];

    hypay_log($order_id, 'order status changed silently', ['status' => $status_to]);

    fn_change_order_status($order_id, $status_to, '', $force_notification);
}

/* ============================================================================
 * J5 capture / void
 * ==========================================================================*/

/**
 * Step 3: capture (charge) a J5 authorization.
 *
 * The amount is always the current order total: to charge less, the order has
 * to be edited first, otherwise the EzCount document would not match the money
 * actually taken. Capturing more than authorized is rejected by design.
 *
 * @param int         $order_id
 * @param float|null  $amount      defaults to the order total
 * @param int|null    $payments    defaults to the number the customer picked
 * @param string|null $personal_id cardholder's Israeli ID, typed on the order
 *                                 page when the payment page never asked the
 *                                 customer for one. Remembered on the
 *                                 authorization, so a further attempt after a
 *                                 refusal does not need it retyped.
 *
 * @return bool
 */
function fn_hypay_capture_j5($order_id, $amount = null, $payments = null, $personal_id = null, $acode = null)
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

    // An immediate-debit (Direct) card is not captured, it is charged again:
    // the money comes from a fresh transaction on the saved card, because Shva
    // refuses a capture that carries the hold's authorization number. Nothing
    // ties that charge to the hold and nothing bounds it by it, so the amount
    // is held to the one the customer approved, exactly. A smaller charge would
    // be a second sale for a sum nobody agreed to, and to take less than the
    // hold the order has to be settled with Hyp rather than edited here.
    if (hypay_is_immediate_card($tx['sp_type'] ?? '') && abs($amount - $authorized) > 0.009) {
        fn_set_notification('E', __('error'), __('hypay_j5_error_immediate_amount_locked', [
            '[amount]' => number_format($authorized, 2, '.', ''),
        ]));
        hypay_log($order_id, 'j5.capture REFUSED: immediate-debit card, amount differs from the hold', [
            'authorized' => $authorized,
            'requested'  => $amount,
            'spType'     => (string) ($tx['sp_type'] ?? ''),
        ]);

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

    // A new authorization number the merchant obtained from the credit company
    // by hand, after a previous capture was refused. It replaces the one that
    // came back with the hold: the old number is what Shva turned down, and
    // re-sending it would only be refused again. The order page offers the field
    // to type it into only once a capture has actually failed - nothing to
    // re-authorize before then - but it is accepted here whenever it is supplied
    // and cleans down to a real number. Garbage is refused loudly rather than
    // sent, so a mistyped number is not mistaken for the credit company's answer.
    if ($acode !== null && trim((string) $acode) !== '') {
        $new_acode = fn_hypay_clean_acode($acode);
        if ($new_acode === '') {
            fn_set_notification('E', __('error'), __('hypay_j5_error_bad_acode'));
            hypay_log($order_id, 'j5.capture: the authorization number typed on the order page is not usable, ignored');

            return false;
        }
        if ($new_acode !== (string) $tx['acode']) {
            fn_hypay_update_transaction($tx['transaction_id'], ['acode' => $new_acode]);
            $tx['acode'] = $new_acode;
            hypay_log($order_id, 'j5.capture: authorization number replaced by hand from the order page');
        }
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

    // An ID typed on the order page wins over whatever came back with the
    // authorization: it is the only thing that can rescue a hold the payment
    // page never collected an ID for. It is held to the same format as any
    // other, though - a number that fails the check digit would be refused by
    // the issuer just as surely as Hyp's own identifier was.
    if ($personal_id !== null && trim((string) $personal_id) !== '') {
        if (hypay_is_israeli_id($personal_id)) {
            $typed = hypay_personal_id_digits($personal_id);
            if ($typed !== (string) $tx['personal_id']) {
                fn_hypay_update_transaction($tx['transaction_id'], ['personal_id' => $typed]);
                $tx['personal_id'] = $typed;
                hypay_log($order_id, 'j5.capture personal id supplied on the order page');
            }
        } else {
            // said out loud rather than treated as a refusal: the capture still
            // goes out, with the placeholder, which is the better of the two
            // numbers Shva could be given
            fn_set_notification('W', __('warning'), __('hypay_j5_warning_bad_personal_id'));
            hypay_log($order_id, 'j5.capture: the ID typed on the order page is not a valid one, ignored');
        }
    }

    // The last gate before the money moves: what Shva is told the cardholder's
    // ID is. Nothing but a real ת.ז - nine digits, left-padded, with a matching
    // check digit - passes it.
    //
    // A Direct (debit) card is what makes this matter. Its issuer checks the ID
    // against the account the card is drawn on and refuses the charge with
    // CCode=6 when it does not match, where an ordinary credit card lets a wrong
    // number through unnoticed. And a wrong number is exactly what was being
    // sent: Hyp's own ten-digit identifier, echoed back in UserId when the
    // payment page never asked the customer for an ID, and kept by every
    // authorization made before this was understood. It fails the check here on
    // the way out, so those holds are captured correctly without the stored row
    // having to be corrected first.
    //
    // What goes instead is the documented 000000000, which says the ID was not
    // supplied - the terms the authorization itself was approved on when the
    // payment page collected none.
    $user_id = hypay_clean_personal_id($tx['personal_id']);
    $stored_id = (string) $tx['personal_id'];
    if ($user_id === HYPAY_PERSONAL_ID_UNKNOWN && $stored_id !== '' && $stored_id !== HYPAY_PERSONAL_ID_UNKNOWN) {
        hypay_log($order_id, 'j5.capture: the stored ID is not a valid one, sending the placeholder', [
            'stored' => $stored_id,
        ]);
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
    //
    // Everything below the linkage is an ordinary token charge, which is also
    // the fallback: strip AuthNum and the three inputObj fields and the same
    // request becomes the "charge a saved token" call, the one way to take the
    // money from a card that will not accept a pre-obtained authorization.
    $params = [
        'action'                          => 'soft',
        'Masof'                           => trim((string) ($pp['masof'] ?? '')),
        'PassP'                           => trim((string) ($pp['passp'] ?? '')),
        'UserId'                          => $user_id,
        // both halves of the name, exactly as the authorization was made: with
        // ClientName alone the acquirer shows the charge under the first name
        // only, which does not match the CCode=700 row next to it
        'ClientName'                      => (string) ($tx['client_name']  ?: ($order_info['firstname'] ?? '')),
        'ClientLName'                     => (string) ($tx['client_lname'] ?? '') ?: (string) ($order_info['lastname'] ?? ''),
        'Token'                           => 'True',
        'CC'                              => $token,
        'Tmonth'                          => $expiry['month'],
        'Tyear'                           => $expiry['year'],
        // same representation the payment page request uses (Amount=150, not 150.00)
        'Amount'                          => round($amount, 2),
        'Info'                            => hypay_build_info($order_id, $pp),
        'Coin'                            => max(1, (int) $tx['coin']),
        // the same encoding flags the payment page request was signed with, so
        // a Hebrew name is not mangled and errMsg comes back readable
        'UTF8'                            => hypay_bool($pp['utf8']    ?? 'Y'),
        'UTF8out'                         => hypay_bool($pp['utf8out'] ?? 'Y'),
    ];
    if ($payments > 1) {
        // charge in the same number of instalments the customer agreed to
        $params['Tash'] = $payments;
        if (isset($pp['tashtype']) && $pp['tashtype'] !== '') {
            $params['tashType'] = (int) $pp['tashtype'];
        }
    }

    // what makes it a capture rather than a fresh charge
    $linkage = [
        'AuthNum'                          => (string) $tx['acode'],
        // the original authorization, in currency subunits (agorot)
        'inputObj.originalAmount'          => (int) round($authorized * 100),
        'inputObj.originalUid'             => (string) $tx['uid'],
        // documented as a constant for every capture, not a value to choose
        'inputObj.authorizationCodeManpik' => 7,
    ];

    // the authorization this capture points back at - the three values Shva
    // matches against the held transaction, spelled out so a refusal (CCode=4)
    // can be compared with the CCode=700 row in the Hyp control panel
    hypay_log($order_id, 'j5.capture references authorization', [
        'hyp_id'                  => $tx['hyp_id'],
        'AuthNum'                 => $tx['acode'],
        'inputObj.originalUid'    => $tx['uid'],
        'UserId'                  => $user_id,
        'inputObj.originalAmount' => $linkage['inputObj.originalAmount'],
        'Amount'                  => $params['Amount'],
        'Tmonth/Tyear'            => $expiry['month'] . '/' . $expiry['year'],
        'Tokef'                   => $tokef,
    ]);

    $result   = fn_hypay_api_request($order_id, array_merge($params, $linkage), 'j5.capture');
    $response = $result['params'];
    $ccode    = isset($response['CCode']) ? (string) $response['CCode'] : '';

    // Refused because the card will not carry a pre-obtained authorization.
    //
    // An immediate-debit (Direct) card is the one this happens on: Shva reads
    // the AuthNum the capture attaches as an approval obtained by hand and
    // refuses to apply it, where an ordinary credit card takes the same request
    // without comment. The three codes below all say that, in the acquirer's
    // words rather than the card's:
    //
    //   512  cannot enter an approval received from voice response
    //   455  cannot perform a forced debit when an approval request is required
    //   445  in an immediate debit card, only immediate debit credit is allowed
    //
    // The money can still be taken - as an ordinary token charge, the request
    // already built above without the linkage. That is a new transaction and a
    // fresh authorization, so it is only attempted on a definite refusal: an
    // unreadable answer is handled below, where nothing is retried and the row
    // stays locked.
    $fallback_used = false;
    $first_refusal = '';
    if ($ccode !== '' && $ccode !== '0'
        && in_array($ccode, ['512', '455', '445'], true)
        && ($pp['j5_capture_fallback'] ?? 'Y') !== 'N'
    ) {
        $refusal = fn_hypay_format_error($ccode, $response['errMsg'] ?? '', $result['raw']);
        hypay_log($order_id, 'j5.capture refused as a forced transaction, charging the token instead', [
            'refusal' => $refusal,
            'spType'  => (string) ($tx['sp_type'] ?? ''),
        ]);

        $result   = fn_hypay_api_request($order_id, $params, 'j5.capture.fallback');
        $response = $result['params'];
        $ccode    = isset($response['CCode']) ? (string) $response['CCode'] : '';

        $fallback_used = true;
        $first_refusal = $refusal;
    }

    if ($ccode === '') {
        // No readable answer: the charge may or may not have happened. The row is
        // deliberately left in 'capturing' so nobody can charge the customer twice
        // before the transaction has been checked in the Hyp control panel.
        $unanswered = $fallback_used
            ? 'capture fallback charge: no response from Hyp (refused capture: ' . $first_refusal . ')'
            : 'capture: no response from Hyp';
        fn_hypay_update_transaction($tx['transaction_id'], ['last_error' => $unanswered]);
        fn_set_notification('E', __('error'), __('hypay_j5_capture_unknown'));
        fn_hypay_order_note($order_id, __('hypay_j5_capture_unknown'));

        return false;
    }

    if ($ccode !== '0') {
        $error = fn_hypay_format_error($ccode, $response['errMsg'] ?? '', $result['raw']);
        // both refusals, in the order they happened: the second one on its own
        // would not say why an ordinary charge was attempted at all
        if ($fallback_used) {
            $error = $first_refusal . ' | ' . __('hypay_j5_capture_fallback_also_refused') . ' ' . $error;
        }
        fn_hypay_update_transaction($tx['transaction_id'], ['status' => 'authorized', 'last_error' => 'capture: ' . $error]);
        fn_set_notification('E', __('error'), __('hypay_j5_capture_failed') . ' ' . $error);
        fn_hypay_order_note($order_id, __('hypay_j5_capture_failed') . ' ' . $error);

        return false;
    }

    $capture_id    = (string) ($response['Id'] ?? '');
    $capture_acode = (string) ($response['ACode'] ?? $tx['acode']);

    // The money is recorded before anything else is attempted with it. A second
    // call follows on the fallback path, and it must not be able to leave a
    // charged order looking uncharged if it hangs or throws.
    fn_hypay_update_transaction($tx['transaction_id'], [
        'status'            => 'captured',
        'payments_captured' => $payments,
        'amount_captured' => $amount,
        'capture_hyp_id'  => $capture_id,
        'capture_acode'   => $capture_acode,
        'captured_at'     => TIME,
        // set as soon as the hold's fate is known; on the fallback path it also
        // marks the row as charged by a separate transaction
        'hold_release_state' => $fallback_used ? 'pending' : '',
        // the refused capture is kept: it is why this order was charged with a
        // second transaction rather than the hold, and the panel prints it
        'last_error'      => $fallback_used ? $first_refusal : '',
    ]);

    // The fallback charge does not consume the hold - it is a transaction of
    // its own, and the authorization is still sitting on the card beside it.
    // Ask Hyp to reverse it so the customer is not looking at both at once.
    //
    // It usually cannot: CancelTrans only reaches a transaction that has not
    // been transmitted yet, and a hold is captured days after it was taken. So
    // the outcome is recorded rather than acted on, and the panel says which of
    // the two happened. Either way the hold is never captured again, and the
    // issuer releases it when the authorization window runs out.
    $release_state = '';
    if ($fallback_used) {
        if ((string) $tx['hyp_id'] !== '') {
            list($release_state) = fn_hypay_cancel_trans($order_id, $pp, $tx['hyp_id']);
        } else {
            $release_state = 'not_attempted';
        }
        hypay_log($order_id, 'j5.capture fallback: hold release attempted', [
            'hyp_id' => $tx['hyp_id'],
            'state'  => $release_state,
        ]);
        fn_hypay_update_transaction($tx['transaction_id'], ['hold_release_state' => $release_state]);
    }

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
    fn_hypay_change_order_status_silently($order_id, $captured_status);

    if (!empty($pp['j5_captured_additional_status'])) {
        fn_hypay_set_additional_status($order_id, $pp['j5_captured_additional_status']);
    }

    fn_hypay_order_note($order_id, __('hypay_j5_note_captured', [
        '[amount]' => number_format($amount, 2, '.', ''),
        '[id]'     => $capture_id,
    ]));

    hypay_log($order_id, 'j5.capture SUCCESS', [
        'amount'        => $amount,
        'capture_id'    => $capture_id,
        'fallback'      => $fallback_used,
        'hold_released' => $release_state,
    ]);
    fn_set_notification('N', __('notice'), __('hypay_j5_capture_ok', ['[amount]' => number_format($amount, 2, '.', '')]));

    if ($fallback_used) {
        $fallback_note = ($release_state === 'confirmed')
            ? __('hypay_j5_note_fallback_released')
            : __('hypay_j5_note_fallback_hold_stays', ['[days]' => fn_hypay_hold_days($pp)]);
        fn_hypay_order_note($order_id, $fallback_note);
        fn_set_notification('W', __('warning'), $fallback_note);
    }

    // the document is issued now, for the amount that was actually charged
    if (($pp['ez_mode'] ?? 'none') === 'direct') {
        $order_info = fn_get_order_info($order_id); // re-read: the status has changed
        fn_hypay_create_ezcount_doc($order_id, $order_info, $pp, [
            'transaction_id' => $capture_id,
            'brand'          => $tx['brand'],
            'last4'          => $tx['last4'],
            'payments'       => $payments,
            'amount'         => $amount,
            'flow'           => 'j5',
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
    //
    // What terminal 0010334524 does, measured rather than guessed:
    //
    //   1 payment   -> CCode=0,   ReversalStatus=777, the hold is released
    //   2 payments  -> CCode=920, ReversalStatus=404
    //   3 payments  -> CCode=920, ReversalStatus=404
    //   3 payments, request repeating the payment count       -> unchanged
    //   3 payments, request repeating the whole schedule
    //     (Payments, Tash, noKPayments, nFirstPayment, firstPayment) -> unchanged
    //
    // So a hold taken in instalments is not reversible through CancelTrans
    // here, and no combination of payment fields changes that: ReversalStatus
    // decodes as "Number of payments field was not entered" in the CCode table,
    // but supplying those fields makes no difference, so the reading is a red
    // herring. Only the documented call is made; the rest is a question for
    // Hyp.
    $cancel_state = 'not_attempted';
    $hyp_detail   = '';
    $payments     = max(1, (int) $tx['payments']);

    if ($tx['hyp_id'] !== '') {
        list($cancel_state, $hyp_detail) = fn_hypay_cancel_trans($order_id, $pp, $tx['hyp_id']);
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
    fn_hypay_change_order_status_silently($order_id, $void_status);

    if ($confirmed) {
        $note   = __('hypay_j5_note_voided_confirmed');
        $notice = __('hypay_j5_void_ok_confirmed');
    } elseif ($cancel_state === 'not_cancellable' && $payments > 1) {
        $note   = __('hypay_j5_note_voided_instalments');
        $notice = __('hypay_j5_void_ok_instalments', ['[days]' => fn_hypay_hold_days($pp)]);
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

    // How far the order has drifted from the hold, for the panel to print instead
    // of leaving it to be worked out from two rows. Same 0.009 tolerance the
    // warnings use - without it a half-agora rounding artefact would be reported
    // in red as a real difference, with no warning beside it and capture still
    // allowed. Zero outside 'authorized': once a capture has gone out, the
    // comparison is not something anyone can act on.
    $delta = ($tx['status'] === 'authorized' && abs($order_total - $authorized) > 0.009)
        ? round($order_total - $authorized, 2)
        : 0.0;

    return [
        'order_id'          => $order_id,
        'status'            => $tx['status'],
        'hyp_id'            => $tx['hyp_id'],
        'acode'             => $tx['acode'],
        // shown in the panel so the values the capture sends back to Shva can be
        // compared with the CCode=700 row in the Hyp control panel
        'uid'               => (string) $tx['uid'],
        // the UID only matters while someone is diagnosing a capture, which is
        // also when debug mode is on; the rest of the time it is a long opaque
        // string taking up a row
        'debug'             => (!empty($pp['debug_mode']) && $pp['debug_mode'] === 'Y'),
        'has_token'         => ($tx['card_token'] !== ''),
        // The card itself is printed once, in the Payment information block above
        // - see fn_hypay_merge_card_line(). What the panel keeps is the one fact
        // that changes what its buttons can do: on an immediate-debit card the
        // capture is refused as a forced transaction and the money is taken by
        // an ordinary charge instead, for the approved amount and no other.
        'is_immediate'      => hypay_is_immediate_card($tx['sp_type'] ?? ''),
        // set only when the money came from a separate charge because the
        // capture was refused; says whether the hold was reversed with it
        'hold_release_state' => (string) ($tx['hold_release_state'] ?? ''),
        'captured_by_fallback' => ($tx['status'] === 'captured' && (string) ($tx['hold_release_state'] ?? '') !== ''),
        // empty when the payment page never collected one: the panel offers a
        // field to type it into, because a Direct card will not be charged
        // without it once its issuer has asked for it
        'personal_id'       => hypay_is_israeli_id($tx['personal_id'])
            ? hypay_personal_id_digits($tx['personal_id'])
            : '',
        // Shown only when there is something to do about it. A hold whose ID is
        // a real one needs no field: it is already printed in the Payment
        // information block, and typing it again cannot improve it. What brings
        // the field back is an ID the capture cannot use - missing, or Hyp's own
        // identifier, or a number whose check digit does not hold - and a
        // capture already refused over the ID.
        'personal_id_needed' => ($tx['status'] === 'authorized' && !hypay_is_israeli_id($tx['personal_id'])),
        // the last capture was refused over the ID (or the CVV, which a token
        // charge does not send) - the one refusal a typed-in ID can undo
        'personal_id_asked' => ($tx['status'] === 'authorized'
            && (strpos((string) $tx['last_error'], 'CCode=6 ') !== false
                || strpos((string) $tx['last_error'], 'CCode=26 ') !== false)),
        // A capture has already gone out and been refused, so the hold is back
        // to 'authorized' with the refusal on the row (every capture error is
        // stored prefixed with "capture:"). Only then does the order page offer
        // a field for a fresh authorization number: before the first attempt
        // there is nothing to re-authorize, and the one that came back with the
        // hold is the number to use.
        'capture_failed' => ($tx['status'] === 'authorized'
            && strncmp((string) $tx['last_error'], 'capture:', 8) === 0),
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
        // on an immediate-debit card the charge is a fresh sale rather than a
        // capture, so it is pinned to the amount the customer approved
        'amount_locked'     => ($tx['status'] === 'authorized' && hypay_is_immediate_card($tx['sp_type'] ?? '')),
        'can_capture'       => ($tx['status'] === 'authorized' && $order_total > 0 && $order_total <= $authorized + 0.009
            && (!hypay_is_immediate_card($tx['sp_type'] ?? '') || abs($order_total - $authorized) <= 0.009)),
        'can_void'          => ($tx['status'] === 'authorized'),
        'amount_mismatch'   => ($tx['status'] === 'authorized' && $order_total > $authorized + 0.009),
        'amount_delta'      => $delta,
        'amount_delta_abs'  => abs($delta),
        'hold_days'         => fn_hypay_hold_days($pp),
        'void_state'        => (string) ($tx['void_state'] ?? ''),
        'last_error'        => (string) $tx['last_error'],
    ];
}

/* ============================================================================
 * Payment links (action=payRequest)
 *
 * Per https://developers.hyp.co.il/pay/common-use-cases/send-payment-links
 *   CREATE -> payRequestId + paymentURL, Hyp sends the link by e-mail / SMS
 *   LIST   -> the 500 most recent links with their status (3 = paid)
 *   DELETE -> cancels a link that has not been paid (CCode=995 if it has)
 *
 * A link is paid on Hyp's own payment page, so the result reaches the store
 * two ways: the customer's return to payment_notification (the same one a
 * checkout payment takes, card details included), and - for when that return
 * never arrives - the LIST lookup made from the order page. Whichever comes
 * first moves the order; the other one only fills in what it knows.
 * ==========================================================================*/

/** a link row by its id */
function fn_hypay_link_get($link_id)
{
    fn_hypay_ensure_schema();

    $row = db_get_row("SELECT * FROM ?:hypay_payment_links WHERE link_id = ?i", (int) $link_id);

    return is_array($row) ? $row : [];
}

/**
 * The most recent link that pays for an order, whatever became of it - the
 * order may be the one the link was created from or one included in it.
 */
function fn_hypay_link_get_latest($order_id)
{
    fn_hypay_ensure_schema();

    $row = db_get_row(
        "SELECT l.* FROM ?:hypay_payment_links AS l"
        . " INNER JOIN ?:hypay_payment_link_orders AS lo ON lo.link_id = l.link_id"
        . " WHERE lo.order_id = ?i ORDER BY l.link_id DESC LIMIT 1",
        (int) $order_id
    );

    return is_array($row) ? $row : [];
}

/** the link the customer can still pay the order with, if there is one */
function fn_hypay_link_get_active($order_id)
{
    fn_hypay_ensure_schema();

    $row = db_get_row(
        "SELECT l.* FROM ?:hypay_payment_links AS l"
        . " INNER JOIN ?:hypay_payment_link_orders AS lo ON lo.link_id = l.link_id"
        . " WHERE lo.order_id = ?i AND l.status = 'active' ORDER BY l.link_id DESC LIMIT 1",
        (int) $order_id
    );

    return is_array($row) ? $row : [];
}

/**
 * The orders a link pays for, the one it was created from first.
 *
 * @return int[]
 */
function fn_hypay_link_order_ids(array $link)
{
    if (empty($link['link_id'])) {
        return [];
    }

    fn_hypay_ensure_schema();

    $ids = array_map('intval', db_get_fields(
        "SELECT order_id FROM ?:hypay_payment_link_orders WHERE link_id = ?i ORDER BY order_id",
        (int) $link['link_id']
    ));

    $primary = (int) ($link['order_id'] ?? 0);
    if ($primary > 0) {
        $ids = array_values(array_unique(array_merge([$primary], $ids)));
    }

    return $ids;
}

/**
 * "Paid by payment link on 29.09.2026 14:05", in the reader's language - with
 * the orders the payment covered when there were several.
 */
function fn_hypay_link_paid_label(array $link)
{
    $label = __('hypay_link_pi_paid', ['[date]' => date('d.m.Y H:i', (int) ($link['paid_at'] ?? 0))]);

    $ids = fn_hypay_link_order_ids($link);
    if (count($ids) > 1) {
        $label .= ' (' . __('hypay_link_pi_orders', ['[orders]' => '#' . implode(', #', $ids)]) . ')';
    }

    return $label;
}

/**
 * The order statuses whose orders the payment link panel offers to include,
 * per the payment method settings.
 *
 * @return string[] status codes, empty when the setting is empty
 */
function fn_hypay_link_order_statuses($pp)
{
    // untyped: the settings template hands this processor_params of a method
    // that has none saved yet
    $pp       = (array) $pp;
    $statuses = $pp['link_order_statuses'] ?? [];
    if (!is_array($statuses)) {
        $statuses = explode(',', (string) $statuses);
    }

    return array_values(array_unique(array_filter(array_map('trim', array_map('strval', $statuses)), 'strlen')));
}

/** how many days a link stays payable, per the settings; 0 for no limit */
function fn_hypay_link_lifetime_days($pp)
{
    return max(0, (int) (((array) $pp)['link_lifetime_days'] ?? 0));
}

/** when the link runs out, 0 when it never does */
function fn_hypay_link_expires_at(array $link, $pp)
{
    $days = fn_hypay_link_lifetime_days($pp);
    if ($days <= 0 || empty($link['created_at'])) {
        return 0;
    }

    return (int) $link['created_at'] + $days * 86400;
}

/**
 * Mark every order a link pays for with the additional status one of the
 * payment link settings names - link_created_additional_status or
 * link_cancelled_additional_status. Nothing when the setting is empty.
 */
function fn_hypay_link_set_orders_additional_status(array $link, array $pp, $setting)
{
    $status = trim((string) ($pp[$setting] ?? ''));
    if ($status === '') {
        return;
    }

    foreach (fn_hypay_link_order_ids($link) as $oid) {
        fn_hypay_set_additional_status($oid, $status);
    }
}

/**
 * The order's active link has outlived the lifetime the settings give it:
 * withdraw it at Hyp and mark it expired, so the window says so and offers a
 * new one.
 *
 * A link Hyp sent is looked up first - paid shortly before it ran out, it is
 * paid, not expired. A signed page cannot be withdrawn at Hyp; like a
 * cancelled one, a payment made on it anyway is still recorded.
 *
 * @return bool true when the link was marked expired just now
 */
function fn_hypay_link_expire_if_due($order_id)
{
    $order_id = (int) $order_id;
    $link     = fn_hypay_link_get_active($order_id);
    if (empty($link)) {
        return false;
    }

    $order_info = (array) fn_get_order_info($order_id);
    $pp         = fn_hypay_link_processor_params($order_info, $link);
    $expires_at = fn_hypay_link_expires_at($link, $pp);
    if ($expires_at <= 0 || $expires_at > TIME) {
        return false;
    }

    $GLOBALS['HYPAY_DEBUG'] = (!empty($pp['debug_mode']) && $pp['debug_mode'] === 'Y');
    $note = '';

    if (($link['kind'] ?? 'request') !== 'sign') {
        // Hyp is asked at most once a minute, as on page open
        if ((int) $link['checked_at'] > TIME - 60) {
            return false;
        }

        $state = fn_hypay_link_check($order_id, true, 15);
        if ($state === 'paid' || $state === 'cancelled') {
            return false;
        }

        $credentials = fn_hypay_link_credentials($pp);
        $ccode       = '';
        if ($credentials) {
            $result = fn_hypay_link_api_request($order_id, $credentials + [
                'action'     => 'payRequest',
                'iCommand'   => 'DELETE',
                'PayRequest' => $link['pay_request_id'],
            ], 'link.expire', 15);
            $ccode = trim((string) ($result['params']['CCode'] ?? ''));
        }

        if ($ccode === '995') {
            // Hyp says it has been paid, and LIST did not show it: the link
            // stays as it is until the payment is recorded
            db_query(
                "UPDATE ?:hypay_payment_links SET last_error = ?s WHERE link_id = ?i",
                fn_hypay_format_error($ccode),
                $link['link_id']
            );

            return false;
        }

        if ($ccode !== '0' && $ccode !== '250') {
            $note = __('hypay_link_expired_not_withdrawn');
        }
    }

    $expired = db_query(
        "UPDATE ?:hypay_payment_links SET status = 'expired', cancelled_at = ?i, last_error = ?s WHERE link_id = ?i AND status = 'active'",
        $expires_at,
        $note,
        $link['link_id']
    );
    if (!$expired) {
        return false;
    }

    hypay_log($order_id, 'link expired', [
        'link_id'    => $link['link_id'],
        'kind'       => $link['kind'] ?? 'request',
        'expires_at' => date('c', $expires_at),
        'withdrawn'  => ($link['kind'] ?? 'request') !== 'sign' && $note === '',
    ]);
    fn_hypay_link_set_orders_additional_status($link, $pp, 'link_cancelled_additional_status');

    return true;
}

/** does this order belong to the same customer as that one? */
function fn_hypay_link_same_customer(array $order, array $other)
{
    $user_id = (int) ($order['user_id'] ?? 0);
    if ($user_id > 0) {
        return (int) ($other['user_id'] ?? 0) === $user_id;
    }

    // a guest is known by the e-mail address alone
    $email = strtolower(trim((string) ($order['email'] ?? '')));

    return (int) ($other['user_id'] ?? 0) === 0
        && $email !== ''
        && strtolower(trim((string) ($other['email'] ?? ''))) === $email;
}

/**
 * Can this order go into a new payment link?
 *
 * Not when it has a document attached - it has been billed - and not when a
 * link already pays for it, or has paid for it.
 */
function fn_hypay_link_order_is_free($order_id)
{
    if (fn_hypay_order_has_document($order_id)) {
        return false;
    }

    $link = fn_hypay_link_get_latest($order_id);

    return empty($link) || !in_array($link['status'], ['active', 'paid'], true);
}

/**
 * The customer's other orders with the statuses chosen in the settings - all
 * of them, so the window can show what each one already has: a document, a
 * link. Only the free ones can be ticked; see fn_hypay_link_order_row().
 *
 * @return array rows of order_id, timestamp, status, total
 */
function fn_hypay_link_candidate_orders(array $order_info, array $pp, $limit = 100)
{
    $statuses = fn_hypay_link_order_statuses($pp);
    if (empty($statuses)) {
        return [];
    }

    $user_id = (int) ($order_info['user_id'] ?? 0);
    $email   = trim((string) ($order_info['email'] ?? ''));
    if ($user_id <= 0 && $email === '') {
        return [];
    }

    $customer = $user_id > 0
        ? db_quote("user_id = ?i", $user_id)
        : db_quote("user_id = 0 AND email = ?s", $email);

    $rows = db_get_array(
        "SELECT order_id, timestamp, status, total FROM ?:orders"
        . " WHERE ?p AND status IN (?a) AND order_id != ?i"
        . " ORDER BY order_id DESC LIMIT ?i",
        $customer,
        $statuses,
        (int) $order_info['order_id'],
        (int) $limit
    );

    return (array) $rows;
}

/** EzCount document type code -> its name in the reader's language */
function fn_hypay_doc_type_name($type)
{
    switch ((string) $type) {
        case '300': return __('hypay_doc_type_300');
        case '305': return __('hypay_doc_type_305');
        case '320': return __('hypay_ez_doc_type_320');
        case '400': return __('hypay_ez_doc_type_400');
    }

    return (string) $type;
}

/**
 * One row of the window's order table: the order, the document it has if
 * any (number and type, as the EzCount Doc Generator lists them), the link it
 * is already in if any, and whether it can be ticked.
 */
function fn_hypay_link_order_row(array $row, array $status_names, $current = false)
{
    $order_id = (int) $row['order_id'];
    $document = fn_hypay_get_order_document($order_id);
    $link     = fn_hypay_link_get_latest($order_id);
    $in_link  = !empty($link) && in_array($link['status'], ['active', 'paid'], true);

    return [
        'order_id'    => $order_id,
        'timestamp'   => (int) $row['timestamp'],
        'status'      => (string) $row['status'],
        'status_name' => (string) ($status_names[$row['status']] ?? $row['status']),
        'total'       => round((float) $row['total'], 2),
        'current'     => (bool) $current,
        'doc_number'  => (string) ($document['ezcount_invoice_id'] ?? ''),
        'doc_type'    => empty($document) ? '' : fn_hypay_doc_type_name($document['invoice_type'] ?? ''),
        'link_state'  => $in_link ? (string) $link['status'] : '',
        'selectable'  => empty($document) && !$in_link,
    ];
}

/** the Info a link carries: the configured template, naming every order */
function hypay_build_link_info(array $order_ids, array $pp)
{
    if (count($order_ids) === 1) {
        return hypay_build_info(reset($order_ids), $pp);
    }

    $tpl = trim((string) ($pp['info'] ?? ''));
    if ($tpl === '') { $tpl = 'Order {order_id}'; }

    $ids = implode(',', array_map('intval', $order_ids));

    return hypay_sanitize_url_echo(strpos($tpl, '{order_id}') !== false
        ? str_replace('{order_id}', $ids, $tpl)
        : $tpl . ' ' . $ids);
}

/**
 * A mobile number the way Hyp takes it for an SMS: 0501234567.
 * +972 / 972 is turned back into the leading zero it replaces.
 */
function fn_hypay_link_normalize_cell($phone)
{
    $digits = preg_replace('/\D+/', '', (string) $phone);

    if (strpos($digits, '972') === 0 && strlen($digits) > 9) {
        $digits = '0' . ltrim(substr($digits, 3), '0');
    }

    return $digits;
}

/**
 * Does the order already have a document attached?
 *
 * A tax invoice, a proforma invoice or a tax invoice receipt - whichever
 * produced it, this add-on's direct API or the EzCount Doc Generator add-on.
 * Both keep it in ?:order_data type 'X', and both call it a document when it
 * carries a number: the same row can hold only the Bank Transfers data, with
 * no document in it at all.
 *
 * This is what decides whether a payment link is offered: an order with a
 * document has been billed, one without it has not.
 */
function fn_hypay_order_has_document($order_id)
{
    return !empty(fn_hypay_get_order_document($order_id));
}

/** the shop's first active Hypay payment method, 0 when there is none */
function fn_hypay_find_hypay_payment_id()
{
    static $payment_id = null;

    if ($payment_id === null) {
        $payment_id = (int) db_get_field(
            "SELECT p.payment_id FROM ?:payments AS p"
            . " INNER JOIN ?:payment_processors AS pp ON pp.processor_id = p.processor_id"
            . " WHERE p.status = 'A' AND (pp.processor_script = ?s OR pp.addon = ?s)"
            . " ORDER BY p.position, p.payment_id LIMIT 1",
            'hypay.php',
            'hypay'
        );
    }

    return $payment_id;
}

/**
 * The Hypay payment method a payment link goes through - its terminal, its
 * statuses, its EzCount settings.
 *
 * The one the link was created with, once there is a link; before that the
 * order's own method when it is a Hypay one, and the shop's Hypay method when
 * it is not - an order taken by phone, or placed with bank transfer, can be
 * paid by link as well.
 */
function fn_hypay_link_payment_id(array $order_info, array $link = [])
{
    if (!empty($link['payment_id'])) {
        return (int) $link['payment_id'];
    }

    $order_id = (int) ($order_info['order_id'] ?? 0);
    if ($order_id > 0 && fn_hypay_order_uses_hypay($order_id, $order_info)) {
        return (int) $order_info['payment_id'];
    }

    return fn_hypay_find_hypay_payment_id();
}

/** processor_params of that payment method, empty when there is none */
function fn_hypay_link_processor_params(array $order_info, array $link = [])
{
    $payment_id = fn_hypay_link_payment_id($order_info, $link);
    if ($payment_id <= 0) {
        return [];
    }

    $data = fn_get_payment_method_data($payment_id);

    return (is_array($data) && !empty($data['processor_params'])) ? (array) $data['processor_params'] : [];
}

/**
 * Is the order's payment method a Hypay one?
 *
 * fn_check_payment_script() compares the processor script name exactly, and
 * that is not the only way an installation can end up registered - an older
 * install, a copied processor row. So the processor row is also read directly,
 * and any sign of this add-on in it is enough.
 */
function fn_hypay_order_uses_hypay($order_id, array $order_info)
{
    if (fn_check_payment_script('hypay.php', $order_id)) {
        return true;
    }

    $payment_id = (int) ($order_info['payment_id'] ?? 0);
    if ($payment_id <= 0) {
        return false;
    }

    $row = db_get_row(
        "SELECT pp.processor, pp.processor_script, pp.addon FROM ?:payments AS p"
        . " LEFT JOIN ?:payment_processors AS pp ON pp.processor_id = p.processor_id"
        . " WHERE p.payment_id = ?i",
        $payment_id
    );
    if (empty($row)) {
        return false;
    }

    return basename((string) $row['processor_script']) === 'hypay.php'
        || (string) $row['addon'] === 'hypay'
        || stripos((string) $row['processor'], 'hyp') !== false;
}

/**
 * One payRequest call.
 *
 * CREATE and DELETE answer with a query string, LIST with a JSON array; both
 * are returned, and the caller reads the one its command produces.
 */
function fn_hypay_link_api_request($order_id, array $params, $label, $timeout = 45)
{
    $url = HYPAY_API_URL . '?' . http_build_query($params);

    hypay_log($order_id, $label . ' request', hypay_mask_params($params));

    $response = (string) Http::get($url, ['timeout' => (int) $timeout]);

    // LIST can be long; the log only needs to show what came back
    hypay_log($order_id, $label . ' response', strlen($response) > 4000 ? substr($response, 0, 4000) . '…' : $response);

    $parsed = [];
    parse_str(trim($response), $parsed);

    $json = json_decode($response, true);

    return [
        'raw'    => $response,
        'params' => is_array($parsed) ? $parsed : [],
        'json'   => is_array($json) ? $json : null,
    ];
}

/**
 * What to do about a refusal that is the terminal's, not the request's.
 *
 * CCode=901 on payRequest reads "payRequest API is not enabled for this
 * terminal": the request is authenticated with the same Masof and PassP the
 * J5 calls use, and it is the terminal that has not been given the API.
 * Sending links by hand from the Hyp portal is a separate permission, so a
 * terminal can have that and still refuse this. The terminal is named, because
 * a shop with more than one Hypay payment method may have looked at another.
 *
 * @return string ' ' + the hint, or '' for any other code
 */
function fn_hypay_link_permission_hint($ccode, array $pp)
{
    $ccode = trim((string) $ccode);
    $masof = trim((string) ($pp['masof'] ?? ''));

    if ($ccode === '901') {
        return ' ' . __('hypay_link_hint_901', ['[masof]' => $masof]);
    }
    if ($ccode === '902') {
        return ' ' . __('hypay_link_hint_902', ['[masof]' => $masof]);
    }

    return '';
}

/** Masof + PassP of the order's Hypay payment method, '' when either is missing */
function fn_hypay_link_credentials(array $pp)
{
    $masof = trim((string) ($pp['masof'] ?? ''));
    $passp = trim((string) ($pp['passp'] ?? ''));

    return ($masof === '' || $passp === '') ? [] : ['Masof' => $masof, 'PassP' => $passp];
}

/**
 * Create a payment link for an order and have Hyp send it to the customer.
 *
 * @param string $email     address to e-mail the link to, '' for none
 * @param string $cell      mobile number to text the link to, '' for none
 * @param int[]  $order_ids more of the same customer's orders to pay for with
 *                          the same link; the order it is created from is
 *                          always one of them
 *
 * @return bool
 */
function fn_hypay_link_create($order_id, $email = '', $cell = '', array $order_ids = [])
{
    fn_hypay_ensure_schema();

    $order_id   = (int) $order_id;
    $order_info = fn_get_order_info($order_id);
    if (empty($order_info)) {
        fn_set_notification('E', __('error'), __('hypay_j5_error_no_order'));

        return false;
    }

    // the Hypay payment method the link is made through: the order's own, or
    // the shop's Hypay method when the order was placed with something else
    $link_payment_id = fn_hypay_link_payment_id($order_info);
    if ($link_payment_id <= 0) {
        fn_set_notification('E', __('error'), __('hypay_link_error_not_hypay'));

        return false;
    }

    $pp = fn_hypay_link_processor_params($order_info);
    $GLOBALS['HYPAY_DEBUG'] = (!empty($pp['debug_mode']) && $pp['debug_mode'] === 'Y');

    if (fn_hypay_order_has_document($order_id)) {
        fn_set_notification('E', __('error'), __('hypay_link_error_has_document'));

        return false;
    }

    if (fn_hypay_link_get_active($order_id)) {
        fn_set_notification('E', __('error'), __('hypay_link_error_active_exists'));

        return false;
    }

    // The other orders picked in the panel, held to the same rules the panel
    // offered them by: the same customer, a status the settings allow, no
    // document, no link of their own. Anything else stops the whole link -
    // leaving an order out quietly would bill the customer a different amount
    // than the merchant chose.
    $allowed_statuses = fn_hypay_link_order_statuses($pp);
    $orders           = [$order_id => $order_info];
    foreach (array_unique(array_map('intval', $order_ids)) as $extra_id) {
        if ($extra_id <= 0 || isset($orders[$extra_id])) {
            continue;
        }

        $extra = fn_get_order_info($extra_id);
        if (empty($extra)
            || !fn_hypay_link_same_customer($order_info, $extra)
            || !in_array((string) $extra['status'], $allowed_statuses, true)
            || !fn_hypay_link_order_is_free($extra_id)
        ) {
            fn_set_notification('E', __('error'), __('hypay_link_error_bad_order', ['[order_id]' => $extra_id]));

            return false;
        }

        $orders[$extra_id] = $extra;
    }

    $amounts = [];
    foreach ($orders as $oid => $o) {
        $amounts[$oid] = round((float) $o['total'], 2);
    }

    $amount = round(array_sum($amounts), 2);
    if ($amount <= 0) {
        fn_set_notification('E', __('error'), __('hypay_link_error_zero_amount'));

        return false;
    }

    $credentials = fn_hypay_link_credentials($pp);
    if (!$credentials) {
        fn_set_notification('E', __('error'), __('hypay_link_error_no_credentials'));

        return false;
    }

    // Hyp needs at least one way to reach the customer, and sends the link
    // through every one it is given
    $email = trim((string) $email);
    $cell  = fn_hypay_link_normalize_cell($cell);

    // Neither ticked: the merchant wants the link itself, to send it their own
    // way. payRequest will not make one without somewhere to send it, so that
    // link is the signed payment page URL instead - see fn_hypay_link_sign().
    $kind = ($email === '' && $cell === '') ? 'sign' : 'request';

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fn_set_notification('E', __('error'), __('hypay_link_error_bad_email'));

        return false;
    }
    if ($cell !== '' && !preg_match('/^0\d{8,9}$/', $cell)) {
        fn_set_notification('E', __('error'), __('hypay_link_error_bad_cell'));

        return false;
    }

    // page language: the same choice the checkout payment page makes
    $lang2     = hypay_lang2_from_order($order_info);
    $page_lang = $pp['page_lang'] ?? 'auto';
    if ($page_lang !== 'ENG' && $page_lang !== 'HEB') {
        $page_lang = ($lang2 === 'he') ? 'HEB' : 'ENG';
    }

    // The document Hyp issues itself when the link is paid follows the
    // integrated EzCount settings, exactly as a checkout charge does
    $is_integrated = (($pp['ez_mode'] ?? 'none') === 'integrated');
    $ez_customer   = $is_integrated ? hypay_ez_customer($pp, $order_info, 'ez_int') : [];
    $ez_name       = (string) ($ez_customer['ezcount_name'] ?? '');

    $info = hypay_build_link_info(array_keys($orders), $pp);

    $params = $credentials + [
        'action'      => 'payRequest',
        'iCommand'    => 'CREATE',
        'Amount'      => $amount,
        'Coin'        => (int) ($pp['coin'] ?? 1),
        'PageLang'    => $page_lang,
        'Info'        => $info,
        // the order number again, in the field the checkout payment page
        // carries it in: when Hyp echoes it on the return, the order is found
        // by it rather than by Info
        'Order'       => $order_id,
        'ClientName'  => hypay_sanitize_url_echo($ez_name !== '' ? $ez_name : ($order_info['firstname'] ?? '')),
        'ClientLName' => hypay_sanitize_url_echo($ez_name !== '' ? ''       : ($order_info['lastname']  ?? '')),
        'UTF8'        => hypay_bool($pp['utf8']    ?? 'Y'),
        'UTF8out'     => hypay_bool($pp['utf8out'] ?? 'Y'),
    ];
    hypay_put($params, 'email', $email);
    hypay_put($params, 'cell',  $cell);

    // instalments, as configured for the checkout payment page
    hypay_put($params, 'Tash',     isset($pp['tash'])     && $pp['tash']     !== '' ? (int) $pp['tash']     : null);
    hypay_put($params, 'tashType', isset($pp['tashtype']) && $pp['tashtype'] !== '' ? (int) $pp['tashtype'] : null);
    if (($pp['fixtash'] ?? 'N') === 'Y') {
        $params['FixTash'] = 'True';
    }

    // Integrated: Hyp issues the document when the link is paid, of the type
    // the payment link setting asks for - or none at all
    $int_doc_type = $is_integrated ? fn_hypay_link_doc_type($pp, 'integrated') : 'none';
    if ($is_integrated && $int_doc_type !== 'none') {
        // every order's lines, so they add up to the amount of the link
        $hesh_desc = '';
        foreach ($orders as $o) {
            list($order_hesh) = hypay_build_heshdesc($o);
            $hesh_desc .= $order_hesh;
        }
        $params['SendHesh'] = hypay_bool($pp['sendhesh'] ?? 'N');
        $params['Pritim']   = hypay_bool($pp['pritim']   ?? 'Y');
        if ($params['Pritim'] === 'True') {
            $params['heshDesc'] = $hesh_desc;
        }
        $params[HYPAY_EZ_INT_DOC_TYPE_PARAM] = $int_doc_type;
        hypay_put($params, 'UserId', (string) ($ez_customer['vat'] ?? ''));
    } elseif ($is_integrated) {
        // nothing to send to the customer; whether Hyp still issues the
        // document depends on how the terminal's invoice module is set up
        $params['SendHesh'] = 'False';
    }

    if ($kind === 'sign') {
        $signed = fn_hypay_link_sign($order_id, $order_info, $orders, $amount, $info, $page_lang, $pp, $ez_customer);
        if (empty($signed['url'])) {
            fn_set_notification('E', __('error'), __('hypay_link_create_failed') . ' ' . $signed['error']);
            hypay_log($order_id, 'link.sign FAILED', $signed['error']);

            return false;
        }
        $answer = ['payRequestId' => $signed['id'], 'paymentURL' => $signed['url']];
        $result = ['raw' => ''];
    } else {
        $result = fn_hypay_link_api_request($order_id, $params, 'link.create');
        $answer = $result['params'];
    }

    $pay_request_id = trim((string) ($answer['payRequestId'] ?? ''));
    $payment_url    = trim((string) ($answer['paymentURL'] ?? ''));

    if ($pay_request_id === '' || $payment_url === '') {
        $error = fn_hypay_format_error($answer['CCode'] ?? '', $answer['errMsg'] ?? '', $result['raw']);
        fn_set_notification('E', __('error'), __('hypay_link_create_failed') . ' ' . $error
            . fn_hypay_link_permission_hint($answer['CCode'] ?? '', $pp));
        hypay_log($order_id, 'link.create FAILED', ['error' => $error, 'Masof' => $credentials['Masof']]);

        return false;
    }

    $sent_to = implode('; ', array_filter([$email, $cell], 'strlen'));

    db_query("INSERT INTO ?:hypay_payment_links ?e", [
        'order_id'       => $order_id,
        'payment_id'     => $link_payment_id,
        'pay_request_id' => $pay_request_id,
        'payment_url'    => $payment_url,
        'amount'         => $amount,
        'coin'           => (int) ($pp['coin'] ?? 1),
        'info'           => $info,
        'sent_to'        => $sent_to,
        'status'         => 'active',
        'kind'           => $kind,
        'created_at'     => TIME,
        'checked_at'     => TIME,
        'last_error'     => '',
    ]);
    $link_id = (int) db_get_field(
        "SELECT link_id FROM ?:hypay_payment_links WHERE pay_request_id = ?s ORDER BY link_id DESC LIMIT 1",
        $pay_request_id
    );

    foreach ($amounts as $oid => $order_amount) {
        db_query("REPLACE INTO ?:hypay_payment_link_orders ?e", [
            'link_id'  => $link_id,
            'order_id' => $oid,
            'amount'   => $order_amount,
        ]);

        // A checkout payment the customer started and abandoned left its
        // marker on the order, and the return from this link would be read as
        // that checkout coming back. The link supersedes it; a checkout started
        // after this sets a fresh marker of its own.
        hypay_clear_back_marker($oid);
    }

    // the orders are marked as having a link out, when the settings ask for it
    fn_hypay_link_set_orders_additional_status(fn_hypay_link_get($link_id), $pp, 'link_created_additional_status');

    hypay_log($order_id, 'link.create SUCCESS', [
        'payRequestId' => $pay_request_id,
        'paymentURL'   => $payment_url,
        'sent_to'      => $sent_to,
        'amount'       => $amount,
        'orders'       => $amounts,
    ]);
    fn_set_notification('N', __('notice'), $kind === 'sign'
        ? __('hypay_link_created_ok_copy')
        : __('hypay_link_created_ok', ['[sent_to]' => $sent_to]));

    return true;
}

/**
 * A payment link nobody sends: the signed payment page URL.
 *
 * payRequest makes a link only together with an e-mail or a mobile number to
 * send it to. When the merchant wants the link alone - to paste into WhatsApp,
 * a chat, an e-mail of their own - it is made the way the checkout makes its
 * payment page: APISign / SIGN, the request the terminal already takes. The
 * page it opens is the checkout's own, and its return comes back to
 * payment_notification with the order number, where the link is found and the
 * payment recorded on every order the link covers.
 *
 * What it cannot do is be withdrawn at Hyp or listed there: cancelling it is
 * the store's own note, and a payment made on it anyway is still recorded.
 *
 * @return array ['id' => local id, 'url' => payment page URL, 'error' => text]
 */
function fn_hypay_link_sign($order_id, array $order_info, array $orders, $amount, $info, $page_lang, array $pp, array $ez_customer)
{
    $ez_name = (string) ($ez_customer['ezcount_name'] ?? '');

    // every order's lines, adding up to the amount of the link - Hyp checks
    // the one against the other unless blockItemValidation says not to
    $hesh_desc = '';
    foreach ($orders as $o) {
        list($order_hesh) = hypay_build_heshdesc($o);
        $hesh_desc .= $order_hesh;
    }

    $params = [
        'action'      => 'APISign',
        'What'        => 'SIGN',
        'Masof'       => trim((string) ($pp['masof']   ?? '')),
        'KEY'         => trim((string) ($pp['api_key'] ?? '')),
        'PassP'       => trim((string) ($pp['passp']   ?? '')),
        'Order'       => (int) $order_id,
        'Info'        => $info,
        'Amount'      => round((float) $amount, 2),
        'UTF8'        => hypay_bool($pp['utf8']    ?? 'Y'),
        'UTF8out'     => hypay_bool($pp['utf8out'] ?? 'Y'),
        'Sign'        => hypay_bool($pp['sign']    ?? 'N'),
        'PageLang'    => $page_lang,
        'ClientName'  => hypay_sanitize_url_echo($ez_name !== '' ? $ez_name : ($order_info['firstname'] ?? '')),
        'ClientLName' => hypay_sanitize_url_echo($ez_name !== '' ? ''       : ($order_info['lastname']  ?? '')),
        'email'       => (string) ($order_info['email'] ?? ''),
        'phone'       => (string) ($order_info['phone'] ?? ''),
        'street'      => hypay_sanitize_url_echo($order_info['s_address'] ?? $order_info['b_address'] ?? ''),
        'city'        => hypay_sanitize_url_echo($order_info['s_city']    ?? $order_info['b_city']    ?? ''),
        'MoreData'    => hypay_bool($pp['moredata'] ?? 'Y'),
        'Pritim'      => hypay_bool($pp['pritim']   ?? 'Y'),
        'FixTash'     => hypay_bool($pp['fixtash']  ?? 'N'),
        'blockItemValidation' => hypay_bool($pp['block_item_validation'] ?? 'N'),
        'Coin'        => (int) ($pp['coin'] ?? 1),
        'ShowEngTashText' => hypay_bool($pp['show_eng_tash_text'] ?? 'N'),
        'hideBtns'    => hypay_bool($pp['hide_btns'] ?? 'N'),
        'tmp'         => (int) ($pp['tmp'] ?? 4),
        'heshDesc'    => $hesh_desc,
        // a link is paid on the customer's own time: the page must not time
        // out on them the way a checkout page may
        'pageTimeOut' => 'False',
        'J5'          => 'False',
    ];
    hypay_put($params, 'Tash',     isset($pp['tash'])     && $pp['tash']     !== '' ? (int) $pp['tash']     : null);
    hypay_put($params, 'tashType', isset($pp['tashtype']) && $pp['tashtype'] !== '' ? (int) $pp['tashtype'] : null);

    // the document Hyp issues itself follows the payment link setting, as it
    // does for a link Hyp sends
    $is_integrated = (($pp['ez_mode'] ?? 'none') === 'integrated');
    $int_doc_type  = $is_integrated ? fn_hypay_link_doc_type($pp, 'integrated') : 'none';
    if ($is_integrated && $int_doc_type !== 'none') {
        $params['SendHesh'] = hypay_bool($pp['sendhesh'] ?? 'N');
        $params[HYPAY_EZ_INT_DOC_TYPE_PARAM] = $int_doc_type;
        hypay_put($params, 'UserId', (string) ($ez_customer['vat'] ?? ''));
    } else {
        $params['SendHesh'] = 'False';
    }

    $url = HYPAY_API_URL . '?' . http_build_query($params);
    hypay_log($order_id, 'link.sign request', hypay_mask_params($params));

    $response = trim((string) Http::get($url, ['timeout' => 30]));
    hypay_log($order_id, 'link.sign response', $response);

    if ($response === '' || strpos($response, 'signature=') === false) {
        $parsed = [];
        parse_str($response, $parsed);

        return [
            'id'    => '',
            'url'   => '',
            'error' => fn_hypay_format_error($parsed['CCode'] ?? '', $parsed['errMsg'] ?? '', $response),
        ];
    }

    return [
        // a local id in the column payRequest ids live in; "S" keeps it from
        // ever matching one of Hyp's
        'id'    => 'S' . substr(md5(uniqid((string) $order_id, true)), 0, 15),
        'url'   => HYPAY_API_URL . '?' . $response,
        'error' => '',
    ];
}

/**
 * Cancel the order's active payment link (iCommand=DELETE).
 *
 * @return bool true when the link is no longer payable - cancelled, or found
 *              to have been paid, in which case the payment is recorded
 */
function fn_hypay_link_cancel($order_id)
{
    fn_hypay_ensure_schema();

    $order_id   = (int) $order_id;
    $order_info = fn_get_order_info($order_id);
    if (empty($order_info)) {
        fn_set_notification('E', __('error'), __('hypay_j5_error_no_order'));

        return false;
    }

    $link = fn_hypay_link_get_active($order_id);
    if (empty($link)) {
        fn_set_notification('E', __('error'), __('hypay_link_error_no_active'));

        return false;
    }

    $pp = fn_hypay_link_processor_params($order_info, $link);
    $GLOBALS['HYPAY_DEBUG'] = (!empty($pp['debug_mode']) && $pp['debug_mode'] === 'Y');

    // A signed payment page cannot be withdrawn at Hyp. Cancelling it is the
    // store's own decision, said out loud: the address still opens, and a
    // payment made on it anyway is recorded rather than lost.
    if (($link['kind'] ?? 'request') === 'sign') {
        db_query(
            "UPDATE ?:hypay_payment_links SET status = 'cancelled', cancelled_at = ?i, last_error = '' WHERE link_id = ?i AND status = 'active'",
            TIME,
            $link['link_id']
        );
        hypay_log($order_id, 'link cancelled (signed page, local only)', ['link_id' => $link['link_id']]);
        fn_hypay_link_set_orders_additional_status($link, $pp, 'link_cancelled_additional_status');
        fn_set_notification('W', __('warning'), __('hypay_link_cancel_sign'));

        return true;
    }

    $credentials = fn_hypay_link_credentials($pp);
    if (!$credentials) {
        fn_set_notification('E', __('error'), __('hypay_link_error_no_credentials'));

        return false;
    }

    $result = fn_hypay_link_api_request($order_id, $credentials + [
        'action'     => 'payRequest',
        'iCommand'   => 'DELETE',
        'PayRequest' => $link['pay_request_id'],
    ], 'link.delete');
    $ccode = trim((string) ($result['params']['CCode'] ?? ''));

    if ($ccode === '0') {
        db_query(
            "UPDATE ?:hypay_payment_links SET status = 'cancelled', cancelled_at = ?i, last_error = '' WHERE link_id = ?i AND status = 'active'",
            TIME,
            $link['link_id']
        );
        hypay_log($order_id, 'link.delete SUCCESS', ['payRequestId' => $link['pay_request_id']]);
        fn_hypay_link_set_orders_additional_status($link, $pp, 'link_cancelled_additional_status');
        fn_set_notification('N', __('notice'), __('hypay_link_cancel_ok'));

        return true;
    }

    if ($ccode === '995') {
        // too late: the customer has paid. The LIST lookup records it the way
        // it records any payment nobody came back from.
        hypay_log($order_id, 'link.delete refused: already paid, looking the payment up');
        $state = fn_hypay_link_check($order_id, true);
        if ($state !== 'paid') {
            // LIST did not confirm it (older than its 500 rows, or unreachable):
            // say so on the link, which stays as it is until the return or a
            // later lookup records the payment
            db_query(
                "UPDATE ?:hypay_payment_links SET last_error = ?s WHERE link_id = ?i",
                fn_hypay_format_error($ccode),
                $link['link_id']
            );
            fn_set_notification('W', __('warning'), __('hypay_link_already_paid'));
        }

        return true;
    }

    if ($ccode === '250') {
        // Hyp does not know the link (any more): nothing is left to pay with
        db_query(
            "UPDATE ?:hypay_payment_links SET status = 'cancelled', cancelled_at = ?i, last_error = ?s WHERE link_id = ?i AND status = 'active'",
            TIME,
            fn_hypay_format_error($ccode),
            $link['link_id']
        );
        fn_hypay_link_set_orders_additional_status($link, $pp, 'link_cancelled_additional_status');
        fn_set_notification('W', __('warning'), __('hypay_link_cancel_not_found'));

        return true;
    }

    $error = fn_hypay_format_error($ccode, $result['params']['errMsg'] ?? '', $result['raw']);
    db_query("UPDATE ?:hypay_payment_links SET last_error = ?s WHERE link_id = ?i", 'cancel: ' . $error, $link['link_id']);
    fn_set_notification('E', __('error'), __('hypay_link_cancel_failed') . ' ' . $error
        . fn_hypay_link_permission_hint($ccode, $pp));

    return false;
}

/**
 * Ask Hyp what became of the order's active link (iCommand=LIST).
 *
 * @param bool $quiet   no notification unless something actually changed -
 *                      for the lookup made on its own when the page opens
 * @param int  $timeout seconds to wait for Hyp
 *
 * @return string paid | cancelled | active | unknown
 */
function fn_hypay_link_check($order_id, $quiet = false, $timeout = 45)
{
    fn_hypay_ensure_schema();

    $order_id = (int) $order_id;
    $link     = fn_hypay_link_get_active($order_id);
    if (empty($link)) {
        if (!$quiet) {
            fn_set_notification('E', __('error'), __('hypay_link_error_no_active'));
        }

        return 'unknown';
    }

    // a signed payment page is not a payRequest: LIST does not know it, and
    // its payment arrives with the customer's return
    if (($link['kind'] ?? 'request') === 'sign') {
        if (!$quiet) {
            fn_set_notification('N', __('notice'), __('hypay_link_check_sign'));
        }

        return 'active';
    }

    $order_info = fn_get_order_info($order_id);
    $pp         = fn_hypay_link_processor_params($order_info, $link);
    $GLOBALS['HYPAY_DEBUG'] = (!empty($pp['debug_mode']) && $pp['debug_mode'] === 'Y');

    $credentials = fn_hypay_link_credentials($pp);
    if (!$credentials) {
        if (!$quiet) {
            fn_set_notification('E', __('error'), __('hypay_link_error_no_credentials'));
        }

        return 'unknown';
    }

    db_query("UPDATE ?:hypay_payment_links SET checked_at = ?i WHERE link_id = ?i", TIME, $link['link_id']);

    $result = fn_hypay_link_api_request($order_id, $credentials + [
        'action'   => 'payRequest',
        'iCommand' => 'LIST',
    ], 'link.list', $timeout);

    if ($result['json'] === null) {
        $error = fn_hypay_format_error($result['params']['CCode'] ?? '', $result['params']['errMsg'] ?? '', $result['raw']);
        hypay_log($order_id, 'link.list FAILED', $error);
        if (!$quiet) {
            fn_set_notification('E', __('error'), __('hypay_link_check_failed') . ' ' . $error
                . fn_hypay_link_permission_hint($result['params']['CCode'] ?? '', $pp));
        }

        return 'unknown';
    }

    $item = null;
    foreach ($result['json'] as $row) {
        if (is_array($row) && (string) ($row['payRequestId'] ?? '') === (string) $link['pay_request_id']) {
            $item = $row;
            break;
        }
    }

    if ($item === null) {
        hypay_log($order_id, 'link.list: link not among the recent ones', ['payRequestId' => $link['pay_request_id']]);
        if (!$quiet) {
            fn_set_notification('W', __('warning'), __('hypay_link_check_not_found'));
        }

        return 'unknown';
    }

    $status = (string) ($item['status'] ?? '');
    hypay_log($order_id, 'link.list: status', ['payRequestId' => $link['pay_request_id'], 'status' => $status, 'transId' => $item['transId'] ?? null]);

    if ($status === '3') {
        fn_hypay_link_settle_from_list($order_id, $link, (string) ($item['transId'] ?? ''));
        fn_set_notification('N', __('notice'), __('hypay_link_check_paid'));

        return 'paid';
    }

    if ($status === '0') {
        // cancelled from the Hyp portal rather than from here
        db_query(
            "UPDATE ?:hypay_payment_links SET status = 'cancelled', cancelled_at = ?i, last_error = ?s WHERE link_id = ?i AND status = 'active'",
            TIME,
            __('hypay_link_check_cancelled_remote'),
            $link['link_id']
        );
        fn_hypay_link_set_orders_additional_status($link, $pp, 'link_cancelled_additional_status');
        fn_set_notification('W', __('warning'), __('hypay_link_check_cancelled_remote'));

        return 'cancelled';
    }

    if (!$quiet) {
        fn_set_notification('N', __('notice'), __('hypay_link_check_unpaid'));
    }

    return 'active';
}

/**
 * The page-open lookup: at most once a minute per link, and with a short
 * timeout, so an order page is never held up for long by a slow gateway.
 * A link past its lifetime is expired first - see fn_hypay_link_expire_if_due().
 */
function fn_hypay_link_auto_check($order_id)
{
    if (fn_hypay_link_expire_if_due($order_id)) {
        return;
    }

    $link = fn_hypay_link_get_active($order_id);
    if (empty($link) || (int) $link['checked_at'] > TIME - 60 || ($link['kind'] ?? 'request') === 'sign') {
        return;
    }

    fn_hypay_link_check($order_id, true, 10);
}

/**
 * Take a link from active to paid, exactly once.
 *
 * @param string $via      return | list
 * @param string $trans_id Hyp's transaction Id, when known
 *
 * @return bool true for the caller that made the change - the one that moves
 *              the order; false when someone else already had
 */
function fn_hypay_link_claim_paid($link_id, $via, $trans_id = '')
{
    return (bool) db_query(
        "UPDATE ?:hypay_payment_links SET status = 'paid', paid_via = ?s, paid_at = ?i, trans_id = ?s, last_error = ''"
        // a signed page cancelled here still opens at Hyp, and an expired link
        // may not have been withdrawn there: money taken on either is recorded
        // all the same
        . " WHERE link_id = ?i AND (status IN ('active', 'expired') OR (status = 'cancelled' AND kind = 'sign'))",
        (string) $via,
        TIME,
        (string) $trans_id,
        (int) $link_id
    );
}

/**
 * Record a link payment on the order: payment information, status, and the
 * additional status an ordinary charge gets.
 *
 * Not fn_finish_payment(): that only acts on an order a checkout payment was
 * started for, and a link is paid without one - an order placed from the admin
 * panel, or one whose checkout payment has already come back declined.
 */
function fn_hypay_link_finish_order($order_id, array $pp_response, array $pp)
{
    $status = (string) ($pp_response['order_status'] ?? '');
    unset($pp_response['order_status']);

    fn_hypay_update_payment_info($order_id, $pp_response);

    if ($status !== '') {
        fn_change_order_status($order_id, $status);
    }

    if (!empty($pp['success_additional_status'])) {
        fn_hypay_set_additional_status($order_id, $pp['success_additional_status']);
    }

    fn_hypay_order_note($order_id, 'paid by payment link');
}

/**
 * The same, for every order a link pays for: each one gets the payment
 * information, the status and the additional status, as if it had been paid
 * on its own.
 */
function fn_hypay_link_finish_orders(array $link, array $pp_response, array $pp)
{
    foreach (fn_hypay_link_order_ids($link) as $oid) {
        fn_hypay_link_finish_order($oid, $pp_response, $pp);
    }
}

/**
 * Card details arriving after the payment was already recorded (the LIST
 * lookup got there first, or the return is replayed): added to every order
 * the link paid for, without moving any of them again.
 */
function fn_hypay_link_update_payment_info(array $link, array $pp_response)
{
    unset($pp_response['order_status']);

    foreach (fn_hypay_link_order_ids($link) as $oid) {
        fn_hypay_update_payment_info($oid, $pp_response);
    }
}

/**
 * LIST says the link was paid, and the customer's return has not been seen:
 * the order is settled with what LIST knows. The card details are not among
 * it - if the return turns up later, it adds them.
 */
function fn_hypay_link_settle_from_list($order_id, array $link, $trans_id)
{
    $trans_id = trim((string) $trans_id);

    if (!fn_hypay_link_claim_paid($link['link_id'], 'list', $trans_id)) {
        return false;
    }

    $link       = fn_hypay_link_get($link['link_id']);
    $order_info = fn_get_order_info($order_id);
    $pp         = fn_hypay_link_processor_params($order_info, $link);

    $pp_response = [
        'reason_text'  => '🟢 Success',
        'hypay_link'   => fn_hypay_link_paid_label($link),
        'order_status' => !empty($pp['success_status']) ? $pp['success_status'] : 'O',
    ];
    if ($trans_id !== '') {
        $pp_response['transaction_id'] = $trans_id;
    }

    // every order the link paid for, not only the one the lookup started from
    fn_hypay_link_finish_orders($link, $pp_response, $pp);
    hypay_log($order_id, 'link paid (found by LIST)', [
        'payRequestId' => $link['pay_request_id'],
        'transId'      => $trans_id,
        'orders'       => fn_hypay_link_order_ids($link),
    ]);

    fn_hypay_link_issue_document($order_id, $pp, $link, [
        'transaction_id' => $trans_id,
        'brand'          => '',
        'last4'          => '',
        'payments'       => 1,
    ]);

    return true;
}

/**
 * Which document a paid payment link gets, per the settings.
 *
 * Direct API and Integrated are set separately ("ez_link_doc_type" and
 * "ez_int_link_doc_type"), each one of: 320 tax invoice receipt, 400 receipt,
 * none. Unset means 320 - a link is a sale like any other.
 *
 * @param string $mode direct | integrated
 *
 * @return int|string 320, 400 or 'none'
 */
function fn_hypay_link_doc_type(array $pp, $mode)
{
    $key   = ($mode === 'integrated') ? 'ez_int_link_doc_type' : 'ez_link_doc_type';
    $value = trim((string) ($pp[$key] ?? ''));

    if ($value === 'none') {
        return 'none';
    }

    return ((int) $value === 400) ? 400 : 320;
}

/**
 * The EzCount document for a paid payment link, issued through the direct API
 * - the same call, and the same record on the order, a checkout payment gets.
 *
 * Integrated mode is not handled here: there Hyp issues the document itself,
 * as asked for when the link was created (fn_hypay_link_create).
 *
 * @param array $card transaction_id, brand, last4, payments
 *
 * @return array|false document info, false when none was issued
 */
function fn_hypay_link_issue_document($order_id, array $pp, array $link, array $card)
{
    $ez_mode = $pp['ez_mode'] ?? 'none';
    if ($ez_mode !== 'direct') {
        hypay_log($order_id, 'link: ezcount skipped (mode != direct)', ['ez_mode' => $ez_mode]);

        return false;
    }

    $doc_type = fn_hypay_link_doc_type($pp, 'direct');
    if ($doc_type === 'none') {
        hypay_log($order_id, 'link: ezcount skipped (no document for payment links)');

        return false;
    }

    // One document for the whole payment, covering every order the link paid
    // for and recorded on each of them - as the EzCount Doc Generator does
    // with a document it issues for several orders.
    $order_ids = fn_hypay_link_order_ids($link);
    if (empty($order_ids)) {
        $order_ids = [(int) $order_id];
    }
    $main_id = (int) array_shift($order_ids);

    $extra_orders = [];
    foreach ($order_ids as $oid) {
        $extra = fn_get_order_info($oid);
        if (!empty($extra)) {
            $extra_orders[] = $extra;
        }
    }

    return fn_hypay_create_ezcount_doc($main_id, fn_get_order_info($main_id), $pp, [
        'extra_orders'   => $extra_orders,
        'transaction_id' => (string) ($card['transaction_id'] ?? ''),
        'brand'          => (string) ($card['brand'] ?? ''),
        'last4'          => (string) ($card['last4'] ?? ''),
        'payments'       => max(1, (int) ($card['payments'] ?? 1)),
        'amount'         => round((float) $link['amount'], 2),
        'flow'           => 'regular',
        'doc_type'       => $doc_type,
    ]);
}

/**
 * The EzCount document recorded on the order, as the direct API left it.
 *
 * @return array empty when there is none
 */
function fn_hypay_get_order_document($order_id)
{
    $data = db_get_field("SELECT data FROM ?:order_data WHERE order_id = ?i AND type = 'X'", (int) $order_id);
    $doc  = $data ? @unserialize($data) : false;

    return (is_array($doc) && !empty($doc['ezcount_invoice_id'])) ? $doc : [];
}

/**
 * The link a payment_notification return belongs to, if it belongs to one.
 *
 * The return from a paid link lands on the same URL as a checkout payment, so
 * it has to be told apart:
 *   - by payRequestId, if Hyp echoes it;
 *   - by the order number, when there is one, and only while no checkout
 *     payment is in flight for that order (the marker the checkout sets);
 *   - by Info (and Amount), when the return carries no order number at all -
 *     Info is what the link was created with and Hyp keeps it.
 *
 * @return array the link row, empty when this is not a link payment
 */
function fn_hypay_link_find_for_return($order_id)
{
    fn_hypay_ensure_schema();

    $order_id = (int) $order_id;

    $req_id = hypay_request_value(['payRequestId', 'PayRequestId', 'PayRequest', 'payRequest']);
    if ($req_id !== '') {
        $row = db_get_row("SELECT * FROM ?:hypay_payment_links WHERE pay_request_id = ?s ORDER BY link_id DESC LIMIT 1", $req_id);
        if (!empty($row) && ($order_id <= 0 || (int) $row['order_id'] === $order_id)) {
            return $row;
        }
    }

    if ($order_id > 0) {
        if (hypay_get_marker_data($order_id)) {
            return [];
        }

        $row = db_get_row(
            "SELECT l.* FROM ?:hypay_payment_links AS l"
            . " INNER JOIN ?:hypay_payment_link_orders AS lo ON lo.link_id = l.link_id"
            . " WHERE lo.order_id = ?i AND (l.status IN ('active', 'paid', 'expired') OR (l.status = 'cancelled' AND l.kind = 'sign'))"
            . " ORDER BY l.link_id DESC LIMIT 1",
            $order_id
        );

        return is_array($row) ? $row : [];
    }

    $info = trim(hypay_utf8_text(hypay_request_value(['Info'])));
    if ($info === '') {
        return [];
    }

    $rows = db_get_array(
        "SELECT * FROM ?:hypay_payment_links WHERE info = ?s"
        . " AND (status IN ('active', 'paid', 'expired') OR (status = 'cancelled' AND kind = 'sign')) ORDER BY link_id DESC",
        $info
    );

    $amount = hypay_request_value(['Amount']);
    if ($amount !== '' && is_numeric($amount)) {
        $rows = array_values(array_filter($rows, static function ($row) use ($amount) {
            return abs((float) $row['amount'] - (float) $amount) < 0.01;
        }));
    }

    // an Info template without {order_id} can match several orders, and a
    // guess would put the money on the wrong one
    $orders = array_unique(array_map(static function ($row) { return (int) $row['order_id']; }, $rows));

    return count($orders) === 1 ? $rows[0] : [];
}

/**
 * The order was paid some other way while a link was still out: withdraw the
 * link so the customer cannot pay a second time. Best effort - the payment
 * that just came in is not held up by it.
 */
function fn_hypay_link_retire($order_id, array $pp)
{
    $link = fn_hypay_link_get_active($order_id);
    if (empty($link)) { return; }

    if (($link['kind'] ?? 'request') === 'sign') {
        db_query(
            "UPDATE ?:hypay_payment_links SET status = 'cancelled', cancelled_at = ?i, last_error = ?s WHERE link_id = ?i AND status = 'active'",
            TIME,
            __('hypay_link_retired'),
            $link['link_id']
        );

        return;
    }

    $credentials = fn_hypay_link_credentials($pp);
    if (!$credentials) { return; }

    $result = fn_hypay_link_api_request($order_id, $credentials + [
        'action'     => 'payRequest',
        'iCommand'   => 'DELETE',
        'PayRequest' => $link['pay_request_id'],
    ], 'link.retire', 15);
    $ccode = trim((string) ($result['params']['CCode'] ?? ''));

    if ($ccode === '0' || $ccode === '250') {
        db_query(
            "UPDATE ?:hypay_payment_links SET status = 'cancelled', cancelled_at = ?i, last_error = ?s WHERE link_id = ?i AND status = 'active'",
            TIME,
            __('hypay_link_retired'),
            $link['link_id']
        );
    } elseif ($ccode === '995') {
        // the link was paid too: two payments for one order, and only a person
        // can sort that out - say it where they will see it
        db_query(
            "UPDATE ?:hypay_payment_links SET last_error = ?s WHERE link_id = ?i",
            __('hypay_link_double_payment'),
            $link['link_id']
        );
        hypay_log($order_id, 'WARNING: the order was paid at checkout AND through its payment link');
    }
}

/**
 * Everything the order page needs to render the payment link block.
 *
 * @return array empty when there is nothing to show: not a Hypay order, or
 *               an order that was paid some other way
 */
function fn_hypay_get_link_panel_data($order_id)
{
    $order_id = (int) $order_id;
    if ($order_id <= 0) { return []; }

    $order_info = fn_get_order_info($order_id);
    if (empty($order_info)) { return []; }

    // Why the block is absent is not visible on the page, so it is said twice:
    // in the page source as an HTML comment ('hidden' below), and with debug
    // mode on in the log. Neither means this was never called - the template
    // is not rendered (hook not reached, or a stale template cache).
    $link    = fn_hypay_link_get_latest($order_id);
    $state   = empty($link) ? 'none' : (string) $link['status'];
    $pp_link = fn_hypay_link_processor_params($order_info, $link);
    $GLOBALS['HYPAY_DEBUG'] = (!empty($pp_link['debug_mode']) && $pp_link['debug_mode'] === 'Y');

    if (empty($pp_link)) {
        return ['hidden' => 'the shop has no active Hypay payment method'];
    }

    // One rule decides it: a document attached to the order means the order
    // has been billed, and no link is offered. A paid link keeps its block -
    // the "paid by payment link on ..." line replaces the button there.
    if ($state !== 'paid' && fn_hypay_order_has_document($order_id)) {
        hypay_log($order_id, 'payment link block hidden: the order has a document attached');

        return ['hidden' => 'the order has a document attached'];
    }

    $order_total = round((float) $order_info['total'], 2);
    $amount      = empty($link) ? 0.0 : round((float) $link['amount'], 2);

    $status_names = (array) fn_get_simple_statuses(STATUSES_ORDER, true, true);

    // The orders the link pays for, with what each of them totals today: the
    // sum drifting away from the amount of the link is what the panel warns
    // about - the customer would pay the old amount.
    $link_orders = [];
    $link_total  = 0.0;
    foreach (fn_hypay_link_order_ids($link) as $oid) {
        $row = db_get_row("SELECT order_id, timestamp, status, total FROM ?:orders WHERE order_id = ?i", $oid);
        if (empty($row)) {
            continue;
        }
        $order_row     = fn_hypay_link_order_row($row, $status_names, $oid === $order_id);
        $link_orders[] = $order_row;
        $link_total   += $order_row['total'];
    }

    // What the panel offers to put into a new link: this order - always, it is
    // the one the button is on - and the customer's other orders the settings
    // allow, each with the facts needed to choose it.
    $candidates = [];
    if ($state !== 'active' && $state !== 'paid') {
        $candidates[] = fn_hypay_link_order_row([
            'order_id'  => $order_id,
            'timestamp' => $order_info['timestamp'],
            'status'    => $order_info['status'],
            'total'     => $order_total,
        ], $status_names, true);
        foreach (fn_hypay_link_candidate_orders($order_info, $pp_link) as $row) {
            $candidates[] = fn_hypay_link_order_row($row, $status_names);
        }
    }

    // the statuses present in the table, for its status filter
    $candidate_statuses = [];
    foreach ($candidates as $c) {
        $candidate_statuses[$c['status']] = $c['status_name'];
    }

    // the first phone the order has, in the order a mobile is likeliest to be in
    $cell = '';
    foreach (['phone', 'b_phone', 's_phone'] as $key) {
        if (trim((string) ($order_info[$key] ?? '')) !== '') {
            $cell = (string) $order_info[$key];
            break;
        }
    }

    return [
        'order_id'      => $order_id,
        'state'         => $state,
        'payment_url'   => (string) ($link['payment_url'] ?? ''),
        'sent_to'       => (string) ($link['sent_to'] ?? ''),
        'amount'        => $amount,
        'created_at'    => (int) ($link['created_at'] ?? 0),
        // for an expired link, the moment it ran out
        'cancelled_at'  => (int) ($link['cancelled_at'] ?? 0),
        // when an active link will run out, 0 when the settings set no limit
        'expires_at'    => ($state === 'active') ? fn_hypay_link_expires_at($link, $pp_link) : 0,
        'paid_at'       => (int) ($link['paid_at'] ?? 0),
        'trans_id'      => (string) ($link['trans_id'] ?? ''),
        'doc_number'    => (string) ($link['doc_number'] ?? ''),
        'last_error'    => (string) ($link['last_error'] ?? ''),
        'order_total'   => $order_total,
        // the order was edited after the link went out: the customer would pay
        // the old amount
        'total_changed' => ($state === 'active' && abs($link_total - $amount) > 0.009),
        'link_orders'   => $link_orders,
        'candidates'    => $candidates,
        'candidate_statuses' => $candidate_statuses,
        'kind'          => (string) ($link['kind'] ?? 'request'),
        'customer_name' => trim(($order_info['firstname'] ?? '') . ' ' . ($order_info['lastname'] ?? '')),
        'currency'      => (string) (fn_hypay_currency_symbol()),
        // whether the settings offer other orders at all - the panel says so
        // when they do and the customer simply has none
        'statuses_set'  => !empty(fn_hypay_link_order_statuses($pp_link)),
        'can_create'    => ($state !== 'active' && $state !== 'paid' && $order_total > 0),
        // what the payment will produce, so nobody is surprised afterwards
        'doc_type'      => fn_hypay_link_panel_doc_type($pp_link),
        // and, once it is paid, what it did produce (direct API)
        'document'      => ($state === 'paid') ? fn_hypay_get_order_document($order_id) : [],
        'email'         => (string) ($order_info['email'] ?? ''),
        'cell'          => fn_hypay_link_normalize_cell($cell),
    ];
}

/**
 * The document a paid link will produce, as a word for the panel.
 *
 * @return array ['mode' => direct|integrated, 'type' => 320|400|none], empty
 *               when EzCount is not used at all
 */
function fn_hypay_link_panel_doc_type(array $pp)
{
    $mode = $pp['ez_mode'] ?? 'none';
    if ($mode !== 'direct' && $mode !== 'integrated') {
        return [];
    }

    return ['mode' => $mode, 'type' => (string) fn_hypay_link_doc_type($pp, $mode)];
}

/** the primary currency's symbol, for sums the window adds up itself */
function fn_hypay_currency_symbol()
{
    $currencies = Registry::get('currencies');
    $primary    = defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : '';

    return (string) ($currencies[$primary]['symbol'] ?? '');
}
