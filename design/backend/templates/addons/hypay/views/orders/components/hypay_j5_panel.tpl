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
                <a class="btn btn-primary cm-post cm-confirm" title="{__("hypay_j5_confirm_capture")}"
                   href="{"hypay.capture?order_id=`$hypay_j5.order_id`&amount=`$hypay_j5.order_total`"|fn_url}">{__("hypay_j5_btn_capture")}</a>
            {else}
                <span class="btn disabled">{__("hypay_j5_btn_capture")}</span>
            {/if}

            <a class="btn cm-post cm-confirm" title="{__("hypay_j5_confirm_void")}"
               href="{"hypay.void?order_id=`$hypay_j5.order_id`"|fn_url}">{__("hypay_j5_btn_void")}</a>

            <p class="muted description">{__("hypay_j5_actions_hint")}</p>
        </div>
    </div>
{/if}
{/if}
{/if}
