<?php

declare(strict_types=1);

/**
 * The *Monthly Project Check-in* activity type, and the custom group scoped to
 * it that holds the VC's two answers.
 *
 * Spec: BrianPKM 3-Resources/mascode-vc-monthly-donation-digest-spec.md, D5 and
 * §Data Model. Ticket: docs/plans/completion-signoff-tickets.md P1-1.
 *
 * ⚠ WHY THESE TWO ARE IN ONE FILE, AND WHY THE OPTION VALUE IS DECLARED FIRST.
 * This departs from the directory's one-entity-per-file naming, and the reason
 * is a real, silent, first-install-only defect rather than taste.
 *
 * `extends_entity_column_value:name` is resolved by
 * CRM_Core_BAO_CustomGroup::getExtendsEntityColumnValueOptions(), which looks
 * the name up in the LIVE activity_type option list at write time. If the
 * option value does not exist yet, the name resolves to nothing and core
 * writes **NULL** — no error, no log line. A NULL there does not mean "scoped
 * to nothing"; it means "scoped to EVERY activity type", so all three fields
 * below would appear on every activity form in CiviCRM.
 *
 * Core creates managed records in a deterministic order: `mixin/mgd-php@2`
 * does `sort($mgdFiles)` and appends each file's array in order, and
 * CRM_Core_ManagedEntities::reconcileEntities() walks the `create` plan in
 * that same order. Declared as two files under the directory's own
 * convention — `CustomGroup_…` and `OptionValue_ActivityType_…` — "C" sorts
 * before "O", so the group was created BEFORE the option value existed, every
 * time, on every clean environment. Verified on dev 2026-09-22: the group
 * reconciled with `extends_entity_column_value` NULL.
 *
 * A LATER reconcile heals it (verified: reset to NULL, `cv flush`, value
 * restored), because by then the option value exists. That is why this is a
 * first-pass-only defect, and why it would most likely have been noticed on
 * production and nowhere else — the deploy ritual happens to run two passes
 * (`cv upgrade:db` then `cv flush`). Relying on that is relying on a
 * coincidence in a runbook.
 *
 * Declaring both in one array removes the ordering question rather than
 * answering it: there is no filename relationship left to break, and the
 * option value is created first because it is first in the array.
 *
 * The same trap applies to the two pre-existing Activity-extending groups
 * (`Project_Definition_Fields`, `Project_Definition_Client_Fields`). They read
 * back correctly ONLY because their values predate the `:name` declarations —
 * those declarations have never had to resolve anything. Recorded rather than
 * fixed here: they are correct on every environment that exists, and changing
 * them is not this ticket's scope.
 *
 * `extends` must stay in the same `values` array as
 * `extends_entity_column_value:name`. Core's option loader needs `extends`
 * (or an id/name it can look one up from) to know which option list to search;
 * an update that omits it resolves to NULL just as silently. Asserted by
 * tests/Unit/Managed/MonthlyCheckinDeclarationTest.php.
 */
return [

  [
    'name' => 'OptionValue_activity_type_Monthly_Project_Check_in',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'activity_type',
        'name' => 'Monthly Project Check-in',
        'label' => 'Monthly Project Check-in',
        'description' => 'A VC\'s answer to the monthly digest: is this project finished, and will they make the donation ask themselves.',
        // 81 because 80 is the highest activity_type weight in use today
        // (Project Definition - Client Authorization). Weight only orders the
        // admin picker; a collision would be cosmetic, but the next free
        // number costs nothing.
        'weight' => 81,
        'is_active' => TRUE,
        'is_default' => FALSE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name', 'option_group_id'],
    ],
  ],

  [
    'name' => 'CustomGroup_Monthly_Project_Checkin',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Monthly_Project_Checkin',
        'title' => 'Monthly Project Check-in',
        'extends' => 'Activity',
        // Matches the OptionValue declared in
        // OptionValue_ActivityType_MonthlyProjectCheckin.mgd.php by NAME, so
        // the numeric activity-type value may differ dev vs prod.
        'extends_entity_column_value:name' => ['Monthly Project Check-in'],
        'style' => 'Inline',
        'collapse_display' => FALSE,
        'is_active' => TRUE,
        'weight' => 17,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'CustomField_MPC_Is_Complete',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Monthly_Project_Checkin',
        'name' => 'is_complete',
        'label' => 'Is this project complete?',
        // Boolean, not a Yes/No option group: the value is read in PHP by
        // VcDigestSubmitSubscriber (P1-5) to decide whether to send the
        // Completion template, and an option group would hand that decision a
        // string whose truthiness depends on the option VALUE. See the
        // mascode memory note feedback_afform_boolean_string_id_bug — core
        // casts `!!option.id`, so a string '0' is TRUE.
        'data_type' => 'Boolean',
        'html_type' => 'Radio',
        'is_required' => FALSE,
        'is_searchable' => TRUE,
        'is_active' => TRUE,
        'weight' => 1,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomField_MPC_Vc_Will_Ask',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Monthly_Project_Checkin',
        'name' => 'vc_will_ask',
        'label' => 'Will you make the donation ask yourself?',
        'data_type' => 'Boolean',
        'html_type' => 'Radio',
        // NOT required, and the distinction is load-bearing rather than
        // lenient: NULL means "the question was not put to them" (because
        // they answered No to is_complete, so the form never showed it),
        // which is a different fact from an answered No. D8 queues the office
        // follow-up on an unasked client, so conflating the two would
        // manufacture work items. Spec §Data Model states the same.
        'is_required' => FALSE,
        'is_searchable' => TRUE,
        'is_active' => TRUE,
        'weight' => 2,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomField_MPC_Digest_Round',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Monthly_Project_Checkin',
        'name' => 'digest_round',
        'label' => 'Digest Round',
        // `YYYY-MM` of the digest that prompted this answer; blank when the
        // form was reached some other way. It is what makes "months stuck"
        // countable (Goal 8) and what the P2-1 idempotency guard will match
        // on, so it is String rather than Date — a round is a month, not a
        // day, and storing a date would invite a same-month comparison to be
        // written as a range.
        'data_type' => 'String',
        'html_type' => 'Text',
        'text_length' => 7,
        'is_required' => FALSE,
        'is_searchable' => TRUE,
        'is_active' => TRUE,
        'weight' => 3,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
];
