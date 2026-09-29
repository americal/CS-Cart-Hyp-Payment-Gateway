{* ============================================================================
 *  Hypay payment link block (action=payRequest).
 *  Rendered by the "orders:payment_info" hook, next to the J5 block.
 *
 *  Unpaid order: a "Payment Link" button that opens a panel to create, copy,
 *  cancel or check the link, with what became of the last link underneath.
 *  Paid through a link: the date and time it was paid, in place of all that.
 * ========================================================================== *}

{if $runtime.controller == "orders" && $runtime.mode == "details"}
{$hypay_link = $order_info.order_id|fn_hypay_get_link_panel_data}

{if $hypay_link}
{$hypay_link_date_format = "`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}

<div class="control-group hypay-link-block">
    <div class="control-label">{__("hypay_link_title")}</div>
    <div class="controls">
        {if $hypay_link.state == "paid"}
            <span class="text-success"><strong>{__("hypay_link_paid_on", ["[date]" => $hypay_link.paid_at|date_format:$hypay_link_date_format])}</strong></span>
            {if $hypay_link.trans_id}<div class="muted"><small>{__("hypay_link_transaction")}: <bdi>{$hypay_link.trans_id}</bdi></small></div>{/if}
        {else}
            <button type="button" class="btn hypay-link-toggle">{__("hypay_link_btn")}</button>

            {* what became of the last link, under the button *}
            {if $hypay_link.state == "active"}
                <div class="text-success hypay-link-state"><small>{__("hypay_link_state_active", ["[date]" => $hypay_link.created_at|date_format:$hypay_link_date_format])}</small></div>
            {elseif $hypay_link.state == "cancelled"}
                <div class="muted hypay-link-state"><small>{__("hypay_link_state_cancelled", ["[date]" => $hypay_link.cancelled_at|date_format:$hypay_link_date_format])}</small></div>
            {/if}

            {* ----------------------------------------------------------------
             *  The panel. Plain links, not a form: this block lives inside
             *  order_info_form, and a nested form would be dropped.
             * -------------------------------------------------------------- *}
            <div class="hypay-link-panel" {if !$smarty.request.hypay_link_open}style="display: none;"{/if}>
                <div class="hypay-link-panel__head">
                    <strong>{__("hypay_link_panel_title", ["[order_id]" => $hypay_link.order_id])}</strong>
                    <button type="button" class="close hypay-link-close" title="{__("hypay_link_close")}">&times;</button>
                </div>

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
                        <button type="button" class="btn hypay-link-close">{__("hypay_link_close")}</button>
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

                    <div class="hypay-link-row muted">
                        <small>{__("hypay_link_amount")}: {include file="common/price.tpl" value=$hypay_link.order_total}</small>
                    </div>

                    <p class="text-error hypay-link-no-contact" style="display: none;">{__("hypay_link_error_no_contact")}</p>

                    <div class="hypay-link-actions">
                        {if $hypay_link.can_create}
                            <a class="btn btn-primary cm-post cm-confirm hypay-link-action hypay-link-create" title="{__("hypay_link_confirm_create")}"
                               data-base="{"hypay.link_create?order_id=`$hypay_link.order_id`"|fn_url}"
                               href="{"hypay.link_create?order_id=`$hypay_link.order_id`"|fn_url}">{__("hypay_link_create")}</a>
                        {else}
                            <span class="btn disabled">{__("hypay_link_create")}</span>
                        {/if}
                        <button type="button" class="btn hypay-link-close">{__("hypay_link_close")}</button>
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
.hypay-link-panel {
    margin-top: 10px; padding: 12px 14px;
    max-width: 560px;
    border: 1px solid #d9d9d9; border-radius: 4px;
    background: #fafafa;
}
.hypay-link-panel__head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 10px;
}
.hypay-link-row { margin-bottom: 8px; }
.hypay-link-label { display: block; margin-bottom: 4px; }
.hypay-link-copy-row { display: flex; gap: 6px; }
.hypay-link-copy-row .hypay-link-url { flex: 1 1 auto; margin: 0; min-width: 0; }
.hypay-link-actions .btn { margin: 0 4px 4px 0; }
.hypay-link-state { margin-top: 4px; }
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
    var block = document.querySelector('.hypay-link-block');
    if (!block) { return; }

    var panel  = block.querySelector('.hypay-link-panel');
    var toggle = block.querySelector('.hypay-link-toggle');

    // Payment Link opens the panel, and closes it again on a second click
    if (toggle && panel) {
        toggle.addEventListener('click', function () {
            panel.style.display = (panel.style.display === 'none') ? '' : 'none';
        });
    }

    var closers = block.querySelectorAll('.hypay-link-close');
    for (var i = 0; i < closers.length; i++) {
        closers[i].addEventListener('click', function () { panel.style.display = 'none'; });
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

    var sync = function () {
        var e = (send_email && send_email.checked && email) ? email.value.replace(/^\s+|\s+$/g, '') : '';
        var c = (send_sms && send_sms.checked && cell) ? cell.value.replace(/[^\d+]/g, '') : '';

        var href = create.getAttribute('data-base');
        if (e !== '') { href += '&email=' + encodeURIComponent(e); }
        if (c !== '') { href += '&cell=' + encodeURIComponent(c); }
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
    for (var j = 0; j < fields.length; j++) {
        if (!fields[j]) { continue; }
        fields[j].addEventListener('input', sync);
        fields[j].addEventListener('change', sync);
    }
    sync();
})();
</script>
{/literal}

{/if}
{/if}
