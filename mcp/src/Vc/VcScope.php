<?php

namespace Civi\Mascode\Mcp\Vc;

/**
 * One volunteer consultant's scope sets for one request (VC access spec § Data Model, D19, D23,
 * D25). Every list is of positive integer IDs, sorted and unique. Built only by VcScopeResolver.
 */
final class VcScope {

  /**
   * @param int $contactId the authenticated VC these sets were resolved for
   * @param int[] $ownCases S_own_cases — the VC coordinates (active row, any end date, D5)
   * @param int[] $poolCases S_pool_cases — Sent for Assignment
   * @param int[] $orgs S_orgs — organisation clients of own and pool cases; the domain org only via own (D25)
   * @param int[] $cases S_cases — organisation-client cases of $orgs, plus own and pool (D2, D23)
   * @param int[] $employees S_employees — active Employee of an org in $orgs; never a VC, never an employee of a domain org (D25)
   * @param int[] $contacts S_contacts — $orgs, $employees, the VC, and the eligible individual clients of own cases (D23)
   * @param int[] $named S_named — contacts on in-scope cases (clients, case relationships) outside $contacts: display name only (D15)
   */
  public function __construct(
    public readonly int $contactId,
    public readonly array $ownCases,
    public readonly array $poolCases,
    public readonly array $orgs,
    public readonly array $cases,
    public readonly array $employees,
    public readonly array $contacts,
    public readonly array $named,
  ) {}

}
