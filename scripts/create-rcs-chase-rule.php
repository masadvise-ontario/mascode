<?php

/**
 * Creates the mas_lifecycle_rcs_chase CiviRule.
 *
 * Trigger: Case is changed. Conditions: case type = service_request AND
 * status transitioned to "Request RCS" AND still in that status when each
 * delayed action fires (the engine re-checks conditions with fresh data, so
 * the chase self-cancels once the client returns the RCS — the Afform
 * submission moves the SR to "RCS Completed").
 *
 * Actions: 2x mas_lifecycle_email (template mas_lifecycle_rcs_chase__client,
 * recipient = client rep, propose mode) delayed 21 and 42 days (~the 21-day
 * intake cycle from the spec; tune here and re-run after deleting the rule,
 * or edit the delays in the CiviRules UI).
 *
 * Idempotent — skips if the rule name already exists.
 * Usage: cv scr scripts/create-rcs-chase-rule.php --user=<admin>
 */

$existing = \CRM_Core_DAO::singleValueQuery("SELECT id FROM civirule_rule WHERE name = 'mas_lifecycle_rcs_chase'");
if ($existing) {
  echo json_encode(['already_exists' => (int) $existing]) . "\n";
  return;
}

$triggerId = (int) \CRM_Core_DAO::singleValueQuery("SELECT id FROM civirule_trigger WHERE name = 'changed_case'");
$actionId = (int) \CRM_Core_DAO::singleValueQuery("SELECT id FROM civirule_action WHERE name = 'mas_lifecycle_email'");
$condIds = [];
foreach (['case_type', 'case_status_changed', 'case_status'] as $n) {
  $condIds[$n] = (int) \CRM_Core_DAO::singleValueQuery("SELECT id FROM civirule_condition WHERE name = '$n'");
}

$srTypeId = (int) \Civi\Api4\CaseType::get(FALSE)->addWhere('name', '=', 'service_request')
  ->execute()->first()['id'];
$requestRcsValue = (int) \Civi\Api4\OptionValue::get(FALSE)
  ->addWhere('option_group_id:name', '=', 'case_status')
  ->addWhere('name', '=', 'Request RCS')
  ->execute()->first()['value'];

$rule = \CRM_Civirules_BAO_CiviRulesRule::writeRecord([
  'name' => 'mas_lifecycle_rcs_chase',
  'label' => 'mas: Lifecycle RCS chase (client)',
  'trigger_id' => $triggerId,
  'is_active' => 1,
  'description' => 'SR enters Request RCS; client is chased in propose-mode at 21/42 days unless the case has left the status (form return moves it to RCS Completed, which cancels pending chases).',
]);
$ruleId = (int) $rule->id;

$conds = [
  [$condIds['case_type'], serialize(['operator' => 0, 'case_type_id' => [$srTypeId]]), NULL],
  [$condIds['case_status_changed'], serialize([
    'original_operator' => '!=', 'original_value' => $requestRcsValue,
    'operator' => '=', 'value' => $requestRcsValue,
  ]), 'AND'],
  [$condIds['case_status'], serialize(['operator' => 0, 'status_id' => [$requestRcsValue]]), 'AND'],
];
$condRows = [];
foreach ($conds as [$cid, $params, $link]) {
  $row = \CRM_Civirules_BAO_CiviRulesRuleCondition::writeRecord([
    'rule_id' => $ruleId,
    'condition_id' => $cid,
    'condition_params' => $params,
    'condition_link' => $link,
    'is_active' => 1,
  ]);
  $condRows[] = (int) $row->id;
}

$actionParams = serialize([
  'template' => 'mas_lifecycle_rcs_chase__client',
  'recipient' => 'client_rep',
  'mode' => 'propose',
]);
$actionRows = [];
foreach ([21, 42] as $days) {
  $delay = new \CRM_Civirules_Delay_XDays();
  $prop = new \ReflectionProperty($delay, 'dayOffset');
  $prop->setAccessible(TRUE);
  $prop->setValue($delay, $days);
  $row = \CRM_Civirules_BAO_CiviRulesRuleAction::writeRecord([
    'rule_id' => $ruleId,
    'action_id' => $actionId,
    'action_params' => $actionParams,
    'delay' => serialize($delay),
    'ignore_condition_with_delay' => 0,
    'is_active' => 1,
  ]);
  $actionRows[] = (int) $row->id;
}

echo json_encode([
  'rule_id' => $ruleId,
  'sr_type_id' => $srTypeId,
  'request_rcs_value' => $requestRcsValue,
  'condition_rows' => $condRows,
  'action_rows' => $actionRows,
], JSON_PRETTY_PRINT) . "\n";
