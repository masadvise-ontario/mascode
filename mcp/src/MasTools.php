<?php

namespace Civi\Mascode\Mcp;

use Civi\Core\Service\AutoSubscriber;
use Civi\Mcp\Protocol\ToolException;
use Civi\Mcp\Tools\Ability;
use Civi\Mcp\Tools\CollectToolsEvent;
use Civi\Mcp\Tools\CoreTools;
use Civi\Mcp\Tools\ToolContext;

/**
 * MAS engagement-lifecycle tools. MAS-specific names (case types, statuses,
 * managed searches, custom groups) live ONLY here, registered through
 * civi.mcp.tools like any other extension would — so the Phase 2 split into a
 * generic core + a MAS tool pack is a file move.
 *
 * Queue questions run the same managed SearchKit displays that back the
 * civicrm/mas-ops-home dashboard, so answers agree with it by construction.
 * Everything runs with checkPermissions TRUE as the signed-in contact.
 *
 * Vocabulary (mascode Civi/Mascode/Managed/*.mgd.php):
 *  - case types: service_request, project
 *  - MAS rep = the "Case Coordinator is" relationship; in MAS data the rep is
 *    contact A, so RelationshipCache near_contact_id is the rep.
 *  - case codes: Cases_SR_Projects_.MAS_SR_Case_Code (SR),
 *    Projects.MAS_Project_Case_Code (project)
 */
class MasTools extends AutoSubscriber {

  /** queue key => [saved search, display, description] */
  public const QUEUES = [
    'new_service_requests' => ['MAS_Ops_New_Service_Requests', 'MAS_Ops_New_Service_Requests_Tile', 'New service requests not yet actioned'],
    'rcs_requested' => ['MAS_Ops_RCS_Requested', 'MAS_Ops_RCS_Requested_Tile', 'Service requests waiting on the client to return the RCS (Request for Consulting Services)'],
    'ready_to_circulate' => ['MAS_Ops_Returned_Ready_To_Circulate', 'MAS_Ops_Returned_Ready_To_Circulate_Tile', 'RCS returned; ready to circulate to volunteer consultants'],
    'in_circulation_no_pickup' => ['MAS_Ops_In_Circulation_No_Pickup', 'MAS_Ops_In_Circulation_No_Pickup_Tile', 'Sent for assignment but no VC has picked it up'],
    'awaiting_close_form' => ['MAS_Ops_Projects_Awaiting_Close_Form', 'MAS_Ops_Projects_Awaiting_Close_Form_Tile', 'Projects waiting on the VC completion form or the client sign-off form'],
    'drafts_awaiting_review' => ['MAS_Ops_Drafts_Awaiting_Review', 'MAS_Ops_Drafts_Awaiting_Review_Tile', 'Draft emails waiting for staff review before sending'],
  ];

  public const CASE_TYPES = ['service_request', 'project'];

  /**
   * MAS "staff" (VC access spec D8): the same test as mascode's VcNativeScreenGuardSubscriber. Every
   * tool in this pack, and the generic civi_get / civi_describe, needs it, so a volunteer consultant
   * who is allowlisted by mistake is offered no tools at all.
   */
  public const STAFF = ['view all contacts', 'edit all contacts', 'administer CiviCRM'];

  public const STAFF_ONLY_CORE_TOOLS = ['civi_describe', 'civi_get'];

  private const COORDINATOR = 'Case Coordinator is';

  private const EMAIL_TYPES = ['Email', 'Bulk Email', 'Sent Automated Email', 'Reminder Sent', 'Draft Email - Needs Review', 'Inbound Email'];

  public static function getSubscribedEvents(): array {
    return [CollectToolsEvent::NAME => [['onCollect', 0], ['restrictCoreTools', -100]]];
  }

  /** Runs after CoreTools has registered (priority -100). */
  public function restrictCoreTools(CollectToolsEvent $event): void {
    foreach (self::STAFF_ONLY_CORE_TOOLS as $name) {
      $event->restrict($name, self::STAFF);
    }
  }

  public function onCollect(CollectToolsEvent $event): void {
    $queueDoc = implode('; ', array_map(fn($k, $q) => "$k = {$q[2]}", array_keys(self::QUEUES), self::QUEUES));
    $caseRef = [
      'case_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'CiviCRM case ID'],
      'case_code' => ['type' => 'string', 'maxLength' => 64, 'description' => 'MAS case code (service request or project code)'],
    ];

    $event->add(new Ability(
      'ops_queue',
      'MAS ops queue',
      "List the cases (or drafts) in one MAS operations queue, exactly as the ops dashboard shows it, with the total count. Queues: $queueDoc.",
      [
        'type' => 'object',
        'properties' => [
          'queue' => ['type' => 'string', 'enum' => array_keys(self::QUEUES)],
          'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
        ],
        'required' => ['queue'],
        'additionalProperties' => FALSE,
      ],
      TRUE, ['access CiviCRM', self::STAFF], [self::class, 'opsQueue'],
    ));

    $event->add(new Ability(
      'pipeline_summary',
      'MAS pipeline summary',
      'Counts of open service requests and open projects by status, from the ops dashboard tiles, plus every queue\'s total.',
      ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => FALSE],
      TRUE, ['access CiviCRM', self::STAFF], [self::class, 'pipelineSummary'],
    ));

    $event->add(new Ability(
      'find_cases',
      'Find MAS cases',
      'Find service requests or projects by type, status, client organization, MAS rep (volunteer consultant / case coordinator), practice area, or how long since the case last changed. '
      . 'Open cases only unless open_only is false. Use status names from civi_describe (entity Case, field status_id).',
      [
        'type' => 'object',
        'properties' => [
          'case_type' => ['type' => 'string', 'enum' => self::CASE_TYPES],
          'status' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 64], 'description' => 'Case status names, e.g. "Request RCS", "Active"'],
          'client' => ['type' => 'string', 'maxLength' => 128, 'description' => 'Part of the client organization name'],
          'rep' => ['type' => 'string', 'maxLength' => 128, 'description' => 'Part of the MAS rep / VC name'],
          'rep_contact_id' => ['type' => 'integer', 'minimum' => 1],
          'practice_area' => ['type' => 'string', 'maxLength' => 64],
          'unchanged_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650, 'description' => 'Only cases not modified in at least this many days'],
          'open_only' => ['type' => 'boolean'],
          'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
        ],
        'additionalProperties' => FALSE,
      ],
      TRUE, ['access CiviCRM', self::STAFF], [self::class, 'findCases'],
    ));

    $event->add(new Ability(
      'case_detail',
      'MAS case detail',
      'Everything about one case: type, status, dates, clients, MAS rep and other roles, the MAS custom fields (service request, project, project definition, project close), and its 20 most recent activities. Give case_id or case_code.',
      ['type' => 'object', 'properties' => $caseRef, 'additionalProperties' => FALSE],
      TRUE, ['access CiviCRM', self::STAFF], [self::class, 'caseDetail'],
    ));

    $event->add(new Ability(
      'case_contact_history',
      'MAS case email history',
      'Emails sent or drafted on one case (manual, automated, reminders, drafts awaiting review), newest first, with counts by type — use it for "what did we send and when" and "how many times have we chased". Give case_id or case_code.',
      [
        'type' => 'object',
        'properties' => $caseRef + ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]],
        'additionalProperties' => FALSE,
      ],
      TRUE, ['access CiviCRM', self::STAFF], [self::class, 'caseContactHistory'],
    ));

    $event->add(new Ability(
      'vc_workload',
      'MAS rep workload',
      'Open cases per MAS rep (volunteer consultant / case coordinator), split into service requests and projects, busiest first.',
      [
        'type' => 'object',
        'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]],
        'additionalProperties' => FALSE,
      ],
      TRUE, ['access CiviCRM', self::STAFF], [self::class, 'vcWorkload'],
    ));

    $event->add(new Ability(
      'overdue_projects',
      'MAS overdue projects',
      'Open projects past their estimated completion date, or started more than N months ago (default 12), with the reason each is flagged.',
      [
        'type' => 'object',
        'properties' => [
          'older_than_months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 120],
          'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
        ],
        'additionalProperties' => FALSE,
      ],
      TRUE, ['access CiviCRM', self::STAFF], [self::class, 'overdueProjects'],
    ));
  }

  // ── Handlers ─────────────────────────────────────────────────

  public static function opsQueue(array $args, ToolContext $ctx): array {
    [$search, $display, $about] = self::QUEUES[$args['queue']];
    $run = self::runDisplay($search, $display, $ctx->limit($args['limit'] ?? NULL));
    return ['queue' => $args['queue'], 'about' => $about] + $run;
  }

  public static function pipelineSummary(array $args, ToolContext $ctx): array {
    $tile = function (string $search): array {
      $out = [];
      foreach (self::rawRun($search, $search . '_Tile', 100)->getArrayCopy() as $row) {
        $out[] = ['status' => $row['data']['label'] ?? NULL, 'count' => (int) ($row['data']['c'] ?? 0)];
      }
      return $out;
    };
    $queues = [];
    foreach (self::QUEUES as $key => [$search, $display]) {
      $queues[$key] = (int) self::rawRun($search, $display, 1, 'row_count')->rowCount;
    }
    return [
      'open_service_requests_by_status' => $tile('MAS_Ops_Dash_SR_Open'),
      'open_projects_by_status' => $tile('MAS_Ops_Dash_PJ_Open'),
      'queue_totals' => $queues,
    ];
  }

  public static function findCases(array $args, ToolContext $ctx): array {
    $api = self::caseQuery()
      ->addSelect('GROUP_CONCAT(DISTINCT client.sort_name) AS clients', 'GROUP_CONCAT(DISTINCT rep.display_name) AS reps')
      ->setLimit($ctx->limit($args['limit'] ?? NULL))
      ->addOrderBy('modified_date', 'ASC');

    if (!empty($args['case_type'])) {
      $api->addWhere('case_type_id:name', '=', $args['case_type']);
    }
    if (!empty($args['status'])) {
      $api->addWhere('status_id:name', 'IN', $args['status']);
    }
    elseif (($args['open_only'] ?? TRUE) === TRUE) {
      $api->addWhere('status_id', 'IN', self::openStatusIds());
    }
    if (!empty($args['client'])) {
      $api->addWhere('client.sort_name', 'LIKE', '%' . self::likeEscape($args['client']) . '%');
    }
    if (!empty($args['rep'])) {
      $api->addWhere('rep.display_name', 'LIKE', '%' . self::likeEscape($args['rep']) . '%');
    }
    if (!empty($args['rep_contact_id'])) {
      $api->addWhere('rep.id', '=', $args['rep_contact_id']);
    }
    if (!empty($args['practice_area'])) {
      $api->addClause('OR',
        ['Cases_SR_Projects_.Practice_Area:label', 'LIKE', '%' . self::likeEscape($args['practice_area']) . '%'],
        ['Projects.Practice_Area:label', 'LIKE', '%' . self::likeEscape($args['practice_area']) . '%'],
      );
    }
    if (!empty($args['unchanged_days'])) {
      $api->addWhere('modified_date', '<', date('Y-m-d H:i:s', time() - 86400 * $args['unchanged_days']));
    }

    $result = $api->execute();
    $rows = array_map([self::class, 'caseRow'], $result->getArrayCopy());
    return ['returned' => count($rows), 'matched' => (int) $result->countMatched(), 'truncated' => $result->countMatched() > count($rows), 'cases' => $rows];
  }

  public static function caseDetail(array $args, ToolContext $ctx): array {
    $id = self::resolveCaseId($args);
    $case = \Civi\Api4\CiviCase::get(TRUE)
      ->addSelect('id', 'subject', 'case_type_id:label', 'case_type_id:name', 'status_id:label', 'start_date', 'end_date', 'created_date', 'modified_date', 'details')
      ->addSelect('Cases_SR_Projects_.*', 'Projects.*', 'Project_Definition.*', 'Project_Definition_Authorization.*', 'Project_Close_VC.*', 'Project_Close_Client.*')
      ->addWhere('id', '=', $id)
      ->execute()->first();
    if (!$case) {
      throw new ToolException("No case with id $id that you can read.");
    }

    $clients = \Civi\Api4\CaseContact::get(TRUE)
      ->addSelect('contact_id', 'contact_id.display_name', 'contact_id.contact_type')
      ->addWhere('case_id', '=', $id)
      ->execute()->getArrayCopy();

    // MAS data is not consistent about which end of a case-role relationship
    // holds the role (the rep is contact A of "Case Coordinator is"; the
    // client rep is contact B of "Case Client Rep is"), so the role holder is
    // taken to be whichever party is not a client of the case.
    $clientIds = array_map('intval', array_column($clients, 'contact_id'));
    $roles = [];
    $rels = \Civi\Api4\Relationship::get(TRUE)
      ->addSelect('relationship_type_id.label_a_b', 'contact_id_a', 'contact_id_a.display_name', 'contact_id_b', 'contact_id_b.display_name', 'is_active')
      ->addWhere('case_id', '=', $id)
      ->execute();
    foreach ($rels as $r) {
      $side = in_array((int) $r['contact_id_a'], $clientIds, TRUE) ? 'b' : 'a';
      $roles[] = [
        'role' => $r['relationship_type_id.label_a_b'] ?? NULL,
        'contact_id' => $r["contact_id_$side"],
        'name' => $r["contact_id_$side.display_name"] ?? NULL,
        'active' => (bool) ($r['is_active'] ?? FALSE),
      ];
    }

    $activities = \Civi\Api4\Activity::get(TRUE)
      ->addSelect('id', 'activity_type_id:label', 'subject', 'activity_date_time', 'status_id:label')
      ->addWhere('case_id', '=', $id)
      ->addWhere('is_deleted', '=', FALSE)
      ->addOrderBy('activity_date_time', 'DESC')
      ->setLimit(20)
      ->execute()->getArrayCopy();

    $fields = [];
    foreach ($case as $k => $v) {
      if (str_contains($k, '.') && $v !== NULL && $v !== '' && $v !== []) {
        $fields[$k] = $v;
      }
    }
    return [
      'case' => CoreTools::trimRow(array_filter($case, fn($k) => !str_contains($k, '.') || str_ends_with($k, ':label') || str_ends_with($k, ':name'), ARRAY_FILTER_USE_KEY)),
      'code' => $case['Cases_SR_Projects_.MAS_SR_Case_Code'] ?? $case['Projects.MAS_Project_Case_Code'] ?? NULL,
      'custom_fields' => CoreTools::trimRow($fields),
      'clients' => $clients,
      'roles' => $roles,
      'recent_activities' => $activities,
    ];
  }

  public static function caseContactHistory(array $args, ToolContext $ctx): array {
    $id = self::resolveCaseId($args);
    $result = \Civi\Api4\Activity::get(TRUE)
      ->addSelect('id', 'activity_type_id:name', 'activity_type_id:label', 'subject', 'activity_date_time', 'status_id:label', 'row_count')
      ->addSelect('GROUP_CONCAT(DISTINCT target.display_name) AS recipients', 'GROUP_FIRST(source.display_name) AS sender')
      ->addJoin('Contact AS target', 'LEFT', 'ActivityContact', ['id', '=', 'target.activity_id'], ['target.record_type_id:name', '=', '"Activity Targets"'])
      ->addJoin('Contact AS source', 'LEFT', 'ActivityContact', ['id', '=', 'source.activity_id'], ['source.record_type_id:name', '=', '"Activity Source"'])
      ->addWhere('case_id', '=', $id)
      ->addWhere('activity_type_id:name', 'IN', self::EMAIL_TYPES)
      ->addWhere('is_deleted', '=', FALSE)
      ->addWhere('is_test', '=', FALSE)
      ->addGroupBy('id')
      ->addOrderBy('activity_date_time', 'DESC')
      ->setLimit($ctx->limit($args['limit'] ?? NULL))
      ->execute();

    $byType = [];
    $rows = [];
    foreach ($result as $a) {
      $label = $a['activity_type_id:label'] ?? '?';
      $byType[$label] = ($byType[$label] ?? 0) + 1;
      unset($a['activity_type_id:name']);
      $rows[] = CoreTools::trimRow($a);
    }
    return [
      'case_id' => $id,
      'returned' => count($rows),
      'matched' => (int) $result->countMatched(),
      'counts_by_type' => $byType ?: new \stdClass(),
      'emails' => $rows,
    ];
  }

  public static function vcWorkload(array $args, ToolContext $ctx): array {
    $rows = \Civi\Api4\CiviCase::get(TRUE)
      ->addSelect('rep.id', 'rep.display_name', 'case_type_id:name', 'COUNT(DISTINCT id) AS n')
      ->addJoin('RelationshipCache AS cc', 'INNER', NULL, ['id', '=', 'cc.case_id'], ['cc.near_relation:name', '=', '"' . self::COORDINATOR . '"'], ['cc.is_active', '=', TRUE])
      ->addJoin('Contact AS rep', 'INNER', NULL, ['rep.id', '=', 'cc.near_contact_id'])
      ->addWhere('is_deleted', '=', FALSE)
      ->addWhere('status_id', 'IN', self::openStatusIds())
      ->addWhere('case_type_id:name', 'IN', self::CASE_TYPES)
      ->addGroupBy('rep.id')
      ->addGroupBy('case_type_id')
      ->setLimit(0)
      ->execute();

    $reps = [];
    foreach ($rows as $r) {
      $rid = $r['rep.id'];
      $reps[$rid] ??= ['contact_id' => $rid, 'name' => $r['rep.display_name'], 'open_service_requests' => 0, 'open_projects' => 0, 'total' => 0];
      $key = ($r['case_type_id:name'] ?? '') === 'project' ? 'open_projects' : 'open_service_requests';
      $reps[$rid][$key] += (int) $r['n'];
      $reps[$rid]['total'] += (int) $r['n'];
    }
    usort($reps, fn($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);
    $limit = $ctx->limit($args['limit'] ?? NULL);
    return ['reps_with_open_cases' => count($reps), 'truncated' => count($reps) > $limit, 'reps' => array_slice($reps, 0, $limit)];
  }

  public static function overdueProjects(array $args, ToolContext $ctx): array {
    $months = $args['older_than_months'] ?? 12;
    $today = date('Y-m-d');
    $cutoff = date('Y-m-d', strtotime("-$months months"));

    $result = self::caseQuery()
      ->addSelect('GROUP_CONCAT(DISTINCT client.sort_name) AS clients', 'GROUP_CONCAT(DISTINCT rep.display_name) AS reps', 'Projects.Estimated_Completion_Date')
      ->addWhere('case_type_id:name', '=', 'project')
      ->addWhere('status_id', 'IN', self::openStatusIds())
      ->addClause('OR', ['Projects.Estimated_Completion_Date', '<', $today], ['start_date', '<', $cutoff])
      ->addOrderBy('start_date', 'ASC')
      ->setLimit($ctx->limit($args['limit'] ?? NULL))
      ->execute();

    $rows = [];
    foreach ($result as $r) {
      $row = self::caseRow($r);
      $est = $r['Projects.Estimated_Completion_Date'] ?? NULL;
      $row['estimated_completion'] = $est;
      $row['reasons'] = array_values(array_filter([
        ($est && $est < $today) ? 'past estimated completion' : NULL,
        (!empty($r['start_date']) && $r['start_date'] < $cutoff) ? "started more than $months months ago" : NULL,
      ]));
      $rows[] = $row;
    }
    return ['returned' => count($rows), 'matched' => (int) $result->countMatched(), 'truncated' => $result->countMatched() > count($rows), 'projects' => $rows];
  }

  // ── Helpers ──────────────────────────────────────────────────

  /** Case.get with the client and MAS-rep joins, grouped per case. */
  private static function caseQuery(): \Civi\Api4\Generic\DAOGetAction {
    return \Civi\Api4\CiviCase::get(TRUE)
      ->addSelect('id', 'subject', 'case_type_id:name', 'status_id:label', 'start_date', 'modified_date', 'row_count')
      ->addSelect('Cases_SR_Projects_.MAS_SR_Case_Code', 'Projects.MAS_Project_Case_Code')
      ->addJoin('Contact AS client', 'LEFT', 'CaseContact', ['id', '=', 'client.case_id'])
      ->addJoin('RelationshipCache AS cc', 'LEFT', NULL, ['id', '=', 'cc.case_id'], ['cc.near_relation:name', '=', '"' . self::COORDINATOR . '"'], ['cc.is_active', '=', TRUE])
      ->addJoin('Contact AS rep', 'LEFT', NULL, ['rep.id', '=', 'cc.near_contact_id'])
      ->addWhere('is_deleted', '=', FALSE)
      ->addGroupBy('id');
  }

  private static function caseRow(array $r): array {
    $modified = $r['modified_date'] ?? NULL;
    return [
      'case_id' => $r['id'],
      'code' => $r['Cases_SR_Projects_.MAS_SR_Case_Code'] ?? $r['Projects.MAS_Project_Case_Code'] ?? NULL,
      'type' => $r['case_type_id:name'] ?? NULL,
      'status' => $r['status_id:label'] ?? NULL,
      'subject' => $r['subject'] ?? NULL,
      'clients' => $r['clients'] ?? NULL,
      'reps' => $r['reps'] ?? NULL,
      'start_date' => $r['start_date'] ?? NULL,
      'modified_date' => $modified,
      'days_since_modified' => $modified ? (int) floor((time() - strtotime($modified)) / 86400) : NULL,
    ];
  }

  /** @return int[] case status values in the "Opened" grouping */
  private static function openStatusIds(): array {
    $ids = \Civi\Api4\OptionValue::get(TRUE)
      ->addSelect('value')
      ->addWhere('option_group_id:name', '=', 'case_status')
      ->addWhere('grouping', '=', 'Opened')
      ->addWhere('is_active', '=', TRUE)
      ->execute()->column('value');
    return array_map('intval', $ids) ?: [0];
  }

  private static function resolveCaseId(array $args): int {
    if (!empty($args['case_id'])) {
      return (int) $args['case_id'];
    }
    $code = trim((string) ($args['case_code'] ?? ''));
    if ($code === '') {
      throw new ToolException('Give case_id or case_code.');
    }
    $ids = \Civi\Api4\CiviCase::get(TRUE)
      ->addSelect('id')
      ->addWhere('is_deleted', '=', FALSE)
      ->addClause('OR', ['Cases_SR_Projects_.MAS_SR_Case_Code', '=', $code], ['Projects.MAS_Project_Case_Code', '=', $code])
      ->setLimit(2)
      ->execute()->column('id');
    if (count($ids) !== 1) {
      throw new ToolException(count($ids) ? "Case code $code matches more than one case; use case_id." : "No case with code $code that you can read.");
    }
    return (int) $ids[0];
  }

  private static function likeEscape(string $s): string {
    return addcslashes($s, '%_\\');
  }

  private static function rawRun(string $search, string $display, int $limit, string $return = 'page:1'): \Civi\Api4\Generic\Result {
    return \Civi\Api4\SearchDisplay::run(TRUE)
      ->setSavedSearch($search)
      ->setDisplay($display)
      ->setReturn($return)
      ->setLimit($limit)
      ->execute();
  }

  /**
   * Run a managed display and return its rows keyed by the display's column
   * labels, plus the true total.
   *
   * The total: APIv4 sets rowCount only when the page comes back short of the
   * display's limit, and leaves it NULL on a full page — which read as 0 here
   * until 2026-09-30 (found in T28; latent, no queue had filled a page). A full
   * page takes its total from a `row_count` run instead.
   */
  private static function runDisplay(string $search, string $display, int $limit): array {
    $labels = [];
    $settings = \Civi\Api4\SearchDisplay::get(TRUE)
      ->addSelect('settings')
      ->addWhere('name', '=', $display)
      ->addWhere('saved_search_id.name', '=', $search)
      ->execute()->first()['settings'] ?? [];
    foreach ($settings['columns'] ?? [] as $col) {
      if (!empty($col['key'])) {
        $labels[$col['key']] = $col['label'] ?? $col['key'];
      }
    }

    // The display's own pager decides what a page is; enforce our cap here.
    $page = self::rawRun($search, $display, $limit)->getArrayCopy();
    $rows = [];
    foreach (array_slice($page, 0, $limit) as $row) {
      $out = ['id' => $row['data']['id'] ?? $row['key'] ?? NULL];
      foreach ($labels as $key => $label) {
        $v = $row['data'][$key] ?? NULL;
        $out[$label] = is_string($v) ? trim(html_entity_decode(strip_tags($v))) : $v;
      }
      $rows[] = CoreTools::trimRow($out);
    }
    // Full = it reached the display's limit, or the requested one (a pager with expose_limit
    // honours that instead). Only a short page is known to be the end.
    $pageLimit = (int) ($settings['limit'] ?? 0);
    $full = count($page) >= $limit || ($pageLimit > 0 && count($page) >= $pageLimit);
    $total = $full ? (int) self::rawRun($search, $display, 1, 'row_count')->rowCount : count($page);
    return ['total' => $total, 'returned' => count($rows), 'truncated' => $total > count($rows), 'rows' => $rows];
  }

}
