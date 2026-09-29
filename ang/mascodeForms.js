// Angular module that carries the shared MAS client-form stylesheet
// (css/mas-forms.css, declared in mascode.php's hook_civicrm_angularModules).
// CSS-only modules still need an angular.module() declaration so that forms
// listing "mascodeForms" in their .aff.json `requires` resolve cleanly.
(function (angular) {
  'use strict';

  /**
   * Per-VC check-in page (afformMASVcCheckin): hide rows with no project behind them.
   *
   * afFieldset.getFieldData() pushes a blank record whenever an entity has no
   * data, so an empty pane renders one phantom row: an "Already answered"
   * entry with no project, or a question block with no case. It is harmless on
   * submit (VcCheckinPageSubscriber drops an unanswered row, and an empty
   * record is skipped) but it reads as a broken page. These class directives
   * attach through the panes' existing CSS classes, which FormBuilder keeps,
   * and hide every repeat item whose record lacks the key a seeded row always
   * has, and the whole pane when nothing is left in it. Display only: nothing
   * about what is submitted changes.
   */
  function hideRowsWithout(key, stateClass) {
    return ['$timeout', function ($timeout) {
      return {
        restrict: 'C',
        require: '?afFieldset',
        link: function (scope, element, attrs, fieldset) {
          if (!fieldset) {
            return;
          }
          var apply = function () {
            var data = fieldset.getData() || [];
            var items = element[0].querySelectorAll('[af-repeat-item]');
            var visible = 0;
            for (var i = 0; i < items.length; i++) {
              var record = data[i];
              var real = !!(record && record.fields && record.fields[key]);
              items[i].style.display = real ? '' : 'none';
              if (real) {
                visible++;
              }
            }
            element[0].style.display = visible ? '' : 'none';
            // Tell the page wrapper, so CSS can show the "nothing to answer"
            // line and hide the submit button (css/mas-forms.css).
            var root = element[0].closest('.mas-vc-checkin');
            if (root) {
              root.classList.toggle(stateClass, visible > 0);
              root.classList.add('mas-vc-checkin-ready');
            }
          };
          scope.$watch(function () {
            return (fieldset.getData() || []).map(function (r) {
              return r && r.fields && r.fields[key] ? '1' : '0';
            }).join(',');
          }, function () {
            $timeout(apply);
          });
        }
      };
    }];
  }

  angular.module('mascodeForms', [])
    // Both keyed on case_id: every seeded row carries one in its prefill data,
    // while a label can legitimately be empty (no client, no code, blank
    // subject). The Answered pane has NO case_id field on purpose — it must
    // stay unsubmittable — so this reads the client-side record, which holds
    // every prefilled key whether or not the layout has a field for it.
    .directive('masVcCheckinOpen', hideRowsWithout('case_id', 'mas-has-open'))
    .directive('masVcCheckinAnswered', hideRowsWithout('case_id', 'mas-has-answered'));
})(angular);
