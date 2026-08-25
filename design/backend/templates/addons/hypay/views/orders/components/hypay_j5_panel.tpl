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

{if $hypay_j5.acode}
<div class="control-group">
    <div class="control-label">{__("hypay_j5_auth_number")}</div>
    <div class="controls"><bdi>{$hypay_j5.acode}</bdi>{if $hypay_j5.hyp_id} <span class="muted"><small>(Id: {$hypay_j5.hyp_id})</small></span>{/if}</div>
</div>
{/if}

{if $hypay_j5.uid}
<div class="control-group">
    <div class="control-label">{__("hypay_j5_uid")}</div>
    <div class="controls">
        <bdi><small>{$hypay_j5.uid}</small></bdi>
        <p class="muted description">{__("hypay_j5_uid_desc")}</p>
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
        <div class="controls">{include file="common/price.tpl" value=$hypay_j5.order_total}</div>
    </div>

    <div class="control-group">
        <div class="control-label">{__("hypay_j5_payments")}</div>
        <div class="controls">
            {if $hypay_j5.status == "authorized" && $hypay_j5.max_payments > 1}
                <select id="hypay_j5_payments_{$hypay_j5.order_id}" class="input-mini"
                        onchange="var a = document.getElementById('hypay_j5_capture_{$hypay_j5.order_id}');
                                  if (a) {ldelim}
                                      a.href = a.getAttribute('data-base') + '&payments=' + this.value;
                                      a.innerHTML = a.getAttribute('data-label') + (this.value > 1 ? ' (' + this.value + ')' : '');
                                  {rdelim}">
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
                <p class="text-warning">{__("hypay_j5_void_not_confirmed_hint")}</p>
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
            {elseif $hypay_j5.order_total < $hypay_j5.amount_authorized}
                <p class="muted">{__("hypay_j5_notice_partial_capture")}</p>
            {/if}

            {if !$hypay_j5.has_token}
                <p class="text-warning">{__("hypay_j5_warning_no_token")}</p>
            {/if}

            {if $hypay_j5.last_error}
                <p class="text-error">{$hypay_j5.last_error}</p>
            {/if}

            {* plain links, not a form: this block lives inside order_info_form *}
            {if $hypay_j5.can_capture}
                <a class="btn btn-primary cm-post cm-confirm hypay-j5-action" title="{__("hypay_j5_confirm_capture")}"
                   id="hypay_j5_capture_{$hypay_j5.order_id}"
                   data-base="{"hypay.capture?order_id=`$hypay_j5.order_id`&amount=`$hypay_j5.order_total`"|fn_url}"
                   data-label="{__("hypay_j5_btn_capture")}"
                   href="{"hypay.capture?order_id=`$hypay_j5.order_id`&amount=`$hypay_j5.order_total`&payments=`$hypay_j5.payments`"|fn_url}">{__("hypay_j5_btn_capture")}</a>
            {else}
                <span class="btn disabled">{__("hypay_j5_btn_capture")}</span>
            {/if}

            <a class="btn cm-post cm-confirm hypay-j5-action" title="{__("hypay_j5_confirm_void")}"
               href="{"hypay.void?order_id=`$hypay_j5.order_id`"|fn_url}">{__("hypay_j5_btn_void")}</a>

            <p class="muted description">{__("hypay_j5_actions_hint")}</p>
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
