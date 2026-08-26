{* ============================================================================
 *  Hypay payment processor settings template
 *  Author: Michael Shapar (micshap100@gmail.com)
 *  Date: 2025-10-20
 *  All rights reserved.
 * ============================================================================ *}


 {* --- Credentials --- *}
 <div class="control-group">
     <label class="control-label" for="hypay_masof">{__("hypay_masof")}</label>
     <div class="controls">
         <input type="text" name="payment_data[processor_params][masof]" id="hypay_masof"
                value="{$processor_params.masof|escape}" class="input-large" />
         <p class="muted description">{__("hypay_masof_desc")}</p>
     </div>
 </div>

 <div class="control-group">
     <label class="control-label" for="hypay_api_key">{__("hypay_api_key")}</label>
     <div class="controls">
         <input type="text" name="payment_data[processor_params][api_key]" id="hypay_api_key"
                value="{$processor_params.api_key|escape}" class="input-large" />
         <p class="muted description">{__("hypay_api_key_desc")}</p>
     </div>
 </div>

 <div class="control-group">
   <label class="control-label" for="hypay_passp">{__("hypay_passp")}</label>
   <div class="controls">
       <input type="text" name="payment_data[processor_params][passp]" id="hypay_passp"
              value="{$processor_params.passp|escape}" class="input-large"/>
       <p class="muted description">{__("hypay_passp_desc")}</p>
   </div>
 </div>

 <div class="control-group">
     <label class="control-label" for="hypay_mode">{__("hypay_mode")}</label>
     <div class="controls">
         <select name="payment_data[processor_params][mode]" id="hypay_mode">
             <option value="demo" {if $processor_params.mode == "demo"}selected="selected"{/if}>{__("hypay_mode_demo")}</option>
             <option value="live" {if $processor_params.mode == "live"}selected="selected"{/if}>{__("hypay_mode_live")}</option>
         </select>
         <p class="muted description">{__("hypay_mode_desc")}</p>
     </div>
 </div>

 <hr>


 {* --- Order statuses mapping --- *}
 {$statuses = $smarty.const.STATUSES_ORDER|fn_get_simple_statuses}

 {assign var="succ_status" value=$processor_params.success_status|default:"O"}
 <div class="control-group">
     <label class="control-label" for="elm_hypay_success_status">{__("hypay_success_status")}:</label>
     <div class="controls">
         <select name="payment_data[processor_params][success_status]" id="elm_hypay_success_status">
             {foreach from=$statuses item="status" key="s_key"}
                 <option value="{$s_key}" {if $s_key == $succ_status}selected="selected"{/if}>{$status}</option>
             {/foreach}
         </select>
         <p class="muted description">{__("hypay_success_status_desc")}</p>
     </div>
 </div>

 {assign var="fail_status" value=$processor_params.fail_status|default:"F"}
 <div class="control-group">
     <label class="control-label" for="elm_hypay_fail_status">{__("hypay_fail_status")}:</label>
     <div class="controls">
         <select name="payment_data[processor_params][fail_status]" id="elm_hypay_fail_status">
             {foreach from=$statuses item="status" key="s_key"}
                 <option value="{$s_key}" {if $s_key == $fail_status}selected="selected"{/if}>{$status}</option>
             {/foreach}
         </select>
         <p class="muted description">{__("hypay_fail_status_desc")}</p>
     </div>
 </div>

 <hr>

 {* --- J5 (two-phase commit) --- *}
 <h3>{__("hypay_j5_section")}</h3>

 {$hypay_payment_type = $processor_params.payment_type}
 {if $hypay_payment_type == ""}
     {if $processor_params.j5 == "Y"}{$hypay_payment_type = "j5"}{else}{$hypay_payment_type = "regular"}{/if}
 {/if}
 <div class="control-group">
     <label class="control-label" for="hypay_payment_type">{__("hypay_payment_type")}</label>
     <div class="controls">
         <select name="payment_data[processor_params][payment_type]" id="hypay_payment_type" class="input-large">
             <option value="regular"   {if $hypay_payment_type == "regular"}selected="selected"{/if}>{__("hypay_payment_type_regular")}</option>
             <option value="j5"        {if $hypay_payment_type == "j5"}selected="selected"{/if}>{__("hypay_payment_type_j5")}</option>
             <option value="usergroup" {if $hypay_payment_type == "usergroup"}selected="selected"{/if}>{__("hypay_payment_type_usergroup")}</option>
         </select>
         <p class="muted description">{__("hypay_payment_type_desc")}</p>
     </div>
 </div>

 {assign var="hypay_j5_groups" value=$processor_params|fn_hypay_get_j5_usergroups}
 <div class="control-group">
     <label class="control-label" for="hypay_j5_usergroups">{__("hypay_j5_usergroups")}</label>
     <div class="controls">
         {if $hypay_usergroups}
             <select name="payment_data[processor_params][j5_usergroups][]" id="hypay_j5_usergroups"
                     multiple="multiple" size="6" class="input-large">
                 {foreach from=$hypay_usergroups item="ug"}
                     <option value="{$ug.usergroup_id}" {if $ug.usergroup_id|in_array:$hypay_j5_groups}selected="selected"{/if}>{$ug.usergroup}</option>
                 {/foreach}
             </select>
             <p><button type="button" class="btn" id="hypay_j5_usergroups_reset">{__("hypay_j5_usergroups_reset")}</button></p>
             {literal}
             <script type="text/javascript">
             (function () {
                 var list  = document.getElementById('hypay_j5_usergroups');
                 var reset = document.getElementById('hypay_j5_usergroups_reset');
                 if (!list || !reset) { return; }

                 // type="button" keeps this out of the form's submit path: the
                 // selection is only cleared here, the method still has to be
                 // saved for the empty list to reach processor_params
                 reset.addEventListener('click', function () {
                     for (var i = 0; i < list.options.length; i++) {
                         list.options[i].selected = false;
                     }
                 });
             })();
             </script>
             {/literal}
         {else}
             <p class="muted">{__("hypay_j5_usergroups_empty")}</p>
         {/if}
         <p class="muted description">{__("hypay_j5_usergroups_desc")}</p>
     </div>
 </div>

 {assign var="hypay_j5_auth_status" value=$processor_params.j5_auth_status|default:"O"}
 <div class="control-group">
     <label class="control-label" for="elm_hypay_j5_auth_status">{__("hypay_j5_auth_status")}</label>
     <div class="controls">
         <select name="payment_data[processor_params][j5_auth_status]" id="elm_hypay_j5_auth_status">
             {foreach from=$statuses item="status" key="s_key"}
                 <option value="{$s_key}" {if $s_key == $hypay_j5_auth_status}selected="selected"{/if}>{$status}</option>
             {/foreach}
         </select>
         <p class="muted description">{__("hypay_j5_auth_status_desc")}</p>
     </div>
 </div>

 {* Additional status alongside each J5 order status. Rendered only while the
    add-on that owns ?:orders.additional_status is active; otherwise the stored
    value rides through in a hidden field, because processor_params are replaced
    wholesale on save and would otherwise be wiped by an unrelated edit. *}
 {assign var="hypay_j5_auth_add_status" value=$processor_params.j5_auth_additional_status|default:""}
 {if $hypay_additional_statuses}
     <div class="control-group">
         <label class="control-label" for="elm_hypay_j5_auth_additional_status">{__("hypay_j5_auth_additional_status")}</label>
         <div class="controls">
             <select name="payment_data[processor_params][j5_auth_additional_status]" id="elm_hypay_j5_auth_additional_status">
                 <option value="">{__("hypay_j5_additional_status_none")}</option>
                 {foreach from=$hypay_additional_statuses item="status" key="s_key"}
                     <option value="{$s_key}" {if $s_key == $hypay_j5_auth_add_status}selected="selected"{/if}>{$status}</option>
                 {/foreach}
             </select>
             <p class="muted description">{__("hypay_j5_auth_additional_status_desc")}</p>
         </div>
     </div>
 {elseif $hypay_j5_auth_add_status}
     <input type="hidden" name="payment_data[processor_params][j5_auth_additional_status]" value="{$hypay_j5_auth_add_status|escape}" />
 {/if}

 {assign var="hypay_j5_captured_status" value=$processor_params.j5_captured_status|default:$succ_status}
 <div class="control-group">
     <label class="control-label" for="elm_hypay_j5_captured_status">{__("hypay_j5_captured_status")}</label>
     <div class="controls">
         <select name="payment_data[processor_params][j5_captured_status]" id="elm_hypay_j5_captured_status">
             {foreach from=$statuses item="status" key="s_key"}
                 <option value="{$s_key}" {if $s_key == $hypay_j5_captured_status}selected="selected"{/if}>{$status}</option>
             {/foreach}
         </select>
         <p class="muted description">{__("hypay_j5_captured_status_desc")}</p>
     </div>
 </div>

 {assign var="hypay_j5_add_status" value=$processor_params.j5_captured_additional_status|default:""}
 {if $hypay_additional_statuses}
     <div class="control-group">
         <label class="control-label" for="elm_hypay_j5_captured_additional_status">{__("hypay_j5_captured_additional_status")}</label>
         <div class="controls">
             <select name="payment_data[processor_params][j5_captured_additional_status]" id="elm_hypay_j5_captured_additional_status">
                 <option value="">{__("hypay_j5_additional_status_none")}</option>
                 {foreach from=$hypay_additional_statuses item="status" key="s_key"}
                     <option value="{$s_key}" {if $s_key == $hypay_j5_add_status}selected="selected"{/if}>{$status}</option>
                 {/foreach}
             </select>
             <p class="muted description">{__("hypay_j5_captured_additional_status_desc")}</p>
         </div>
     </div>
 {elseif $hypay_j5_add_status}
     <input type="hidden" name="payment_data[processor_params][j5_captured_additional_status]" value="{$hypay_j5_add_status|escape}" />
 {/if}

 {assign var="hypay_j5_void_status" value=$processor_params.j5_void_status|default:"I"}
 <div class="control-group">
     <label class="control-label" for="elm_hypay_j5_void_status">{__("hypay_j5_void_status")}</label>
     <div class="controls">
         <select name="payment_data[processor_params][j5_void_status]" id="elm_hypay_j5_void_status">
             {foreach from=$statuses item="status" key="s_key"}
                 <option value="{$s_key}" {if $s_key == $hypay_j5_void_status}selected="selected"{/if}>{$status}</option>
             {/foreach}
         </select>
         <p class="muted description">{__("hypay_j5_void_status_desc")}</p>
     </div>
 </div>

 <div class="control-group">
     <label class="control-label" for="hypay_j5_hold_days">{__("hypay_j5_hold_days")}</label>
     <div class="controls">
         <input type="number" name="payment_data[processor_params][j5_hold_days]" id="hypay_j5_hold_days"
                value="{$processor_params.j5_hold_days|default:5}" min="1" max="60" class="input-small" />
         <p class="muted description">{__("hypay_j5_hold_days_desc")}</p>
     </div>
 </div>

 <hr>

 {* --- Return URL (read-only) --- *}
 <div class="control-group">
     <label class="control-label" for="hypay_return_url">{__("hypay_return_url")}</label>
     <div class="controls">
         <input type="text" readonly id="hypay_return_url"
                value="{$config.current_location}/index.php?dispatch=payment_notification.success&payment=hypay" class="input-xxlarge" />
         <p class="muted description">{__("hypay_return_url_desc")}</p>
     </div>
 </div>

 <hr>

 {* --- General params --- *}
 <div class="control-group">
     <label class="control-label" for="hypay_info">{__("hypay_info")}</label>
     <div class="controls">
         <input type="text" name="payment_data[processor_params][info]" id="hypay_info"
                value="{$processor_params.info|default:'Order {order_id}'|escape}" class="input-xxlarge" />
         <p class="muted description">{__("hypay_info_desc")}</p>
     </div>
 </div>

 <div class="control-group">
     <label class="control-label">{__("hypay_encoding_flags")}</label>
     <div class="controls">
         <label class="checkbox">
             <input type="checkbox" name="payment_data[processor_params][utf8]" value="Y" {if $processor_params.utf8 == "Y" || !$processor_params.utf8}checked{/if} />
             UTF8
         </label>
         <label class="checkbox">
             <input type="checkbox" name="payment_data[processor_params][utf8out]" value="Y" {if $processor_params.utf8out == "Y" || !$processor_params.utf8out}checked{/if} />
             UTF8out
         </label>
         <label class="checkbox">
             <input type="checkbox" name="payment_data[processor_params][sign]" value="Y" {if $processor_params.sign == "Y"}checked{/if} />
             Sign
         </label>
         <p class="muted description">{__("hypay_encoding_flags_desc")}</p>
     </div>
 </div>

 <div class="control-group">
     <label class="control-label" for="hypay_pagelang">{__("hypay_pagelang")}</label>
     <div class="controls">
         <select name="payment_data[processor_params][page_lang]" id="hypay_pagelang" class="input-medium">
             <option value="auto" {if $processor_params.page_lang == "auto" || !$processor_params.page_lang}selected{/if}>{__("hypay_pagelang_auto")}</option>
             <option value="ENG" {if $processor_params.page_lang == "ENG"}selected{/if}>ENG</option>
             <option value="HEB" {if $processor_params.page_lang == "HEB"}selected{/if}>HEB</option>
         </select>
         <p class="muted description">{__("hypay_pagelang_desc")}</p>
     </div>
 </div>

 <div class="control-group">
     <label class="control-label" for="hypay_coin">{__("hypay_coin")}</label>
     <div class="controls">
         <select name="payment_data[processor_params][coin]" id="hypay_coin" class="input-medium">
             <option value="1" {if $processor_params.coin == "1" || !$processor_params.coin}selected{/if}>1 — ILS</option>
             <option value="2" {if $processor_params.coin == "2"}selected{/if}>2 — USD</option>
             <option value="3" {if $processor_params.coin == "3"}selected{/if}>3 — EUR</option>
             <option value="4" {if $processor_params.coin == "4"}selected{/if}>4 — GBP</option>
         </select>
     </div>
 </div>

 <div class="control-group">
     <label class="control-label" for="hypay_tmp">{__("hypay_tmp")}</label>
     <div class="controls">
         <input type="number" name="payment_data[processor_params][tmp]" id="hypay_tmp"
                value="{$processor_params.tmp|default:4}" min="1" class="input-small" />
         <p class="muted description">{__("hypay_tmp_desc")}</p>
     </div>
 </div>

 <hr>

 {* --- Payments options --- *}
 <div class="control-group">
     <label class="control-label" for="hypay_tash">{__("hypay_tash")}</label>
     <div class="controls">
         <input type="number" name="payment_data[processor_params][tash]" id="hypay_tash"
                value="{$processor_params.tash|escape}" min="0" class="input-small" />
         <p class="muted description">{__("hypay_tash_desc")}</p>
     </div>
 </div>

 <div class="control-group">
     <label class="control-label" for="hypay_tashtype">{__("hypay_tashtype")}</label>
     <div class="controls">
         <select name="payment_data[processor_params][tashtype]" id="hypay_tashtype" class="input-medium">
             <option value=""  {if $processor_params.tashtype == ""}selected{/if}>—</option>
             <option value="1" {if $processor_params.tashtype == "1"}selected{/if}>1 — regular</option>
             <option value="6" {if $processor_params.tashtype == "6"}selected{/if}>6 — credit</option>
         </select>
         <label class="checkbox">
             <input type="checkbox" name="payment_data[processor_params][fixtash]" value="Y" {if $processor_params.fixtash == "Y"}checked{/if} />
             {__("hypay_fixtash")}
         </label>
         <p class="muted description">{__("hypay_tashtype_desc")}</p>
     </div>
 </div>

 <div class="control-group">
     <label class="control-label" for="hypay_tash_first">{__("hypay_tash_first")}</label>
     <div class="controls">
         <input type="text" name="payment_data[processor_params][tash_first]" id="hypay_tash_first"
                value="{$processor_params.tash_first|escape}" class="input-small" />
         <p class="muted description">{__("hypay_tash_first_desc")}</p>
     </div>
 </div>

 <hr>

 {* --- Emails / UX / Risk --- *}
 <div class="control-group">
     <label class="control-label">{__("hypay_flags")}</label>
     <div class="controls">
         <label class="checkbox"><input type="checkbox" name="payment_data[processor_params][sendemail]" value="Y" {if $processor_params.sendemail == "Y"}checked{/if} /> sendemail</label>
         <label class="checkbox"><input type="checkbox" name="payment_data[processor_params][moredata]" value="Y"  {if $processor_params.moredata == "Y"}checked{/if}  /> MoreData</label>
         <label class="checkbox"><input type="checkbox" name="payment_data[processor_params][pagetimeout]" value="Y" {if $processor_params.pagetimeout == "Y"}checked{/if} /> pageTimeOut</label>
         <label class="checkbox"><input type="checkbox" name="payment_data[processor_params][postpone]" value="Y"   {if $processor_params.postpone == "Y"}checked{/if}   /> Postpone</label>
         <label class="checkbox"><input type="checkbox" name="payment_data[processor_params][show_eng_tash_text]" value="Y" {if $processor_params.show_eng_tash_text == "Y"}checked{/if} /> ShowEngTashText</label>
         <label class="checkbox"><input type="checkbox" name="payment_data[processor_params][hide_btns]" value="Y"  {if $processor_params.hide_btns == "Y"}checked{/if}  /> hideBtns</label>
     </div>
 </div>

 <hr>

 <div class="control-group">
     <label class="control-label" for="hypay_building_field_id">{__("hypay_building_field_id")}</label>
     <div class="controls">
         <input type="number"
                name="payment_data[processor_params][building_field_id]"
                id="hypay_building_field_id"
                value="{$processor_params.building_field_id|default:''|escape}"
                class="input-small" min="0" />
         <p class="muted description">{__("hypay_building_field_id_desc")}</p>
     </div>
 </div>

 <div class="control-group">
     <label class="control-label" for="hypay_debug_mode">{__("hypay_debug_mode")}</label>
     <div class="controls">
         <label class="checkbox">
             <input type="checkbox"
                    name="payment_data[processor_params][debug_mode]"
                    id="hypay_debug_mode"
                    value="Y"
                    {if $processor_params.debug_mode == "Y"}checked{/if} />
             {__("hypay_debug_mode_label")}
         </label>
         <p class="muted description">
             {__("hypay_debug_mode_desc")}
             <br/>
             <code>/home/domain/www/var/log/hypay_ezcount.log</code>
         </p>
     </div>
 </div>

 <hr>

 <h3>EzCount</h3>
 <div class="control-group">
     <label class="control-label" for="ez_mode">{__("hypay_ez_mode")}</label>
     <div class="controls">
         <select name="payment_data[processor_params][ez_mode]" id="ez_mode" class="input-medium">
             <option value="none" {if $processor_params.ez_mode|default:"none" == "none"}selected{/if}>{__("hypay_ez_mode_none")}</option>
             <option value="integrated" {if $processor_params.ez_mode == "integrated"}selected{/if}>{__("hypay_ez_mode_integrated")}</option>
             <option value="direct" {if $processor_params.ez_mode == "direct"}selected{/if}>{__("hypay_ez_mode_direct")}</option>
         </select>
         <p class="muted description">{__("hypay_ez_mode_desc")}</p>
     </div>
 </div>


 <hr>
 <h4>EzCount (Integrated)</h4>
     {* --- Ezcount (integrated via Hypay) --- *}
     <div class="control-group">
         <label class="control-label">{__("hypay_ezcount_flags")}</label>
         <div class="controls">
             <label class="checkbox"><input type="checkbox" name="payment_data[processor_params][sendhesh]" value="Y" {if $processor_params.sendhesh == "Y"}checked{/if} /> SendHesh</label>
             <label class="checkbox"><input type="checkbox" name="payment_data[processor_params][pritim]" value="Y"   {if $processor_params.pritim == "Y"}checked{/if}   /> Pritim</label>
             <label class="checkbox"><input type="checkbox" name="payment_data[processor_params][block_item_validation]" value="Y" {if $processor_params.block_item_validation == "Y"}checked{/if} /> blockItemValidation</label>
             <p class="muted description">{__("hypay_ezcount_flags_desc")}</p>
         </div>
     </div>


<hr>

    {* --- EzCount (Direct API) --- *}
    <h4>EzCount (Direct API)</h4>

    <div class="control-group">
        <label class="control-label" for="ez_environment">Environment</label>
        <div class="controls">
            <select name="payment_data[processor_params][ez_environment]" id="ez_environment" class="input-medium">
                <option value="demo" {if $processor_params.ez_environment == "demo" || !$processor_params.ez_environment}selected{/if}>Demo</option>
                <option value="live" {if $processor_params.ez_environment == "live"}selected{/if}>Live</option>
            </select>
            <p class="muted description">Choose EzCount API environment.</p>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label" for="ez_api_key">EzCount API key</label>
        <div class="controls">
            <input type="text" name="payment_data[processor_params][ez_api_key]" id="ez_api_key"
                   value="{$processor_params.ez_api_key|escape}" class="input-xxlarge"/>
            <p class="muted description">Your EzCount API key.</p>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label" for="ez_developer_email">Developer email</label>
        <div class="controls">
            <input type="text" name="payment_data[processor_params][ez_developer_email]" id="ez_developer_email"
                   value="{$processor_params.ez_developer_email|escape}" class="input-xxlarge"/>
            <p class="muted description">Registered developer email for EzCount API.</p>
        </div>
    </div>

    <div class="control-group">
        <label class="control-label" for="ez_ua_uuid">UA UUID (account identifier)</label>
        <div class="controls">
            <input type="text" name="payment_data[processor_params][ez_ua_uuid]" id="ez_ua_uuid"
                   value="{$processor_params.ez_ua_uuid|escape}" class="input-xxlarge"/>
            <p class="muted description">EzCount account identifier (UA UUID) if applicable.</p>
        </div>
    </div>

    {* NEW: document type 320/400 *}
    <div class="control-group">
        <label class="control-label" for="ez_doc_type">{__("hypay_ez_doc_type")}</label>
        <div class="controls">
            <select name="payment_data[processor_params][ez_doc_type]" id="ez_doc_type" class="input-medium">
                <option value="320" {if $processor_params.ez_doc_type|default:320 == 320}selected{/if}>{__("hypay_ez_doc_type_320")} (320)</option>
                <option value="400" {if $processor_params.ez_doc_type == 400}selected{/if}>{__("hypay_ez_doc_type_400")} (400)</option>
            </select>
            <p class="muted description">{__("hypay_ez_doc_type_desc")}</p>
        </div>
    </div>

    {* Line items: itemized products or a single order line. Regular checkout and
       the document issued after a J5 capture are set separately; the J5 one is
       empty by default and then follows the regular setting. *}
    {assign var="ez_line_items_mode" value=$processor_params.ez_line_items_mode|default:"list_products"}
    <div class="control-group">
        <label class="control-label" for="ez_line_items_mode">{__("hypay_ez_line_items_mode")}</label>
        <div class="controls">
            <select name="payment_data[processor_params][ez_line_items_mode]" id="ez_line_items_mode" class="input-large">
                <option value="list_products" {if $ez_line_items_mode == "list_products"}selected="selected"{/if}>{__("hypay_ez_line_items_mode_products")}</option>
                <option value="list_orders" {if $ez_line_items_mode == "list_orders"}selected="selected"{/if}>{__("hypay_ez_line_items_mode_orders")}</option>
            </select>
            <p class="muted description">{__("hypay_ez_line_items_mode_desc")}</p>
        </div>
    </div>

    {assign var="ez_line_items_mode_j5" value=$processor_params.ez_line_items_mode_j5|default:""}
    <div class="control-group">
        <label class="control-label" for="ez_line_items_mode_j5">{__("hypay_ez_line_items_mode_j5")}</label>
        <div class="controls">
            <select name="payment_data[processor_params][ez_line_items_mode_j5]" id="ez_line_items_mode_j5" class="input-large">
                <option value="" {if $ez_line_items_mode_j5 == ""}selected="selected"{/if}>{__("hypay_ez_line_items_mode_j5_inherit")}</option>
                <option value="list_products" {if $ez_line_items_mode_j5 == "list_products"}selected="selected"{/if}>{__("hypay_ez_line_items_mode_products")}</option>
                <option value="list_orders" {if $ez_line_items_mode_j5 == "list_orders"}selected="selected"{/if}>{__("hypay_ez_line_items_mode_orders")}</option>
            </select>
            <p class="muted description">{__("hypay_ez_line_items_mode_j5_desc")}</p>
        </div>
    </div>

    {* Show items including VAT (0/1) *}
    <div class="control-group">
        <label class="control-label" for="ez_show_items_including_vat">{__("hypay_ez_show_items_including_vat")}</label>
        <div class="controls">
            {* hidden = 0 to ensure 0 is sent when checkbox is off *}
            <input type="hidden" name="payment_data[processor_params][ez_show_items_including_vat]" value="0" />
            <label class="checkbox">
                <input type="checkbox"
                       id="ez_show_items_including_vat"
                       name="payment_data[processor_params][ez_show_items_including_vat]"
                       value="1"
                       {if $processor_params.ez_show_items_including_vat|default:1}checked{/if} />
                {__("hypay_ez_show_items_including_vat_label")}
            </label>
            <p class="muted description">{__("hypay_ez_show_items_including_vat_desc")}</p>
        </div>
    </div>

    {* Auto-calc payments (0/1) *}
    <div class="control-group">
        <label class="control-label" for="ez_auto_calc_payments">{__("hypay_ez_auto_calc_payments")}</label>
        <div class="controls">
            {* hidden = 0 to ensure 0 is sent when checkbox is off *}
            <input type="hidden" name="payment_data[processor_params][ez_auto_calc_payments]" value="0" />
            <label class="checkbox">
                <input type="checkbox"
                       id="ez_auto_calc_payments"
                       name="payment_data[processor_params][ez_auto_calc_payments]"
                       value="1"
                       {if $processor_params.ez_auto_calc_payments|default:0}checked{/if} />
                {__("hypay_ez_auto_calc_payments_label")}
            </label>
            <p class="muted description">{__("hypay_ez_auto_calc_payments_desc")}</p>
        </div>
    </div>



    {* NEW: lang he/en *}
    <div class="control-group">
        <label class="control-label" for="ez_doc_lang">{__("hypay_ez_doc_lang")}</label>
        <div class="controls">
            <select name="payment_data[processor_params][ez_doc_lang]" id="ez_doc_lang" class="input-small">
                <option value="he" {if $processor_params.ez_doc_lang|default:'he' == 'he'}selected{/if}>he</option>
                <option value="en" {if $processor_params.ez_doc_lang == 'en'}selected{/if}>en</option>
            </select>
            <p class="muted description">{__("hypay_ez_doc_lang_desc")}</p>
        </div>
    </div>

    {* Created-by API key (distributors only, optional) *}
    <div class="control-group">
        <label class="control-label" for="ez_created_by_api_key">{__("hypay_ez_created_by_api_key")}</label>
        <div class="controls">
            <input type="text"
                   name="payment_data[processor_params][ez_created_by_api_key]"
                   id="ez_created_by_api_key"
                   value="{$processor_params.ez_created_by_api_key|escape}"
                   class="input-xxlarge" />
            <p class="muted description">{__("hypay_ez_created_by_api_key_desc")}</p>
        </div>
    </div>





