{* ============================================================================
 *  Hypay J5 (two-phase commit) block for the order details page.
 *  Rendered once per page, right after the "Payment information" section.
 * ========================================================================== *}

{if $runtime.controller == "orders" && $runtime.mode == "details" && $hypay_j5_panel_rendered != $order_info.order_id}
{$hypay_j5 = $order_info.order_id|fn_hypay_get_j5_panel_data}

{if $hypay_j5}
{$hypay_j5_panel_rendered = $order_info.order_id scope="root"}
{$hypay_date_format = $settings.Appearance.date_format}

<div class="hypay-j5-block" style="margin-top: 10px;">
    <h6>{__("hypay_j5_title")}</h6>

    <table class="table table-condensed">
        <tbody>
            <tr>
                <td>{__("hypay_j5_status")}</td>
                <td>
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
                </td>
            </tr>
            <tr>
                <td>{__("hypay_j5_authorized_amount")}</td>
                <td>{include file="common/price.tpl" value=$hypay_j5.amount_authorized}
                    {if $hypay_j5.authorized_at}<span class="muted"> — {$hypay_j5.authorized_at|date_format:$hypay_date_format}</span>{/if}
                </td>
            </tr>
            <tr>
                <td>{__("hypay_j5_auth_number")}</td>
                <td>{$hypay_j5.acode}{if $hypay_j5.hyp_id} <span class="muted">(Id: {$hypay_j5.hyp_id})</span>{/if}</td>
            </tr>
            {if $hypay_j5.status == "authorized" || $hypay_j5.status == "capturing"}
                <tr>
                    <td>{__("hypay_j5_expires_at")}</td>
                    <td>
                        {if $hypay_j5.expires_at}{$hypay_j5.expires_at|date_format:$hypay_date_format}{else}&mdash;{/if}
                        {if $hypay_j5.is_expired}<span class="text-error"> — {__("hypay_j5_expired")}</span>{/if}
                    </td>
                </tr>
                <tr>
                    <td>{__("hypay_j5_order_total")}</td>
                    <td>{include file="common/price.tpl" value=$hypay_j5.order_total}</td>
                </tr>
            {/if}
            {if $hypay_j5.status == "captured"}
                <tr>
                    <td>{__("hypay_j5_captured_amount")}</td>
                    <td>{include file="common/price.tpl" value=$hypay_j5.amount_captured}
                        {if $hypay_j5.captured_at}<span class="muted"> — {$hypay_j5.captured_at|date_format:$hypay_date_format}</span>{/if}
                    </td>
                </tr>
                {if $hypay_j5.capture_hyp_id}
                    <tr>
                        <td>{__("hypay_j5_capture_transaction")}</td>
                        <td>{$hypay_j5.capture_hyp_id}</td>
                    </tr>
                {/if}
            {/if}
            {if $hypay_j5.status == "voided" && $hypay_j5.voided_at}
                <tr>
                    <td>{__("hypay_j5_voided_at")}</td>
                    <td>{$hypay_j5.voided_at|date_format:$hypay_date_format}</td>
                </tr>
            {/if}
        </tbody>
    </table>

    {if $hypay_j5.status == "capturing"}
        <p class="text-error">{__("hypay_j5_stuck_capturing")}</p>
        {if $hypay_j5.last_error}<p class="muted">{$hypay_j5.last_error}</p>{/if}
    {/if}

    {if $hypay_j5.status == "authorized"}
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

        <form action="{""|fn_url}" method="post" name="hypay_j5_form_{$hypay_j5.order_id}">
            <input type="hidden" name="order_id" value="{$hypay_j5.order_id}" />
            <input type="hidden" name="amount" value="{$hypay_j5.order_total}" />

            {if $hypay_j5.can_capture}
                <button class="btn btn-primary" type="submit" name="dispatch[hypay.capture]"
                        onclick="return confirm('{__("hypay_j5_confirm_capture")|escape:"javascript"}');">
                    {__("hypay_j5_btn_capture")}
                </button>
            {else}
                <button class="btn" type="button" disabled="disabled">{__("hypay_j5_btn_capture")}</button>
            {/if}

            <button class="btn" type="submit" name="dispatch[hypay.void]"
                    onclick="return confirm('{__("hypay_j5_confirm_void")|escape:"javascript"}');">
                {__("hypay_j5_btn_void")}
            </button>
        </form>

        <p class="muted description">{__("hypay_j5_actions_hint")}</p>
    {/if}
</div>
{/if}
{/if}
