<?php
$existing = \CRM_Core_DAO::singleValueQuery("SELECT id FROM civirule_rule WHERE name = 'mas_lifecycle_close_chase'");
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

// Project case type id + Awaiting Close Form status value (looked up, not hardcoded)
$projectTypeId = (int) \Civi\Api4\CaseType::get(FALSE)->addWhere('name', '=', 'project')
  ->execute()->first()['id'];
$awaitingValue = (int) \Civi\Api4\OptionValue::get(FALSE)
  ->addWhere('option_group_id:name', '=', 'case_status')
  ->addWhere('name', '=', 'Awaiting Close Form')
  ->execute()->first()['value'];

$rule = \CRM_Civirules_BAO_CiviRulesRule::writeRecord([
  'name' => 'mas_lifecycle_close_chase',
  'label' => 'mas: Lifecycle close-form chase (client)',
  'trigger_id' => $triggerId,
  'is_active' => 1,
  'description' => 'Project enters Awaiting Close Form; client is chased in propose-mode at 30/90/150 days unless the case has left the status (conditions re-checked with fresh data at each delayed firing).',
]);
$ruleId = (int) $rule->id;

// Conditions (AND chain)
$conds = [
  [$condIds['case_type'], serialize(['operator' => 0, 'case_type_id' => [$projectTypeId]]), NULL],
  [$condIds['case_status_changed'], serialize([
    'original_operator' => '!=', 'original_value' => $awaitingValue,
    'operator' => '=', 'value' => $awaitingValue,
  ]), 'AND'],
  [$condIds['case_status'], serialize(['operator' => 0, 'status_id' => [$awaitingValue]]), 'AND'],
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

// Three delayed propose-mode chase actions: 30 / 90 / 150 days
$actionParams = serialize([
  'template' => 'mas_lifecycle_close_chase__client',
  'recipient' => 'client_rep',
  'mode' => 'propose',
]);
$actionRows = [];
foreach ([30, 90, 150] as $days) {
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
  'trigger_id' => $triggerId,
  'project_type_id' => $projectTypeId,
  'awaiting_value' => $awaitingValue,
  'condition_rows' => $condRows,
  'action_rows' => $actionRows,
], JSON_PRETTY_PRINT) . "\n";
