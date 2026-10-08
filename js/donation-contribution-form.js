// file: js/donation-contribution-form.js
//
// R1, contact-first donation entry, and R10, one contribution per cheque
// (docs/plans/donations-tickets.md; spec BrianPKM
// 3-Resources/mas-donation-process.md §7). Loaded on the classic New/Edit
// Contribution form by Civi\Mascode\Event\DonationSubscriber.
//
//  - Projects lists only the chosen contributor's projects; pick every
//    project the cheque covers.
//  - Volunteer Consultants lists the coordinators of ALL picked projects.
//    When the projects change, picks that no longer belong are dropped and
//    each project's sole coordinator is added; for a project with several,
//    the CSM picks the lead.
//
// Both fields are multi-value (serialized) EntityReference custom fields, which
// core's classic form renders single-select, so the widgets are re-created
// with `multiple`. The hidden input then holds "12,34", which core splits on
// save (CRM_Core_BAO_CustomField::formatCustomField).
//
// Core's autocomplete reads the `data-api-params` object again on every
// search, so putting the chosen contact/projects into its `values` is enough;
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

  // Multi-select, and open on click: these lists are short once narrowed.
  // Re-create the widget keeping the same apiParams object (the one `values`
  // is written into). Idempotent.
  function enhance($input) {
    var s2 = $input.data('select2');
    if (!s2 || (s2.opts.minimumInputLength === 0 && s2.opts.multiple)) {
      return;
    }
    $input.crmAutocomplete('destroy');
    $input.crmAutocomplete($input.data('apiEntity'), $input.data('apiParams'),
      $.extend({}, $input.data('selectParams') || {}, {minimumInputLength: 0, multiple: true}));
  }

  function setValues($input, values) {
    var p = $input.data('apiParams');
    if (p) {
      p.values = values;
    }
  }

  function ids(val) {
    return (val || '').split(',').filter(function (v) { return /^\d+$/.test(v); });
  }

  function contactId($form) {
    var v = $form.find('input[name=contact_id]').val();
    return /^\d+$/.test(v || '') ? v : null;
  }

  // After the projects change: keep the VC picks that coordinate one of
  // them, and add each project's sole coordinator. Drops a late answer for a
  // project list the CSM has since changed.
  function refreshVcs($vc, $project) {
    var asked = $project.val();
    var pids = ids(asked);
    if (!pids.length) {
      $vc.select2('data', []);
      return;
    }
    var base = $vc.data('apiParams') || {};
    // Promise.all, not $.when: $.when hands a single request's rows straight
    // through instead of a list of lists.
    Promise.all(pids.map(function (pid) {
      var params = $.extend({}, base, {input: '', values: {'Donation_Link.Linked_Project': pid}});
      return CRM.api4('Contact', 'autocomplete', params);
    })).then(function (lists) {
      if ($project.val() !== asked) {
        return;
      }
      var allowed = {}, add = [];
      lists.forEach(function (list) {
        (list || []).forEach(function (row) { allowed[row.id] = row; });
        if (list && list.length === 1) {
          add.push(list[0]);
        }
      });
      var keep = ($vc.select2('data') || []).filter(function (row) { return allowed[row.id]; });
      add.forEach(function (row) {
        if (!keep.some(function (k) { return String(k.id) === String(row.id); })) {
          keep.push(row);
        }
      });
      $vc.select2('data', keep, true);
    }).catch(function (e) {
      // A failed lookup leaves the VC picks as they are; the CSM can still pick.
      CRM.console('warn', 'Volunteer Consultant lookup failed', e);
    });
  }

  function sync($form, changed) {
    var $project = fieldFor($form, PROJECT);
    var $vc = fieldFor($form, VC);
    var cid = contactId($form);
    $project.add($vc).each(function () { enhance($(this)); });
    if ($project.length) {
      setValues($project, cid ? {contact_id: cid} : {});
      // A new contributor: projects picked for the previous one no longer apply.
      if (changed === 'contact' && $project.val()) {
        $project.select2('data', [], true);
      }
    }
    if ($vc.length) {
      var pids = $project.length ? ids($project.val()) : [];
      setValues($vc, pids.length ? {'Donation_Link.Linked_Project': pids.join(',')} : {});
      if (changed === 'project') {
        refreshVcs($vc, $project);
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
