<?php

namespace Civi\Mascode\Mcp\Vc;

use Civi\Mcp\Config;
use Civi\Mcp\Protocol\McpServer;
use Civi\Mcp\Protocol\ToolRefused;
use Civi\Mcp\Scoped\ScopedQuery;
use Civi\Mcp\Scoped\ScopePolicy;
use Civi\Mcp\Tools\Ability;
use Civi\Mcp\Tools\PublishedDisplay;
use Civi\Mcp\Tools\ToolContext;

/**
 * The volunteer consultant (VC) tools (VC access spec § VC tools; ticket T6): `vc_describe`,
 * `vc_query` and `vc_directory`. Pure — VcToolsSubscriber supplies the site's policy, executor,
 * field metadata, directory and active-VC check — so the wiring is unit-tested without CiviCRM.
 *
 * Audience: every tool requires `access MCP` and is withheld from anyone holding a staff permission
 * (the $staff list, MasTools::STAFF), so staff and VCs never share a tool (spec Goal 1). Each call
 * first checks that the caller is an ELIGIBLE VC — VC_Status Active, or Test for MAS's own test
 * accounts (Brian, 2026-09-30) — the directory's caller rule (T28 review M1), applied to all three, because `access MCP` goes to the whole Subscriber role (D4) and that role includes withdrawn
 * VCs and non-VCs, who would otherwise read the pool cases and their past cases (DECISIONS D30).
 *
 * The boundaries are elsewhere and reviewed there: `vc_query` and `vc_describe` are ScopedQuery under
 * VcScopePolicy (D1, the one checkPermissions FALSE path), `vc_directory` is mascode's published
 * display (D27). A scope that cannot be resolved (ScopeRefused) returns its one fixed message; the
 * cause goes to the server log and the call's audit row (ToolRefused::reason, T7), never the caller.
 * Every call notes `audience: vc` on its audit row, and the scope-set counts once a scope resolved.
 */
final class VcTools {

  public const NAMES = ['vc_describe', 'vc_directory', 'vc_query'];

  /** The `audience` note on every VC tool's audit row (VC access spec § McpCallLog). */
  public const AUDIENCE = 'vc';

  /**
   * D30: the VC_Status values (option names) that may use the VC tools. `Test` marks MAS's own test
   * accounts; the directory still lists Active VCs only, so no VC sees a test account there.
   */
  public const ELIGIBLE_STATUSES = ['Active', 'Test'];

  /** What vc_describe says about each entity, beyond its field list. */
  public const ENTITY_NOTES = [
    'Case' => 'Your own cases, the cases waiting for a VC (Sent for Assignment), and every case of the organisations those cases are for. Client feedback fields come back only where the client agreed to share them with the VC; elsewhere they are null.',
    'CaseContact' => 'Which contacts are the clients of which cases (case_id, contact_id). Query it to find a case\'s client, then query Contact with that id.',
    'Contact' => 'The organisations of your cases, their current employees, you, and the individual clients of your own cases. Anyone else named on those cases (staff, other VCs, some clients) comes back as id and display_name only; look up VCs with vc_directory.',
    'Email' => 'Email addresses of the contacts you can read in full (see Contact). Never other VCs\' or staff addresses.',
    'Phone' => 'Phone numbers of the contacts you can read in full (see Contact). Never other VCs\' or staff numbers.',
    'Address' => 'Postal addresses of the contacts you can read in full (see Contact). Never other VCs\' or staff addresses.',
    'Relationship' => 'Case roles on your cases (such as who coordinates a case), and relationships between contacts you can read in full.',
  ];

  private ?ScopePolicy $built = NULL;

  /**
   * @param \Closure $policy fn(): ScopePolicy — built once, on first use (VcScopePolicy::forSite())
   * @param \Closure $execute ScopedQuery's executor (Api4Executor)
   * @param \Closure $metadata fn(string $entity): array<string, array> getFields rows keyed by name,
   *   for vc_describe's titles and option lists
   * @param \Closure $isEligibleVc fn(int $contactId): bool — D30
   * @param \Closure $log fn(string $reason): void — why a call was refused, for the server log
   */
  public function __construct(
    private readonly \Closure $policy,
    private readonly \Closure $execute,
    private readonly \Closure $metadata,
    private readonly \Closure $isEligibleVc,
    private readonly PublishedDisplay $directory,
    private readonly \Closure $log,
  ) {}

  /**
   * @param string[] $staff permissions any one of which withholds every VC tool (MasTools::STAFF)
   * @return Ability[]
   */
  public function abilities(array $staff): array {
    if (!$staff) {
      // With no exclusion the VC tools would sit beside the staff tools, for staff too.
      throw new \LogicException('The VC tools need the staff permissions they are withheld from');
    }
    $entities = VcScopePolicy::entityNames();
    $list = implode(', ', $entities);

    $directory = $this->directory->ability(
      'vc_directory',
      'MAS volunteer consultant directory',
      'List the active MAS volunteer consultants (VCs): name, areas of expertise, and email where the VC chose to share it. '
      . 'Filter by part of a name, an area of expertise, or contact IDs — use contact_ids to put names to the contact IDs vc_query returns (a case coordinator, say). No phone numbers or addresses.',
      Config::PERMISSION,
      $staff,
    );

    return [
      new Ability(
        'vc_describe',
        'Describe what you can read',
        "List the entities vc_query can read ($list), or (with `entity`) the fields you can ask for: name, title, type, whether it can be filtered or sorted, and option values for short option lists. "
        . 'Call this before vc_query to learn the exact field names.',
        [
          'type' => 'object',
          'properties' => [
            'entity' => ['type' => 'string', 'enum' => $entities, 'description' => 'Entity to describe. Omit to list entities.'],
          ],
          'additionalProperties' => FALSE,
        ],
        TRUE,
        Config::PERMISSION,
        fn(array $args, ToolContext $ctx): array => $this->describe($args, $ctx),
        $staff,
      ),
      new Ability(
        'vc_query',
        'Read your MAS cases and clients',
        "Read records of one entity ($list) from the cases and organisations you work with. `select` is required: field names exactly as vc_describe lists them. "
        . 'Joins are not available (no "contact_id.display_name"): query one entity, then another with the IDs it returned — CaseContact then Contact for a case\'s client — and use vc_directory for a VC\'s name. '
        . '`where` is a list of [field, operator, value] clauses, all ANDed; group with ["OR", [clause, …]]. Only ID, number, yes/no and date fields can be filtered or sorted, never text. '
        . 'Records outside your cases are simply absent. Results are capped; `truncated: true` means there are more rows — narrow the query or page with `offset`.',
        [
          'type' => 'object',
          'properties' => [
            'entity' => ['type' => 'string', 'enum' => $entities],
            'select' => [
              'type' => 'array', 'minItems' => 1, 'maxItems' => ScopedQuery::MAX_SELECT,
              'items' => ['type' => 'string', 'maxLength' => 128],
            ],
            'where' => ['type' => 'array', 'maxItems' => ScopedQuery::MAX_WHERE, 'items' => ['type' => 'array']],
            'orderBy' => [
              'type' => 'object',
              'additionalProperties' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
            ],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => ScopedQuery::MAX_OFFSET],
          ],
          'required' => ['entity', 'select'],
          'additionalProperties' => FALSE,
        ],
        TRUE,
        Config::PERMISSION,
        fn(array $args, ToolContext $ctx): array => $this->query($args, $ctx),
        $staff,
      ),
      new Ability(
        $directory->name, $directory->title, $directory->description, $directory->inputSchema, TRUE,
        $directory->permission,
        function (array $args, ToolContext $ctx) use ($directory): array {
          $ctx->audit('audience', self::AUDIENCE);
          $ctx->audit('target', VcDirectory::DISPLAY);
          $this->assertEligibleVc($ctx);
          return ($directory->callback)($args, $ctx);
        },
        $directory->excludedPermissions,
      ),
    ];
  }

  public function describe(array $args, ToolContext $ctx): array {
    return $this->guarded($ctx, function () use ($args, $ctx): array {
      $entity = $args['entity'] ?? NULL;
      $out = $this->engine()->describe(is_string($entity) ? $entity : NULL, $ctx, $this->metadata);
      if (isset($out['entity'])) {
        $out['note'] = self::entityNote($out['entity']);
      }
      return $out;
    });
  }

  public function query(array $args, ToolContext $ctx): array {
    return $this->guarded($ctx, fn(): array => $this->engine()->run($args, $ctx));
  }

  public static function entityNote(string $entity): string {
    if ($entity === 'Activity') {
      return 'Activities on the cases you can read, of these types only: ' . implode(', ', VcScopePolicy::ACTIVITY_FULL) . '. '
        . 'These come back as type and date only: ' . implode(', ', VcScopePolicy::ACTIVITY_TYPE_DATE) . '. '
        . 'Login links and other credentials are removed from the text.';
    }
    return self::ENTITY_NOTES[$entity] ?? '';
  }

  private function engine(): ScopedQuery {
    $this->built ??= ($this->policy)();
    return new ScopedQuery($this->built, $this->execute);
  }

  /**
   * @param \Closure(): array $run
   * @throws ToolRefused when the caller is not an eligible VC or their scope cannot be resolved
   */
  private function guarded(ToolContext $ctx, \Closure $run): array {
    $ctx->audit('audience', self::AUDIENCE);
    $this->assertEligibleVc($ctx);
    try {
      return $run();
    }
    catch (ScopeRefused $e) {
      ($this->log)($e->reason);
      throw new ToolRefused(ScopeRefused::MESSAGE, 'VC scope refused: ' . $e->reason, $e);
    }
    finally {
      // Counts only (never IDs), whether the call succeeded or not.
      if ($this->built instanceof VcScopePolicy && ($sizes = $this->built->resolvedSizes()) !== NULL) {
        $ctx->audit('scope_sizes', $sizes);
      }
    }
  }

  /**
   * D30 (an Active or Test VC), checked in PHP on the caller's own contact row (id, contact_sub_type,
   * MAS_Rep.VC_Status:name, is_deleted selected), never trusted to APIv4 where clauses alone: with
   * permissions on, APIv4 silently drops a clause on a field the caller may not see (PR #17 review L1).
   */
  public static function isEligibleVcRow(?array $row, int $contactId): bool {
    return $row !== NULL
      && $contactId > 0
      && (int) ($row['id'] ?? 0) === $contactId
      && in_array(VcScopeResolver::VC_SUB_TYPE, (array) ($row['contact_sub_type'] ?? []), TRUE)
      && in_array($row['MAS_Rep.VC_Status:name'] ?? NULL, self::ELIGIBLE_STATUSES, TRUE)
      && ($row['is_deleted'] ?? TRUE) === FALSE;
  }

  private function assertEligibleVc(ToolContext $ctx): void {
    $reason = 'caller is not an active or Test VC';
    try {
      $eligible = $ctx->contactId > 0 && ($this->isEligibleVc)($ctx->contactId) === TRUE;
    }
    catch (\Throwable $e) {
      // An error (say, a role that cannot see the MAS_Rep group) counts as "not active", with the
      // same message: the caller must not learn the check's structure (PR #17 review L2).
      $eligible = FALSE;
      // The message goes to the server log only, so an operator can see which prerequisite is
      // missing (PR #17 review round 2, L3).
      // A database error's message can echo query values, so it is withheld, as the endpoint does.
      $message = McpServer::isDatabaseError($e) ? '(database error; message withheld)' : mb_substr($e->getMessage(), 0, 200);
      $reason = 'VC eligibility check failed: ' . get_class($e) . ': ' . $message;
    }
    if (!$eligible) {
      ($this->log)($reason);
      // The same message as a scope refusal: the caller learns nothing about why. The reason goes
      // to the audit row (and the server log) only.
      throw new ToolRefused(ScopeRefused::MESSAGE, $reason);
    }
  }

}
