{* ============================================================================
 *  "Payment Link" in the order's tools menu (the gear next to Save).
 *  Opens the payment link window as a dialog - views/hypay/link_panel.tpl,
 *  loaded by ajax - and says under its name what became of the last link.
 * ========================================================================== *}

{$hypay_link_menu = $order_info.order_id|fn_hypay_get_link_panel_data}

{* why there is no menu item, readable in the page source *}
{if $hypay_link_menu.hidden}
<!-- hypay payment link: hidden, {$hypay_link_menu.hidden|escape} -->
{elseif $hypay_link_menu}
{$hypay_link_menu_date = "`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
<li>
    <a class="cm-dialog-opener cm-ajax" id="hypay_link_opener"
       data-ca-target-id="content_hypay_payment_link"
       data-ca-dialog-title="{__("hypay_link_panel_title", ["[order_id]" => $order_info.order_id])}"
       href="{"hypay.link_panel?order_id=`$order_info.order_id`"|fn_url}">
        {__("hypay_link_btn")} 💳
        {if $hypay_link_menu.state == "paid"}
            <br /><small class="text-success">{__("hypay_link_paid_on", ["[date]" => $hypay_link_menu.paid_at|date_format:$hypay_link_menu_date])}</small>
        {elseif $hypay_link_menu.state == "active"}
            <br /><small class="text-success">{__("hypay_link_state_active", ["[date]" => $hypay_link_menu.created_at|date_format:$hypay_link_menu_date])}</small>
        {elseif $hypay_link_menu.state == "cancelled"}
            <br /><small class="muted">{__("hypay_link_state_cancelled", ["[date]" => $hypay_link_menu.cancelled_at|date_format:$hypay_link_menu_date])}</small>
        {/if}
    </a>

    {* back from Create / Cancel / Check payment: the window opens again, with
       the outcome in it *}
    {if $smarty.request.hypay_link_open}
    {literal}
    <script type="text/javascript">
    (function () {
        var jq = window.jQuery || (window.Tygh && window.Tygh.$);
        if (!jq) { return; }
        jq(function () {
            window.setTimeout(function () { jq('#hypay_link_opener').trigger('click'); }, 300);
        });
    })();
    </script>
    {/literal}
    {/if}
</li>
{/if}
