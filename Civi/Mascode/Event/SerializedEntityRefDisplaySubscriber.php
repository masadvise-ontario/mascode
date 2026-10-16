<?php

declare(strict_types=1);

// file: Civi/Mascode/Event/SerializedEntityRefDisplaySubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Mascode\Util\EntityRefIds;

/**
 * Works around a core bug that made View Contribution fatal for every linked
 * donation (handoff #1174; seen live at the 2026-10-15 demo).
 *
 * CRM_Core_BAO_CustomField::formatDisplayValue() renders an EntityReference
 * field with `Entity.autocomplete` and `'ids' => [$value]`. For a multi-value
 * (serialized) field $value is already an array, so the ids arrive nested and
 * the SQL builder throws a TypeError (mysqli_real_escape_string(): Argument #2
 * must be of type string). Donation_Link.Linked_Project and Linked_VC are
 * serialized since R10 (upgrade_5020), so every contribution with either set
 * crashed the classic view screen, wherever it was opened from. Unfixed on
 * core master as of 2026-10-16.
 *
 * Two parts, both generic to any serialized EntityReference field:
 *  1. civi.api.prepare: flatten a nested `ids` on an autocomplete request.
 *     That stops the fatal. A nested array is never valid input, and render
 *     mode still runs under the caller's own permission check.
 *  2. alterCustomFieldDisplayValue: core then calls ->single(), which blanks
 *     the value when two or more ids resolve (a gift split over projects).
 *     Rebuild the display from every id's autocomplete label, the same label
 *     and permission bypass core uses for a single-value field.
 *
 * Remove both once core handles serialized EntityReference display.
 * Nothing here may break a page: failures are logged and core's own display
 * is kept.
 */
class SerializedEntityRefDisplaySubscriber extends AutoSubscriber
{
    public static function getSubscribedEvents(): array
    {
        return [
            'civi.api.prepare' => 'onApiPrepare',
            'hook_civicrm_alterCustomFieldDisplayValue' => 'onAlterDisplayValue',
        ];
    }

    /** @param \Civi\API\Event\PrepareEvent $event */
    public function onApiPrepare($event): void
    {
        $request = $event->getApiRequest();
        if (!$request instanceof \Civi\Api4\Generic\AutocompleteAction) {
            return;
        }
        // APIv4 parameter getters are magic; read through getParams().
        $ids = $request->getParams()['ids'] ?? null;
        if (!is_array($ids)) {
            return;
        }
        $flat = EntityRefIds::flatten($ids);
        if ($flat !== null) {
            $request->setIds($flat);
        }
    }

    /** @param \Civi\Core\Event\GenericHookEvent $event */
    public function onAlterDisplayValue($event): void
    {
        $field = $event->fieldInfo;
        if (
            !is_array($field)
            || ($field['data_type'] ?? null) !== 'EntityReference'
            || empty($field['serialize'])
            || empty($field['fk_entity'])
        ) {
            return;
        }
        $ids = EntityRefIds::parse($event->value);
        if (!$ids) {
            return;
        }
        try {
            $rows = civicrm_api4($field['fk_entity'], 'autocomplete', [
                'checkPermissions' => false,
                'ids' => $ids,
            ]);
            $labels = [];
            foreach ($rows as $row) {
                $labels[(int) $row['id']] = (string) ($row['label'] ?? '');
            }
            $ordered = [];
            foreach ($ids as $id) {
                if (isset($labels[$id]) && $labels[$id] !== '') {
                    $ordered[] = $labels[$id];
                }
            }
            if ($ordered) {
                $event->displayValue = implode(', ', $ordered);
            }
        }
        catch (\Throwable $e) {
            \Civi::log()->warning('mascode: serialized EntityReference display failed for field {field}: {msg}', [
                'field' => $field['name'] ?? '?',
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
