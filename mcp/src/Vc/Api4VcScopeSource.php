<?php

namespace Civi\Mascode\Mcp\Vc;

use Civi\Api4\CaseContact;
use Civi\Api4\Contact;
use Civi\Api4\Domain;
use Civi\Api4\Relationship;
use Civi\Api4\SavedSearch;

/**
 * VcScopeSource on a real site. Every read is checkPermissions FALSE (VC access spec D1: scope
 * resolution runs fixed, policy-named reads) and none takes a value from tool arguments.
 */
final class Api4VcScopeSource implements VcScopeSource {

  /** mascode's declaration file, relative to its extension directory. */
  public const DECLARATION_FILE = 'Civi/Mascode/Managed/SavedSearch_MAS_VC_Scope_Sets.mgd.php';

  private const COORDINATOR = 'Case Coordinator is';
  private const EMPLOYEE = 'Employee of';

  public function declarations(): array {
    try {
      $path = \CRM_Extension_System::singleton()->getMapper()->keyToBasePath('mascode');
    }
    catch (\CRM_Extension_Exception_MissingException) {
      return [];
    }
    $file = $path . '/' . self::DECLARATION_FILE;
    if (!is_file($file)) {
      return [];
    }
    $out = [];
    foreach ((array) (include $file) as $decl) {
      $values = $decl['params']['values'] ?? NULL;
      if (($decl['entity'] ?? NULL) === 'SavedSearch' && is_array($values) && isset($values['name'], $values['api_entity'], $values['api_params'])) {
        $out[$values['name']] = ['api_entity' => $values['api_entity'], 'api_params' => $values['api_params']];
      }
    }
    return $out;
  }

  public function storedSearches(array $names): array {
    if (!$names) {
      return [];
    }
    $out = [];
    foreach (SavedSearch::get(FALSE)->addSelect('name', 'api_entity', 'api_params')->addWhere('name', 'IN', $names)->execute() as $row) {
      $out[$row['name']] = ['api_entity' => (string) $row['api_entity'], 'api_params' => (array) $row['api_params']];
    }
    return $out;
  }

  /** @param array<string, mixed> $params */
  public function runSearch(string $entity, array $params): array {
    if (($params['checkPermissions'] ?? NULL) !== FALSE) {
      throw new \LogicException('Scope searches run with checkPermissions FALSE only');
    }
    return self::column(civicrm_api4($entity, 'get', $params), 'id');
  }

  public function coordinatedCaseIds(int $contactId): array {
    return self::column(Relationship::get(FALSE)->addSelect('case_id')
      ->addWhere('contact_id_a', '=', $contactId)
      ->addWhere('relationship_type_id.name_a_b', '=', self::COORDINATOR)
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('case_id', 'IS NOT NULL')
      ->addWhere('case_id.is_deleted', '=', FALSE)->execute(), 'case_id');
  }

  public function isLiveContact(int $contactId): bool {
    return Contact::get(FALSE)->addSelect('id')->addWhere('id', '=', $contactId)
      ->addWhere('is_deleted', '=', FALSE)->execute()->count() === 1;
  }

  public function domainOrgIds(): array {
    return self::column(Domain::get(FALSE)->addSelect('contact_id')->execute(), 'contact_id');
  }

  public function casesOfClients(array $orgIds): array {
    if (!$orgIds) {
      return [];
    }
    return self::column(CaseContact::get(FALSE)->addSelect('case_id')
      ->addWhere('contact_id', 'IN', $orgIds)
      ->addWhere('case_id.is_deleted', '=', FALSE)->execute(), 'case_id');
  }

  public function caseClients(array $caseIds): array {
    if (!$caseIds) {
      return [];
    }
    $out = [];
    foreach (CaseContact::get(FALSE)
      ->addSelect('case_id', 'contact_id', 'contact_id.contact_type', 'contact_id.contact_sub_type')
      ->addWhere('case_id', 'IN', $caseIds)
      ->addWhere('contact_id.is_deleted', '=', FALSE)->execute() as $row) {
      $out[] = [
        'case_id' => (int) $row['case_id'],
        'contact_id' => (int) $row['contact_id'],
        'contact_type' => (string) $row['contact_id.contact_type'],
        'sub_types' => array_values((array) ($row['contact_id.contact_sub_type'] ?? [])),
      ];
    }
    return $out;
  }

  public function activeEmployeeIds(array $orgIds): array {
    if (!$orgIds) {
      return [];
    }
    return self::column(Relationship::get(FALSE)->addSelect('contact_id_a')
      ->addWhere('contact_id_b', 'IN', $orgIds)
      ->addWhere('relationship_type_id.name_a_b', '=', self::EMPLOYEE)
      ->addWhere('is_active', '=', TRUE)->execute(), 'contact_id_a');
  }

  public function liveIndividuals(array $contactIds): array {
    if (!$contactIds) {
      return [];
    }
    $out = [];
    foreach (Contact::get(FALSE)->addSelect('id', 'contact_sub_type')
      ->addWhere('id', 'IN', array_values($contactIds))
      ->addWhere('contact_type', '=', 'Individual')
      ->addWhere('is_deleted', '=', FALSE)->execute() as $row) {
      $out[(int) $row['id']] = array_values((array) ($row['contact_sub_type'] ?? []));
    }
    return $out;
  }

  public function caseRelationshipContacts(array $caseIds): array {
    if (!$caseIds) {
      return [];
    }
    $ids = [];
    foreach (Relationship::get(FALSE)->addSelect('contact_id_a', 'contact_id_b')
      ->addWhere('case_id', 'IN', $caseIds)->execute() as $row) {
      $ids[] = (int) $row['contact_id_a'];
      $ids[] = (int) $row['contact_id_b'];
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) {
      return [];
    }
    return self::column(Contact::get(FALSE)->addSelect('id')->addWhere('id', 'IN', $ids)
      ->addWhere('is_deleted', '=', FALSE)->execute(), 'id');
  }

  /** @return int[] */
  private static function column(iterable $rows, string $key): array {
    $out = [];
    foreach ($rows as $row) {
      $out[] = (int) $row[$key];
    }
    return array_values(array_unique($out));
  }

}
