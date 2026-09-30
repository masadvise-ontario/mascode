<?php

namespace Civi\Mascode\Mcp\Vc;

/**
 * The reads VcScopeResolver needs, so the set rules are unit-tested without CiviCRM. The live
 * implementation (Api4VcScopeSource) runs every read with checkPermissions FALSE: the scope is
 * being computed, so the caller's own permissions cannot bound it (VC access spec D1).
 *
 * Every method returns live (not trashed) records only, unless it says otherwise.
 */
interface VcScopeSource {

  /**
   * mascode's declarations of the scope searches (SavedSearch_MAS_VC_Scope_Sets.mgd.php).
   *
   * @return array<string, array{api_entity: string, api_params: array}> keyed by search name; empty when mascode is missing
   */
  public function declarations(): array;

  /**
   * The stored saved searches of these names, as APIv4 returns them.
   *
   * @param string[] $names
   * @return array<string, array{api_entity: string, api_params: array}> keyed by name; a missing search is absent
   */
  public function storedSearches(array $names): array;

  /**
   * Run APIv4 get with exactly these params (checkPermissions FALSE).
   *
   * @return int[] the `id` column
   */
  public function runSearch(string $entity, array $params): array;

  /**
   * Own cases read from Relationship, not RelationshipCache: live cases with an active
   * "Case Coordinator is" relationship whose contact_id_a is $contactId, any end date (D5).
   *
   * @return int[]
   */
  public function coordinatedCaseIds(int $contactId): array;

  /** Whether the contact exists and is not trashed. */
  public function isLiveContact(int $contactId): bool;

  /** @return int[] contact_id of every Domain row (the site's own organisation or organisations) */
  public function domainOrgIds(): array;

  /**
   * @param int[] $orgIds
   * @return int[] live cases with a client in $orgIds
   */
  public function casesOfClients(array $orgIds): array;

  /**
   * @param int[] $caseIds
   * @return list<array{case_id: int, contact_id: int, contact_type: string, sub_types: string[]}> the live clients of those cases
   */
  public function caseClients(array $caseIds): array;

  /**
   * Read from Relationship, not RelationshipCache, so a stale cache row cannot widen the set.
   *
   * @param int[] $orgIds
   * @return int[] contact_id_a of every active "Employee of" relationship whose contact_id_b is in $orgIds (contacts not filtered)
   */
  public function activeEmployeeIds(array $orgIds): array;

  /**
   * @param int[] $contactIds
   * @return array<int, string[]> live Individuals among $contactIds => their contact sub-types
   */
  public function liveIndividuals(array $contactIds): array;

  /**
   * @param int[] $caseIds
   * @return int[] live contacts on either side of any relationship filed on those cases
   */
  public function caseRelationshipContacts(array $caseIds): array;

}
