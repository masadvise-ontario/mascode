<?php

declare(strict_types=1);

// file: Civi/Mascode/Util/EntityRefIds.php

namespace Civi\Mascode\Util;

/**
 * Id handling for multi-value (serialized) EntityReference custom fields.
 * No CiviCRM dependency, so the unit suite covers it on CI.
 *
 * See SerializedEntityRefDisplaySubscriber for why this exists.
 */
final class EntityRefIds
{
    /** CRM_Core_DAO::VALUE_SEPARATOR, repeated so this class needs no CiviCRM. */
    private const SEP = "\x01";

    /**
     * The flat id list for an autocomplete `ids` param, or NULL when it is
     * already flat (nothing to change). Core's display code passes
     * `[$value]` where $value is itself the field's id array, so a nested
     * array is always that bug and never a caller's intent.
     *
     * @param array<mixed> $ids
     * @return int[]|null
     */
    public static function flatten(array $ids): ?array
    {
        $nested = false;
        foreach ($ids as $id) {
            if (is_array($id)) {
                $nested = true;
                break;
            }
        }
        if (!$nested) {
            return null;
        }
        $flat = [];
        array_walk_recursive($ids, function ($id) use (&$flat) {
            $flat[] = $id;
        });
        return self::ints($flat);
    }

    /**
     * The ids held in a stored or submitted field value: an array, a
     * separator-padded string ("\x0112\x0134\x01"), a comma list (the
     * default-value widget), or a single id.
     *
     * @param mixed $value
     * @return int[]
     */
    public static function parse($value): array
    {
        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, function ($id) use (&$flat) {
                $flat[] = $id;
            });
            return self::ints($flat);
        }
        if (is_int($value)) {
            return self::ints([$value]);
        }
        if (!is_string($value)) {
            return [];
        }
        return self::ints(preg_split('/[' . self::SEP . ',]/', $value) ?: []);
    }

    /**
     * Positive integer ids, de-duplicated, first-seen order kept.
     *
     * @param array<mixed> $values
     * @return int[]
     */
    private static function ints(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (is_int($v) || (is_string($v) && ctype_digit(trim($v)))) {
                $id = (int) $v;
                if ($id > 0 && !in_array($id, $out, true)) {
                    $out[] = $id;
                }
            }
        }
        return $out;
    }
}
