{* ============================================================================
 *  Hypay J5 (two-phase commit) block.
 *  Rendered by the "orders:payment_info" hook, right below the standard
 *  "Payment information" section of the order details page.
 * ========================================================================== *}

{if $runtime.controller == "orders" && $runtime.mode == "details"}
{$hypay_j5 = $order_info.order_id|fn_hypay_get_j5_panel_data}

{if $hypay_j5}
{$hypay_date_format = "`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}

<div class="control-group shift-top">
    {include file="common/subheader.tpl" title=__("hypay_j5_title")}
</div>

<div class="control-group">
    <div class="control-label">{__("hypay_j5_status")}</div>
    <div class="controls">
        {if $hypay_j5.status == "authorized"}
            <span class="text-warning"><strong>{__("hypay_j5_status_authorized")}</strong></span>
        {elseif $hypay_j5.status == "capturing"}
            <span class="text-warning"><strong>{__("hypay_j5_status_capturing")}</strong></span>
        {elseif $hypay_j5.status == "captured"}
            <span class="text-success"><strong>{__("hypay_j5_status_captured")}</strong></span>
        {elseif $hypay_j5.status == "voided"}
            <strong>{__("hypay_j5_status_voided")}</strong>
        {else}
            <strong>{$hypay_j5.status}</strong>
        {/if}
    </div>
</div>

<div class="control-group">
    <div class="control-label">{__("hypay_j5_authorized_amount")}</div>
    <div class="controls">
        {include file="common/price.tpl" value=$hypay_j5.amount_authorized}
        {if $hypay_j5.authorized_at}<div class="muted"><small>{$hypay_j5.authorized_at|date_format:$hypay_date_format}</small></div>{/if}
    </div>
</div>

{* The card is printed once, in the Payment information block above. What it
   means for the capture is said where the buttons are. *}
{if $hypay_j5.acode}
<div class="control-group">
    <div class="control-label">{__("hypay_j5_auth_number")}</div>
    <div class="controls"><bdi>{$hypay_j5.acode}</bdi>{if $hypay_j5.hyp_id} <span class="muted"><small>(Id: {$hypay_j5.hyp_id})</small></span>{/if}</div>
</div>
{/if}

{* only while debug mode is on: the UID is what a capture is diagnosed with,
   and the rest of the time it is a long opaque string taking up a row *}
{if $hypay_j5.uid && $hypay_j5.debug}
<div class="control-group">
    <div class="control-label">{__("hypay_j5_uid")}</div>
    <div class="controls">
        <bdi><small>{$hypay_j5.uid}</small></bdi>
    </div>
</div>
{/if}

{if $hypay_j5.status == "authorized" || $hypay_j5.status == "capturing"}
    <div class="control-group">
        <div class="control-label">{__("hypay_j5_expires_at")}</div>
        <div class="controls">
            {if $hypay_j5.expires_at}{$hypay_j5.expires_at|date_format:$hypay_date_format}{else}&mdash;{/if}
            {if $hypay_j5.is_expired}<div class="text-error"><small>{__("hypay_j5_expired")}</small></div>{/if}
        </div>
    </div>

    <div class="control-group">
        <div class="control-label">{__("hypay_j5_order_total")}</div>
        <div class="controls">
            {include file="common/price.tpl" value=$hypay_j5.order_total}
            {* the difference spelled out: comparing it against the hold two rows
               up is exactly the arithmetic nobody should be doing by eye *}
            {if $hypay_j5.amount_delta != 0}
                <div class="{if $hypay_j5.amount_delta > 0}text-error{else}text-warning{/if}"><small>
                    {if $hypay_j5.amount_delta > 0}+{else}&minus;{/if}{include file="common/price.tpl" value=$hypay_j5.amount_delta_abs}
                    {__("hypay_j5_delta_vs_hold")}
                </small></div>
            {/if}
        </div>
    </div>

    <div class="control-group">
        <div class="control-label">{__("hypay_j5_payments")}</div>
        <div class="controls">
            {if $hypay_j5.status == "authorized" && $hypay_j5.max_payments > 1}
                <select class="input-mini hypay-j5-payments">
                    {for $p = 1 to $hypay_j5.max_payments}
                        <option value="{$p}" {if $p == $hypay_j5.payments}selected="selected"{/if}>{$p}</option>
                    {/for}
                </select>
                <p class="muted description">{__("hypay_j5_payments_desc")}</p>
            {else}
                {$hypay_j5.payments}
            {/if}
        </div>
    </div>
{/if}

{if $hypay_j5.personal_id_needed || $hypay_j5.personal_id_asked}
    {* The cardholder's ID, offered only when the capture cannot use the one it
       has. The payment page does not always ask for one, and a Direct (debit)
       card's issuer refuses the capture when it is missing or wrong - the one
       refusal the merchant can undo from here, by reading the number off the
       customer. A hold whose ID is already a real one shows no field: it is
       printed in the Payment information block above, and retyping it cannot
       make it any more correct. *}
    <div class="control-group">
        <div class="control-label">{__("hypay_j5_personal_id")}</div>
        <div class="controls">
            <input type="text" class="input-medium hypay-j5-personal-id" maxlength="9"
                   inputmode="numeric" autocomplete="off"
                   value="{$hypay_j5.personal_id}" placeholder="{__("hypay_j5_personal_id_unknown")}" />

            {* the last capture was refused over the ID: a standing state of the
               hold, not a comment on what is in the field right now *}
            {if $hypay_j5.personal_id_asked}
                {if $hypay_j5.personal_id_was_valid}
                    {* the number sent had already passed the check digit, so
                       retyping the same one changes nothing - say what is left *}
                    <p class="text-error description">{__("hypay_j5_personal_id_refused_valid")}</p>
                {else}
                    <p class="text-error description">{__("hypay_j5_personal_id_refused")}</p>
                {/if}
            {/if}

            {* raised by the script below, and only while what has been typed
               cannot be an ID - an empty field is not an error, it is the
               normal state of a hold the payment page collected none for *}
            <p class="text-error description hypay-j5-personal-id-bad" style="display: none;">
                {__("hypay_j5_personal_id_bad")}</p>

            {capture name="hypay_hint_personal_id"}{__("hypay_j5_personal_id_desc")}{/capture}
            <p class="muted description">{__("hypay_j5_personal_id_desc_short")}<span
                class="cm-tooltip hypay-j5-hint" title="{$smarty.capture.hypay_hint_personal_id|escape}">i</span></p>
        </div>
    </div>
{/if}

{if $hypay_j5.status == "captured"}
    <div class="control-group">
        <div class="control-label">{__("hypay_j5_captured_amount")}</div>
        <div class="controls">
            {include file="common/price.tpl" value=$hypay_j5.amount_captured}
            {if $hypay_j5.captured_at}<div class="muted"><small>{$hypay_j5.captured_at|date_format:$hypay_date_format}</small></div>{/if}
        </div>
    </div>
    <div class="control-group">
        <div class="control-label">{__("hypay_j5_payments")}</div>
        <div class="controls">{$hypay_j5.payments_captured}</div>
    </div>
    {if $hypay_j5.capture_hyp_id}
        <div class="control-group">
            <div class="control-label">{__("hypay_j5_capture_transaction")}</div>
            <div class="controls"><bdi>{$hypay_j5.capture_hyp_id}</bdi></div>
        </div>
    {/if}
    {* the money came from a separate charge because the capture was refused:
       say so, and say what became of the hold that is still on the card *}
    {if $hypay_j5.captured_by_fallback}
        <div class="control-group">
            <div class="controls">
                <p class="text-warning">{__("hypay_j5_captured_by_fallback")}</p>
                {if $hypay_j5.hold_release_state == "confirmed"}
                    <p class="muted">{__("hypay_j5_fallback_hold_released")}</p>
                {else}
                    <p class="muted">{__("hypay_j5_fallback_hold_stays", ["[days]" => $hypay_j5.hold_days])}</p>
                {/if}
                {if $hypay_j5.last_error}<p class="muted"><small>{$hypay_j5.last_error}</small></p>{/if}
            </div>
        </div>
    {/if}
{/if}

{if $hypay_j5.status == "voided"}
    {if $hypay_j5.voided_at}
        <div class="control-group">
            <div class="control-label">{__("hypay_j5_voided_at")}</div>
            <div class="controls">{$hypay_j5.voided_at|date_format:$hypay_date_format}</div>
        </div>
    {/if}
    {if $hypay_j5.last_error}
        <div class="control-group">
            <div class="controls">
                {if $hypay_j5.void_state == "not_cancellable" && $hypay_j5.payments > 1}
                    <p class="muted">{__("hypay_j5_void_instalments_hint", ["[days]" => $hypay_j5.hold_days])}</p>
                {elseif $hypay_j5.void_state == "not_cancellable"}
                    <p class="muted">{__("hypay_j5_void_not_cancellable_hint", ["[days]" => $hypay_j5.hold_days])}</p>
                {else}
                    <p class="text-warning">{__("hypay_j5_void_not_confirmed_hint")}</p>
                {/if}
                <p class="muted"><small>{$hypay_j5.last_error}</small></p>
            </div>
        </div>
    {/if}
{/if}

{if $hypay_j5.status == "capturing"}
    <div class="control-group">
        <div class="controls">
            <p class="text-error">{__("hypay_j5_stuck_capturing")}</p>
            {if $hypay_j5.last_error}<p class="muted"><small>{$hypay_j5.last_error}</small></p>{/if}
        </div>
    </div>
{/if}

{if $hypay_j5.status == "authorized"}
    <div class="control-group">
        <div class="controls">
            {if $hypay_j5.amount_mismatch}
                <p class="text-error">{__("hypay_j5_warning_total_above_hold")}</p>
            {elseif $hypay_j5.amount_locked && $hypay_j5.amount_delta != 0}
                {* a smaller charge on such a card is a second sale, not a
                   partial capture: it is refused rather than warned about *}
                <p class="text-error">{__("hypay_j5_immediate_amount_locked_blocked")}</p>
            {elseif $hypay_j5.amount_delta < 0}
                <p class="text-warning">{__("hypay_j5_notice_partial_capture")}</p>
            {elseif $hypay_j5.amount_locked}
                <p class="muted">{__("hypay_j5_immediate_amount_locked")}</p>
            {/if}

            {if !$hypay_j5.has_token}
                <p class="text-warning">{__("hypay_j5_warning_no_token")}</p>
            {/if}

            {if $hypay_j5.last_error}
                <p class="text-error">{$hypay_j5.last_error}</p>
            {/if}

            {* plain links, not a form: this block lives inside order_info_form *}
            {if $hypay_j5.can_capture}
                <a class="btn btn-primary cm-post cm-confirm hypay-j5-action hypay-j5-capture-link" title="{__("hypay_j5_confirm_capture")}"
                   data-base="{"hypay.capture?order_id=`$hypay_j5.order_id`&amount=`$hypay_j5.order_total`"|fn_url}"
                   data-label="{__("hypay_j5_btn_capture")}"
                   data-payments="{$hypay_j5.payments}"
                   href="{"hypay.capture?order_id=`$hypay_j5.order_id`&amount=`$hypay_j5.order_total`&payments=`$hypay_j5.payments`"|fn_url}">{__("hypay_j5_btn_capture")}</a>
            {else}
                <span class="btn disabled">{__("hypay_j5_btn_capture")}</span>
            {/if}

            <a class="btn cm-post cm-confirm hypay-j5-action" title="{__("hypay_j5_confirm_void")}"
               href="{"hypay.void?order_id=`$hypay_j5.order_id`"|fn_url}">{__("hypay_j5_btn_void")}</a>

            {* one short line each, with the full explanation on the marker. The
               text goes through capture + escape rather than straight into the
               attribute: at least one translation contains a double quote
               (Hebrew writes a total as סה"כ) and would end the attribute early. *}
            {capture name="hypay_hint_capture"}{__("hypay_j5_actions_hint")}{/capture}
            <p class="muted description">{__("hypay_j5_actions_hint_short")}<span
                class="cm-tooltip hypay-j5-hint" title="{$smarty.capture.hypay_hint_capture|escape}">i</span></p>

            {if $hypay_j5.payments > 1}
                {capture name="hypay_hint_void"}{__("hypay_j5_void_instalments_notice", ["[days]" => $hypay_j5.hold_days])}{/capture}
                <p class="muted description">{__("hypay_j5_void_instalments_notice_short")}<span
                    class="cm-tooltip hypay-j5-hint" title="{$smarty.capture.hypay_hint_void|escape}">i</span></p>
            {/if}
        </div>
    </div>
{/if}

{* ----------------------------------------------------------------------------
 *  Capture / Cancel hold both make a server-to-server call to Hyp that can take
 *  a few seconds, and the page does not repaint until it answers. Cover it with
 *  a "working" overlay so nobody clicks twice, and keep the notification with
 *  the outcome on screen instead of letting it fade out.
 * ------------------------------------------------------------------------- *}
{literal}
<style>
.hypay-j5-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(255, 255, 255, .78);
    z-index: 100000;
}
.hypay-j5-overlay__box {
    position: absolute; top: 45%; left: 50%;
    margin: 0 0 0 -110px; width: 220px;
    padding: 22px 16px;
    background: #fff; border: 1px solid #d9d9d9; border-radius: 4px;
    box-shadow: 0 2px 14px rgba(0, 0, 0, .18);
    text-align: center; font-weight: bold;
}
.hypay-j5-spinner {
    display: block; margin: 0 auto 12px;
    width: 26px; height: 26px;
    border: 3px solid #e0e0e0; border-top-color: #4a90d9; border-radius: 50%;
    animation: hypay-j5-spin .8s linear infinite;
}
@keyframes hypay-j5-spin { to { transform: rotate(360deg); } }

/* the "i" marker carrying a hint too long to keep on screen */
.hypay-j5-hint {
    display: inline-block;
    width: 14px; height: 14px;
    margin: 0 4px;                 /* not margin-left: the admin may be RTL */
    border: 1px solid #b6b6b6; border-radius: 50%;
    background: #fff; color: #6b6b6b;
    font: bold 10px/14px sans-serif;
    text-align: center; vertical-align: middle;
    cursor: help;
}
</style>
{/literal}

<div id="hypay_j5_overlay" class="hypay-j5-overlay" style="display: none;">
    <div class="hypay-j5-overlay__box">
        <span class="hypay-j5-spinner"></span>
        <span>{__("hypay_j5_working")}</span>
    </div>
</div>

{literal}
<script type="text/javascript">
(function () {
    // Capture is a link, not a submit button - the panel is rendered inside
    // order_info_form and a nested form would be dropped by the browser. So the
    // two fields that shape the charge are written into its query string, and
    // rewritten together: a select that only knew about the instalments would
    // erase the ID beside it every time it changed.
    var link     = document.querySelector('.hypay-j5-capture-link');
    var payments = document.querySelector('.hypay-j5-payments');
    var personal = document.querySelector('.hypay-j5-personal-id');
    var bad_id   = document.querySelector('.hypay-j5-personal-id-bad');

    // the link is missing whenever Capture is not on offer - a total above the
    // hold, or an immediate-debit card the order has drifted away from. The ID
    // beside it is still worth checking as it is typed: it is what the next
    // attempt will carry once the order is put right.
    if (!link && !personal) { return; }

    // The same test the capture applies before it sends anything: up to nine
    // digits, not all zeros, and the last of them a check digit computed from
    // the other eight. Repeated here only so a mistyped number is answered
    // while it is being typed - hypay_is_israeli_id() still has the last word,
    // and a number this lets through is replaced by 000000000 there if it
    // turns out not to be one after all.
    var is_israeli_id = function (digits) {
        if (digits === '' || digits.length > 9 || parseInt(digits, 10) === 0) { return false; }

        while (digits.length < 9) { digits = '0' + digits; }

        var sum = 0;
        for (var i = 0; i < 9; i++) {
            var n = parseInt(digits.charAt(i), 10) * ((i % 2) + 1);
            sum += (n > 9) ? n - 9 : n;
        }

        return (sum % 10) === 0;
    };

    var sync = function () {
        var id = personal ? personal.value.replace(/\D+/g, '') : '';

        // an empty field is the normal state of a hold whose payment page never
        // asked for an ID, so only something typed and unusable is called out
        if (bad_id) {
            bad_id.style.display = (id !== '' && !is_israeli_id(id)) ? '' : 'none';
        }

        if (!link) { return; }

        var count = parseInt(payments ? payments.value : link.getAttribute('data-payments'), 10) || 1;
        var href  = link.getAttribute('data-base') + '&payments=' + count;
        if (id !== '') { href += '&personal_id=' + id; }

        link.href = href;
        link.innerHTML = link.getAttribute('data-label') + (count > 1 ? ' (' + count + ')' : '');
    };

    if (payments) { payments.addEventListener('change', sync); }
    if (personal) {
        personal.addEventListener('input', sync);
        personal.addEventListener('change', sync);
    }
    sync();
})();
</script>
{/literal}

{literal}
<script type="text/javascript">
(function () {
    var overlay = document.getElementById('hypay_j5_overlay');
    if (!overlay) { return; }

    // the confirmation dialog opens after this click, so arm the overlay here
    // and only raise it once the page really starts loading Hyp's answer
    var armed = false;
    var arm   = function () { armed = true; };
    var links = document.querySelectorAll('.hypay-j5-action');
    for (var i = 0; i < links.length; i++) {
        links[i].addEventListener('click', arm);
    }

    var show = function () { if (armed) { overlay.style.display = 'block'; } };
    window.addEventListener('beforeunload', show);
    window.addEventListener('pagehide', show);

    // returning through the browser cache must not leave it stuck on screen
    window.addEventListener('pageshow', function () {
        armed = false;
        overlay.style.display = 'none';
    });
})();
</script>
{/literal}

{if $smarty.request.hypay_result}
{literal}
<script type="text/javascript">
(function () {
    // this page was reached straight from Capture / Cancel hold: the
    // notification carries the outcome, so it stays until it is closed
    var unpin = function () {
        var list = document.querySelectorAll('.cm-auto-hide');
        for (var i = 0; i < list.length; i++) {
            list[i].className = list[i].className.replace(/(^|\s)cm-auto-hide(?=\s|$)/g, '');
        }
    };
    unpin();
    document.addEventListener('DOMContentLoaded', unpin);

    var jq = window.jQuery || window.$;
    if (!jq || !jq.fn) { return; }

    jq(function () {
        // the fade-out may already have been scheduled before the class was
        // stripped, so hold the notification open until it is dismissed
        var closed = false;
        jq(document).on('click', '.cm-notification-close, .notification-body .close, .close', function () {
            closed = true;
        });

        var stop_at = new Date().getTime() + 60000;
        var timer = window.setInterval(function () {
            if (closed || new Date().getTime() > stop_at) {
                window.clearInterval(timer);
                return;
            }
            unpin();
            jq('.cm-notification-content, .notification-content').each(function () {
                var note = jq(this);
                if (note.is(':hidden') || parseFloat(note.css('opacity')) < 1) {
                    note.stop(true, true).css('opacity', 1).show();
                }
            });
        }, 250);
    });
})();
</script>
{/literal}
{/if}

{/if}
{/if}
