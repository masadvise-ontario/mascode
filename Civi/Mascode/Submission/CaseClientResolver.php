<?php

declare(strict_types=1);

namespace Civi\Mascode\Submission;

/**
 * Decides WHICH contact names the client on the MAS staff copy of a form
 * confirmation, and which contact id the case link may use.
 *
 * WHY THIS IS ITS OWN CLASS
 *
 * This logic previously lived inline in AfformSubmitSubscriber, where it could
 * not be tested: it sat between two API4 calls in a method CI cannot execute.
 * Three review rounds went by with it covered only by a source-reading
 * tripwire, and the tripwire proved unable to constrain it — round 3
 * demonstrated six wrong implementations that the tripwire still accepted,
 * three of which re-introduced defects named in earlier rounds. Substring
 * assertions over source pin a token in context; they cannot pin a preference
 * order.
 *
 * So the preference order moved here, behind the same seam that already works
 * for StaffCopyIdentification: a class with NO CiviCRM dependency, taking rows
 * that someone else fetched, returning a decision. It is tested behaviourally,
 * in CI, by constructing the row shapes directly.
 *
 * THE PREFERENCE ORDER, and why each rung exists:
 *
 *   1. A live Organization client of the case. This is the answer in every
 *      ordinary submission — the organization is who staff act on, where the
 *      submitting individual is the name they already had and could not use.
 *   2. The organization this submission itself saved. Reached when the case
 *      has no live Organization client, and — more importantly — on the three
 *      forms that declare no Organization1 entity at all.
 *   3. A trashed Organization client, as a last resort. A trashed name still
 *      tells staff whose submission this is; an empty Client row tells them
 *      nothing.
 *
 * Rung 3 is defensive, NOT a response to observed data. An earlier version of
 * this comment claimed 8 live projects in the dev clone had that shape; that
 * was wrong, and the correction matters because the false figure is what
 * motivated the rung. The real counts (clone of 2026-05-30): 185 cases carry a
 * trashed Organization client and ALL 185 are themselves in the trash, so zero
 * are reachable from a submission. The rung stands because a trashed case can
 * be undeleted and a client can be trashed after its case is created, not
 * because it fires today.
 *
 * THE CONTACT ID IS NEVER OFF THE CASE. It is only ever taken from the client
 * rows handed in, so a case link built from it always points at a contact the
 * case actually belongs to. Rung 2 supplies a NAME only — the organization a
 * submission saved need not be a client of the case, and a cid that is not on
 * the case yields a link the case tab renders under the wrong contact.
 */
class CaseClientResolver
{
    /**
     * @param array $clientRows
     *   Rows as returned by CaseContact::get(), each with `contact_id`,
     *   `contact_id.display_name`, `contact_id.contact_type` and
     *   `contact_id.is_deleted`. Order matters: the first match at each rung
     *   wins, so the caller is responsible for ordering them deterministically.
     * @param callable|null $submittedOrgName
     *   Lazily supplies rung 2 — a `fn(): string` returning the display name of
     *   the (live) organization this submission saved, or ''. Lazy so the
     *   caller's extra query is not issued when rung 1 already answered.
     *
     * @return array{name: string, contact_id: int}
     *   `contact_id` is 0 when the case has no live client; the caller must
     *   then build no case link, since CiviCRM's case view needs a cid.
     */
    public function resolve(array $clientRows, ?callable $submittedOrgName = null): array
    {
        $liveOrg = null;
        $liveAny = null;
        $trashedOrgName = '';

        foreach ($clientRows as $row) {
            $isOrg = ($row['contact_id.contact_type'] ?? '') === 'Organization';
            $isTrashed = !empty($row['contact_id.is_deleted']);
            $name = trim((string) ($row['contact_id.display_name'] ?? ''));

            // An Organization with a blank display_name cannot name anything,
            // so it must not consume rung 1 and block rung 2 — otherwise the
            // block would show no Client while the link pointed at that org.
            if (!$isTrashed && $isOrg && $name !== '' && $liveOrg === null) {
                $liveOrg = ['name' => $name, 'contact_id' => (int) ($row['contact_id'] ?? 0)];
            }
            if (!$isTrashed && $liveAny === null) {
                $liveAny = ['contact_id' => (int) ($row['contact_id'] ?? 0)];
            }
            if ($isTrashed && $isOrg && $name !== '' && $trashedOrgName === '') {
                $trashedOrgName = $name;
            }
        }

        // The cid: the named organization when there is one, otherwise any live
        // client of the case, so the link still resolves. Never rung 2.
        $contactId = $liveOrg['contact_id'] ?? ($liveAny['contact_id'] ?? 0);

        if ($liveOrg !== null) {
            return ['name' => $liveOrg['name'], 'contact_id' => $contactId];
        }

        $submitted = $submittedOrgName === null ? '' : trim((string) $submittedOrgName());
        if ($submitted !== '') {
            return ['name' => $submitted, 'contact_id' => $contactId];
        }

        return ['name' => $trashedOrgName, 'contact_id' => $contactId];
    }
}
