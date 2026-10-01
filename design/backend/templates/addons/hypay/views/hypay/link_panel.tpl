{* ============================================================================
 *  Hypay payment link window.
 *
 *  Content of the dialog opened from the order's tools menu (the gear next to
 *  Save): hooks/orders/details_tools.post.tpl -> hypay.link_panel, loaded with
 *  cm-dialog-opener + cm-ajax. Its buttons do not leave the page: they post to
 *  hypay.link_* with hypay_ajax=1 and the window is redrawn in place with the
 *  answer - no reload, and no dialog opening itself again afterwards.
 *
 *  No link yet: the customer, J4 (charge now, the default) or J5 (hold only,
 *  this order alone), where to send it - or nowhere, to copy it yourself -
 *  the orders to pay for (with the document each already has), Create.
 *  The buttons are the window's footer: always in view, the rest scrolls.
 *  Active link: the link, Copy, the orders it covers, Check payment, Cancel.
 *  Paid: when, the transaction, the document.
 *  Expired (past the lifetime the settings give a link): when it ran out, and
 *  the same form as for no link, to make a new one.
 * ========================================================================== *}

{if $hypay_link.hidden}
<div class="hypay-lp">
    <div class="hypay-lp-alert hypay-lp-alert--w">{__("hypay_link_unavailable")} <small>({$hypay_link.hidden})</small></div>
</div>
{elseif $hypay_link}
{$hypay_lp_date = "`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}

<div class="hypay-lp" id="hypay_link_dialog_block"
     data-state="{$hypay_link.state}"
     data-currency="{$hypay_link.currency}"
     data-url-create="{"hypay.link_create"|fn_url}"
     data-url-cancel="{"hypay.link_cancel"|fn_url}"
     data-url-check="{"hypay.link_check"|fn_url}"
     data-order-id="{$hypay_link.order_id}"
     data-confirm-create="{__("hypay_link_confirm_create")}"
     data-confirm-cancel="{__("hypay_link_confirm_cancel")}"
     data-label-create="{__("hypay_link_create_only")}"
     data-label-send="{__("hypay_link_create")}"
     data-error-network="{__("hypay_link_error_network")}">

    {* everything but the buttons: scrolls inside the window when it is long *}
    <div class="hypay-lp-body">

    {* the outcome of the last button, when the window was redrawn by one *}
    {foreach $hypay_link_notices|default:[] as $notice}
        <div class="hypay-lp-alert hypay-lp-alert--{$notice.type|lower}">{$notice.message}</div>
    {/foreach}

    {* --------------------------------------------------------------- customer *}
    <div class="hypay-lp-card hypay-lp-summary">
        <div class="hypay-lp-summary__item">
            <span class="hypay-lp-summary__label">{__("hypay_link_customer")}</span>
            <span class="hypay-lp-summary__value">{$hypay_link.customer_name|default:"—"}</span>
        </div>
        <div class="hypay-lp-summary__item">
            <span class="hypay-lp-summary__label">{__("email")}</span>
            <span class="hypay-lp-summary__value"><bdi>{$hypay_link.email|default:"—"}</bdi></span>
        </div>
        <div class="hypay-lp-summary__item">
            <span class="hypay-lp-summary__label">{__("phone")}</span>
            <span class="hypay-lp-summary__value"><bdi>{$hypay_link.cell|default:"—"}</bdi></span>
        </div>
        {if $hypay_link.doc_type}
            <div class="hypay-lp-summary__item">
                <span class="hypay-lp-summary__label">{__("hypay_link_document_after")}</span>
                <span class="hypay-lp-summary__value">
                    {if $hypay_link.doc_type.type == "none"}{__("hypay_ez_link_doc_type_none")}
                    {elseif $hypay_link.doc_type.type == "400"}{__("hypay_ez_doc_type_400")}
                    {else}{__("hypay_ez_doc_type_320")}{/if}
                    <small class="muted">({if $hypay_link.doc_type.mode == "direct"}Direct API{else}Integrated{/if})</small>
                </span>
            </div>
        {/if}
    </div>

    {if $hypay_link.state == "paid"}
        {* ---------------------------------------------------------------- paid *}
        <div class="hypay-lp-banner hypay-lp-banner--ok">
            {if $hypay_link.j5}
                <strong>✓ {__("hypay_link_authorized_on", ["[date]" => $hypay_link.paid_at|date_format:$hypay_lp_date])}</strong>
                <div class="hypay-lp-banner__meta">{__("hypay_link_authorized_capture_hint")}</div>
            {else}
                <strong>✓ {__("hypay_link_paid_on", ["[date]" => $hypay_link.paid_at|date_format:$hypay_lp_date])}</strong>
            {/if}
            <div class="hypay-lp-banner__meta">
                {__("hypay_link_amount")}: <strong>{include file="common/price.tpl" value=$hypay_link.amount}</strong>
                {if $hypay_link.trans_id} &middot; {__("hypay_link_transaction")}: <bdi>{$hypay_link.trans_id}</bdi>{/if}
                {if $hypay_link.document}
                    &middot; {__("hypay_link_document")}:
                    {if $hypay_link.document.invoice_type == "400"}{__("hypay_ez_doc_type_400")}{else}{__("hypay_ez_doc_type_320")}{/if}
                    #<bdi>{$hypay_link.document.ezcount_invoice_id}</bdi>{if $hypay_link.document.ezcount_invoice_url}
                    (<a href="{$hypay_link.document.ezcount_invoice_url}" target="_blank" rel="noopener">PDF</a>){/if}
                {elseif $hypay_link.doc_number}
                    &middot; {__("hypay_link_document")}: #<bdi>{$hypay_link.doc_number}</bdi> <small>({__("hypay_link_document_by_hyp")})</small>
                {/if}
            </div>
            {if $hypay_link.last_error}<div class="hypay-lp-banner__meta">{$hypay_link.last_error}</div>{/if}
        </div>
    {elseif $hypay_link.state == "active"}
        {* -------------------------------------------------------------- active *}
        <div class="hypay-lp-card">
            <div class="hypay-lp-card__title">
                {__("hypay_link_url")}
                <span class="hypay-lp-badge hypay-lp-badge--ok">{__("hypay_link_state_active", ["[date]" => $hypay_link.created_at|date_format:$hypay_lp_date])}</span>
                <span class="hypay-lp-badge hypay-lp-badge--muted">{if $hypay_link.j5}{__("hypay_link_deal_j5")}{else}{__("hypay_link_deal_j4")}{/if}</span>
                {if $hypay_link.expires_at}
                    <span class="hypay-lp-badge hypay-lp-badge--muted">{__("hypay_link_expires_on", ["[date]" => $hypay_link.expires_at|date_format:$hypay_lp_date])}</span>
                {/if}
            </div>
            <div class="hypay-lp-copy">
                <input type="text" class="hypay-lp-url" readonly="readonly" dir="ltr" value="{$hypay_link.payment_url}" />
                <button type="button" class="btn btn-primary hypay-lp-copy-btn" data-copied="{__("hypay_link_copied")}">{__("hypay_link_copy")}</button>
                <a class="btn" href="{$hypay_link.payment_url}" target="_blank" rel="noopener">{__("hypay_link_open")}</a>
            </div>
            <div class="hypay-lp-meta">
                {__("hypay_link_amount")}: <strong>{include file="common/price.tpl" value=$hypay_link.amount}</strong>
                &middot;
                {if $hypay_link.sent_to}
                    {__("hypay_link_sent_to")}: <bdi>{$hypay_link.sent_to}</bdi>
                {else}
                    {__("hypay_link_not_sent")}
                {/if}
            </div>
            {if $hypay_link.total_changed}
                <div class="hypay-lp-alert hypay-lp-alert--e">{__("hypay_link_warning_total_changed")}</div>
            {/if}
            {if $hypay_link.last_error}
                <div class="hypay-lp-alert hypay-lp-alert--w">{$hypay_link.last_error}</div>
            {/if}
        </div>
    {elseif $hypay_link.state == "cancelled"}
        <div class="hypay-lp-banner hypay-lp-banner--muted">
            {__("hypay_link_state_cancelled", ["[date]" => $hypay_link.cancelled_at|date_format:$hypay_lp_date])}
            {if $hypay_link.last_error}<div class="hypay-lp-banner__meta">{$hypay_link.last_error}</div>{/if}
        </div>
    {elseif $hypay_link.state == "expired"}
        <div class="hypay-lp-banner hypay-lp-banner--warn">
            <strong>{__("hypay_link_state_expired", ["[date]" => $hypay_link.cancelled_at|date_format:$hypay_lp_date])}</strong>
            <div class="hypay-lp-banner__meta">{__("hypay_link_expired_recreate")}</div>
            {if $hypay_link.last_error}<div class="hypay-lp-banner__meta">{$hypay_link.last_error}</div>{/if}
        </div>
    {/if}

    {$hypay_lp_pick = ($hypay_link.state != "active" && $hypay_link.state != "paid")}

    {* ------------------------------------------------------------- deal type *}
    {if $hypay_lp_pick}
        <div class="hypay-lp-card">
            <div class="hypay-lp-card__title">{__("hypay_link_deal")}</div>
            <label class="radio hypay-lp-deal">
                <input type="radio" name="hypay_lp_deal" class="hypay-lp-deal-input" value="j4" checked="checked" />
                {__("hypay_link_deal_j4")}
                <small class="muted">— {__("hypay_link_deal_j4_desc")}</small>
            </label>
            <label class="radio hypay-lp-deal">
                <input type="radio" name="hypay_lp_deal" class="hypay-lp-deal-input" value="j5" />
                {__("hypay_link_deal_j5")}
                <small class="muted">— {__("hypay_link_deal_j5_desc")}</small>
            </label>
        </div>
    {/if}

    {* ---------------------------------------------------------------- delivery *}
    {if $hypay_lp_pick}
        <div class="hypay-lp-card">
            <div class="hypay-lp-card__title">{__("hypay_link_delivery")}</div>
            <div class="hypay-lp-send">
                <label class="checkbox hypay-lp-send__toggle">
                    <input type="checkbox" class="hypay-lp-send-email" />
                    {__("hypay_link_send_email")}
                </label>
                <input type="email" class="input-large hypay-lp-email" dir="ltr" autocomplete="off" value="{$hypay_link.email}" />
            </div>
            <div class="hypay-lp-send">
                <label class="checkbox hypay-lp-send__toggle">
                    <input type="checkbox" class="hypay-lp-send-sms" />
                    {__("hypay_link_send_sms")}
                </label>
                <input type="text" class="input-medium hypay-lp-cell" dir="ltr" inputmode="tel" autocomplete="off"
                       value="{$hypay_link.cell}" placeholder="0501234567" />
            </div>
            <p class="muted hypay-lp-note hypay-lp-copy-only">{__("hypay_link_copy_only_hint")}</p>
        </div>
    {/if}

    {* ------------------------------------------------------------------ orders *}
    {if $hypay_link.state == "active" || $hypay_link.state == "paid"}
        {$hypay_lp_rows = $hypay_link.link_orders}
    {else}
        {$hypay_lp_rows = $hypay_link.candidates}
    {/if}

    <div class="hypay-lp-toolbar">
        <div><strong>{__("hypay_link_total_orders")}:</strong> <span class="hypay-lp-count">{$hypay_lp_rows|count}</span></div>
        {if $hypay_lp_pick && $hypay_link.candidate_statuses|count > 1}
            <div>
                <label class="hypay-lp-inline">{__("hypay_link_status_filter")}:
                    <select class="input-medium hypay-lp-filter">
                        <option value="">{__("all")}</option>
                        {foreach $hypay_link.candidate_statuses as $code => $name}
                            <option value="{$code}">{$name}</option>
                        {/foreach}
                    </select>
                </label>
            </div>
        {/if}
        <div class="hypay-lp-toolbar__sum">
            <strong>{if $hypay_lp_pick}{__("hypay_link_total_selected")}{else}{__("hypay_link_amount")}{/if}:</strong>
            {if $hypay_lp_pick}{$hypay_lp_sum = $hypay_link.order_total}{else}{$hypay_lp_sum = $hypay_link.amount}{/if}
            <span class="hypay-lp-sum">{include file="common/price.tpl" value=$hypay_lp_sum}</span>
        </div>
    </div>

    <div class="hypay-lp-table-wrap">
    <table class="table table-middle hypay-lp-table">
        <thead>
            <tr>
                {if $hypay_lp_pick}<th width="1%"></th>{/if}
                <th>{__("hypay_link_col_order")}</th>
                <th>{__("hypay_link_col_date")}</th>
                <th class="hypay-lp-right">{__("hypay_link_col_total")}</th>
                <th>{__("hypay_link_col_status")}</th>
                <th class="hypay-lp-center">{__("hypay_link_col_document")}</th>
                <th>{__("hypay_link_col_doc_type")}</th>
                <th>{__("hypay_link_col_doc_number")}</th>
            </tr>
        </thead>
        <tbody>
            {foreach $hypay_lp_rows as $r}
                <tr class="hypay-lp-row{if $hypay_lp_pick && !$r.selectable && !$r.current} hypay-lp-row--locked{/if}{if $r.current} hypay-lp-row--current{/if}"
                    data-status="{$r.status}">
                    {if $hypay_lp_pick}
                        <td>
                            <input type="checkbox" class="hypay-lp-order" value="{$r.order_id}" data-total="{$r.total}"
                                   data-selectable="{if $r.selectable && !$r.current}1{else}0{/if}"
                                   {if $r.current}checked="checked" disabled="disabled"{elseif !$r.selectable}disabled="disabled"{/if} />
                        </td>
                    {/if}
                    <td>
                        <a href="{"orders.details?order_id=`$r.order_id`"|fn_url}" target="_blank">#{$r.order_id}</a>
                        {if $r.current}<span class="hypay-lp-tag">{__("hypay_link_current_order")}</span>{/if}
                        {if $hypay_lp_pick && $r.link_state == "active"}<span class="hypay-lp-tag hypay-lp-tag--warn">{__("hypay_link_in_other_link")}</span>{/if}
                        {if $hypay_lp_pick && $r.link_state == "paid"}<span class="hypay-lp-tag hypay-lp-tag--ok">{__("hypay_link_paid_by_link")}</span>{/if}
                    </td>
                    <td class="nowrap">{$r.timestamp|date_format:$hypay_lp_date}</td>
                    <td class="hypay-lp-right nowrap">{include file="common/price.tpl" value=$r.total}</td>
                    <td>{$r.status_name}</td>
                    <td class="hypay-lp-center">{if $r.doc_number}<span class="hypay-lp-yes">✔</span>{else}<span class="hypay-lp-no">✘</span>{/if}</td>
                    <td>{$r.doc_type|default:""}</td>
                    <td><bdi>{$r.doc_number|default:""}</bdi></td>
                </tr>
            {/foreach}
        </tbody>
    </table>
    </div>

    {if $hypay_lp_pick && $hypay_lp_rows|count <= 1}
        <p class="muted hypay-lp-note">{if $hypay_link.statuses_set}{__("hypay_link_no_other_orders")}{else}{__("hypay_link_no_statuses_set")}{/if}</p>
    {/if}

    </div>

    {* ------------------------------------------------- buttons (Close, then the
       actions, the main one rightmost): the window's footer, always in view
       below the body however long the orders table grows *}
    <div class="hypay-lp-actions">
        <span class="hypay-lp-busy" style="display: none;"><span class="hypay-lp-spinner"></span>{__("hypay_j5_working")}</span>
        <button type="button" class="btn cm-dialog-closer hypay-lp-close">{__("hypay_link_close")}</button>
        {if $hypay_lp_pick}
            {if $hypay_link.can_create}
                <button type="button" class="btn btn-primary hypay-lp-action" data-action="create">{__("hypay_link_create")}</button>
            {else}
                <button type="button" class="btn btn-primary" disabled="disabled">{__("hypay_link_create")}</button>
            {/if}
        {elseif $hypay_link.state == "active"}
            {if $hypay_link.kind != "sign"}
                <button type="button" class="btn hypay-lp-action" data-action="check">{__("hypay_link_check")}</button>
            {/if}
            <button type="button" class="btn hypay-lp-action hypay-lp-danger" data-action="cancel">{__("hypay_link_cancel")}</button>
        {/if}
    </div>
</div>

{literal}
<style>
/* the window is fitted to the dialog (see fit below): a body that scrolls and
   a footer with the buttons, edge to edge, that does not */
.hypay-lp { min-width: 760px; font-size: 13px; display: flex; flex-direction: column; box-sizing: border-box; }
.hypay-lp-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 12px 16px 4px; }
@media (max-width: 820px) { .hypay-lp { min-width: 0; } }
.hypay-lp-card { border: 1px solid #e3e6ea; border-radius: 8px; background: #fafbfc; padding: 12px 16px; margin-bottom: 14px; }
.hypay-lp-card__title { font-weight: 600; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.hypay-lp-summary { display: flex; flex-wrap: wrap; gap: 14px 40px; }
.hypay-lp-summary__item { display: flex; flex-direction: column; min-width: 140px; }
.hypay-lp-summary__label { color: #8a9099; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 2px; }
.hypay-lp-summary__value { font-weight: 600; }
.hypay-lp-banner { border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; }
.hypay-lp-banner--ok { background: #eaf7ee; border: 1px solid #b9e3c6; color: #1e6b36; }
.hypay-lp-banner--muted { background: #f4f5f7; border: 1px solid #e3e6ea; color: #6b7280; }
.hypay-lp-banner--warn { background: #fff7e6; border: 1px solid #f3d9a4; color: #8a5a00; }
.hypay-lp-banner__meta { margin-top: 4px; color: #3f4a54; }
.hypay-lp-alert { border-radius: 6px; padding: 8px 12px; margin: 0 0 10px; }
.hypay-lp-alert--n, .hypay-lp-alert--i { background: #eaf7ee; color: #1e6b36; border: 1px solid #b9e3c6; }
.hypay-lp-alert--w { background: #fff7e6; color: #8a5a00; border: 1px solid #f3d9a4; }
.hypay-lp-alert--e { background: #fdecec; color: #a12626; border: 1px solid #f3bcbc; }
.hypay-lp-toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 28px; margin: 4px 0 8px; }
.hypay-lp-toolbar__sum { margin-left: auto; font-size: 14px; }
.hypay-lp-sum { font-weight: 700; }
.hypay-lp-inline { display: inline-flex; align-items: center; gap: 6px; margin: 0; }
.hypay-lp-inline select { margin: 0; }
.hypay-lp-table-wrap { overflow-x: auto; margin-bottom: 8px; }
.hypay-lp-table { width: 100%; background: #fff; border: 1px solid #e3e6ea; border-radius: 8px; border-collapse: separate; margin-bottom: 0; }
.hypay-lp-table th { background: #f6f7f9; font-weight: 600; color: #4b5563; white-space: nowrap; }
.hypay-lp-table td, .hypay-lp-table th { vertical-align: middle; }
.hypay-lp-table .hypay-lp-right { text-align: right; }
.hypay-lp-table .hypay-lp-center { text-align: center; }
.hypay-lp-row--current { background: #f3f8ff; }
.hypay-lp-row--locked td { color: #9aa1a9; }
.hypay-lp-row--locked a { color: #8aa4c0; }
.hypay-lp-yes { color: #2e9d4f; font-weight: 700; }
.hypay-lp-no { color: #e0485f; font-weight: 700; }
.hypay-lp-tag { display: inline-block; margin: 0 4px; padding: 0 6px; border-radius: 10px; background: #e7eefc; color: #3a5aa8; font-size: 11px; line-height: 18px; }
.hypay-lp-tag--warn { background: #fff1d6; color: #8a5a00; }
.hypay-lp-tag--ok { background: #e2f5e8; color: #1e6b36; }
.hypay-lp-badge { font-weight: 400; font-size: 11px; padding: 1px 8px; border-radius: 10px; }
.hypay-lp-badge--ok { background: #e2f5e8; color: #1e6b36; }
.hypay-lp-badge--muted { background: #eef0f3; color: #4b5563; }
.hypay-lp-copy { display: flex; gap: 6px; align-items: center; }
.hypay-lp-copy .hypay-lp-url { flex: 1 1 auto; min-width: 0; margin: 0; font-family: monospace; }
.hypay-lp-meta { margin-top: 8px; color: #4b5563; }
.hypay-lp-deal { margin: 0 0 4px; }
.hypay-lp-send { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; }
.hypay-lp-send__toggle { min-width: 150px; margin: 0; }
.hypay-lp-send input[type=email], .hypay-lp-send input[type=text] { margin: 0; }
.hypay-lp-send--off input[type=email], .hypay-lp-send--off input[type=text] { opacity: .45; }
.hypay-lp-note { margin: 4px 0 10px; font-size: 12px; }
.hypay-lp-actions { flex: 0 0 auto; display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 12px 16px; background: #fff; border-top: 1px solid #e3e6ea; }
.hypay-lp-danger { color: #a12626; }
.hypay-lp-busy { color: #6b7280; display: inline-flex; align-items: center; gap: 6px; margin-right: auto; }
.hypay-lp-spinner { width: 14px; height: 14px; border: 2px solid #d6dae0; border-top-color: #4a90d9; border-radius: 50%; animation: hypay-lp-spin .8s linear infinite; }
@keyframes hypay-lp-spin { to { transform: rotate(360deg); } }
.hypay-lp.is-busy { opacity: .7; pointer-events: none; }
</style>

<script type="text/javascript">
(function () {
    // Defined once per page, run for every copy of the window: the first comes
    // with CS-Cart's ajax (which runs this script), the later ones are drawn in
    // place by the buttons below (which do not run scripts - they call this).
    if (!window.hypayLinkPanel) {
        window.hypayLinkPanel = function (block) {
            if (!block || block.getAttribute('data-hypay-ready')) { return; }
            block.setAttribute('data-hypay-ready', '1');

            var $q  = function (sel) { return block.querySelector(sel); };
            var $qa = function (sel) { return block.querySelectorAll(sel); };
            var jq  = window.jQuery || (window.Tygh && window.Tygh.$);
            var changed_to_paid = false;

            // --- close: CS-Cart's cm-dialog-closer, with a fallback
            var closers = $qa('.hypay-lp-close');
            for (var i = 0; i < closers.length; i++) {
                closers[i].addEventListener('click', function () {
                    if (!jq) { return; }
                    var dialog = jq(block).closest('.ui-dialog-content');
                    if (dialog.length && dialog.is(':visible')) {
                        try { dialog.dialog('close'); } catch (e) {}
                    }
                });
            }

            // --- copy
            var copy = $q('.hypay-lp-copy-btn');
            var url  = $q('.hypay-lp-url');
            if (copy && url) {
                var label = copy.innerHTML;
                var done  = function () {
                    copy.innerHTML = copy.getAttribute('data-copied');
                    window.setTimeout(function () { copy.innerHTML = label; }, 1500);
                };
                var fallback = function () {
                    url.focus(); url.select();
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

            // --- order picking: sum, filter, delivery
            var orders     = $qa('.hypay-lp-order');
            var sum        = $q('.hypay-lp-sum');
            var filter     = $q('.hypay-lp-filter');
            var send_email = $q('.hypay-lp-send-email');
            var send_sms   = $q('.hypay-lp-send-sms');
            var email      = $q('.hypay-lp-email');
            var cell       = $q('.hypay-lp-cell');
            var create     = $q('.hypay-lp-action[data-action="create"]');
            var deals      = $qa('.hypay-lp-deal-input');
            var currency   = block.getAttribute('data-currency') || '';

            var picked = function () {
                var ids = [], total = 0;
                for (var k = 0; k < orders.length; k++) {
                    if (!orders[k].checked) { continue; }
                    total += parseFloat(orders[k].getAttribute('data-total')) || 0;
                    // this order is always in: its box only shows it
                    if (orders[k].getAttribute('data-selectable') === '1') { ids.push(orders[k].value); }
                }
                return { ids: ids, total: total };
            };

            var sync = function () {
                if (sum && orders.length) {
                    sum.innerHTML = currency + picked().total.toFixed(2);
                }
                var e_on = !!(send_email && send_email.checked);
                var s_on = !!(send_sms && send_sms.checked);
                if (email) { email.parentNode.className = email.parentNode.className.replace(/\s*hypay-lp-send--off/g, '') + (e_on ? '' : ' hypay-lp-send--off'); }
                if (cell)  { cell.parentNode.className  = cell.parentNode.className.replace(/\s*hypay-lp-send--off/g, '')  + (s_on ? '' : ' hypay-lp-send--off'); }
                if (create) {
                    create.innerHTML = block.getAttribute((e_on || s_on) ? 'data-label-send' : 'data-label-create');
                }
            };

            if (filter) {
                filter.addEventListener('change', function () {
                    var rows = $qa('.hypay-lp-row'), shown = 0;
                    for (var r = 0; r < rows.length; r++) {
                        var show = !filter.value
                            || rows[r].getAttribute('data-status') === filter.value
                            || /hypay-lp-row--current/.test(rows[r].className);
                        rows[r].style.display = show ? '' : 'none';
                        if (show) { shown++; }
                    }
                    var count = $q('.hypay-lp-count');
                    if (count) { count.innerHTML = shown; }
                });
            }

            // J5 holds the money of this order alone: the others are unticked
            // and locked while it is chosen, and given back when it is not
            var deal = function () {
                for (var d = 0; d < deals.length; d++) {
                    if (deals[d].checked) { return deals[d].value; }
                }
                return 'j4';
            };
            var sync_deal = function () {
                var j5 = deal() === 'j5';
                for (var o = 0; o < orders.length; o++) {
                    if (orders[o].getAttribute('data-selectable') !== '1') { continue; }
                    if (j5) { orders[o].checked = false; }
                    orders[o].disabled = j5;
                }
                sync();
            };
            for (var dd = 0; dd < deals.length; dd++) {
                deals[dd].addEventListener('change', sync_deal);
            }

            var inputs = [send_email, send_sms, email, cell];
            for (var m = 0; m < orders.length; m++) { inputs.push(orders[m]); }
            for (var j = 0; j < inputs.length; j++) {
                if (!inputs[j]) { continue; }
                inputs[j].addEventListener('input', sync);
                inputs[j].addEventListener('change', sync);
            }
            sync();

            // --- buttons: post, then draw the answer in place of this window
            var busy = $q('.hypay-lp-busy');
            var run  = function (action) {
                var params = new URLSearchParams();
                params.append('order_id', block.getAttribute('data-order-id'));
                params.append('hypay_ajax', '1');
                if (window.Tygh && window.Tygh.security_hash) {
                    params.append('security_hash', window.Tygh.security_hash);
                }

                if (action === 'create') {
                    if (send_email && send_email.checked && email) { params.append('email', email.value.replace(/^\s+|\s+$/g, '')); }
                    if (send_sms && send_sms.checked && cell)     { params.append('cell', cell.value.replace(/[^\d+]/g, '')); }
                    var ids = picked().ids;
                    if (ids.length) { params.append('order_ids', ids.join(',')); }
                    params.append('deal', deal());
                    if (!window.confirm(block.getAttribute('data-confirm-create'))) { return; }
                }
                if (action === 'cancel' && !window.confirm(block.getAttribute('data-confirm-cancel'))) { return; }

                block.className += ' is-busy';
                if (busy) { busy.style.display = ''; }

                fetch(block.getAttribute('data-url-' + action), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: params.toString()
                }).then(function (response) {
                    return response.text();
                }).then(function (html) {
                    var holder = document.createElement('div');
                    holder.innerHTML = html;
                    var fresh = holder.querySelector('.hypay-lp');
                    if (!fresh) { throw new Error('no window in the answer'); }

                    // a payment found by Check payment changed the order
                    // itself: the page behind is reloaded when this closes
                    if (fresh.getAttribute('data-state') === 'paid' && block.getAttribute('data-state') !== 'paid') {
                        changed_to_paid = true;
                    }
                    var reload = changed_to_paid || block.getAttribute('data-reload-on-close') === '1';

                    block.parentNode.replaceChild(fresh, block);
                    if (reload) { fresh.setAttribute('data-reload-on-close', '1'); }
                    window.hypayLinkPanel(fresh);
                    window.hypayLinkFit();
                }).catch(function () {
                    block.className = block.className.replace(/\s*is-busy/g, '');
                    if (busy) { busy.style.display = 'none'; }
                    window.alert(block.getAttribute('data-error-network'));
                });
            };

            var buttons = $qa('.hypay-lp-action');
            for (var b = 0; b < buttons.length; b++) {
                buttons[b].addEventListener('click', function () { run(this.getAttribute('data-action')); });
            }

            // the order page behind shows the payment only after a reload
            if (jq && !block.getAttribute('data-close-bound')) {
                block.setAttribute('data-close-bound', '1');
                var content = jq(block).closest('.ui-dialog-content');
                if (content.length) {
                    content.off('dialogclose.hypay').on('dialogclose.hypay', function () {
                        var current = document.getElementById('hypay_link_dialog_block');
                        if (current && current.getAttribute('data-reload-on-close') === '1') {
                            window.location.reload();
                        }
                    });
                }
            }
        };
    }

    // The window takes the whole inside of the dialog, over its padding, so
    // the footer runs edge to edge at the bottom and only the body scrolls.
    // Refitted whenever the dialog changes size; the current window is looked
    // up each time, as the buttons replace it.
    if (!window.hypayLinkFit) {
        window.hypayLinkFit = function () {
            var block = document.getElementById('hypay_link_dialog_block');
            var content = block && block.parentNode;
            if (!content || !/(^|\s)ui-dialog-content(\s|$)/.test(content.className)) { return; }
            if (!content.getAttribute('data-hypay-fit')) {
                content.setAttribute('data-hypay-fit', '1');
                if (window.ResizeObserver) { new ResizeObserver(window.hypayLinkFit).observe(content); }
                window.addEventListener('resize', window.hypayLinkFit);
            }
            if (!content.clientHeight) { return; }
            var cs = window.getComputedStyle(content);
            block.style.margin = '-' + cs.paddingTop + ' -' + cs.paddingRight + ' -' + cs.paddingBottom + ' -' + cs.paddingLeft;
            block.style.height = content.clientHeight + 'px';
        };
    }

    // CS-Cart may run this a moment before the markup is in place
    var tries = 0;
    var start = function () {
        var block = document.getElementById('hypay_link_dialog_block');
        if (!block) {
            if (tries++ < 20) { window.setTimeout(start, 50); }
            return;
        }
        window.hypayLinkPanel(block);
        window.hypayLinkFit();
    };
    start();
})();
</script>
{/literal}

{/if}
