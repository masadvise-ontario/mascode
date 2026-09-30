<?php

// File: Civi/Mascode/Event/VcRoleFieldGuardSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Event\PreEvent;
use Civi\Core\Service\AutoSubscriber;
use Civi\Mascode\Security\VcRoleFieldPolicy;

/**
 * Refuses a non-staff change to a contact's volunteer consultant standing — the MAS_Rep sub-type
 * and the staff-only MAS_Rep fields (VcRoleFieldPolicy, which holds the rules and the why).
 *
 * Two hooks, because the values arrive by two routes:
 *   - hook_civicrm_pre on a contact: the sub-type, and custom values sent with the contact save
 *     (`params['custom']`, the APIv4 / BAO route). Checked here so the save aborts before the
 *     contact row is written. It is also the only guard on REMOVING the sub-type: core then deletes
 *     the sub-type's custom rows by direct SQL, with no customPre.
 *   - hook_civicrm_customPre on the MAS_Rep group: every other custom-value write (inline custom
 *     data edit, the CustomValue API, profiles).
 * Throwing aborts the save; API calls roll back in their transaction.
 *
 * Staff (VcRoleFieldPolicy::STAFF_PERMISSIONS) are unaffected. A process with no signed-in user
 * (cron) counts as non-staff; nothing scheduled writes these fields (checked 2026-09-30).
 */
class VcRoleFieldGuardSubscriber extends AutoSubscriber
{
    private const CONTACT_TYPES = ['Individual', 'Organization', 'Household', 'Contact'];

    /** @var array<int, string>|null MAS_Rep custom field id => name */
    private static ?array $fields = null;

    private static ?int $groupId = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'hook_civicrm_pre' => 'onPre',
            'hook_civicrm_customPre' => 'onCustomPre',
        ];
    }

    /** hook_civicrm_pre is a PreEvent: action / entity / id / params (not op / objectName). */
    public function onPre(PreEvent $event): void
    {
        if (!in_array($event->entity, self::CONTACT_TYPES, true) || !in_array($event->action, ['create', 'edit'], true)) {
            return;
        }
        $params = is_array($event->params) ? $event->params : [];
        $id = $event->action === 'edit' ? (int) $event->id : 0;
        $incoming = $this->incomingFromContactParams($params);
        if (!array_key_exists('contact_sub_type', $params) && !$incoming) {
            return;
        }
        if (self::isStaff()) {
            return;
        }
        $stored = $id ? $this->stored($id) : [];
        if (VcRoleFieldPolicy::changesVcSubType($params, $stored['contact_sub_type'] ?? null)
            || VcRoleFieldPolicy::changedFields($incoming, $stored)) {
            $this->refuse($id, 'contact save');
        }
    }

    /** hook_civicrm_customPre: op / groupID / entityID / params (a list of field arrays). */
    public function onCustomPre(GenericHookEvent $event): void
    {
        if ((int) $event->groupID !== self::groupId()) {
            return;
        }
        $incoming = [];
        $names = self::fieldNames();
        foreach ((array) $event->params as $field) {
            $name = $names[(int) ($field['custom_field_id'] ?? 0)] ?? null;
            if ($name !== null) {
                $incoming[$name] = $field['value'] ?? null;
            }
        }
        if (!array_intersect_key($incoming, VcRoleFieldPolicy::STAFF_ONLY_FIELDS) || self::isStaff()) {
            return;
        }
        $id = (int) $event->entityID;
        if (VcRoleFieldPolicy::changedFields($incoming, $id ? $this->stored($id) : [])) {
            $this->refuse($id, 'custom data save');
        }
    }

    /** @return array<string, mixed> staff-only field name => value, from `params['custom']` */
    private function incomingFromContactParams(array $params): array
    {
        $out = [];
        if (!is_array($params['custom'] ?? null)) {
            return $out;
        }
        $names = self::fieldNames();
        foreach ($params['custom'] as $fieldId => $rows) {
            $name = $names[(int) $fieldId] ?? null;
            if ($name === null || !isset(VcRoleFieldPolicy::STAFF_ONLY_FIELDS[$name]) || !is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $out[$name] = is_array($row) ? ($row['value'] ?? null) : null;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> the stored sub-type and staff-only fields, keyed by field name */
    private function stored(int $contactId): array
    {
        $select = ['contact_sub_type'];
        foreach (array_keys(VcRoleFieldPolicy::STAFF_ONLY_FIELDS) as $name) {
            $select[] = VcRoleFieldPolicy::CUSTOM_GROUP . '.' . $name;
        }
        $row = \Civi\Api4\Contact::get(false)
            ->setSelect($select)
            ->addWhere('id', '=', $contactId)
            ->execute()->first() ?? [];
        $out = ['contact_sub_type' => $row['contact_sub_type'] ?? null];
        foreach (array_keys(VcRoleFieldPolicy::STAFF_ONLY_FIELDS) as $name) {
            $out[$name] = $row[VcRoleFieldPolicy::CUSTOM_GROUP . '.' . $name] ?? null;
        }
        return $out;
    }

    private function refuse(int $contactId, string $route): void
    {
        \Civi::log()->warning('VC role field change refused for a non-staff user ({route}, contact {cid})', [
            'route' => $route,
            'cid' => $contactId,
        ]);
        throw new \CRM_Core_Exception(VcRoleFieldPolicy::MESSAGE);
    }

    private static function isStaff(): bool
    {
        return \CRM_Core_Permission::check([VcRoleFieldPolicy::STAFF_PERMISSIONS]);
    }

    private static function groupId(): int
    {
        return self::$groupId ??= (int) (\Civi\Api4\CustomGroup::get(false)
            ->addSelect('id')
            ->addWhere('name', '=', VcRoleFieldPolicy::CUSTOM_GROUP)
            ->execute()->first()['id'] ?? -1);
    }

    /** @return array<int, string> */
    private static function fieldNames(): array
    {
        if (self::$fields === null) {
            self::$fields = [];
            foreach (\Civi\Api4\CustomField::get(false)
                ->addSelect('id', 'name')
                ->addWhere('custom_group_id:name', '=', VcRoleFieldPolicy::CUSTOM_GROUP)
                ->execute() as $f) {
                self::$fields[(int) $f['id']] = (string) $f['name'];
            }
        }
        return self::$fields;
    }
}
