<?php

declare(strict_types=1);

namespace Civi\Mascode\Security;

/**
 * Who may change a contact's volunteer consultant (VC) standing: only MAS staff.
 *
 * WHY. The WordPress Subscriber role (every VC) holds `edit my contact`, `access all custom data`
 * and `access CiviCRM`, so a VC can edit their own contact — the backend contact form, inline
 * custom-data edit, or APIv4 over `civicrm/ajax/api4`. `MAS_Rep.VC_Status` and the `MAS_Rep`
 * contact sub-type decide who counts as a VC: the VC directory (SavedSearch_MAS_VC_Directory) and
 * the MAS CiviCRM MCP's VC tools (decision D30) admit only an Active or Test VC, reading those
 * values on the caller's own record. Without this rule a withdrawn VC could make themselves Active
 * (or Test) and read them. Found in review of mascode PR #64 / MCP PR #18 (M1, 2026-09-30).
 *
 * WHAT. For a caller who is not staff, a save is refused when it CHANGES:
 *   - whether the contact has the MAS_Rep sub-type (adding or removing it), or
 *   - any field in STAFF_ONLY_FIELDS of the MAS_Rep custom group.
 * A save that carries these values unchanged passes — the backend contact form always submits
 * contact_sub_type, and public forms update a VC's contact without touching them. The four fields
 * a VC maintains themselves (areas of expertise, skills, share-email consent) are not guarded.
 *
 * Pure functions over arrays, so the rules are unit-tested without CiviCRM; the subscriber
 * (Civi/Mascode/Event/VcRoleFieldGuardSubscriber.php) reads the stored values and consults this.
 */
final class VcRoleFieldPolicy
{
    public const SUB_TYPE = 'MAS_Rep';

    public const CUSTOM_GROUP = 'MAS_Rep';

    /** MAS_Rep field name => data type used to compare stored and incoming values. */
    public const STAFF_ONLY_FIELDS = [
        'VC_Status' => 'String',
        'Admin' => 'Boolean',
        'Board_Member' => 'Boolean',
        'Enrollment_Date' => 'Date',
        'End_Date' => 'Date',
    ];

    /** MAS "staff": any one of these (the same test as VcNativeScreenGuardSubscriber and the MCP). */
    public const STAFF_PERMISSIONS = ['view all contacts', 'edit all contacts', 'administer CiviCRM'];

    public const MESSAGE = 'Only MAS staff can change a volunteer consultant\'s status or role '
        . '(VC status, admin, board member, enrolment dates, or the Volunteer Consultant contact type).';

    /**
     * The sub-types in any form CiviCRM passes them: a list, a separator-delimited string
     * ("\x01A\x01B\x01"), a comma list, a single name, '' / 'null' / NULL for none.
     *
     * @return string[]
     */
    public static function subTypes(mixed $value): array
    {
        if ($value === null || $value === '' || $value === 'null' || $value === false) {
            return [];
        }
        $parts = is_array($value) ? $value : preg_split('/[\x01,]/', (string) $value);
        $out = [];
        foreach ((array) $parts as $p) {
            if (is_scalar($p) && trim((string) $p) !== '') {
                $out[] = trim((string) $p);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Whether a save changes the contact's MAS_Rep sub-type membership.
     *
     * @param array<string, mixed> $params the save's params; no contact_sub_type key = unchanged
     * @param mixed $stored the stored contact_sub_type (NULL for a new contact)
     */
    public static function changesVcSubType(array $params, mixed $stored): bool
    {
        if (!array_key_exists('contact_sub_type', $params)) {
            return false;
        }
        $before = in_array(self::SUB_TYPE, self::subTypes($stored), true);
        $after = in_array(self::SUB_TYPE, self::subTypes($params['contact_sub_type']), true);
        return $before !== $after;
    }

    /**
     * The staff-only fields whose incoming value differs from the stored one.
     *
     * @param array<string, mixed> $incoming field name => new value (only the fields being saved)
     * @param array<string, mixed> $stored field name => stored value (missing = empty)
     * @return string[] field names
     */
    public static function changedFields(array $incoming, array $stored): array
    {
        $changed = [];
        foreach (self::STAFF_ONLY_FIELDS as $name => $type) {
            if (!array_key_exists($name, $incoming)) {
                continue;
            }
            if (self::normalise($type, $incoming[$name]) !== self::normalise($type, $stored[$name] ?? null)) {
                $changed[] = $name;
            }
        }
        return $changed;
    }

    /**
     * One comparable form per type. Anything unrecognised compares as its trimmed string, so an
     * odd format reads as a change and is refused (fails closed) rather than slipping through.
     */
    public static function normalise(string $type, mixed $value): ?string
    {
        if (is_bool($value)) {
            // APIv4 returns a stored Boolean as a PHP bool; forms send '1' / '0'.
            return $type === 'Boolean' ? ($value ? '1' : '0') : ($value ? '1' : null);
        }
        if (is_array($value)) {
            $value = implode(',', array_map('strval', $value));
        }
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        switch ($type) {
            case 'Boolean':
                if (in_array(strtolower($s), ['1', 'true', 'yes'], true)) {
                    return '1';
                }
                if (in_array(strtolower($s), ['0', 'false', 'no'], true)) {
                    return '0';
                }
                return $s;

            case 'Date':
                // Stored 'Y-m-d' / 'Y-m-d H:i:s'; forms send 'YmdHis', 'Y-m-d' or 'm/d/Y'.
                if (preg_match('/^\d{14}$/', $s)) {
                    $t = \DateTime::createFromFormat('YmdHis', $s);
                }
                elseif (preg_match('/^\d{8}$/', $s)) {
                    $t = \DateTime::createFromFormat('Ymd', $s);
                }
                else {
                    $ts = strtotime($s);
                    $t = $ts === false ? false : (new \DateTime())->setTimestamp($ts);
                }
                return $t ? $t->format('Y-m-d') : $s;

            default:
                return $s;
        }
    }
}
