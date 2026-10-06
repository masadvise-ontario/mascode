// file: js/donation-contribution-form.js
//
// R1, contact-first donation entry (docs/plans/donations-tickets.md; spec
// BrianPKM 3-Resources/mas-donation-process.md §7). Loaded on the classic
// New/Edit Contribution form by Civi\Mascode\Event\DonationSubscriber.
//
//  - Project lists only the chosen contributor's projects.
//  - Volunteer Consultant lists only that project's coordinators, and is
//    filled in when there is exactly one; with several, the CSM picks the lead.
//
// Core's autocomplete reads the `data-api-params` object again on every
// search, so putting the chosen contact/project into its `values` is enough;
// DonationSubscriber turns them into a WHERE clause on the server. This is a
// data-entry convenience, not a control: a submitted id is not checked. The
// custom fields arrive by AJAX (the custom-data block reloads when the
// financial type changes), so everything is wired on crmLoad rather than once.
(function ($, CRM) {
  'use strict';

  var PROJECT = 'Contribution.Donation_Link.Linked_Project';
  var VC = 'Contribution.Donation_Link.Linked_VC';

  function fieldFor($form, fieldName) {
    return $form.find('input.crm-form-autocomplete[data-api-params]').filter(function () {
      var p = $(this).data('apiParams');
      return p && p.fieldName === fieldName;
    }).first();
  }

  function setValues($input, values) {
    var p = $input.data('apiParams');
    if (p) {
      p.values = values;
    }
  }

  function contactId($form) {
    var v = $form.find('input[name=contact_id]').val();
    return /^\d+$/.test(v || '') ? v : null;
  }

  // Fill the VC when the project has exactly one coordinator. Never
  // overwrites a VC the CSM already chose, and drops a late answer for a
  // project the CSM has since changed.
  function autofillVc($vc, $project, pid) {
    if ($vc.val()) {
      return;
    }
    CRM.api4('Contact', 'autocomplete', $.extend({}, $vc.data('apiParams'), {input: ''})).then(function (result) {
      if (result.length === 1 && !$vc.val() && $project.val() === pid) {
        $vc.select2('data', result[0], true);
      }
    });
  }

  function sync($form, changed) {
    var $project = fieldFor($form, PROJECT);
    var $vc = fieldFor($form, VC);
    var cid = contactId($form);
    if ($project.length) {
      setValues($project, cid ? {contact_id: cid} : {});
      // A new contributor: a project picked for the previous one no longer applies.
      if (changed === 'contact' && $project.val()) {
        $project.select2('val', '', true);
      }
    }
    if ($vc.length) {
      var pid = $project.length ? $project.val() : '';
      setValues($vc, pid ? {'Donation_Link.Linked_Project': pid} : {});
      if (changed === 'project') {
        $vc.select2('val', '');
        if (pid) {
          autofillVc($vc, $project, pid);
        }
      }
    }
  }

  // Namespaced and re-bound: a popup form re-runs this file on every open.
  $(document).off('crmLoad.masDonation').on('crmLoad.masDonation', function (e) {
    var $form = $(e.target).closest('form.CRM_Contribute_Form_Contribution');
    if (!$form.length) {
      $form = $(e.target).find('form.CRM_Contribute_Form_Contribution');
    }
    if (!$form.length) {
      return;
    }
    $form.off('.masDonation')
      .on('change.masDonation', 'input[name=contact_id]', function () { sync($form, 'contact'); })
      .on('change.masDonation', 'input.crm-form-autocomplete[data-api-params]', function () {
        var p = $(this).data('apiParams');
        if (p && p.fieldName === PROJECT) {
          sync($form, 'project');
        }
      });
    sync($form, null);
  });
})(CRM.$, CRM);
