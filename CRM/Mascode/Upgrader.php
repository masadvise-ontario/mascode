<?php

use CRM_Mascode_ExtensionUtil as E;

/**
 * Collection of upgrade steps (upgrade_NNNN), run via `cv upgrade:db`.
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
   * Reconciles managed entities FIRST (the deploy ritual runs upgrade:db
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
      'ensureClientPdSendRule',
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
      'ensureVcCloseSendRule',
    ] as $method) {
      $result = $p::$method();
      $this->ctx->log->info("5003: $method => " . json_encode($result));
    }
    return TRUE;
  }

  /**
   * Graduate every lifecycle email from propose mode to auto mode
   * (2026-08-20, Brian's call): the emails now send immediately instead of
   * queueing as "Draft Email - Needs Review" activities for CSM click-send.
   *
   * The ensure*() provisioners short-circuit on rules that already exist, so
   * their flipped 'mode' literals only cover fresh installs — existing
   * environments need this rewrite of the serialized action_params.
   * Idempotent; safe to re-run. Reversible via the same method with
   * 'propose', or per-rule in the CiviRules UI action-config form.
   *
   * Note: the SR→Project PD request (ServiceRequestToProject) is not a
   * CiviRules row and carries its mode in code — already flipped there.
   *
   * @return bool
   */
  public function upgrade_5010(): bool {
    $this->ctx->log->info('Applying update 5010 - lifecycle emails: propose -> auto');
    $result = \Civi\Mascode\Service\LifecycleRuleProvisioner::setLifecycleEmailMode('auto');
    $this->ctx->log->info('5010: setLifecycleEmailMode => ' . json_encode($result));
    return TRUE;
  }

  /**
   * Provision the RCS chase rule and retire the two "_propose" rule names
   * (task #931). Two rules-as-code gaps closed together, one upgrade step:
   *
   *  - mas_lifecycle_rcs_chase was the only lifecycle rule with no ensure*()
   *    method and no upgrade caller — it existed only where someone had run
   *    scripts/create-rcs-chase-rule.php by hand. ensureRcsChaseRule() now
   *    provisions it here like every sibling. Idempotent: the ensure*() method
   *    short-circuits on the existing rule name.
   *  - mas_lifecycle_vc_close_propose / mas_lifecycle_pd_client_propose both
   *    send immediately since 2026-08-20, so "propose" is a fossil. The rule
   *    NAME is the ensure*() idempotency key, so the rename has to migrate the
   *    civirule_rule row rather than only change the code literal — otherwise
   *    the provisioner would create a duplicate beside the old rule.
   *    renameLegacyProposeRules() does the guarded, idempotent UPDATE.
   *
   * Order matters: the rename runs FIRST so an existing environment is on the
   * new names before anything reads them. On a fresh install both are no-ops in
   * the harmless direction (the rule was created with the new name; RCS chase
   * short-circuits once present). Re-running the step is a no-op.
   *
   * @return bool
   */
  public function upgrade_5011(): bool {
    $this->ctx->log->info('Applying update 5011 - RCS chase provisioner + retire _propose rule names');
    $p = \Civi\Mascode\Service\LifecycleRuleProvisioner::class;

    $renamed = $p::renameLegacyProposeRules();
    $this->ctx->log->info('5011: renameLegacyProposeRules => ' . json_encode($renamed));

    $rcs = $p::ensureRcsChaseRule();
    $this->ctx->log->info('5011: ensureRcsChaseRule => ' . json_encode($rcs));

    return TRUE;
  }

  /**
   * Provision the manual-intake RCS chase.
   *
   * mas_lifecycle_rcs_chase arms off a status TRANSITION into "Request RCS",
   * so it only ever armed Service Requests that came in through the web form
   * (created at "Ongoing", advanced later by the ask email). A Service Request
   * created directly at "Request RCS" in the CiviCRM "New Case" UI — the
   * MAJORITY intake path — never transitions into the status and was never
   * chased: 0 of 23 since the rule was created, against 19 of 19 for the web
   * form. mas_lifecycle_rcs_chase_on_create closes that on the mas_new_case
   * trigger.
   *
   * This step is deliberately additive. Editing rule 9 would have been the
   * obvious move and would have done nothing: the ensure*() methods
   * short-circuit on the rule NAME, so a changed trigger or condition set is
   * silently skipped wherever the rule already exists — as upgrade_5011 found
   * out. A new name is created normally.
   *
   * NOT a backfill. The 39 Service Requests already sitting in "Request RCS"
   * unarmed are untouched by this step and by the new rule (mas_new_case fires
   * on creation only). Nine of them have been silent for up to two years, so
   * what goes to those clients is a decision for the coordinator, not an
   * upgrade step — see docs/RCS-CHASE-BACKLOG.md.
   */
  public function upgrade_5012(): bool {
    $this->ctx->log->info('Applying update 5012 - RCS chase for manually-created SRs (opened AT Request RCS)');

    // The new rule sits on the mas_new_case trigger, and the provisioner throws
    // if that civirule_trigger row is missing. Our triggers.json is normally
    // registered from hook_civicrm_postUpgrade($op == 'finish') — which runs
    // AFTER this whole upgrade queue — so on an installed site that predates the
    // trigger, this step would abort `cv upgrade:db` midway. Register first;
    // insertTriggersFromJson() is idempotent, so this is a no-op wherever the
    // row already exists (production included, verified 2026-09-09).
    $triggersFile = \CRM_Mascode_ExtensionUtil::path('Civi/Mascode/CiviRules/triggers.json');
    if (file_exists($triggersFile) && class_exists('\CRM_Civirules_Utils_Upgrader')) {
      \CRM_Civirules_Utils_Upgrader::insertTriggersFromJson($triggersFile);
      $this->ctx->log->info('5012: registered CiviRules triggers from triggers.json (idempotent)');
    }
    else {
      // Return rather than fall through: ensureRcsChaseOnCreateRule() throws on
      // the missing trigger row, which would re-create the very mid-upgrade
      // abort this block exists to prevent. Only reachable with CiviRules
      // uninstalled, where mascode does not function anyway.
      $this->ctx->log->warning(
        "5012: SKIPPED - triggers.json not found or CiviRules absent at $triggersFile. "
        . 'Provision the rule later with: cv scr scripts/create-rcs-chase-rule.php'
      );
      return TRUE;
    }

    $onCreate = \Civi\Mascode\Service\LifecycleRuleProvisioner::ensureRcsChaseOnCreateRule();
    $this->ctx->log->info('5012: ensureRcsChaseOnCreateRule => ' . json_encode($onCreate));

    return TRUE;
  }


  /**
   * Converge the client lifecycle template on its Signoff name.
   *
   * Production's template 75 was renamed BY HAND in the UI on 2026-09-17 to
   * "MAS Project Signoff - Client Template". ProjectLifecycleStatusSubscriber
   * still keyed the transition on the old title, so getTemplateSubjects()
   * stopped finding it and sending the client signoff email no longer advanced
   * the case — which in turn meant mas_lifecycle_close_chase never armed. The
   * failure is silent: the email sends, and nothing logs.
   *
   * The code side of that is fixed in the same commit as this step. This step
   * exists because the DECLARATION cannot fix a drifted environment on its own:
   * the managed record is `update => 'unmodified'`, so a template edited in the
   * UI is never rewritten by a deploy. Dev (and any other environment restored
   * from a pre-rename dump) therefore still holds the old title and would break
   * in the mirror-image direction the moment the code lands.
   *
   * Idempotent by construction, and a genuine no-op on production.
   *
   * Deliberately does NOT touch the VC template. Renaming that one to
   * "MAS Project Completion - VC Template" is part of the wider Phase 0 rename,
   * which is gated on the spec being approved. This step is the unblocking
   * subset: it fixes what is broken now and nothing else.
   */
  public function upgrade_5013(): bool {
    $this->ctx->log->info('Applying update 5013 - converge client lifecycle template on "MAS Project Signoff - Client Template"');

    $oldTitle = 'MAS Project Close - Client Template';
    $newTitle = 'MAS Project Signoff - Client Template';

    $rows = \Civi\Api4\MessageTemplate::get(FALSE)
      ->addSelect('id', 'msg_title', 'msg_subject')
      ->addWhere('msg_title', 'IN', [$oldTitle, $newTitle])
      ->execute();

    $byTitle = [];
    foreach ($rows as $row) {
      $byTitle[$row['msg_title']][] = $row;
    }

    // Both titles present. Do NOT guess which one the site actually sends:
    // picking wrong re-breaks the transition, and merging them would discard a
    // body somebody edited by hand. TRANSITIONS keys the new title, so the
    // system is already consistent — the old row is dead weight a human should
    // retire once they have confirmed which body is current.
    if (isset($byTitle[$oldTitle]) && isset($byTitle[$newTitle])) {
      $this->ctx->log->warning(
        '5013: SKIPPED - both "' . $oldTitle . '" (id ' . $byTitle[$oldTitle][0]['id'] . ') and "'
        . $newTitle . '" (id ' . $byTitle[$newTitle][0]['id'] . ') exist. '
        . 'The live transition uses the latter. Retire the former by hand once its body is confirmed superseded.'
      );
      return TRUE;
    }

    // Production's state. Nothing to do — and specifically, the subject is left
    // exactly as it is: matchTransition() reads the subject back OUT of the
    // database, so any subject works provided it collides with no other
    // lifecycle template's prefix. That invariant (D18) is asserted by
    // tests/Live/LifecycleTransitionTemplatesTest.php against the live rows and
    // by tests/Unit/Event/LifecycleTransitionTemplateWiringTest.php against the
    // declarations; overwriting a hand-edited subject here would be an
    // unrequested content change.
    //
    // The rename branch below DOES set the subject, which looks inconsistent
    // with that and is deliberate. There, the title is being migrated from a
    // value no environment should still hold, so the row is being brought onto
    // the declaration wholesale rather than half-migrated: a renamed title
    // beside the old subject is a state the declaration never describes. Here,
    // the title already matches and the subject is whatever a human chose.
    if (isset($byTitle[$newTitle])) {
      $this->ctx->log->info('5013: no-op - "' . $newTitle . '" already present (id ' . $byTitle[$newTitle][0]['id'] . ')');
      return TRUE;
    }

    if (!isset($byTitle[$oldTitle])) {
      // Neither title exists. Managed-entity reconciliation will create the
      // template from the declaration, so this is not an error — it is a fresh
      // install, where there is nothing to migrate.
      $this->ctx->log->info('5013: no-op - neither title present; the managed declaration will provide the template');
      return TRUE;
    }

    // ⚠ SIDE EFFECT, and it outlives this step. CiviCRM stamps
    // civicrm_managed.entity_modified_date on ANY edit of an API4-managed
    // entity (CRM/Core/BAO/Managed.php, hook_civicrm_post 'edit'), with no
    // exemption for a write made by code such as this one. updateExistingEntity()
    // then evaluates `update => 'unmodified'` as
    // `$doUpdate = empty($item['entity_modified_date'])`, so from here on the
    // managed declaration is INERT for this template on this site: no deploy
    // will rewrite its title, subject or body again.
    //
    // Production reached that state already, via the 2026-09-17 hand rename.
    // This step brings every other environment to it too. The consequence for
    // follow-up work is concrete: the retired "MAS Project Close - Client" <h1>
    // still in the sibling .body.html CANNOT be fixed by editing the
    // declaration — that change would deploy and silently do nothing. It has to
    // ship as its own upgrade step.
    $id = (int) $byTitle[$oldTitle][0]['id'];
    \Civi\Api4\MessageTemplate::update(FALSE)
      ->addWhere('id', '=', $id)
      ->addValue('msg_title', $newTitle)
      ->addValue('msg_subject', 'MAS Project Signoff')
      ->execute();

    $this->ctx->log->info('5013: renamed message template ' . $id . ' to "' . $newTitle . '" (subject "MAS Project Signoff")');

    return TRUE;
  }


  /**
   * Repoint CiviRules actions that still name the retired client template title.
   *
   * The sibling of upgrade_5013, and the more urgent of the two. 5013 fixes the
   * STATUS TRANSITION, which failed silently. This one fixes the SEND: rule
   * mas_lifecycle_vc_close_send stores the template title as serialised data in
   * civirule_rule_action.action_params, LifecycleMailer::loadTemplate() resolves
   * it by msg_title and THROWS when it does not resolve, so with a stale title
   * the client close email is not sent at all.
   *
   * A separate step rather than more code inside 5013, because 5013 has already
   * been applied on dev — folding this into it would leave dev permanently
   * unrepaired while looking like it had run.
   *
   * Idempotent. Safe to re-run.
   */
  public function upgrade_5014(): bool {
    $this->ctx->log->info('Applying update 5014 - repoint CiviRules actions at "MAS Project Signoff - Client Template"');

    if (!class_exists('\CRM_Civirules_BAO_CiviRulesRule')) {
      // Same reasoning as 5012: abort loudly rather than fail mid-queue on a
      // site without CiviRules, where mascode does not function anyway.
      $this->ctx->log->warning('5014: SKIPPED - CiviRules is not installed.');
      return TRUE;
    }

    $result = \Civi\Mascode\Service\LifecycleRuleProvisioner::repointClientCloseTemplate();

    foreach ($result['updated'] as $row) {
      $this->ctx->log->info('5014: repointed civirule_rule_action ' . $row['id'] . ' (rule ' . $row['rule'] . ')');
    }
    foreach ($result['skipped'] as $row) {
      // A skip is not a failure, but it IS something a human should read: it
      // means a row mentioned the old title and was left as it was.
      $this->ctx->log->warning('5014: left civirule_rule_action ' . $row['id'] . ' (rule ' . $row['rule'] . ') unchanged - ' . $row['reason']);
    }
    if (!$result['updated'] && !$result['skipped']) {
      $this->ctx->log->info('5014: no-op - no CiviRules action names the retired title');
    }

    return TRUE;
  }


  /**
   * Converge the VC lifecycle template on its Completion name (P0-2).
   *
   * The sibling of upgrade_5013, for the other half of the rename. P0-2 renames
   * the VC template from "MAS Project Close - VC Template" to
   * "MAS Project Completion - VC Template", and
   * ProjectLifecycleStatusSubscriber::TRANSITIONS is rekeyed in the same commit.
   *
   * Why a step is needed when the declaration already carries the new title:
   * that declaration is `update => 'unmodified'`, so CiviCRM will not rewrite a
   * template anyone has edited in the CiviCRM UI. On such a site the title would
   * stay "Close" while TRANSITIONS keys "Completion", the lookup would miss, and
   * sending the VC completion email would silently stop advancing the case — the
   * exact failure that took production down on 2026-09-17, in the mirror image.
   * Both dev and production were verified unstamped on 2026-09-21, so there the
   * declaration does the work and this step is a no-op; it exists for the
   * environment that is not.
   *
   * Also repoints any CiviRules action naming the old title. No rule sends this
   * template today — it goes out by hand, and a sweep of dev and production on
   * 2026-09-21 found none — so this is expected to be a no-op. It is here
   * because "expected to be" is not "is", and the cost of being wrong is an
   * action that throws instead of sending.
   *
   * WHAT THIS STEP DOES NOT DO. It writes msg_title and msg_subject, never
   * msg_html. On the only site where its rename branch actually fires — one
   * whose template was hand-edited in the UI — the new <h1> heading therefore
   * does not arrive, and that is deliberate: overwriting a body someone edited
   * by hand is exactly what `update => 'unmodified'` exists to prevent. Such a
   * site gets a correctly-titled template with its own body, and someone has to
   * decide what its heading should say. Everywhere else the declaration carries
   * the body and this step no-ops.
   *
   * Idempotent. Safe to re-run.
   */
  public function upgrade_5015(): bool {
    $this->ctx->log->info('Applying update 5015 - converge VC lifecycle template on "MAS Project Completion - VC Template"');

    $oldTitle = 'MAS Project Close - VC Template';
    $newTitle = 'MAS Project Completion - VC Template';

    $rows = \Civi\Api4\MessageTemplate::get(FALSE)
      ->addSelect('id', 'msg_title')
      ->addWhere('msg_title', 'IN', [$oldTitle, $newTitle])
      ->execute();

    $byTitle = [];
    foreach ($rows as $row) {
      $byTitle[$row['msg_title']][] = $row;
    }

    if (isset($byTitle[$oldTitle]) && isset($byTitle[$newTitle])) {
      // Same reasoning as 5013: do not guess which one the site sends, and do
      // not merge two bodies a human may have edited separately. TRANSITIONS
      // keys the new title, so the live path is already consistent; the old row
      // is dead weight for a human to retire once they have compared them.
      $this->ctx->log->warning(
        '5015: SKIPPED - both "' . $oldTitle . '" (id ' . $byTitle[$oldTitle][0]['id'] . ') and "'
        . $newTitle . '" (id ' . $byTitle[$newTitle][0]['id'] . ') exist. The live transition uses '
        . 'the latter. Retire the former by hand once its body is confirmed superseded — and note '
        . 'that until you do, staff sending the OLD one will not advance the case. '
        . 'Any CiviRules action naming the old title HAS still been repointed to the new one '
        . 'below; only the template rows were left alone.'
      );
    }
    elseif (isset($byTitle[$newTitle])) {
      $this->ctx->log->info('5015: title already converged (id ' . $byTitle[$newTitle][0]['id'] . ')');
    }
    elseif (!isset($byTitle[$oldTitle])) {
      $this->ctx->log->info('5015: no template under either title; the managed declaration will provide it');
    }
    else {
      $id = (int) $byTitle[$oldTitle][0]['id'];
      // ⚠ As at 5013: this write stamps civicrm_managed.entity_modified_date for
      // this template, after which `update => 'unmodified'` stops rewriting it
      // and later body/subject edits must ship as their own upgrade step rather
      // than as a declaration edit.
      \Civi\Api4\MessageTemplate::update(FALSE)
        ->addWhere('id', '=', $id)
        ->addValue('msg_title', $newTitle)
        ->addValue('msg_subject', 'Project Completion')
        ->execute();
      $this->ctx->log->info('5015: renamed message template ' . $id . ' to "' . $newTitle . '" (subject "Project Completion")');
    }

    if (class_exists('\CRM_Civirules_BAO_CiviRulesRule')) {
      $result = \Civi\Mascode\Service\LifecycleRuleProvisioner::repointRuleActionTemplate($oldTitle, $newTitle);
      foreach ($result['updated'] as $row) {
        $this->ctx->log->info('5015: repointed civirule_rule_action ' . $row['id'] . ' (rule ' . $row['rule'] . ')');
      }
      foreach ($result['skipped'] as $row) {
        $this->ctx->log->warning('5015: left civirule_rule_action ' . $row['id'] . ' (rule ' . $row['rule'] . ') unchanged - ' . $row['reason']);
      }
      if (!$result['updated'] && !$result['skipped']) {
        $this->ctx->log->info('5015: no CiviRules action named the retired VC title (expected)');
      }
    }

    return TRUE;
  }

  /**
   * Rename the "after RCS" template onto the naming convention.
   *
   * Sibling of upgrade_5015, and it exists for the same reason: the
   * declaration in MessageTemplate_after_RCS.mgd.php now carries the new
   * title, but it is `update => 'unmodified'`, so CiviCRM will not rewrite a
   * template anyone has edited in the CiviCRM UI. On such a site the row would
   * keep the old title while the declaration claims the new one, and the next
   * flush would MATCH NOTHING — `match` is on msg_title — and create a second
   * template rather than rename the first. That is the failure this step
   * prevents, and it is likelier here than it was at 5015: this body is a
   * snapshot of a template staff have been editing in the UI for years.
   *
   * The convention, for the record: `MAS <Title Case>` staff send by hand,
   * `mas_*` the system sends unattended, with a `__recipient` suffix. The
   * `_lifecycle_` infix marks the engagement lifecycle and is NOT a reliable
   * signal of a CiviRules rule — the Phase 4 donation trio carry it while being
   * subscriber-fired. "after RCS" said neither. The new title,
   * mas_lifecycle_rcs_circulated__client, pairs it with the VC-facing half of
   * the same event, mas_lifecycle_vc_assignment_offer__vc.
   *
   * NOTHING SENDS THIS TEMPLATE TODAY. A sweep of every civirule_rule_action
   * in dev on 2026-09-23 found no action naming it, and no PHP in this
   * extension references it. So the rename cannot break a send path, because
   * there is no send path — the prefix states the intended mechanism, not a
   * live one. The repoint below is therefore expected to be a no-op, and is
   * here for the same reason 5015's was: "expected to be" is not "is".
   *
   * WHAT THIS STEP DOES NOT DO. It writes msg_title only — not msg_subject and
   * not msg_html. The subject stays "your request got circulated" and the body
   * keeps both of its known defects (a hard-coded first name where a token
   * belongs, and a garbled reimbursement sentence that contradicts the RCS
   * form). Those are item 0 in docs/plans/completion-signoff-tickets.md and
   * need a human decision about wording, not a rename.
   *
   * ⚠ As at 5013 and 5015: this write stamps civicrm_managed.entity_modified_date
   * for this template, after which `update => 'unmodified'` stops rewriting it
   * and later body/subject edits must ship as their own upgrade step rather
   * than as a declaration edit.
   *
   * Idempotent. Safe to re-run.
   */
  public function upgrade_5016(): bool {
    $this->ctx->log->info('Applying update 5016 - rename "after RCS" to "mas_lifecycle_rcs_circulated__client"');

    $oldTitle = 'after RCS';
    $newTitle = 'mas_lifecycle_rcs_circulated__client';

    $rows = \Civi\Api4\MessageTemplate::get(FALSE)
      ->addSelect('id', 'msg_title')
      ->addWhere('msg_title', 'IN', [$oldTitle, $newTitle])
      ->execute();

    $byTitle = [];
    foreach ($rows as $row) {
      $byTitle[$row['msg_title']][] = $row;
    }

    if (isset($byTitle[$oldTitle]) && isset($byTitle[$newTitle])) {
      // Same reasoning as 5015: do not guess which one the site sends, and do
      // not merge two bodies a human may have edited separately. Nothing sends
      // either one today, so this is a tidiness question for a human rather
      // than a broken path.
      $this->ctx->log->warning(
        '5016: SKIPPED - both "' . $oldTitle . '" (id ' . $byTitle[$oldTitle][0]['id'] . ') and "'
        . $newTitle . '" (id ' . $byTitle[$newTitle][0]['id'] . ') exist. Compare the two bodies and '
        . 'retire one by hand. Any CiviRules action naming the old title HAS still been repointed '
        . 'below; only the template rows were left alone.'
      );
    }
    elseif (isset($byTitle[$newTitle])) {
      $this->ctx->log->info('5016: title already renamed (id ' . $byTitle[$newTitle][0]['id'] . ')');
    }
    elseif (!isset($byTitle[$oldTitle])) {
      $this->ctx->log->info('5016: no template under either title; the managed declaration will provide it');
    }
    else {
      $id = (int) $byTitle[$oldTitle][0]['id'];
      \Civi\Api4\MessageTemplate::update(FALSE)
        ->addWhere('id', '=', $id)
        ->addValue('msg_title', $newTitle)
        ->execute();
      $this->ctx->log->info('5016: renamed message template ' . $id . ' to "' . $newTitle . '"');
    }

    if (class_exists('\CRM_Civirules_BAO_CiviRulesRule')) {
      $result = \Civi\Mascode\Service\LifecycleRuleProvisioner::repointRuleActionTemplate($oldTitle, $newTitle);
      foreach ($result['updated'] as $row) {
        $this->ctx->log->info('5016: repointed civirule_rule_action ' . $row['id'] . ' (rule ' . $row['rule'] . ')');
      }
      foreach ($result['skipped'] as $row) {
        $this->ctx->log->warning('5016: left civirule_rule_action ' . $row['id'] . ' (rule ' . $row['rule'] . ') unchanged - ' . $row['reason']);
      }
      if (!$result['updated'] && !$result['skipped']) {
        $this->ctx->log->info('5016: no CiviRules action named "after RCS" (expected)');
      }
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
