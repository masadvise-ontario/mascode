// Angular module that carries the shared MAS client-form stylesheet
// (css/mas-forms.css, declared in mascode.php's hook_civicrm_angularModules).
// CSS-only modules still need an angular.module() declaration so that forms
// listing "mascodeForms" in their .aff.json `requires` resolve cleanly.
(function (angular) {
  'use strict';
  angular.module('mascodeForms', []);
})(angular);
