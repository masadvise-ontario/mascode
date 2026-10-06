<?php

declare(strict_types=1);

namespace Civi\Mascode\Service;

/**
 * Donation → Project / VC link (donations tickets DN-2 and DN-5; spec BrianPKM
 * 3-Resources/mas-donation-process.md §3b.1).
 *
 * Fills Contribution `Donation_Link.Linked_VC` from the Linked Project's Case
 * Coordinator when there is exactly one candidate (coordinatorFor()),
 * fill-empty only: a VC set by hand is never overwritten, and a
 * VC once stored stays put when the case role later changes, so a past
 * donation keeps its credit.
 *
 * Writes go through CRM_Core_BAO_CustomValueTable::setValues(), NOT
 * Contribution::update. A contribution save can rewrite financial records
 * (line items, financial transactions), and nothing here may touch money.
 * setValues() fires hook_civicrm_custom only, so it does not re-enter
 * DonationSubscriber either.
 *
 * Deliberately does NOT file core's "Contribution" activity on the case. The VC
 * Portal case screen (SavedSearch Case_Details_VC_Activities) lists every case
 * activity with its subject, and core puts the AMOUNT in that subject. The VC
 * learns the amount from the notification EMAIL only (R2, 2026-10-06), never
 * from anything filed on the case.
 */
final class DonationLinker
{
    public const FIELD_PROJECT = 'Donation_Link.Linked_Project';
    public const FIELD_VC = 'Donation_Link.Linked_VC';

    /** On `Case Coordinator is` the VC is contact_id_a; see VcDigestRunner::COORDINATOR_RELATION. */
    public const COORDINATOR_RELATION = VcDigestRunner::COORDINATOR_RELATION;

    /** Financial types that are donations. "Donation" is the legacy pre-DN-1 type. */
    public const DONATION_TYPES = ['Client Donation', 'Private Donation', 'Donation'];

    /**
     * Fill Linked_VC for one contribution if it has a project and no VC.
     *
     * @return int|null the VC contact id now credited, or NULL
     */
    public static function fillVc(int $contributionId): ?int
    {
        $row = \Civi\Api4\Contribution::get(false)
            ->addSelect('id', self::FIELD_PROJECT, self::FIELD_VC)
            ->addWhere('id', '=', $contributionId)
            ->execute()
            ->first();
        if (!$row) {
            return null;
        }
        if (!empty($row[self::FIELD_VC])) {
            return (int) $row[self::FIELD_VC];
        }
        $projectId = (int) ($row[self::FIELD_PROJECT] ?? 0);
        if (!$projectId) {
            return null;
        }
        $vcId = self::coordinatorFor($projectId);
        if ($vcId) {
            self::writeCustom($contributionId, self::FIELD_VC, $vcId);
        }
        return $vcId;
    }

    /**
     * The VC credited for a project AUTOMATICALLY: the only person in
     * coordinatorsFor(), else NULL so the CSM chooses (a guess would put the
     * amount in front of the wrong VC since R2; rounds 8-9 of PR #76).
     *
     * Current is tested with core's `is_current`, not `is_active` (an ended
     * role often still has is_active = 1; see VcDigestRunner). A COMPLETED
     * project's roles are usually ended by the time the donation arrives, so
     * its past coordinators count, and the credit still belongs to them.
     */
    public static function coordinatorFor(int $caseId): ?int
    {
        return self::soleCoordinator(self::coordinatorsFor($caseId));
    }

    /** The one VC in a credit list, else NULL. Pure, so DonationRulesTest pins it. */
    public static function soleCoordinator(array $ids): ?int
    {
        return count($ids) === 1 ? (int) reset($ids) : null;
    }

    /**
     * The VCs a donation to this project may credit (creditableCoordinators()),
     * in pickCoordinator() order. Trashed contacts are left out AFTER the
     * current/past choice: the picker cannot show them (core adds
     * is_deleted = FALSE), and a trashed CURRENT coordinator must not make the
     * list fall back to past ones (round 10). R1: the contribution
     * form's Volunteer Consultant picker offers only these; R2: only these get
     * the VC notice.
     *
     * @return int[]
     */
    public static function coordinatorsFor(int $caseId): array
    {
        $typeId = self::coordinatorTypeId();
        if (!$typeId) {
            return [];
        }
        $roles = \Civi\Api4\Relationship::get(false)
            ->addSelect('contact_id_a', 'contact_id_a.is_deleted', 'is_current', 'start_date', 'end_date', 'id')
            ->addWhere('case_id', '=', $caseId)
            ->addWhere('relationship_type_id', '=', $typeId)
            ->execute()
            ->getArrayCopy();
        $trashed = array_map('intval', array_column(array_filter($roles, static fn($r) => !empty($r['contact_id_a.is_deleted'])), 'contact_id_a'));
        return array_values(array_diff(self::creditableCoordinators($roles), $trashed));
    }

    /**
     * Who a donation may credit, from a project's coordinator roles. Pure, so
     * DonationRulesTest pins it.
     *
     *  - Every CURRENT coordinator (core `is_current`), earliest started first.
     *  - Otherwise every coordinator the project has had, in pickCoordinator()
     *    order. A completed project's roles are all ended by then.
     *
     * The role history cannot tell a VC who did the work from one assigned by
     * mistake and removed: core's "remove role" and its case close both end
     * roles, dates are mostly missing, and on the dev clone 3,759 of 4,172
     * projects have only disabled roles (rounds 8-9 of PR #76). So nothing
     * here guesses: coordinatorFor() fills a VC automatically only when this
     * list has ONE person, and otherwise the CSM picks (R1).
     *
     * @param array<int,array{contact_id_a:int,is_current?:bool,start_date?:?string,end_date?:?string,id:int}> $roles
     * @return int[]
     */
    public static function creditableCoordinators(array $roles): array
    {
        $current = array_values(array_filter($roles, static fn($r) => !empty($r['is_current'])));
        $pool = $current ?: $roles;
        $order = [];
        while ($pool) {
            $next = self::pickCoordinator($pool);
            $order[] = $next;
            $pool = array_values(array_filter($pool, static fn($r) => (int) $r['contact_id_a'] !== $next));
        }
        return $order;
    }

    /**
     * The live Project cases a contact is a client of. R1: the contribution
     * form's Project picker offers only the chosen contributor's projects.
     *
     * @return int[]
     */
    public static function projectIdsForClient(int $contactId): array
    {
        $rows = \Civi\Api4\CaseContact::get(false)
            ->addSelect('case_id')
            ->addWhere('contact_id', '=', $contactId)
            ->addWhere('case_id.case_type_id:name', '=', 'project')
            ->addWhere('case_id.is_deleted', '=', false)
            ->execute()
            ->getArrayCopy();
        return array_values(array_unique(array_map('intval', array_column($rows, 'case_id'))));
    }

    /**
     * Choose the credited VC from a project's coordinator roles. Pure, so
     * DonationRulesTest pins it.
     *
     *  1. A current role (core `is_current`). If several, the earliest started,
     *     so the answer is stable between runs.
     *  2. Otherwise the role that ENDED most recently: for a completed project
     *     that is the coordinator who finished the work. A role with no end
     *     date (disabled, or not yet started) sorts after every ended one,
     *     because "ended most recently" is the better evidence of who did the work.
     *  3. Ties: the later start, then the higher id.
     *
     * @param array<int,array{contact_id_a:int,is_current?:bool,start_date?:?string,end_date?:?string,id:int}> $roles
     */
    public static function pickCoordinator(array $roles): ?int
    {
        if (!$roles) {
            return null;
        }
        $current = array_filter($roles, static fn($r) => !empty($r['is_current']));
        if ($current) {
            usort($current, static fn($a, $b) => [(string) ($a['start_date'] ?? ''), $a['id']] <=> [(string) ($b['start_date'] ?? ''), $b['id']]);
            return (int) $current[0]['contact_id_a'];
        }
        usort($roles, static fn($a, $b) =>
            [(string) ($b['end_date'] ?? ''), (string) ($b['start_date'] ?? ''), $b['id']]
            <=> [(string) ($a['end_date'] ?? ''), (string) ($a['start_date'] ?? ''), $a['id']]);
        return (int) $roles[0]['contact_id_a'];
    }

    /**
     * DN-5: link existing donations to projects from the `Pxxxxx` codes in
     * their free-text Source.
     *
     * Fill-empty and idempotent: a contribution that already has a Linked
     * Project is left alone, so a re-run (or a hand correction) is never
     * overwritten. The FIRST code is linked. A multi-code row ("FIRST P99002 …
     * SECOND P99003") is linked to the first and REPORTED, because one
     * contribution cannot be split into two without touching money. The CSM
     * decides whether to split it. Codes matching no Project case are reported
     * too. Only custom values are written, so no notifications fire and no
     * financial record changes.
     *
     * A linked donation whose project has several coordinators gets no VC
     * (coordinatorFor()); it is listed in vc_needs_pick for the CSM.
     *
     * @return array{scanned:int, linked:int, vc_filled:int, already_linked:int,
     *   multi_code:array<int,string[]>, unmatched:array<int,string[]>,
     *   vc_needs_pick:int[], dry_run:bool}
     */
    public static function backfill(bool $dryRun = true): array
    {
        $rows = \Civi\Api4\Contribution::get(false)
            ->addSelect('id', 'source', self::FIELD_PROJECT)
            ->addWhere('financial_type_id:name', 'IN', self::DONATION_TYPES)
            ->addWhere('source', 'REGEXP', 'P[0-9]{5}')
            ->addOrderBy('id')
            ->execute();

        $out = ['scanned' => 0, 'linked' => 0, 'vc_filled' => 0, 'already_linked' => 0,
            'multi_code' => [], 'unmatched' => [], 'vc_needs_pick' => [], 'dry_run' => $dryRun];
        $byCode = self::projectIdsByCode();
        foreach ($rows as $row) {
            $out['scanned']++;
            $codes = self::projectCodesIn($row['source']);
            if (count($codes) > 1) {
                $out['multi_code'][(int) $row['id']] = $codes;
            }
            if (!empty($row[self::FIELD_PROJECT])) {
                $out['already_linked']++;
                continue;
            }
            $projectId = $byCode[$codes[0] ?? ''] ?? null;
            if (!$projectId) {
                $out['unmatched'][(int) $row['id']] = $codes;
                continue;
            }
            $out['linked']++;
            if (!self::coordinatorFor($projectId)) {
                $out['vc_needs_pick'][] = (int) $row['id'];
            }
            if (!$dryRun) {
                self::writeCustom((int) $row['id'], self::FIELD_PROJECT, $projectId);
                if (self::fillVc((int) $row['id'])) {
                    $out['vc_filled']++;
                }
            }
        }
        return $out;
    }

    /**
     * Project case code → case id, for live (not trashed) Project cases.
     * A code on two cases maps to neither, so a wrong link is never guessed.
     *
     * @return array<string,int>
     */
    private static function projectIdsByCode(): array
    {
        $map = [];
        $dupes = [];
        $cases = \Civi\Api4\CiviCase::get(false)
            ->addSelect('id', 'Projects.MAS_Project_Case_Code')
            ->addWhere('case_type_id:name', '=', 'project')
            ->addWhere('is_deleted', '=', false)
            ->addWhere('Projects.MAS_Project_Case_Code', 'IS NOT EMPTY')
            ->execute();
        foreach ($cases as $c) {
            $code = strtoupper(trim((string) $c['Projects.MAS_Project_Case_Code']));
            if (isset($map[$code])) {
                $dupes[$code] = true;
            }
            $map[$code] = (int) $c['id'];
        }
        return array_diff_key($map, $dupes);
    }

    /**
     * Extract every project code in a free-text Source string, in order.
     * "FIRST P99002 … and SECOND P99003" → ['P99002', 'P99003'].
     *
     * @return string[]
     */
    public static function projectCodesIn(?string $source): array
    {
        if ($source === null || $source === '') {
            return [];
        }
        preg_match_all('/\bP\d{5}\b/', strtoupper($source), $m);
        return array_values(array_unique($m[0]));
    }

    /**
     * Write one Donation_Link field by name. Resolves the field id at run
     * time: custom field ids are not portable between dev and prod.
     */
    public static function writeCustom(int $contributionId, string $field, int $value): void
    {
        [, $name] = explode('.', $field, 2);
        $fieldId = \Civi\Api4\CustomField::get(false)
            ->addSelect('id')
            ->addWhere('custom_group_id.name', '=', 'Donation_Link')
            ->addWhere('name', '=', $name)
            ->execute()
            ->first()['id'] ?? null;
        if (!$fieldId) {
            throw new \RuntimeException("Donation_Link.$name is not provisioned; run cv flush");
        }
        // setValues() takes its params by reference, so it needs a variable.
        $params = [
            'entityID' => $contributionId,
            "custom_{$fieldId}" => $value,
        ];
        \CRM_Core_BAO_CustomValueTable::setValues($params);
    }

    /** Digits only, above zero; anything else is NULL. For DonationSubscriber (R1); pure, so DonationRulesTest pins it. */
    public static function positiveInt($v): ?int
    {
        if (is_bool($v) || !is_scalar($v) || !ctype_digit((string) $v) || (int) $v <= 0) {
            return null;
        }
        return (int) $v;
    }

    /**
     * QuickForm select options without the one whose value is $value. For DonationSubscriber;
     * pure, so DonationRulesTest pins R7.
     *
     * @param array<int,array{text?:string,attr?:array}> $options
     */
    public static function withoutOption(array $options, int $value): array
    {
        return array_values(array_filter($options,
            static fn($o) => (string) ($o['attr']['value'] ?? '') !== (string) $value));
    }

    private static ?int $coordinatorTypeId = null;

    /** Resolved numerically: `relationship_type_id:name_a_b` matches nothing in a where clause. */
    private static function coordinatorTypeId(): ?int
    {
        if (self::$coordinatorTypeId === null) {
            self::$coordinatorTypeId = (int) (\Civi\Api4\RelationshipType::get(false)
                ->addSelect('id')
                ->addWhere('name_a_b', '=', self::COORDINATOR_RELATION)
                ->execute()
                ->first()['id'] ?? 0);
        }
        return self::$coordinatorTypeId ?: null;
    }
}
