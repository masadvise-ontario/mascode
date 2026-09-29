<?php

declare(strict_types=1);

// file: Civi/Mascode/Event/VcCheckinPageSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Mascode\Digest\CheckinPageRows;
use Civi\Mascode\Service\VcDigestRunner;

/**
 * The per-VC check-in page (P1-8): one public Afform, one block per project.
 *
 * Plan: docs/plans/completion-signoff-p1-8-per-vc-checkin-page.md. Design
 * proven by the P1-7 spike. What this class owns, and ONLY for
 * `afformMASVcCheckin`:
 *
 *   onRespond   seeds the rows. Core loads nothing for this form, so the rows
 *               are APPENDED to the `Afform.prefill` response: one id-less
 *               `Activity1` row per eligible project, and one read-only
 *               `Activity2` row per project already answered this round.
 *   onValidate  refuses the whole submit if any ANSWERED row names no case or a
 *               case the session contact may not answer for (D10). Validate
 *               runs before any entity write, and Afform submit is not
 *               transactional, so this is the place a refusal must happen.
 *   onDrop      (50) removes unanswered rows, which core would otherwise save
 *               because a Hidden `case_id` makes them non-empty. FAILS CLOSED.
 *   onBackstop  (30) re-checks every surviving record and throws if one is
 *               unanswered or not entitled — a drop that silently stopped
 *               dropping would otherwise let a never-validated row reach the
 *               save. FAILS CLOSED.
 *
 * After that, VcDigestSubmitSubscriber (10, -100) runs P1-5's per-record logic
 * for this form exactly as for the per-project one: normalise `vc_will_ask`,
 * fill the source contact, stamp round and subject, and on "Yes" send the
 * Completion template.
 */
class VcCheckinPageSubscriber extends AutoSubscriber
{
    public const FORM_NAME = 'afformMASVcCheckin';

    public static function getSubscribedEvents(): array
    {
        return [
            // Late, so any other respond listener has already run.
            'civi.api.respond' => ['onRespond', -100],
            'civi.afform.validate' => ['onValidate', 0],
            'civi.afform.submit' => [
                // Both ABOVE VcDigestSubmitSubscriber::onBeforeSave (10) and
                // core's processGenericEntity (0).
                ['onDrop', 50],
                ['onBackstop', 30],
            ],
        ];
    }

    /**
     * Append this VC's rows to the prefill response.
     *
     * Scoped three ways, each of which would otherwise leak one VC's projects:
     * this form's name, `fillMode: form`, and a session contact (a digest link's
     * token puts the VC in the session; no contact means no rows).
     */
    public function onRespond($event): void
    {
        $request = $event->getApiRequest();
        if (!$this->isThisPrefill($request)) {
            return;
        }

        try {
            $vcId = (int) (\CRM_Core_Session::getLoggedInContactID() ?: 0);
            if (!$vcId) {
                return;
            }

            [$open, $answered] = $this->projectsFor($vcId);

            $result = $event->getResponse();
            $result = $this->withItem($result, 'Activity1', CheckinPageRows::rowsFor($open));
            $result = $this->withItem($result, 'Activity2', CheckinPageRows::rowsFor($answered));
            $event->setResponse($result);
        } catch (\Throwable $e) {
            // A read. Failing here renders an empty page, which is the safe
            // direction; the VC is asked again next month.
            \Civi::log()->error('VcCheckinPageSubscriber.php - Could not seed the check-in rows: ' . $e->getMessage());
        }
    }

    /**
     * D10 on every ANSWERED `Activity1` row, before any write.
     */
    public function onValidate($event): void
    {
        if (!$this->isThisForm($event)) {
            return;
        }

        $rows = $event->getSubmittedValues()['Activity1'] ?? [];
        $refused = CheckinPageRows::refusals(
            is_array($rows) ? $rows : [],
            static fn(int $caseId): bool => CheckinCaseEntitlementSubscriber::isEntitledToCase($caseId)
        );

        if ($refused) {
            \Civi::log()->warning('VcCheckinPageSubscriber.php - Refused a check-in page submission', [
                'afform' => self::FORM_NAME,
                'rows' => $refused,
                'logged_in_contact' => (int) (\CRM_Core_Session::getLoggedInContactID() ?: 0) ?: null,
            ]);
            $event->addError(ts('One or more of these projects is not yours to answer for. Nothing was saved.'));
        }
    }

    /**
     * Remove unanswered rows before they are saved. Does NOT catch.
     *
     * P1-5's listeners catch `Throwable` so as not to block a VC's answer; this
     * one must not. Validate only judged ANSWERED rows, so if the drop failed
     * open, every unanswered, never-validated row would reach the save — an
     * activity on an arbitrary case.
     */
    public function onDrop($event): void
    {
        if (!$this->isThisForm($event) || $event->getEntityName() !== 'Activity1') {
            return;
        }
        $event->setRecords(CheckinPageRows::keepAnswered($event->getRecords()));
    }

    /**
     * The fail-closed re-check of what survived the drop.
     */
    public function onBackstop($event): void
    {
        if (!$this->isThisForm($event) || $event->getEntityName() !== 'Activity1') {
            return;
        }

        $bad = CheckinPageRows::backstopViolations(
            $event->getRecords(),
            static fn(int $caseId): bool => CheckinCaseEntitlementSubscriber::isEntitledToCase($caseId)
        );
        if ($bad) {
            \Civi::log()->error('VcCheckinPageSubscriber.php - Backstop refused records that should not have survived', [
                'afform' => self::FORM_NAME,
                'rows' => $bad,
            ]);
            throw new \Civi\API\Exception\UnauthorizedException(
                ts('One or more of these projects could not be recorded. Nothing was saved.')
            );
        }
    }

    // ------------------------------------------------------------------

    /**
     * The VC's eligible projects, split into still-to-answer and answered.
     *
     * Eligibility is exactly the digest's: `VcDigestRunner`'s selection and
     * coordinator grouping, for this one VC. "Answered this round" is a stored
     * check-in on the case in the current round that passes the same
     * `isAnswered` rule the submit uses.
     *
     * @return array{0:array,1:array} [open, answered], each a list of
     *   `['case_id' => int, 'label' => string]`.
     */
    private function projectsFor(int $vcId): array
    {
        $today = date('Y-m-d');
        $selected = VcDigestRunner::selectEligibleProjects($today);
        $grouped = VcDigestRunner::groupByCoordinator($selected['projects']);
        $projects = $grouped['by_vc'][$vcId]['projects'] ?? [];
        if (!$projects) {
            return [[], []];
        }

        $caseIds = array_map(static fn($p) => (int) $p['case_id'], $projects);
        $labels = $this->labels($caseIds, $projects);
        $answered = $this->answeredThisRound($caseIds);

        $open = [];
        $done = [];
        foreach ($caseIds as $caseId) {
            $row = ['case_id' => $caseId, 'label' => $labels[$caseId] ?? ''];
            if (isset($answered[$caseId])) {
                $done[] = $row;
            } else {
                $open[] = $row;
            }
        }
        return [$open, $done];
    }

    /**
     * Case id => "client — code — subject".
     */
    private function labels(array $caseIds, array $projects): array
    {
        $codes = [];
        foreach (\Civi\Api4\CiviCase::get(false)
            ->addSelect('id', 'Projects.MAS_Project_Case_Code')
            ->addWhere('id', 'IN', $caseIds)
            ->setLimit(0)
            ->execute() as $row) {
            $codes[(int) $row['id']] = (string) ($row['Projects.MAS_Project_Case_Code'] ?? '');
        }

        $clients = \Civi\Mascode\Service\VcDigestMailer::indexClientNames(
            \Civi\Api4\CaseContact::get(false)
                ->addSelect('case_id', 'contact_id.display_name')
                ->addWhere('case_id', 'IN', $caseIds)
                ->addWhere('contact_id.is_deleted', '=', false)
                ->addOrderBy('contact_id.sort_name')
                ->setLimit(0)
                ->execute()
                ->getArrayCopy()
        );

        $labels = [];
        foreach ($projects as $project) {
            $caseId = (int) $project['case_id'];
            $labels[$caseId] = CheckinPageRows::label(
                $clients[$caseId] ?? '',
                $codes[$caseId] ?? '',
                (string) ($project['subject'] ?? '')
            );
        }
        return $labels;
    }

    /**
     * Case ids with an answered check-in in THEIR round.
     *
     * Each case is judged against `VcDigestSubmitSubscriber::roundFor()`, the
     * same rule that stamps `digest_round` on save — never the calendar month,
     * which disagrees whenever a digest runs early or late.
     *
     * @return array<int,true>
     */
    private function answeredThisRound(array $caseIds): array
    {
        $rounds = [];
        foreach ($caseIds as $caseId) {
            $rounds[$caseId] = VcDigestSubmitSubscriber::roundFor($caseId);
        }

        $rows = \Civi\Api4\Activity::get(false)
            ->addSelect('case_id', 'Monthly_Project_Checkin.digest_round', CheckinPageRows::IS_COMPLETE)
            ->addWhere('case_id', 'IN', $caseIds)
            ->addWhere('activity_type_id:name', '=', VcDigestSubmitSubscriber::ACTIVITY_TYPE)
            ->addWhere('Monthly_Project_Checkin.digest_round', 'IN', array_values(array_unique($rounds)))
            ->addWhere('is_deleted', '=', false)
            ->setLimit(0)
            ->execute();

        $answered = [];
        foreach ($rows as $row) {
            $caseId = (int) $row['case_id'];
            if (($rounds[$caseId] ?? null) === $row['Monthly_Project_Checkin.digest_round']
                && CheckinPageRows::isAnswered($row)) {
                $answered[$caseId] = true;
            }
        }
        return $answered;
    }

    /**
     * The response with the named item's values set, appending it if absent.
     */
    private function withItem($result, string $name, array $values)
    {
        foreach ($result as $i => $item) {
            if (($item['name'] ?? null) === $name) {
                $item['values'] = $values;
                $result[$i] = $item;
                return $result;
            }
        }
        $result[] = ['name' => $name, 'values' => $values];
        return $result;
    }

    private function isThisPrefill($request): bool
    {
        return is_object($request)
            && method_exists($request, 'getEntityName')
            && $request->getEntityName() === 'Afform'
            && $request->getActionName() === 'prefill'
            && $request->getName() === self::FORM_NAME
            && $request->getFillMode() === 'form';
    }

    private function isThisForm($event): bool
    {
        return ($event->getAfform()['name'] ?? null) === self::FORM_NAME;
    }
}
