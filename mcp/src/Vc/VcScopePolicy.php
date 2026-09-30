<?php

namespace Civi\Mascode\Mcp\Vc;

use Civi\Mcp\Scoped\EntityPolicy;
use Civi\Mcp\Scoped\Gate;
use Civi\Mcp\Scoped\ScopePolicy;

/**
 * What a volunteer consultant (VC) may read through `vc_query` (VC access spec § vc_query entity
 * policy; D2, D3, D14, D15, D22, D23, D25; ticket T5). Rows come from the caller's VcScope; fields
 * are the allowlists below, listed by exact name — no wildcard reaches this file. Every returned
 * string passes TextSanitiser::forVc().
 *
 * Fail closed: a listed field (or a listed `:label` / `:name` form) that getFields no longer offers
 * refuses the whole entity (ScopeRefused), so a renamed or deleted custom field never silently
 * changes what is returned, and a data type always comes from getFields, never from this file
 * (T4 review M4). Custom groups are taken from CustomGroup.get, restricted to the groups named here.
 *
 * Activity follows docs/plans/vc-activity-policy.md §1-§2: a closed type list as a scope clause,
 * per-type demotion to "type and date only", and the copied-Email rule.
 */
final class VcScopePolicy implements ScopePolicy {

  /** D22: client feedback is returned only where the RAW share answer is exactly this. */
  public const SHARE_FIELD = 'Project_Close_Client.share_with_vc';
  public const SHARE_YES = 'Yes';

  /** D22, listed by name: ratings as well as comments. share_with_vc and use_in_marketing never. */
  public const CLIENT_FEEDBACK = [
    'Project_Close_Client.satisfaction', 'Project_Close_Client.satisfaction:label',
    'Project_Close_Client.satisfaction_comment',
    'Project_Close_Client.would_use_mas_again', 'Project_Close_Client.would_use_mas_again:label',
    'Project_Close_Client.reuse_comment',
    'Project_Close_Client.would_work_with_vc_again', 'Project_Close_Client.would_work_with_vc_again:label',
    'Project_Close_Client.would_recommend_mas', 'Project_Close_Client.would_recommend_mas:label',
    'Project_Close_Client.benefits_realized',
  ];

  /**
   * entity => [scope set(s), returned fields, custom groups]. Link-type custom fields are left out
   * (the sanitiser would return "[link removed]" and nothing else), as are inactive ones.
   */
  public const ENTITIES = [
    'Case' => [
      'fields' => [
        'id', 'subject', 'case_type_id', 'case_type_id:label', 'case_type_id:name', 'status_id', 'status_id:label', 'status_id:name',
        'start_date', 'end_date', 'created_date', 'modified_date',
        'Cases_SR_Projects_.Practice_Area', 'Cases_SR_Projects_.Practice_Area:label', 'Cases_SR_Projects_.Referral', 'Cases_SR_Projects_.Referral:label',
        'Cases_SR_Projects_.Notes', 'Cases_SR_Projects_.Request_Details', 'Cases_SR_Projects_.MAS_SR_Case_Code', 'Cases_SR_Projects_.Related_Project_Case_Code',
        'Cases_SR_Projects_.Virtual_Work', 'Cases_SR_Projects_.Virtual_Work:label', 'Cases_SR_Projects_.Requested_Start_Date',
        'Cases_SR_Projects_.Flexible_Start_Date', 'Cases_SR_Projects_.Board_Approval', 'Cases_SR_Projects_.Board_Approval:label',
        'Cases_SR_Projects_.Board_Support', 'Cases_SR_Projects_.T_C_Authorized_and_Approved', 'Cases_SR_Projects_.T_C_Authorized_and_Approved:label',
        'Cases_SR_Projects_.Authorized', 'Cases_SR_Projects_.Authorized_Name', 'Cases_SR_Projects_.Authorized_Title', 'Cases_SR_Projects_.Authorized_Date',
        'Projects.Practice_Area', 'Projects.Practice_Area:label', 'Projects.Project_Type', 'Projects.Project_Type:label', 'Projects.Notes',
        'Projects.MAS_Project_Case_Code', 'Projects.Related_SR_Case_Code', 'Projects.Estimated_Completion_Date',
        'Project_Definition.estimated_duration', 'Project_Definition.assistance_provided', 'Project_Definition.project_completion',
        'Project_Definition_Authorization.agreed_with_description', 'Project_Definition_Authorization.expected_benefits',
        // client_signature (the client's typed name) is left out: FieldGuard refuses any "signature".
        'Project_Definition_Authorization.capacity_increase',
        'Project_Definition_Authorization.client_title', 'Project_Definition_Authorization.authorized_certification',
        // D3: any in-scope VC.
        'Project_Close_VC.hours_worked', 'Project_Close_VC.expenses_incurred', 'Project_Close_VC.services_delivered',
      ],
      'groups' => ['Cases_SR_Projects_', 'Projects', 'Project_Definition', 'Project_Definition_Authorization', 'Project_Close_VC', 'Project_Close_Client'],
    ],
    'CaseContact' => [
      'fields' => ['id', 'case_id', 'contact_id'],
      'groups' => [],
    ],
    'Contact' => [
      'fields' => [
        'id', 'contact_type', 'contact_sub_type', 'display_name', 'organization_name', 'first_name', 'last_name', 'job_title', 'employer_id',
        'Organization.Industry', 'Organization.Industry:label', 'Organization.Budget', 'Organization._Employees', 'Organization._Volunteers',
        'Organization.Charity_Status', 'Organization.Charity_Status:label', 'Organization.Charity_Business_', 'Organization.Notes',
        'Organization.Client_Type', 'Organization.Client_Type:label', 'Organization.Date_RCS_Document_Signed',
      ],
      'groups' => ['Organization'],
    ],
    'Email' => [
      'fields' => ['id', 'contact_id', 'email', 'location_type_id', 'location_type_id:label', 'is_primary'],
      'groups' => [],
    ],
    'Phone' => [
      'fields' => ['id', 'contact_id', 'phone', 'phone_ext', 'phone_type_id', 'phone_type_id:label', 'location_type_id', 'location_type_id:label', 'is_primary'],
      'groups' => [],
    ],
    'Address' => [
      'fields' => [
        'id', 'contact_id', 'street_address', 'supplemental_address_1', 'city', 'state_province_id', 'state_province_id:label', 'postal_code',
        'country_id', 'country_id:label', 'location_type_id', 'location_type_id:label', 'is_primary',
      ],
      'groups' => [],
    ],
    'Relationship' => [
      'fields' => [
        'id', 'relationship_type_id', 'relationship_type_id:label', 'relationship_type_id:name', 'contact_id_a', 'contact_id_b', 'case_id',
        'start_date', 'end_date', 'is_active',
      ],
      'groups' => [],
    ],
  ];

  /** vc-activity-policy.md §1: returned with subject and details (after the sanitiser). */
  public const ACTIVITY_FULL = [
    'Open Case', 'Case Status Update', 'Change Case Status', 'Change Case Subject', 'Change Case Type', 'Change Case Start Date',
    'Assign Case Role', 'Remove Case Role', 'Link Cases', 'Follow up', 'Email', 'Request for Consulting Services (RCS)',
    'Project Definition', 'Project Definition - Client Authorization', 'Project Close', 'Project Close - VC Report', 'Monthly Project Check-in',
  ];

  /**
   * §1: returned as type and date only. Sent Automated Email belongs to the "type, date and
   * subject" row, but its subject is returned only for a template on SAE_SUBJECT_TEMPLATES, and
   * that list starts empty (§2), so today it is type and date only. Adding the first entry needs
   * the §2 subject rule (one well-formed meta block, read from the RAW details, not a draft).
   */
  public const ACTIVITY_TYPE_DATE = ['Sent Automated Email', 'Project Close - Client Feedback', 'Merge Case', 'Reassigned Case'];

  /** §2: templates whose Sent Automated Email subject may be returned. Empty: no subjects. */
  public const SAE_SUBJECT_TEMPLATES = [];

  /** §1: the only Activity custom group returned; any other group makes a copy of its type demoted (§2). */
  public const ACTIVITY_GROUPS = ['Monthly_Project_Checkin'];

  public const ACTIVITY_FIELDS = [
    'id', 'case_id', 'activity_type_id', 'activity_type_id:label', 'activity_type_id:name', 'subject', 'activity_date_time',
    'status_id', 'status_id:label', 'details',
    'Monthly_Project_Checkin.is_complete', 'Monthly_Project_Checkin.vc_will_ask', 'Monthly_Project_Checkin.digest_round',
  ];

  /** What a "type and date only" row loses. */
  public const ACTIVITY_DEMOTABLE = [
    'subject', 'details', 'Monthly_Project_Checkin.is_complete', 'Monthly_Project_Checkin.vc_will_ask', 'Monthly_Project_Checkin.digest_round',
  ];

  /** D15: a contact in S_named is returned as these fields only. */
  public const NAMED_FIELDS = ['id', 'display_name'];

  /** @var array<string, array<string, array{data_type: string, suffixes: string[]}>> */
  private array $fieldCache = [];

  /** The last scope this policy resolved, for the audit row's scope sizes (resolvedSizes()). */
  private ?VcScope $resolved = NULL;

  /**
   * @param \Closure $scope fn(int $contactId): VcScope — VcScopeResolver::resolve()
   * @param \Closure $getFields fn(string $entity): array<string, array{data_type: string, suffixes: string[]}>
   * @param \Closure $customGroups fn(string $entity): string[] — names of active groups extending it
   * @param \Closure|null $activities fn(int[] $ids): array<int, array{type: string, source_record_id: ?int, case_ids: int[]}>
   *   — the given activities (not trashed), for the copied-Email rule. NULL: every copy is demoted.
   * @param \Closure|null $foreignTypes fn(): ?string[] — activity type names extended by any custom
   *   Activity group, active or not, except the trusted ones (trustedActivityGroups(): an
   *   ACTIVITY_GROUPS group all of whose fields are listed for it in ACTIVITY_FIELDS); NULL means a
   *   foreign group extends every type.
   */
  public function __construct(
    private readonly \Closure $scope,
    private readonly \Closure $getFields,
    private readonly \Closure $customGroups,
    private readonly ?\Closure $activities = NULL,
    private readonly ?\Closure $foreignTypes = NULL,
  ) {}

  /** Entity => the `extends` values of the custom groups that may attach to it. */
  private const EXTENDS = [
    'Case' => ['Case'],
    'Contact' => ['Contact', 'Organization', 'Individual', 'Household'],
    'Activity' => ['Activity'],
  ];

  /** The policy on a real site, for one request (the resolver memoises per contact). */
  public static function forSite(): self {
    $resolver = new VcScopeResolver(new Api4VcScopeSource());
    return new self(
      fn(int $contactId): VcScope => $resolver->resolve($contactId),
      function (string $entity): array {
        $out = [];
        foreach (civicrm_api4($entity, 'getFields', ['checkPermissions' => FALSE, 'loadOptions' => FALSE, 'select' => ['name', 'data_type', 'suffixes']]) as $f) {
          $out[$f['name']] = ['data_type' => (string) $f['data_type'], 'suffixes' => array_values((array) ($f['suffixes'] ?? []))];
        }
        return $out;
      },
      fn(string $entity): array => isset(self::EXTENDS[$entity])
        ? array_column((array) civicrm_api4('CustomGroup', 'get', [
          'checkPermissions' => FALSE,
          'select' => ['name'],
          'where' => [['extends', 'IN', self::EXTENDS[$entity]], ['is_active', '=', TRUE]],
        ]), 'name')
        : [],
      function (array $ids): array {
        $out = [];
        foreach (civicrm_api4('Activity', 'get', [
          'checkPermissions' => FALSE,
          'select' => ['id', 'activity_type_id:name', 'source_record_id', 'case_id'],
          'where' => [['id', 'IN', $ids], ['is_deleted', '=', FALSE]],
        ]) as $a) {
          $out[(int) $a['id']] = [
            'type' => (string) $a['activity_type_id:name'],
            'source_record_id' => $a['source_record_id'] ? (int) $a['source_record_id'] : NULL,
            'case_ids' => array_map('intval', (array) ($a['case_id'] ?? [])),
          ];
        }
        return $out;
      },
      function (): ?array {
        $fields = [];
        foreach (civicrm_api4('CustomField', 'get', [
          'checkPermissions' => FALSE,
          'select' => ['custom_group_id:name', 'name'],
          'where' => [['custom_group_id:name', 'IN', self::ACTIVITY_GROUPS]],
        ]) as $f) {
          $fields[] = ['group' => (string) $f['custom_group_id:name'], 'name' => (string) $f['name']];
        }
        $trusted = self::trustedActivityGroups($fields);
        $where = [['extends', '=', 'Activity']];
        if ($trusted) {
          $where[] = ['name', 'NOT IN', $trusted];
        }
        // Active or not: a copy renders inactive groups' values too (§2).
        $typeIds = self::foreignTypeIds(civicrm_api4('CustomGroup', 'get', [
          'checkPermissions' => FALSE,
          'select' => ['name', 'extends_entity_column_value'],
          'where' => $where,
        ]));
        if ($typeIds === NULL || !$typeIds) {
          return $typeIds;
        }
        return array_column((array) civicrm_api4('OptionValue', 'get', [
          'checkPermissions' => FALSE,
          'select' => ['name'],
          'where' => [['option_group_id:name', '=', 'activity_type'], ['value', 'IN', array_values(array_unique($typeIds))]],
        ]), 'name');
      },
    );
  }

  /**
   * ACTIVITY_FIELDS' custom fields by group, so a field name listed for one group never covers a
   * field of the same name in another (PR #13 review, L).
   *
   * @return array<string, string[]>
   */
  public static function listedActivityFields(): array {
    $out = array_fill_keys(self::ACTIVITY_GROUPS, []);
    foreach (self::ACTIVITY_FIELDS as $f) {
      if (str_contains($f, '.')) {
        [$group, $name] = explode('.', $f, 2);
        $out[$group][] = $name;
      }
    }
    return $out;
  }

  /**
   * The allowlisted Activity groups that stay trusted by the copied-Email rule: those holding no
   * field outside ACTIVITY_FIELDS. A group that gains an unlisted field counts as foreign, because a
   * copy renders every field of its source's groups (PR #12 review M1).
   *
   * @param array<array{group: string, name: string}> $fields every field of ACTIVITY_GROUPS, active or not
   * @return string[]
   */
  public static function trustedActivityGroups(array $fields): array {
    $listed = self::listedActivityFields();
    $untrusted = [];
    foreach ($fields as $f) {
      if (!in_array($f['name'], $listed[$f['group']] ?? [], TRUE)) {
        $untrusted[$f['group']] = TRUE;
      }
    }
    return array_values(array_diff(self::ACTIVITY_GROUPS, array_keys($untrusted)));
  }

  /**
   * Activity type ids the foreign groups extend; NULL when one of them extends every type.
   *
   * @param iterable<array{extends_entity_column_value?: mixed}> $groups
   * @return int[]|null
   */
  public static function foreignTypeIds(iterable $groups): ?array {
    $typeIds = [];
    foreach ($groups as $g) {
      $values = array_filter((array) ($g['extends_entity_column_value'] ?? []));
      if (!$values) {
        return NULL;
      }
      array_push($typeIds, ...array_map('intval', $values));
    }
    return array_values(array_unique($typeIds));
  }

  public function entities(): array {
    return self::entityNames();
  }

  /** The entities `vc_query` offers, without building a policy (the tool schema's enum). */
  public static function entityNames(): array {
    return array_merge(array_keys(self::ENTITIES), ['Activity']);
  }

  public function entityPolicy(string $entity, int $contactId): ?EntityPolicy {
    if ($entity === 'Activity') {
      return $this->activityPolicy($this->scopeFor($contactId));
    }
    if (!isset(self::ENTITIES[$entity])) {
      return NULL;
    }
    $s = $this->scopeFor($contactId);
    $groups = array_values(array_intersect(self::ENTITIES[$entity]['groups'], ($this->customGroups)($entity)));
    if (count($groups) !== count(self::ENTITIES[$entity]['groups'])) {
      throw new ScopeRefused("custom group missing for $entity");
    }
    $fields = $this->typed($entity, self::ENTITIES[$entity]['fields']);
    $filter = \Closure::fromCallable([TextSanitiser::class, 'forVc']);

    return match ($entity) {
      'Case' => new EntityPolicy(
        scope: [['id', 'IN', $s->cases]],
        fields: $fields,
        conditional: array_fill_keys(self::CLIENT_FEEDBACK, new Gate(self::SHARE_FIELD, self::SHARE_YES))
          + $this->assertListed($entity, self::CLIENT_FEEDBACK),
        stringFilter: $filter,
        customGroups: $groups,
      ),
      'CaseContact' => new EntityPolicy([['case_id', 'IN', $s->cases]], $fields, stringFilter: $filter),
      'Contact' => new EntityPolicy(
        scope: [['id', 'IN', array_values(array_unique(array_merge($s->contacts, $s->named)))]],
        fields: $fields,
        internal: ['id'],
        demotable: array_values(array_diff(array_keys($fields), self::NAMED_FIELDS)),
        demote: self::nameOnly($s->named, array_values(array_diff(array_keys($fields), self::NAMED_FIELDS))),
        stringFilter: $filter,
        customGroups: $groups,
      ),
      // D14: client contacts' email, phone and address; never anyone outside S_contacts.
      'Email', 'Phone', 'Address' => new EntityPolicy([['contact_id', 'IN', $s->contacts]], $fields, stringFilter: $filter),
      'Relationship' => new EntityPolicy(
        scope: [['OR', [
          ['case_id', 'IN', $s->cases],
          // Not a case role: a case's relationships come only through the case branch, so a role on
          // a trashed or out-of-scope case between two visible contacts stays hidden (D9, D23).
          ['AND', [['contact_id_a', 'IN', $s->contacts], ['contact_id_b', 'IN', $s->contacts], ['case_id', 'IS NULL']]],
        ]]],
        fields: $fields,
        stringFilter: $filter,
      ),
    };
  }

  private function scopeFor(int $contactId): VcScope {
    return $this->resolved = ($this->scope)($contactId);
  }

  /**
   * How many IDs each scope set held, if this policy resolved one — counts only, never the IDs, for
   * the audit log (VC access spec § McpCallLog `scope_sizes`).
   *
   * @return array<string, int>|null
   */
  public function resolvedSizes(): ?array {
    $s = $this->resolved;
    return $s === NULL ? NULL : [
      'own_cases' => count($s->ownCases),
      'pool_cases' => count($s->poolCases),
      'orgs' => count($s->orgs),
      'cases' => count($s->cases),
      'employees' => count($s->employees),
      'contacts' => count($s->contacts),
      'named' => count($s->named),
    ];
  }

  /** docs/plans/vc-activity-policy.md §1-§2. */
  private function activityPolicy(VcScope $s): EntityPolicy {
    $groups = array_values(array_intersect(self::ACTIVITY_GROUPS, ($this->customGroups)('Activity')));
    if (count($groups) !== count(self::ACTIVITY_GROUPS)) {
      throw new ScopeRefused('custom group missing for Activity');
    }
    return new EntityPolicy(
      // The type list is a clause of the query itself, ANDed at the top level (§1).
      scope: [
        ['case_id', 'IN', $s->cases],
        ['activity_type_id:name', 'IN', array_merge(self::ACTIVITY_FULL, self::ACTIVITY_TYPE_DATE)],
        ['is_deleted', '=', FALSE],
        ['is_current_revision', '=', TRUE],
      ],
      fields: $this->typed('Activity', self::ACTIVITY_FIELDS),
      internal: ['activity_type_id:name', 'source_record_id'],
      demotable: self::ACTIVITY_DEMOTABLE,
      demote: fn(array $rows): array => $this->demoteActivities($rows, $s),
      stringFilter: \Closure::fromCallable([TextSanitiser::class, 'forVc']),
      customGroups: $groups,
    );
  }

  /**
   * Per row, the fields to null: a type-and-date type loses them all; so does a copied Email
   * whose source is missing, trashed, itself a copy, on a case outside scope, of a type not
   * returned with details, or of a type a foreign custom group extends (§2). Anything unexpected
   * demotes (fail closed).
   *
   * @param list<array> $rows
   * @return list<string[]>
   */
  private function demoteActivities(array $rows, VcScope $s): array {
    $sourceIds = array_values(array_unique(array_filter(array_map(fn($r) => (int) ($r['source_record_id'] ?? 0), $rows))));
    $sources = ($sourceIds && $this->activities) ? ($this->activities)($sourceIds) : [];
    $foreign = $this->foreignTypes ? ($this->foreignTypes)() : NULL;
    $cases = array_flip($s->cases);
    $out = [];
    foreach ($rows as $row) {
      $type = $row['activity_type_id:name'] ?? NULL;
      $keep = in_array($type, self::ACTIVITY_FULL, TRUE);
      if ($keep && $type === 'Email' && !empty($row['source_record_id'])) {
        $src = $sources[(int) $row['source_record_id']] ?? NULL;
        $keep = $src !== NULL
          && empty($src['source_record_id'])
          && in_array($src['type'], self::ACTIVITY_FULL, TRUE)
          && $foreign !== NULL && !in_array($src['type'], $foreign, TRUE)
          && array_intersect_key(array_flip($src['case_ids']), $cases) !== [];
      }
      $out[] = $keep ? [] : self::ACTIVITY_DEMOTABLE;
    }
    return $out;
  }

  /**
   * D15: a row whose id is in S_named keeps only its name. The rule sees the raw row, whose `id`
   * the engine always fetches (it is internal); a row without one is demoted too (fail closed).
   *
   * @param int[] $named
   * @param string[] $nullable
   */
  private static function nameOnly(array $named, array $nullable): \Closure {
    $named = array_flip($named);
    return fn(array $rows): array => array_map(
      fn(array $row): array => (!isset($row['id']) || isset($named[(int) $row['id']])) ? $nullable : [],
      $rows,
    );
  }

  /**
   * @param string[] $names
   * @return array<string, string> name => data_type, from getFields
   * @throws ScopeRefused when a listed name or suffix is not offered
   */
  private function typed(string $entity, array $names): array {
    $out = [];
    foreach ($names as $name) {
      $out[$name] = $this->typeOf($entity, $name);
    }
    return $out;
  }

  /**
   * The conditional fields get no entry in $fields (EntityPolicy refuses an unconditional suffix
   * sibling), but they must exist all the same.
   *
   * @param string[] $names
   * @return array<string, Gate> always empty; called for its check
   */
  private function assertListed(string $entity, array $names): array {
    foreach ($names as $name) {
      $this->typeOf($entity, $name);
    }
    $this->typeOf($entity, self::SHARE_FIELD);
    return [];
  }

  private function typeOf(string $entity, string $name): string {
    $fields = $this->fieldCache[$entity] ??= ($this->getFields)($entity);
    [$base, $suffix] = array_pad(explode(':', $name, 2), 2, NULL);
    $field = $fields[$base] ?? NULL;
    if ($field === NULL || ($suffix !== NULL && !in_array($suffix, $field['suffixes'], TRUE))) {
      throw new ScopeRefused("field not offered: $entity.$name");
    }
    // A suffixed form is a label or machine name: text.
    return $suffix === NULL ? $field['data_type'] : 'String';
  }

}
