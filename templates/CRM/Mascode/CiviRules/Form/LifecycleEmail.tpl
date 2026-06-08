{* templates/CRM/Mascode/CiviRules/Form/LifecycleEmail.tpl *}

<h3>{ts}Lifecycle Email (propose/auto) Configuration{/ts}</h3>

<div class="crm-block crm-form-block crm-form-block-civirules-action-lifecycle-email">

    <div class="help">
        <p>{ts}Drafts (propose mode) or sends (auto mode) a lifecycle email for the triggering case. Propose mode writes a "Draft Email - Needs Review" activity on the case for the CSM to review and send; auto mode emails immediately and records a "Sent Automated Email" activity.{/ts}</p>
    </div>

    <div class="crm-section">
        <div class="label">{$form.template.label}</div>
        <div class="content">
            {$form.template.html}
            <div class="description">{ts}The CiviCRM message template to render against the case and recipient.{/ts}</div>
        </div>
        <div class="clear"></div>
    </div>

    <div class="crm-section">
        <div class="label">{$form.recipient.label}</div>
        <div class="content">
            {$form.recipient.html}
            <div class="description">{ts}Case roles resolve at send time from the triggering case.{/ts}</div>
        </div>
        <div class="clear"></div>
    </div>

    <div class="crm-section">
        <div class="label">{$form.recipient_contact_id.label}</div>
        <div class="content">
            {$form.recipient_contact_id.html}
            <div class="description">{ts}Only used when Recipient is "Specific contact ID...".{/ts}</div>
        </div>
        <div class="clear"></div>
    </div>

    <div class="crm-section">
        <div class="label">{$form.mode.label}</div>
        <div class="content">
            {$form.mode.html}
            <div class="description">{ts}Ship propose; graduate to auto per rule once Nina and Steve sign off.{/ts}</div>
        </div>
        <div class="clear"></div>
    </div>

    <div class="crm-section">
        <div class="label">{$form.source_contact_id.label}</div>
        <div class="content">
            {$form.source_contact_id.html}
            <div class="description">{ts}Activity source contact. Empty = the MAS admin contact (mascode_admin_contact_id setting).{/ts}</div>
        </div>
        <div class="clear"></div>
    </div>

    <div class="crm-section">
        <div class="label">{$form.delay_days.label}</div>
        <div class="content">
            {$form.delay_days.html}
            <div class="description">{ts}Days to wait after the trigger before this action fires (chase cadence). Empty = immediately. Conditions are re-checked with fresh data when a delayed action fires, so chases self-cancel when the case moves on.{/ts}</div>
        </div>
        <div class="clear"></div>
    </div>

</div>

<div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
