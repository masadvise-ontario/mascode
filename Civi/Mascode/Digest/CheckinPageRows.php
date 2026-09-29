<?php

declare(strict_types=1);

// file: Civi/Mascode/Digest/CheckinPageRows.php

namespace Civi\Mascode\Digest;

/**
 * The rules of the per-VC check-in page (P1-8), free of every CiviCRM
 * dependency so CI can hold them.
 *
 * The page is a public Afform with one repeated `Activity1` block per project.
 * Its rows are seeded server-side and come back with a caller-editable Hidden
 * `case_id`, so three decisions have to agree exactly, and they live here as
 * one set of functions rather than three inline tests:
 *
 *   - which rows count as ANSWERED (`isAnswered`);
 *   - which answered rows must refuse the whole submit (`refusals`);
 *   - which rows survive to be saved (`keepAnswered`), and the fail-closed
 *     re-check of what survived (`backstopViolations`).
 *
 * If they disagreed, a crafted row carrying `vc_will_ask` and no
 * `is_complete` could pass one test, fail another, and be saved as an empty
 * check-in. Plan: docs/plans/completion-signoff-p1-8-per-vc-checkin-page.md.
 */
final class CheckinPageRows
{
    public const IS_COMPLETE = 'Monthly_Project_Checkin.is_complete';

    /**
     * Has this row answered the first question?
     *
     * STRICT, in both directions. `is_complete` must be present and be an
     * unambiguous true (`CheckinAnswer::isTrue`) or an unambiguous false
     * (`false`, `0`, `'0'`). `''`, `null`, an absent key, and strings such as
     * `'false'` or `'yes'` are NOT answered, so the row is dropped rather than
     * recorded — the safe direction, because an unanswered project is simply
     * asked about again next month. Works on submitted and on stored values.
     */
    public static function isAnswered(array $fields): bool
    {
        if (!array_key_exists(self::IS_COMPLETE, $fields)) {
            return false;
        }
        $value = $fields[self::IS_COMPLETE];
        return CheckinAnswer::isTrue($value) || $value === false || $value === 0 || $value === '0';
    }

    /**
     * A single positive integer case id, or 0 for anything else.
     *
     * Arrays and non-digit strings are refused rather than coerced, as in
     * CheckinAnswer::fillSourceContact(): `(int)` of a non-empty array is 1.
     */
    public static function caseIdOf($raw): int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : 0;
        }
        if (is_string($raw) && $raw !== '' && ctype_digit($raw)) {
            $id = (int) $raw;
            return $id > 0 ? $id : 0;
        }
        return 0;
    }

    /**
     * The seeded rows for the page. NEVER with an id.
     *
     * An `id` would make core treat the row as an existing activity: an UPDATE
     * of it, or a silently swallowed refusal under `update: false` (P1-7,
     * fact 3). Rows are new check-ins; only `case_id` and the display label
     * travel.
     *
     * @param array<int,array{case_id:int,label:string}> $projects
     * @return array<int,array{fields:array}>
     */
    public static function rowsFor(array $projects): array
    {
        $rows = [];
        foreach ($projects as $project) {
            $caseId = self::caseIdOf($project['case_id'] ?? null);
            if (!$caseId) {
                continue;
            }
            $rows[] = ['fields' => ['case_id' => $caseId, 'subject' => (string) ($project['label'] ?? '')]];
        }
        return $rows;
    }

    /**
     * The label a VC recognises a project by: client, code, subject.
     *
     * Joined rather than trimmed (see VcDigestSubmitSubscriber::recordAnswers
     * on why `trim()` and an em dash do not mix).
     */
    public static function label(string $client, string $code, string $subject): string
    {
        return implode(' — ', array_filter([trim($client), trim($code), trim($subject)], 'strlen'));
    }

    /**
     * Answered rows that must refuse the WHOLE submit, index => reason.
     *
     * Only ANSWERED rows are judged. An unanswered row whose case has gone
     * stale between page load and submit — a role that ended, a case
     * reassigned to a new id, a project another coordinator just advanced —
     * must not block the VC's other answers; it is dropped instead.
     *
     * @param array $rows Submitted rows, each `['fields' => [...]]`.
     * @param callable $isEntitled fn(int $caseId): bool — the D10 predicate.
     * @return array<int|string,string>
     */
    public static function refusals(array $rows, callable $isEntitled): array
    {
        $refused = [];
        foreach ($rows as $i => $row) {
            $fields = $row['fields'] ?? [];
            if (!self::isAnswered($fields)) {
                continue;
            }
            $caseId = self::caseIdOf($fields['case_id'] ?? null);
            if (!$caseId) {
                $refused[$i] = 'no case';
            } elseif (!$isEntitled($caseId)) {
                $refused[$i] = 'not entitled';
            }
        }
        return $refused;
    }

    /**
     * The answered records, KEYS PRESERVED.
     *
     * Keys are preserved because core pairs saved ids back to records by index
     * (`setEntityId($index)`), and the after-save handler walks the surviving
     * keys.
     */
    public static function keepAnswered(array $records): array
    {
        return array_filter($records, static fn($r) => self::isAnswered($r['fields'] ?? []));
    }

    /**
     * Surviving records that should not have survived, index => reason.
     *
     * The fail-closed re-check after the drop: every record about to be saved
     * must be answered AND entitled. Non-empty means something upstream (the
     * drop, or validate) did not do its job, and the submit must be refused.
     */
    public static function backstopViolations(array $records, callable $isEntitled): array
    {
        $bad = [];
        foreach ($records as $i => $record) {
            $fields = $record['fields'] ?? [];
            if (!self::isAnswered($fields)) {
                $bad[$i] = 'unanswered';
                continue;
            }
            $caseId = self::caseIdOf($fields['case_id'] ?? null);
            if (!$caseId) {
                $bad[$i] = 'no case';
            } elseif (!$isEntitled($caseId)) {
                $bad[$i] = 'not entitled';
            }
        }
        return $bad;
    }
}
