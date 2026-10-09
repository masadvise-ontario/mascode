// file: js/donation-fee-estimate.js
//
// DN-7 (docs/plans/donations-tickets.md): on core's Record Payment form, fill
// in an estimated CanadaHelps fee (rate x payment amount) for the Treasurer to
// check against the CanadaHelps statement and overwrite. Loaded by
// Civi\Mascode\Event\DonationSubscriber::estimateFee(), which passes the rate
// and the CanadaHelps payment-method value in CRM.vars.mascodeFeeEstimate.
//
// Only a fee box that is empty, or still holds this script's own estimate, is
// ever written: a figure the Treasurer typed is never replaced. Switching the
// method away from CanadaHelps takes the untouched estimate back out. The form
// usually opens in a popup, so it is wired on crmLoad. Accepted: a typed fee
// that happens to equal the estimate is treated as the estimate.
(function ($, CRM) {
  'use strict';

  var FORM = 'form.CRM_Contribute_Form_AdditionalPayment';

  // Read a localized amount ("1,234.56", or "1.234,56" where that is the locale).
  // The separators come from the site settings; '' is a legitimate thousands
  // separator, so only a missing value falls back.
  function amount(val, opts) {
    var sep = typeof opts.thousands === 'string' ? opts.thousands : ',';
    var dec = typeof opts.decimal === 'string' && opts.decimal ? opts.decimal : '.';
    var n = parseFloat(String(val || '').split(sep).join('').split(dec).join('.'));
    return isNaN(n) ? null : n;
  }

  function estimateFor(total, opts) {
    return CRM.formatMoney(Math.round(total * opts.rate) / 100, true);
  }

  function wire($form, opts) {
    if ($form.data('mascodeFeeEstimate')) {
      return;
    }
    $form.data('mascodeFeeEstimate', true);
    var $fee = $('#fee_amount', $form);
    var $method = $('#payment_instrument_id', $form);
    var $total = $('#total_amount', $form);
    if (!$fee.length || !$method.length) {
      return;
    }
    // A form re-shown after a failed save still holds the estimate it was
    // given: recognise it as ours, so it keeps following the amount.
    var start = amount($total.val(), opts);
    var estimate = (start !== null && $fee.val() === estimateFor(start, opts)) ? $fee.val() : null;
    var $note = $('<div class="description"></div>')
      .text(ts('Estimated at %1% of the payment. Check it against the CanadaHelps statement and change it if it differs.', {1: opts.rate}))
      .hide()
      .insertAfter($fee);

    function apply() {
      var untouched = $fee.val() === '' || $fee.val() === estimate;
      var total = amount($total.val(), opts);
      if (String($method.val()) === opts.instrument && total !== null && total > 0) {
        if (untouched) {
          estimate = estimateFor(total, opts);
          $fee.val(estimate).trigger('change');
          $note.show();
        }
        return;
      }
      if (estimate !== null && $fee.val() === estimate) {
        $fee.val('').trigger('change');
      }
      estimate = null;
      $note.hide();
    }

    $method.on('change', apply);
    $total.on('change', apply);
    $fee.on('input', function () {
      if ($fee.val() !== estimate) {
        $note.hide();
      }
    });
    apply();
  }

  // Namespaced and re-bound, in case this file runs more than once on a page.
  $(document).off('crmLoad.masFeeEstimate').on('crmLoad.masFeeEstimate', function (e) {
    // Each load of the form resets these vars; a refund sets enabled false.
    var opts = CRM.vars.mascodeFeeEstimate;
    if (!opts || !opts.enabled) {
      return;
    }
    $(e.target).closest(FORM).add($(e.target).find(FORM)).each(function () {
      wire($(this), opts);
    });
  });
})(CRM.$, CRM);
