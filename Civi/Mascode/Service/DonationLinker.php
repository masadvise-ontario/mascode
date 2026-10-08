<?php

declare(strict_types=1);

namespace Civi\Mascode\Service;

/**
 * Donation → Project / VC link (donations tickets DN-2 and DN-5; spec BrianPKM
 * 3-Resources/mas-donation-process.md §3b.1).
 *
 * One contribution per cheque (R10, 2026-10-08): `Linked_Project` and
 * `Linked_VC` each hold SEVERAL ids (serialized fields); read them with ids().
 * When the VC list is empty, it is filled with each linked project's sole
 * coordinator (coordinatorFor()). A VC list set by hand is never touched, and
 * a stored VC stays put when the case role later changes, so a past donation
 * keeps its credit. `Linked_Project_Codes` is a view-only copy of the
 * projects' codes for the lists, kept in step by refreshCodes().
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

    /** View-only "P26101, P26102": APIv4 cannot join a case's custom fields through a serialized field. */
    public const FIELD_CODES = 'Donation_Link.Linked_Project_Codes';

    /** On `Case Coordinator is` the VC is contact_id_a; see VcDigestRunner::COORDINATOR_RELATION. */
    public const COORDINATOR_RELATION = VcDigestRunner::COORDINATOR_RELATION;

    /** Financial types that are donations. "Donation" is the legacy pre-DN-1 type. */
    public const DONATION_TYPES = ['Client Donation', 'Private Donation', 'Donation'];

    /**
     * Fill Linked_VC when it is EMPTY: each linked project's sole coordinator.
     * A project with several coordinators adds nobody; the CSM picks (R1).
     *
     * @return int[] the VCs now credited (the existing list when it was set)
     */
    public static function fillVc(int $contributionId): array
    {
        $row = \Civi\Api4\Contribution::get(false)
            ->addSelect('id', self::FIELD_PROJECT, self::FIELD_VC)
            ->addWhere('id', '=', $contributionId)
            ->execute()
            ->first();
        if (!$row) {
            return [];
        }
        $current = self::ids($row[self::FIELD_VC] ?? null);
        if ($current) {
            return $current;
        }
        $vcs = self::soleCoordinators(self::ids($row[self::FIELD_PROJECT] ?? null));
        if ($vcs) {
            self::writeCustom($contributionId, self::FIELD_VC, $vcs);
        }
        return $vcs;
    }

    /**
     * Add VCs to a contribution's Linked_VC list, keeping those already there.
     *
     * @param int[] $vcIds
     * @return bool whether anything was added
     */
    private static function addVcs(int $contributionId, array $vcIds): bool
    {
        if (!$vcIds) {
            return false;
        }
        $current = self::ids(\Civi\Api4\Contribution::get(false)
            ->addSelect(self::FIELD_VC)
            ->addWhere('id', '=', $contributionId)
            ->execute()
            ->first()[self::FIELD_VC] ?? null);
        $merged = array_values(array_unique(array_merge($current, $vcIds)));
        if (count($merged) === count($current)) {
            return false;
        }
        self::writeCustom($contributionId, self::FIELD_VC, $merged);
        return true;
    }

    /** @param int[] $projectIds @return int[] each project's sole coordinator, distinct */
    private static function soleCoordinators(array $projectIds): array
    {
        $vcs = [];
        foreach ($projectIds as $pid) {
            $vc = self::coordinatorFor($pid);
            if ($vc) {
                $vcs[] = $vc;
            }
        }
        return array_values(array_unique($vcs));
    }

    /**
     * Distinct positive ids from a serialized field's value: an array (APIv4),
     * one id, or a string separated by the value separator, commas, semicolons
     * or spaces (the form posts "12,34"; R4 map cells use ";"). Anything else
     * is dropped. Pure, so DonationRulesTest pins it.
     *
     * @return int[]
     */
    public static function ids($value): array
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return [];
        }
        $parts = is_array($value) ? $value : preg_split('/[\x01,;\s]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $part) {
            $id = self::positiveInt(is_string($part) ? trim($part) : $part);
            if ($id) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    /**
     * Rewrite Linked_Project_Codes from the current Linked_Project, when it
     * differs. Called after every contribution save and every link write here.
     */
    public static function refreshCodes(int $contributionId): void
    {
        $row = \Civi\Api4\Contribution::get(false)
            ->addSelect('id', self::FIELD_PROJECT, self::FIELD_CODES)
            ->addWhere('id', '=', $contributionId)
            ->execute()
            ->first();
        if (!$row) {
            return;
        }
        $codes = self::codesFor(self::ids($row[self::FIELD_PROJECT] ?? null));
        if ($codes !== (string) ($row[self::FIELD_CODES] ?? '')) {
            self::writeCustom($contributionId, self::FIELD_CODES, $codes);
        }
    }

    /** @param int[] $projectIds @return string "P26101, P26102", in link order */
    public static function codesFor(array $projectIds): string
    {
        if (!$projectIds) {
            return '';
        }
        $cases = \Civi\Api4\CiviCase::get(false)
            ->addSelect('id', 'subject', 'Projects.MAS_Project_Case_Code')
            ->addWhere('id', 'IN', $projectIds)
            ->addWhere('is_deleted', 'IN', [0, 1])
            ->execute()
            ->indexBy('id');
        $out = [];
        foreach ($projectIds as $pid) {
            $c = $cases[$pid] ?? [];
            $out[] = self::codeLabel($c['Projects.MAS_Project_Case_Code'] ?? null, $c['subject'] ?? null, $pid);
        }
        return implode(', ', $out);
    }

    /**
     * A project's code for the lists: the case-code field, else the code a
     * pre-2020 subject starts with ("16148 Strategic plan" → P16148), else
     * "#<case id>". Pure, so DonationRulesTest pins it.
     */
    public static function codeLabel(?string $code, ?string $subject, int $caseId): string
    {
        $code = strtoupper(trim((string) $code));
        if ($code !== '') {
            return $code;
        }
        if (preg_match('/^\s*P?(\d{5})\b/i', (string) $subject, $m)) {
            return 'P' . $m[1];
        }
        return '#' . $caseId;
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
     * With $everyone, every live coordinator the project has EVER had, current
     * or not: R4's history map names the VC who did the work, who may since
     * have been replaced. Never use it for the picker or the notice.
     *
     * @return int[]
     */
    public static function coordinatorsFor(int $caseId, bool $everyone = false): array
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
        $ids = $everyone
            ? array_values(array_unique(array_map('intval', array_column($roles, 'contact_id_a'))))
            : self::creditableCoordinators($roles);
        return array_values(array_diff($ids, $trashed));
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
     * overwritten. EVERY code that matches a Project is linked (R10: one
     * contribution can name several projects). One exception: a multi-code
     * row whose link is still exactly its FIRST code, which is what the
     * single-project upgrade_5019 wrote, gets the other codes added
     * (`multi_added`). Codes matching no Project case are reported. Only
     * custom values are written, so no notifications fire and no financial
     * record changes.
     *
     * A linked donation with a project that has several coordinators may be
     * missing a VC (fillVc()); it is listed in vc_needs_pick for the CSM.
     *
     * @return array{scanned:int, linked:int, multi_added:int, vc_filled:int, already_linked:int,
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

        $out = ['scanned' => 0, 'linked' => 0, 'multi_added' => 0, 'vc_filled' => 0, 'already_linked' => 0,
            'multi_code' => [], 'unmatched' => [], 'vc_needs_pick' => [], 'dry_run' => $dryRun];
        $byCode = self::projectIdsByCode();
        foreach ($rows as $row) {
            $out['scanned']++;
            $id = (int) $row['id'];
            $codes = self::projectCodesIn($row['source']);
            if (count($codes) > 1) {
                $out['multi_code'][$id] = $codes;
            }
            $missing = array_values(array_filter($codes, static fn($c) => empty($byCode[$c])));
            if (!$codes || $missing) {
                // No well-formed code at all (e.g. a six-digit typo) is reported as [].
                $out['unmatched'][$id] = $missing;
            }
            $projectIds = self::ids(array_map(static fn($c) => $byCode[$c] ?? null, $codes));
            $linked = self::ids($row[self::FIELD_PROJECT] ?? null);
            $write = self::backfillWrite($linked, $projectIds);
            if ($write === null) {
                if ($linked) {
                    $out['already_linked']++;
                }
                continue;
            }
            $out[$linked ? 'multi_added' : 'linked']++;
            $sole = self::soleCoordinators($write);
            if (count($sole) < count($write)) {
                $out['vc_needs_pick'][] = $id;
            }
            if (!$dryRun) {
                self::writeCustom($id, self::FIELD_PROJECT, $write);
                self::refreshCodes($id);
                if ($linked) {
                    // Projects ADDED to an existing link bring their sole
                    // coordinators into the VC list; fillVc() would not,
                    // because the list is no longer empty.
                    if (self::addVcs($id, self::soleCoordinators(array_values(array_diff($write, $linked))))) {
                        $out['vc_filled']++;
                    }
                }
                elseif (self::fillVc($id)) {
                    $out['vc_filled']++;
                }
            }
        }
        return $out;
    }

    /**
     * What backfill() writes to Linked_Project, or NULL to leave it. Pure, so
     * DonationRulesTest pins it: an empty link gets every matched code; a link
     * that is exactly the first matched code (upgrade_5019's single-project
     * write) gets the rest; anything else was set by hand and is left alone.
     *
     * @param int[] $linked @param int[] $fromSource
     * @return int[]|null
     */
    public static function backfillWrite(array $linked, array $fromSource): ?array
    {
        if (!$fromSource) {
            return null;
        }
        if (!$linked) {
            return $fromSource;
        }
        if (count($fromSource) > 1 && $linked === [$fromSource[0]]) {
            return $fromSource;
        }
        return null;
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
     * Write one Donation_Link field by name: an id list for the serialized
     * fields (setValues() pads it), a string for the codes. Resolves the field
     * id at run time: custom field ids are not portable between dev and prod.
     *
     * @param int[]|string $value
     */
    public static function writeCustom(int $contributionId, string $field, $value): void
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

    /**
     * R4: link historical donations from a reviewed map (contribution → its
     * projects, optional VCs), built offline from the Treasurer's workbook.
     * `case_id` and `vc_id` may each hold several ids separated by ";" (R10:
     * one cheque, several projects). The map holds ids only and lives outside
     * this public repo. Dry run unless $dryRun is false. Every row is
     * re-checked against live data by historyVerdict(), so a stale or wrong
     * map row is refused, never written.
     *
     * @param array<int,array<string,mixed>> $map raw CSV rows: contribution_id, case_id, vc_id (may be blank)
     * @return array{rows:int,dry_run:bool,project_written:int,vc_written:int,unchanged:int,refused:string[],conflicts:string[]}
     */
    public static function linkHistory(array $map, bool $dryRun = true): array
    {
        $out = ['rows' => 0, 'dry_run' => $dryRun, 'project_written' => 0, 'vc_written' => 0,
            'unchanged' => 0, 'refused' => [], 'conflicts' => []];
        // A contribution named twice would make the dry run and the apply
        // disagree (the apply's first row wins), so neither row is used.
        $seen = array_count_values(array_map(
            static fn($m) => (string) (self::positiveInt(trim((string) ($m['contribution_id'] ?? ''))) ?? trim((string) ($m['contribution_id'] ?? ''))),
            $map
        ));
        foreach ($map as $m) {
            $out['rows']++;
            $cid = self::positiveInt(trim((string) ($m['contribution_id'] ?? '')));
            $caseIds = self::ids($m['case_id'] ?? null);
            $vcIds = self::ids($m['vc_id'] ?? null);
            if (!$cid || !$caseIds || !self::cleanIdList($m['case_id'] ?? '') || !self::cleanIdList($m['vc_id'] ?? '')) {
                $out['refused'][] = 'malformed map row ' . $out['rows'];
                continue;
            }
            if ($seen[(string) $cid] > 1) {
                $out['refused'][] = "row {$out['rows']}, contribution $cid: named more than once in the map";
                continue;
            }
            $v = self::historyVerdict(self::historyState($cid, $caseIds), $caseIds, $vcIds);
            if ($v['refuse']) {
                $out['refused'][] = "row {$out['rows']}, contribution $cid: {$v['refuse']}";
                continue;
            }
            foreach ($v['conflicts'] as $c) {
                $out['conflicts'][] = "row {$out['rows']}, contribution $cid: $c";
            }
            if ($v['conflicts']) {
                continue;
            }
            if (!$v['project'] && !$v['vc']) {
                $out['unchanged']++;
                continue;
            }
            if ($v['project']) {
                $out['project_written']++;
            }
            if ($v['vc']) {
                $out['vc_written']++;
            }
            if (!$dryRun) {
                if ($v['project']) {
                    self::writeCustom($cid, self::FIELD_PROJECT, $caseIds);
                    self::refreshCodes($cid);
                }
                if ($v['vc']) {
                    self::writeCustom($cid, self::FIELD_VC, $vcIds);
                }
            }
        }
        return $out;
    }

    /** A map cell is blank or ids separated by ";" (or spaces) and nothing else. */
    private static function cleanIdList($cell): bool
    {
        return preg_match('/^[\s;]*(?:\d+[\s;]*)*$/', (string) $cell) === 1;
    }

    /**
     * What linkHistory() may write for one map row. Pure, so DonationRulesTest
     * pins it. Refuses unless the contribution is a live, non-test donation of
     * a type in DONATION_TYPES, EVERY case is a live Project, and the donor is
     * a client of every one of them (a wrong map row must not credit another
     * client's project or VC). Each VC must have coordinated at least one of
     * the cases. Fill-empty only, comparing sets: any existing value that
     * differs from the map (a VC credited where the map has none included) is
     * a conflict, and the row writes nothing.
     *
     * A gift inside DonationNotifier's window ($s['recent']) is refused: the
     * write itself notifies no one, but its next ordinary save would email
     * the newly linked VCs the amount. Recent gifts are linked on the
     * contribution form instead, where the CSM sees that happen.
     *
     * @param array|null $s from historyState(): contribution, cases, clients per case, coordinators
     * @param int[] $caseIds
     * @param int[] $vcIds
     * @return array{refuse:?string,project:bool,vc:bool,conflicts:string[]}
     */
    public static function historyVerdict(?array $s, array $caseIds, array $vcIds): array
    {
        $r = ['refuse' => null, 'project' => false, 'vc' => false, 'conflicts' => []];
        if (!$s || empty($s['contribution'])) {
            $r['refuse'] = 'contribution not found';
            return $r;
        }
        $c = $s['contribution'];
        if (!in_array($c['financial_type_id:name'] ?? '', self::DONATION_TYPES, true) || !empty($c['is_test'])) {
            $r['refuse'] = 'not a live donation';
            return $r;
        }
        foreach ($caseIds as $caseId) {
            $case = $s['cases'][$caseId] ?? null;
            if (!$case || ($case['case_type_id:name'] ?? '') !== 'project' || !empty($case['is_deleted'])) {
                $r['refuse'] = "case $caseId is not a live Project";
                return $r;
            }
            if (!in_array((int) $c['contact_id'], array_map('intval', $s['clients'][$caseId] ?? []), true)) {
                $r['refuse'] = "donor is not a client of project $caseId";
                return $r;
            }
        }
        $coordinators = array_map('intval', $s['coordinators'] ?? []);
        foreach ($vcIds as $vcId) {
            if (!in_array($vcId, $coordinators, true)) {
                $r['refuse'] = "VC $vcId is not a coordinator of these projects";
                return $r;
            }
        }
        if (!empty($s['recent'])) {
            $r['refuse'] = 'inside the notification window; link it on the contribution form';
            return $r;
        }
        $same = static fn(array $a, array $b) => !array_diff($a, $b) && !array_diff($b, $a);
        $projects = self::ids($c[self::FIELD_PROJECT] ?? null);
        if ($projects && !$same($projects, $caseIds)) {
            $r['conflicts'][] = 'already linked to project(s) ' . implode(', ', $projects);
        }
        $vcs = self::ids($c[self::FIELD_VC] ?? null);
        if ($vcs && !$same($vcs, $vcIds)) {
            $r['conflicts'][] = 'already credits VC(s) ' . implode(', ', $vcs);
        }
        if ($r['conflicts']) {
            return $r;
        }
        $r['project'] = !$projects;
        $r['vc'] = $vcIds && !$vcs;
        return $r;
    }

    /** The live facts historyVerdict() judges, for one contribution and its cases. @param int[] $caseIds */
    private static function historyState(int $contributionId, array $caseIds): array
    {
        $contribution = \Civi\Api4\Contribution::get(false)
            ->addSelect('id', 'contact_id', 'financial_type_id:name', 'is_test', 'created_date', 'receive_date', self::FIELD_PROJECT, self::FIELD_VC)
            ->addWhere('id', '=', $contributionId)
            ->execute()
            ->first();
        $cases = \Civi\Api4\CiviCase::get(false)
            ->addSelect('id', 'case_type_id:name', 'is_deleted')
            ->addWhere('id', 'IN', $caseIds)
            ->addWhere('is_deleted', 'IN', [0, 1])
            ->execute()
            ->indexBy('id')
            ->getArrayCopy();
        $clients = [];
        foreach (\Civi\Api4\CaseContact::get(false)->addSelect('case_id', 'contact_id')->addWhere('case_id', 'IN', $caseIds)->execute() as $cc) {
            $clients[(int) $cc['case_id']][] = (int) $cc['contact_id'];
        }
        $coordinators = [];
        foreach (array_keys($cases) as $caseId) {
            // EVERY coordinator each project has had: the workbook names the VC
            // who did the work, who may since have been replaced by a current one.
            array_push($coordinators, ...self::coordinatorsFor((int) $caseId, true));
        }
        return [
            'contribution' => $contribution,
            'recent' => $contribution
                && DonationNotifier::insideWindow($contribution['created_date'] ?? null, $contribution['receive_date'] ?? null),
            'cases' => $cases,
            'clients' => $clients,
            'coordinators' => array_values(array_unique($coordinators)),
        ];
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
