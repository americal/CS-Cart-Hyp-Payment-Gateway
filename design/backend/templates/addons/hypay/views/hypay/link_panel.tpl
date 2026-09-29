{* ============================================================================
 *  Hypay payment link window (action=payRequest).
 *
 *  Content of the dialog opened from the order's tools menu (the gear next to
 *  Save): hooks/orders/details_tools.post.tpl -> hypay.link_panel, loaded with
 *  cm-dialog-opener + cm-ajax. It lives outside the order form, so it has no
 *  place in Payment information and no form to stay out of.
 *
 *  No link yet: the orders to pay for, where to send it, Create.
 *  Active link: the link, Copy, Cancel, Check payment.
 *  Paid: when, the transaction, the document.
 * ========================================================================== *}

{if $hypay_link.hidden}
<p class="muted">{__("hypay_link_unavailable")} <small>({$hypay_link.hidden})</small></p>
{elseif $hypay_link}
{$hypay_link_date_format = "`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}

{* the orders a link pays for, when there is more than this one *}
{capture name="hypay_link_orders_line"}{if $hypay_link.link_orders|count > 1}{__("hypay_link_orders_in_link")}:{foreach $hypay_link.link_orders as $lo} <a href="{"orders.details?order_id=`$lo.order_id`"|fn_url}">#{$lo.order_id}</a>{if !$lo@last},{/if}{/foreach}{/if}{/capture}

<div class="hypay-link-block" id="hypay_link_dialog_block">
    <div class="hypay-link-panel">
        {if $hypay_link.state == "paid"}
            <span class="text-success"><strong>{__("hypay_link_paid_on", ["[date]" => $hypay_link.paid_at|date_format:$hypay_link_date_format])}</strong></span>
            {if $hypay_link.trans_id}<div class="muted"><small>{__("hypay_link_transaction")}: <bdi>{$hypay_link.trans_id}</bdi></small></div>{/if}
            {if $smarty.capture.hypay_link_orders_line|trim}<div class="muted"><small>{$smarty.capture.hypay_link_orders_line nofilter}</small></div>{/if}
            {* the document the payment produced, as a checkout payment records it *}
            {if $hypay_link.document}
                <div class="muted"><small>{__("hypay_link_document")}:
                    {if $hypay_link.document.invoice_type == "400"}{__("hypay_ez_doc_type_400")}{else}{__("hypay_ez_doc_type_320")}{/if}
                    #<bdi>{$hypay_link.document.ezcount_invoice_id}</bdi>{if $hypay_link.document.ezcount_invoice_url}
                    &middot; <a href="{$hypay_link.document.ezcount_invoice_url}" target="_blank" rel="noopener">PDF</a>{/if}
                </small></div>
            {elseif $hypay_link.doc_number}
                <div class="muted"><small>{__("hypay_link_document")}: #<bdi>{$hypay_link.doc_number}</bdi> ({__("hypay_link_document_by_hyp")})</small></div>
            {/if}
            <div class="hypay-link-actions">
                <button type="button" class="btn cm-dialog-closer hypay-link-close">{__("hypay_link_close")}</button>
            </div>
        {else}
            {* what became of the last link *}
            {if $hypay_link.state == "active"}
                <p class="text-success hypay-link-state">{__("hypay_link_state_active", ["[date]" => $hypay_link.created_at|date_format:$hypay_link_date_format])}</p>
            {elseif $hypay_link.state == "cancelled"}
                <p class="muted hypay-link-state">{__("hypay_link_state_cancelled", ["[date]" => $hypay_link.cancelled_at|date_format:$hypay_link_date_format])}</p>
            {/if}

            <div>

                {if $hypay_link.state == "active"}
                    <div class="hypay-link-row">
                        <label class="hypay-link-label" for="hypay_link_url">{__("hypay_link_url")}</label>
                        <div class="hypay-link-copy-row">
                            <input type="text" id="hypay_link_url" class="hypay-link-url" readonly="readonly"
                                   dir="ltr" value="{$hypay_link.payment_url|escape}" />
                            <button type="button" class="btn hypay-link-copy"
                                    data-copied="{__("hypay_link_copied")|escape}">{__("hypay_link_copy")}</button>
                        </div>
                    </div>

                    <div class="hypay-link-row muted">
                        <small>
                            {__("hypay_link_amount")}: {include file="common/price.tpl" value=$hypay_link.amount}
                            {if $hypay_link.sent_to} &middot; {__("hypay_link_sent_to")}: <bdi>{$hypay_link.sent_to}</bdi>{/if}
                        </small>
                        {if $smarty.capture.hypay_link_orders_line|trim}<div><small>{$smarty.capture.hypay_link_orders_line nofilter}</small></div>{/if}
                    </div>

                    {if $hypay_link.total_changed}
                        <p class="text-error">{__("hypay_link_warning_total_changed")}</p>
                    {/if}

                    {if $hypay_link.last_error}
                        <p class="text-warning"><small>{$hypay_link.last_error}</small></p>
                    {/if}

                    <div class="hypay-link-actions">
                        <a class="btn cm-post cm-confirm hypay-link-action" title="{__("hypay_link_confirm_cancel")}"
                           href="{"hypay.link_cancel?order_id=`$hypay_link.order_id`"|fn_url}">{__("hypay_link_cancel")}</a>
                        <a class="btn cm-post hypay-link-action"
                           href="{"hypay.link_check?order_id=`$hypay_link.order_id`"|fn_url}">{__("hypay_link_check")}</a>
                        <button type="button" class="btn cm-dialog-closer hypay-link-close">{__("hypay_link_close")}</button>
                    </div>
                {else}
                    <div class="hypay-link-row">
                        <label class="checkbox">
                            <input type="checkbox" class="hypay-link-send-email" {if $hypay_link.email}checked="checked"{/if} />
                            {__("hypay_link_send_email")}
                        </label>
                        <input type="email" class="input-large hypay-link-email" dir="ltr" autocomplete="off"
                               value="{$hypay_link.email|escape}" />
                    </div>
                    <div class="hypay-link-row">
                        <label class="checkbox">
                            <input type="checkbox" class="hypay-link-send-sms" {if $hypay_link.cell}checked="checked"{/if} />
                            {__("hypay_link_send_sms")}
                        </label>
                        <input type="text" class="input-medium hypay-link-cell" dir="ltr" inputmode="tel" autocomplete="off"
                               value="{$hypay_link.cell|escape}" placeholder="0501234567" />
                    </div>

                    {* Which orders the link pays for. This one is always in it; the
                       customer's other orders are offered by the statuses chosen
                       in the payment method settings, and only while they have
                       no document and no link of their own. *}
                    <div class="hypay-link-row">
                        <label class="hypay-link-label">{__("hypay_link_orders_title")}</label>
                        <table class="table table-condensed hypay-link-orders">
                            <thead>
                                <tr>
                                    <th width="1%"></th>
                                    <th>{__("hypay_link_col_order")}</th>
                                    <th>{__("hypay_link_col_date")}</th>
                                    <th>{__("hypay_link_col_status")}</th>
                                    <th class="right">{__("hypay_link_col_total")}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {foreach $hypay_link.candidates as $c}
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="hypay-link-order" value="{$c.order_id}"
                                                   data-total="{$c.total}"
                                                   {if $c.current}checked="checked" disabled="disabled"{/if} />
                                        </td>
                                        <td>
                                            {if $c.current}
                                                #{$c.order_id} <span class="muted">({__("hypay_link_current_order")})</span>
                                            {else}
                                                <a href="{"orders.details?order_id=`$c.order_id`"|fn_url}" target="_blank">#{$c.order_id}</a>
                                            {/if}
                                        </td>
                                        <td><small>{$c.timestamp|date_format:$hypay_link_date_format}</small></td>
                                        <td><small>{$c.status_name}</small></td>
                                        <td class="right">{include file="common/price.tpl" value=$c.total}</td>
                                    </tr>
                                {/foreach}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="right"><strong>{__("hypay_link_total_selected")}:</strong></td>
                                    <td class="right"><strong class="hypay-link-sum"
                                        data-symbol="{$currencies[$smarty.const.CART_PRIMARY_CURRENCY].symbol|default:""|escape}">{include file="common/price.tpl" value=$hypay_link.order_total}</strong></td>
                                </tr>
                            </tfoot>
                        </table>
                        {if $hypay_link.candidates|count <= 1}
                            <p class="muted"><small>{if $hypay_link.statuses_set}{__("hypay_link_no_other_orders")}{else}{__("hypay_link_no_statuses_set")}{/if}</small></p>
                        {/if}
                    </div>

                    {* what the payment will produce, per the payment method settings *}
                    {if $hypay_link.doc_type}
                        <div class="hypay-link-row muted">
                            <small>{__("hypay_link_document_after")}:
                                {if $hypay_link.doc_type.type == "none"}{__("hypay_ez_link_doc_type_none")}
                                {elseif $hypay_link.doc_type.type == "400"}{__("hypay_ez_doc_type_400")}
                                {else}{__("hypay_ez_doc_type_320")}{/if}
                                ({if $hypay_link.doc_type.mode == "direct"}Direct API{else}Integrated{/if})
                            </small>
                        </div>
                    {/if}

                    <p class="text-error hypay-link-no-contact" style="display: none;">{__("hypay_link_error_no_contact")}</p>

                    <div class="hypay-link-actions">
                        {if $hypay_link.can_create}
                            <a class="btn btn-primary cm-post cm-confirm hypay-link-action hypay-link-create" title="{__("hypay_link_confirm_create")}"
                               data-base="{"hypay.link_create?order_id=`$hypay_link.order_id`"|fn_url}"
                               href="{"hypay.link_create?order_id=`$hypay_link.order_id`"|fn_url}">{__("hypay_link_create")}</a>
                        {else}
                            <span class="btn disabled">{__("hypay_link_create")}</span>
                        {/if}
                        <button type="button" class="btn cm-dialog-closer hypay-link-close">{__("hypay_link_close")}</button>
                    </div>

                    {capture name="hypay_hint_link"}{__("hypay_link_hint")}{/capture}
                    <p class="muted description">{__("hypay_link_hint_short")}<span
                        class="cm-tooltip hypay-link-hint" title="{$smarty.capture.hypay_hint_link|escape}">i</span></p>
                {/if}
            </div>
        {/if}
    </div>
</div>

{literal}
<style>
.hypay-link-panel { padding: 4px 2px; min-width: 520px; }
@media (max-width: 640px) { .hypay-link-panel { min-width: 0; } }
.hypay-link-row { margin-bottom: 8px; }
.hypay-link-label { display: block; margin-bottom: 4px; }
.hypay-link-copy-row { display: flex; gap: 6px; }
.hypay-link-copy-row .hypay-link-url { flex: 1 1 auto; margin: 0; min-width: 0; }
.hypay-link-actions .btn { margin: 0 4px 4px 0; }
.hypay-link-state { margin-top: 4px; }
.hypay-link-orders { margin-bottom: 4px; background: #fff; }
.hypay-link-orders td, .hypay-link-orders th { vertical-align: middle; }
.hypay-link-orders .right { text-align: right; }
.hypay-link-hint {
    display: inline-block;
    width: 14px; height: 14px;
    margin: 0 4px;
    border: 1px solid #b6b6b6; border-radius: 50%;
    background: #fff; color: #6b6b6b;
    font: bold 10px/14px sans-serif;
    text-align: center; vertical-align: middle;
    cursor: help;
}
</style>

<script type="text/javascript">
(function () {
    // This arrives by ajax into the dialog, and CS-Cart may run it a moment
    // before the markup is in place - so it waits for the block, briefly.
    var tries = 0;
    var init  = function () {
    var block = document.getElementById('hypay_link_dialog_block');
    if (!block) {
        if (tries++ < 20) { window.setTimeout(init, 50); }
        return;
    }
    if (block.getAttribute('data-hypay-ready')) { return; }
    block.setAttribute('data-hypay-ready', '1');

    // Close: cm-dialog-closer is CS-Cart's own; this is the fallback for a
    // build that does not know it
    var closers = block.querySelectorAll('.hypay-link-close');
    for (var i = 0; i < closers.length; i++) {
        closers[i].addEventListener('click', function () {
            var jq = window.jQuery || (window.Tygh && window.Tygh.$);
            if (!jq) { return; }
            var dialog = jq(block).closest('.ui-dialog-content');
            if (dialog.length && dialog.is(':visible')) {
                try { dialog.dialog('close'); } catch (e) {}
            }
        });
    }

    // Copy: the clipboard API where the page is allowed it, the old selection
    // trick where it is not (plain http admin panels)
    var copy = block.querySelector('.hypay-link-copy');
    var url  = block.querySelector('.hypay-link-url');
    if (copy && url) {
        var label = copy.innerHTML;
        var done  = function () {
            copy.innerHTML = copy.getAttribute('data-copied');
            window.setTimeout(function () { copy.innerHTML = label; }, 1500);
        };
        var fallback = function () {
            url.focus();
            url.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
        };
        copy.addEventListener('click', function () {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url.value).then(done, fallback);
            } else {
                fallback();
            }
        });
        url.addEventListener('focus', function () { url.select(); });
    }

    // Create: the contacts it is sent to go into the link's query string, and
    // the button is held back while there is nowhere to send it
    var create     = block.querySelector('.hypay-link-create');
    var send_email = block.querySelector('.hypay-link-send-email');
    var send_sms   = block.querySelector('.hypay-link-send-sms');
    var email      = block.querySelector('.hypay-link-email');
    var cell       = block.querySelector('.hypay-link-cell');
    var no_contact = block.querySelector('.hypay-link-no-contact');

    if (!create) { return; }

    var orders = block.querySelectorAll('.hypay-link-order');
    var sum    = block.querySelector('.hypay-link-sum');

    var sync = function () {
        var e = (send_email && send_email.checked && email) ? email.value.replace(/^\s+|\s+$/g, '') : '';
        var c = (send_sms && send_sms.checked && cell) ? cell.value.replace(/[^\d+]/g, '') : '';

        // the other orders ticked in the table, and what they all add up to -
        // this order is always in, its box is only there to show it
        var ids   = [];
        var total = 0;
        for (var k = 0; k < orders.length; k++) {
            if (!orders[k].checked) { continue; }
            total += parseFloat(orders[k].getAttribute('data-total')) || 0;
            if (!orders[k].disabled) { ids.push(orders[k].value); }
        }
        if (sum) {
            var symbol = sum.getAttribute('data-symbol') || '';
            sum.innerHTML = symbol + total.toFixed(2);
        }

        var href = create.getAttribute('data-base');
        if (e !== '') { href += '&email=' + encodeURIComponent(e); }
        if (c !== '') { href += '&cell=' + encodeURIComponent(c); }
        if (ids.length) { href += '&order_ids=' + ids.join(','); }
        create.href = href;

        var ok = (e !== '' || c !== '');
        create.className = create.className.replace(/(^|\s)disabled(?=\s|$)/g, '') + (ok ? '' : ' disabled');
        if (no_contact) { no_contact.style.display = ok ? 'none' : ''; }
    };

    // a disabled-looking link must not go anywhere either
    create.addEventListener('click', function (event) {
        if (/(^|\s)disabled(\s|$)/.test(create.className)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    var fields = [send_email, send_sms, email, cell];
    for (var m = 0; m < orders.length; m++) { fields.push(orders[m]); }
    for (var j = 0; j < fields.length; j++) {
        if (!fields[j]) { continue; }
        fields[j].addEventListener('input', sync);
        fields[j].addEventListener('change', sync);
    }
    sync();
    };

    init();
})();
</script>
{/literal}

{/if}
