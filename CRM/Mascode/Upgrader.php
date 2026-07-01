<?php

use CRM_Mascode_ExtensionUtil as E;

/**
 * Collection of upgrade steps (upgrade_NNNN), run via `cv ext:upgrade-db`.
 * This is a first-class config channel — see docs/CONFIGURATION-AS-CODE.md.
 */
class CRM_Mascode_Upgrader extends \CRM_Extension_Upgrader_Base
{
  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Reorder case_status option-value weights into the MAS lifecycle sequence
   * so the Cases Dashboard (and the native Summary of Involvement) list
   * statuses in workflow order rather than by value. Display-only and
   * idempotent. Keyed by VALUE, not name, because the case_status machine
   * names "Closed" (value 2 / label "Resolved") and "closed" (value 15 /
   * label "Closed") collide case-insensitively. See
   * mas-lifecycle-dashboard-spec (Cases Dashboard).
   *
   * @return bool
   */
  public function upgrade_5001(): bool {
    $this->ctx->log->info('Applying update 5001 - reorder case_status weights');
    // value => weight (workflow order: open SR, closed SR, open project, closed project)
    $seq = [
      1 => 1, 6 => 2, 18 => 3, 7 => 4,
      10 => 5, 5 => 6, 8 => 7, 9 => 8, 15 => 9,
      16 => 10, 14 => 11, 19 => 12,
      13 => 13, 12 => 14, 11 => 15,
      2 => 16,
    ];
    foreach ($seq as $value => $weight) {
      \Civi\Api4\OptionValue::update(FALSE)
        ->addWhere('option_group_id:name', '=', 'case_status')
        ->addWhere('value', '=', (string) $value)
        ->addValue('weight', $weight)
        ->execute();
    }
    return TRUE;
  }

  /**
   * Close-path status rework (2026-06-12): "Awaiting Close Form" (19) is
   * replaced by Awaiting Project Definition (20) / Awaiting VC Project Close
   * Form (21) / Awaiting Client Project Close Form (22).
   *
   * Reconciles managed entities FIRST (the deploy ritual runs ext:upgrade-db
   * before cv flush, so the new OptionValues may not exist yet), then
   * migrates existing cases 19 → 22 (the old status meant "client has been
   * asked"), then reasserts the full workflow-order weight map. Keyed by
   * VALUE for the same name-collision reason as upgrade_5001.
   *
   * @return bool
   */
  public function upgrade_5002(): bool {
    $this->ctx->log->info('Applying update 5002 - close-path status rework');

    civicrm_api4('Managed', 'reconcile', ['modules' => ['mascode'], 'checkPermissions' => FALSE]);

    $migrated = \Civi\Api4\CiviCase::update(FALSE)
      ->addWhere('status_id', '=', 19)
      ->addValue('status_id', 22)
      ->execute();
    // Trashed cases are excluded by the API's default filter — migrate them
    // too, so a later un-trash doesn't resurrect the retired status.
    $migratedTrashed = \Civi\Api4\CiviCase::update(FALSE)
      ->addWhere('status_id', '=', 19)
      ->addWhere('is_deleted', '=', TRUE)
      ->addValue('status_id', 22)
      ->execute();
    $this->ctx->log->info('5002: migrated ' . count($migrated) . ' case(s) (+' . count($migratedTrashed) . ' trashed) from Awaiting Close Form to Awaiting Client Project Close Form');

    // value => weight (workflow order: SR block, then project block)
    $seq = [
      1 => 1, 6 => 2, 18 => 3, 7 => 4,
      10 => 5, 5 => 6, 8 => 7, 9 => 8, 15 => 9,
      20 => 10, 16 => 11, 14 => 12, 21 => 13, 22 => 14,
      13 => 15, 12 => 16, 11 => 17,
      2 => 18,
      19 => 19,
    ];
    foreach ($seq as $value => $weight) {
      \Civi\Api4\OptionValue::update(FALSE)
        ->addWhere('option_group_id:name', '=', 'case_status')
        ->addWhere('value', '=', (string) $value)
        ->addValue('weight', $weight)
        ->execute();
    }
    return TRUE;
  }

  /**
   * Project Definition split (2026-06-12, same-day follow-up to 5002):
   * "Awaiting Project Definition" (20) becomes "Awaiting VC Project
   * Definition" and gains a client stage "Awaiting Client Project
   * Definition" (23). Reconciles managed entities first (applies the rename
   * + creates 23), then reasserts the workflow-order weight map.
   *
   * @return bool
   */
  public function upgrade_5004(): bool {
    $this->ctx->log->info('Applying update 5004 - project-definition status split');

    civicrm_api4('Managed', 'reconcile', ['modules' => ['mascode'], 'checkPermissions' => FALSE]);

    // value => weight (workflow order: SR block, then project block)
    $seq = [
      1 => 1, 6 => 2, 18 => 3, 7 => 4,
      10 => 5, 5 => 6, 8 => 7, 9 => 8, 15 => 9,
      20 => 10, 23 => 11, 16 => 12, 14 => 13, 21 => 14, 22 => 15,
      13 => 16, 12 => 17, 11 => 18,
      2 => 19,
      19 => 20,
    ];
    foreach ($seq as $value => $weight) {
      \Civi\Api4\OptionValue::update(FALSE)
        ->addWhere('option_group_id:name', '=', 'case_status')
        ->addWhere('value', '=', (string) $value)
        ->addValue('weight', $weight)
        ->execute();
    }
    return TRUE;
  }

  /**
   * Provision the Project Definition flow rules (2026-06-12): VC PD chase,
   * client PD authorization chase, and the propose-authorization-on-VC-
   * definition rule. Reconciles managed entities first so the PD activity
   * types, custom fields, and templates exist. Idempotent.
   *
   * @return bool
   */
  public function upgrade_5005(): bool {
    $this->ctx->log->info('Applying update 5005 - provision project-definition rules');

    civicrm_api4('Managed', 'reconcile', ['modules' => ['mascode'], 'checkPermissions' => FALSE]);

    $p = \Civi\Mascode\Service\LifecycleRuleProvisioner::class;
    foreach ([
      'ensureVcPdChaseRule',
      'ensureClientPdChaseRule',
      'ensureClientPdProposeRule',
    ] as $method) {
      $result = $p::$method();
      $this->ctx->log->info("5005: $method => " . json_encode($result));
    }
    return TRUE;
  }

  /**
   * Project-close answers moved to the project CASE (2026-06-14). Reconcile
   * creates the new case groups (Project_Close_VC / Project_Close_Client);
   * this then deactivates the legacy UNMANAGED activity groups so their fields
   * stop appearing on the close activities. Idempotent; no-op where the legacy
   * groups are absent (e.g. a fresh install).
   *
   * @return bool
   */
  public function upgrade_5006(): bool {
    $this->ctx->log->info('Applying update 5006 - retire legacy project-close activity custom groups');

    civicrm_api4('Managed', 'reconcile', ['modules' => ['mascode'], 'checkPermissions' => FALSE]);

    $deactivated = \Civi\Api4\CustomGroup::update(FALSE)
      ->addWhere('name', 'IN', ['Project_Close_VC_Fields', 'Project_Close_Client_Fields'])
      ->addValue('is_active', FALSE)
      ->execute();
    $this->ctx->log->info('5006: deactivated ' . count($deactivated) . ' legacy close activity group(s)');
    return TRUE;
  }

  /**
   * Order the project case-detail sections so "Project Close - VC Report"
   * appears before "Project Close - Client Feedback" (2026-06-17). Managed
   * CustomGroup reconcile does NOT apply `weight` to existing groups (only to
   * freshly-created ones), so the weights declared in the .mgd.php files are
   * set explicitly here for already-installed sites. Idempotent.
   *
   * @return bool
   */
  public function upgrade_5007(): bool {
    $this->ctx->log->info('Applying update 5007 - reorder project-close case sections (VC Report before Client Feedback)');

    \Civi\Api4\CustomGroup::update(FALSE)
      ->addWhere('name', '=', 'Project_Close_VC')
      ->addValue('weight', 15)
      ->execute();
    \Civi\Api4\CustomGroup::update(FALSE)
      ->addWhere('name', '=', 'Project_Close_Client')
      ->addValue('weight', 16)
      ->execute();
    return TRUE;
  }

  /**
   * Consolidate project hours onto the close report (2026-06-29). Historically
   * hours lived in two places: the legacy Projects.Hours field and the
   * close-report Project_Close_VC.hours_worked field. This sums the two onto
   * hours_worked (the authoritative close-report field), repoints the two
   * DB-only board SavedSearches that still reference the legacy field, then
   * deletes Projects.Hours. The managed board + case-detail SearchKits are
   * repointed via their .mgd.php files (applied on cv flush), not here.
   *
   * Idempotent: guarded on the legacy field's existence, so it is a clean
   * no-op on environments where the consolidation has already been applied.
   *
   * @return bool
   */
  public function upgrade_5008(): bool {
    $this->ctx->log->info('Applying update 5008 - consolidate project hours onto close report; remove legacy Projects.Hours');

    $legacy = \Civi\Api4\CustomField::get(FALSE)
      ->addWhere('custom_group_id:name', '=', 'Projects')
      ->addWhere('name', '=', 'Hours')
      ->addSelect('id')
      ->execute()->first();
    if (!$legacy) {
      $this->ctx->log->info('5008: Projects.Hours absent - already consolidated, skipping');
      return TRUE;
    }

    // 1) Backfill: hours_worked = COALESCE(Projects.Hours,0) + COALESCE(hours_worked,0)
    //    Only write where the sum is > 0 and differs from the current value.
    $rows = \Civi\Api4\CiviCase::get(FALSE)
      ->addSelect('id', 'Projects.Hours', 'Project_Close_VC.hours_worked')
      ->addWhere('case_type_id:name', '=', 'project')
      ->addWhere('is_deleted', '=', FALSE)
      ->execute();
    $updated = 0;
    foreach ($rows as $r) {
      $old = $r['Projects.Hours'];
      $new = $r['Project_Close_VC.hours_worked'];
      $hasOld = ($old !== NULL && $old !== '');
      $hasNew = ($new !== NULL && $new !== '');
      if (!$hasOld && !$hasNew) {
        continue;
      }
      $sum = (float) ($hasOld ? $old : 0) + (float) ($hasNew ? $new : 0);
      if ($sum <= 0) {
        continue;
      }
      if ($hasNew && (float) $new == $sum) {
        continue;
      }
      \Civi\Api4\CiviCase::update(FALSE)
        ->addValue('Project_Close_VC.hours_worked', $sum)
        ->addWhere('id', '=', $r['id'])
        ->execute();
      $updated++;
    }
    $this->ctx->log->info("5008: backfilled hours_worked on $updated project case(s)");

    // 2) Repoint the two DB-only board SavedSearches (matched by name, since
    //    IDs are not portable) and any of their SearchDisplays.
    $repointed = $this->repointHoursRefs(['19_Completed_Projects', '21_Hours_from_Completed_Projects']);
    $this->ctx->log->info("5008: repointed $repointed DB-only search entit(ies) off Projects.Hours");

    // 3) Delete the legacy field (drops its value column).
    \Civi\Api4\CustomField::delete(FALSE)->addWhere('id', '=', $legacy['id'])->execute();
    $this->ctx->log->info('5008: deleted legacy Projects.Hours custom field');

    return TRUE;
  }

  /**
   * Move the client-authored `expected_benefits` custom field from the VC
   * Project_Definition group to the client Project_Definition_Authorization
   * group (2026-07-01). The field is filled in by the CLIENT on the PD-Client
   * authorization form ("Your input" section), so it belongs with the other
   * authorization answers. This aligns three views that were previously wrong:
   * the manage-case section it displays under, the PD-Client activity summary
   * (SubmissionSummaryService reads the Auth case group), and the VC-portal
   * case-detail SearchKit card.
   *
   * The .mgd.php files declare the end state (field now under the Auth group).
   * The CiviCRM upgrade queue reconciles managed entities BEFORE this step, so
   * a fresh (empty) Auth-group expected_benefits field already exists by the
   * time we run — the primary path is therefore: copy any existing values from
   * the old VC-group field to the new Auth-group field, then delete the old
   * field (dropping its column). Real submissions survive. As a fallback for
   * environments where reconcile has NOT yet created the destination field, we
   * use CRM_Core_BAO_CustomField::moveField() (migrates the column + data).
   *
   * Idempotent: guarded on the old field's existence, so it is a clean no-op
   * once the field no longer lives in the Project_Definition group.
   *
   * @return bool
   */
  public function upgrade_5009(): bool {
    $this->ctx->log->info('Applying update 5009 - move expected_benefits to Project_Definition_Authorization group');

    $old = \Civi\Api4\CustomField::get(FALSE)
      ->addWhere('name', '=', 'expected_benefits')
      ->addWhere('custom_group_id:name', '=', 'Project_Definition')
      ->addSelect('id')
      ->execute()->first();

    if (!$old) {
      $this->ctx->log->info('5009: expected_benefits absent from Project_Definition group - already migrated, skipping move');
    }
    else {
      $newGroup = \Civi\Api4\CustomGroup::get(FALSE)
        ->addWhere('name', '=', 'Project_Definition_Authorization')
        ->addSelect('id')
        ->execute()->first();
      if (!$newGroup) {
        throw new \CRM_Core_Exception('5009: Project_Definition_Authorization custom group not found');
      }

      // Has managed reconcile already created the destination field? (Primary path.)
      $new = \Civi\Api4\CustomField::get(FALSE)
        ->addWhere('name', '=', 'expected_benefits')
        ->addWhere('custom_group_id:name', '=', 'Project_Definition_Authorization')
        ->addSelect('id')
        ->execute()->first();

      if ($new) {
        // Copy values across (only where the Auth field is still empty, so a
        // re-run never clobbers real authorization data), then drop the old field.
        $rows = \Civi\Api4\CiviCase::get(FALSE)
          ->addSelect('id', 'Project_Definition.expected_benefits', 'Project_Definition_Authorization.expected_benefits')
          ->addWhere('Project_Definition.expected_benefits', 'IS NOT NULL')
          ->execute();
        $copied = 0;
        foreach ($rows as $r) {
          $src = $r['Project_Definition.expected_benefits'];
          $dst = $r['Project_Definition_Authorization.expected_benefits'];
          if ($src === NULL || $src === '') {
            continue;
          }
          if ($dst !== NULL && $dst !== '') {
            continue;
          }
          \Civi\Api4\CiviCase::update(FALSE)
            ->addValue('Project_Definition_Authorization.expected_benefits', $src)
            ->addWhere('id', '=', $r['id'])
            ->execute();
          $copied++;
        }
        \Civi\Api4\CustomField::delete(FALSE)->addWhere('id', '=', $old['id'])->execute();
        $this->ctx->log->info("5009: copied expected_benefits on $copied case(s); deleted legacy VC-group field {$old['id']}");
      }
      else {
        // Fallback: destination field not created yet — migrate the column + data.
        \CRM_Core_BAO_CustomField::moveField($old['id'], $newGroup['id']);
        $this->ctx->log->info("5009: moved expected_benefits (field {$old['id']}) to Project_Definition_Authorization");
      }

      // Drop any now-orphaned managed_entities row for the old declaration so
      // reconcile doesn't trip over it. Idempotent (0 rows if already gone).
      \CRM_Core_DAO::executeQuery(
        "DELETE FROM civicrm_managed WHERE module = 'mascode' AND name = 'CustomField_ProjectDef_Expected_Benefits'"
      );
    }

    // Reweight the Authorization group's fields to mirror the PD-Client form
    // flow (matches the .mgd.php declarations; set explicitly because
    // pre-existing fields don't reliably pick up new managed weights on
    // reconcile, and moveField preserves the field's old weight).
    $authWeights = [
      'agreed_with_description' => 1,
      'expected_benefits' => 2,
      'capacity_increase' => 3,
      'client_signature' => 4,
      'client_title' => 5,
      'authorized_certification' => 6,
    ];
    foreach ($authWeights as $name => $weight) {
      \Civi\Api4\CustomField::update(FALSE)
        ->addWhere('custom_group_id:name', '=', 'Project_Definition_Authorization')
        ->addWhere('name', '=', $name)
        ->addValue('weight', $weight)
        ->execute();
    }

    // Reweight the VC group too (mirrors PD-VC form order). Existing installs
    // carry drifted weights that reconcile does not reset, and removing
    // expected_benefits left a hole, so set all three explicitly.
    $vcWeights = [
      'estimated_duration' => 1,
      'assistance_provided' => 2,
      'project_completion' => 3,
    ];
    foreach ($vcWeights as $name => $weight) {
      \Civi\Api4\CustomField::update(FALSE)
        ->addWhere('custom_group_id:name', '=', 'Project_Definition')
        ->addWhere('name', '=', $name)
        ->addValue('weight', $weight)
        ->execute();
    }

    $this->ctx->log->info('5009: reweighted PD fields to form order');

    civicrm_api4('Managed', 'reconcile', ['modules' => ['mascode'], 'checkPermissions' => FALSE]);
    return TRUE;
  }

  /**
   * Replace every 'Projects.Hours' string with 'Project_Close_VC.hours_worked'
   * in the api_params of the named DB-only SavedSearches and the settings of
   * their SearchDisplays. Returns the number of entities updated. Idempotent.
   */
  private function repointHoursRefs(array $ssNames): int {
    $old = 'Projects.Hours';
    $new = 'Project_Close_VC.hours_worked';
    $deep = function ($v) use (&$deep, $old, $new) {
      if (is_array($v)) {
        foreach ($v as $k => $vv) {
          $v[$k] = $deep($vv);
        }
        return $v;
      }
      return is_string($v) ? str_replace($old, $new, $v) : $v;
    };
    $count = 0;
    $searches = \Civi\Api4\SavedSearch::get(FALSE)
      ->addWhere('name', 'IN', $ssNames)
      ->addSelect('id', 'api_params')
      ->execute();
    $ids = [];
    foreach ($searches as $s) {
      $ids[] = $s['id'];
      $after = $deep($s['api_params']);
      if (json_encode($after) !== json_encode($s['api_params'])) {
        \Civi\Api4\SavedSearch::update(FALSE)->addWhere('id', '=', $s['id'])->addValue('api_params', $after)->execute();
        $count++;
      }
    }
    if ($ids) {
      $displays = \Civi\Api4\SearchDisplay::get(FALSE)
        ->addWhere('saved_search_id', 'IN', $ids)
        ->addSelect('id', 'settings')
        ->execute();
      foreach ($displays as $d) {
        $after = $deep($d['settings']);
        if (json_encode($after) !== json_encode($d['settings'])) {
          \Civi\Api4\SearchDisplay::update(FALSE)->addWhere('id', '=', $d['id'])->addValue('settings', $after)->execute();
          $count++;
        }
      }
    }
    return $count;
  }

  /**
   * Provision the lifecycle close-path CiviRules rule assemblies as code
   * (zero-touch direction, 2026-06-12): retarget the existing client
   * close-chase rule to the new status, and create the VC close-report
   * chase + the propose-client-close-on-VC-report rules. All idempotent —
   * see Civi\Mascode\Service\LifecycleRuleProvisioner.
   *
   * @return bool
   */
  public function upgrade_5003(): bool {
    $this->ctx->log->info('Applying update 5003 - provision lifecycle close-path rules');
    $p = \Civi\Mascode\Service\LifecycleRuleProvisioner::class;
    foreach ([
      'ensureLifecycleEmailAction',
      'retargetClientCloseChaseRule',
      'ensureClientCloseChaseRule',
      'ensureVcCloseChaseRule',
      'ensureVcCloseProposeRule',
    ] as $method) {
      $result = $p::$method();
      $this->ctx->log->info("5003: $method => " . json_encode($result));
    }
    return TRUE;
  }

  /**
   * Example: Run an external SQL script when the module is installed.
   *
   * Note that if a file is present sql\auto_install that will run regardless of this hook.
   */
  // public function install(): void {
  //   $this->executeSqlFile('sql/my_install.sql');
  // }

  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   */
  // public function postInstall(): void {
  //  $customFieldId = civicrm_api3('CustomField', 'getvalue', array(
  //    'return' => array("id"),
  //    'name' => "customFieldCreatedViaManagedHook",
  //  ));
  //  civicrm_api3('Setting', 'create', array(
  //    'myWeirdFieldSetting' => array('id' => $customFieldId, 'weirdness' => 1),
  //  ));
  // }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   *
   * Note that if a file is present sql\auto_uninstall that will run regardless of this hook.
   */
  // public function uninstall(): void {
  //   $this->executeSqlFile('sql/my_uninstall.sql');
  // }

  /**
   * Example: Run a simple query when a module is enabled.
   */
  // public function enable(): void {
  //  CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  // }

  /**
   * Example: Run a simple query when a module is disabled.
   */
  // public function disable(): void {
  //   CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  // }

  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  // public function upgrade_4200(): bool {
  //   $this->ctx->log->info('Applying update 4200');
  //   CRM_Core_DAO::executeQuery('UPDATE foo SET bar = "whiz"');
  //   CRM_Core_DAO::executeQuery('DELETE FROM bang WHERE willy = wonka(2)');
  //   return TRUE;
  // }

  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  // public function upgrade_4201(): bool {
  //   $this->ctx->log->info('Applying update 4201');
  //   // this path is relative to the extension base dir
  //   $this->executeSqlFile('sql/upgrade_4201.sql');
  //   return TRUE;
  // }

  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  // public function upgrade_4202(): bool {
  //   $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

  //   $this->addTask(E::ts('Process first step'), 'processPart1', $arg1, $arg2);
  //   $this->addTask(E::ts('Process second step'), 'processPart2', $arg3, $arg4);
  //   $this->addTask(E::ts('Process second step'), 'processPart3', $arg5);
  //   return TRUE;
  // }
  // public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  // public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  // public function processPart3($arg5) { sleep(10); return TRUE; }

  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  // public function upgrade_4203(): bool {
  //   $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

  //   $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
  //   $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
  //   for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
  //     $endId = $startId + self::BATCH_SIZE - 1;
  //     $title = E::ts('Upgrade Batch (%1 => %2)', array(
  //       1 => $startId,
  //       2 => $endId,
  //     ));
  //     $sql = '
  //       UPDATE civicrm_contribution SET foobar = apple(banana()+durian)
  //       WHERE id BETWEEN %1 and %2
  //     ';
  //     $params = array(
  //       1 => array($startId, 'Integer'),
  //       2 => array($endId, 'Integer'),
  //     );
  //     $this->addTask($title, 'executeSql', $sql, $params);
  //   }
  //   return TRUE;
  // }
}
