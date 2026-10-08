<?php

declare(strict_types=1);

// file: Civi/Mascode/Event/DonationSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Core\Event\PostEvent;
use Civi\Mascode\Service\DonationLinker;
use Civi\Mascode\Service\DonationNotifier;

/**
 * Donations tickets DN-1/2/3 (spec BrianPKM 3-Resources/mas-donation-process.md).
 *
 *  - After a Contribution is saved: add each linked project's sole
 *    coordinator where no credited VC covers it, refresh the view-only
 *    project codes, then send any donation notifications due.
 *  - Any Donation_Link write refreshes the codes; a contact merge moves VC
 *    credit to the survivor (core does not, for a serialized EntityReference).
 *  - The contribution form's "Project" picker (custom EntityReference to Case)
 *    offers Project cases only. Core stores no filter for EntityReference
 *    custom fields, so the restriction is added as a trusted filter on the
 *    Case.autocomplete request that the field makes.
 *  - R1, contact-first entry (spec §7): js/donation-contribution-form.js puts
 *    the chosen contributor and projects into the autocomplete's `values`, and
 *    onApiPrepare narrows Projects to that contact's projects and Volunteer
 *    Consultants to the coordinators of every picked project (R10). `values` comes from the
 *    browser, so it may only NARROW: it is honoured only for staff who can
 *    edit contributions, and with no value the pickers behave as before.
 *    It is a data-entry convenience, NOT enforcement: a submitted id is not
 *    checked against the lists. So it steps aside rather than leave a dead
 *    end: not when rendering a saved value (`ids`), not when the contact has
 *    no projects (e.g. an individual), not when the projects have no
 *    coordinator.
 *  - R7: the legacy "Donation" type is hidden from NEW contributions. It is
 *    not disabled: core's edit form lists active types only, so editing one
 *    of the historical gifts would blank its type and invite a re-type, which
 *    writes adjusting financial transactions.
 *
 * postCommit, not post: inside a transaction it runs only after the save has
 * committed, so mail never goes out about a contribution that then rolls back.
 * (Custom values are already stored by `post` time, since BAO
 * Contribution::add() writes them first. With no transaction open, core runs
 * postCommit immediately at `post`, which is still correct.)
 *
 * Nothing here may break a contribution save, because staff are entering
 * money. Every failure is logged and swallowed.
 */
class DonationSubscriber extends AutoSubscriber
{
    /** The fieldName the contribution form passes to Case.autocomplete for Linked_Project. */
    public const PROJECT_FIELD_NAME = 'Contribution.' . DonationLinker::FIELD_PROJECT;

    /** The fieldName the contribution form passes to Contact.autocomplete for Linked_VC. */
    public const VC_FIELD_NAME = 'Contribution.' . DonationLinker::FIELD_VC;

    /** The pre-DN-1 financial type: kept for history, not offered for new entries (R7). */
    public const LEGACY_TYPE = 'Donation';

    private const FORM = 'CRM_Contribute_Form_Contribution';

    /**
     * R1 narrowing, by autocomplete fieldName, from onApiPrepare to
     * onAutocompleteDefault. It must be a WHERE clause on the search, not a
     * filter: AutocompleteAction writes the typed text into
     * filters[<search field>], and `id` is a search field, so an `id` filter
     * is overwritten by the input.
     *
     * @var array<string,int[]>
     */
    private static array $narrowTo = [];

    public static function getSubscribedEvents(): array
    {
        return [
            'hook_civicrm_postCommit' => 'onPostCommit',
            'hook_civicrm_custom' => 'onCustom',
            'hook_civicrm_merge' => 'onMerge',
            'civi.api.prepare' => 'onApiPrepare',
            // After core's providers (priority 0), which replace api_params wholesale.
            'civi.search.autocompleteDefault' => ['onAutocompleteDefault', -100],
            'hook_civicrm_buildForm' => 'onBuildForm',
            'hook_civicrm_validateForm' => 'onValidateForm',
        ];
    }

    public function onPostCommit(PostEvent $event): void
    {
        if ($event->entity !== 'Contribution' || !in_array($event->action, ['create', 'edit'], true) || !$event->id) {
            return;
        }
        $id = (int) $event->id;
        try {
            DonationLinker::fillVc($id);
            DonationLinker::refreshCodes($id);
        }
        catch (\Throwable $e) {
            \Civi::log()->error("DonationSubscriber: filling Linked VC / project codes for contribution $id failed: " . $e->getMessage());
        }
        try {
            DonationNotifier::notify($id);
        }
        catch (\Throwable $e) {
            \Civi::log()->error("DonationSubscriber: notifications for contribution $id failed: " . $e->getMessage());
        }
    }

    /**
     * Keep the view-only project codes in step with ANY write to the
     * Donation_Link values (CustomValue API, imports), not only contribution
     * saves. refreshCodes() writes only when the codes differ, so its own
     * write re-enters here once and stops.
     *
     * @param \Civi\Core\Event\GenericHookEvent $event
     */
    public function onCustom($event): void
    {
        if (!in_array($event->op ?? '', ['create', 'edit'], true) || !$event->entityID) {
            return;
        }
        try {
            if ((int) $event->groupID === DonationLinker::groupId()) {
                DonationLinker::refreshCodes((int) $event->entityID);
            }
        }
        catch (\Throwable $e) {
            \Civi::log()->error('DonationSubscriber: refreshing project codes for contribution ' . (int) $event->entityID . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Contact merge: move VC credit to the surviving contact
     * (DonationLinker::mergeSql()).
     *
     * @param \Civi\Core\Event\GenericHookEvent $event
     */
    public function onMerge($event): void
    {
        if (($event->type ?? '') !== 'sqls' || !$event->mainId || !$event->otherId) {
            return;
        }
        $vc = DonationLinker::vcColumn();
        if ($vc) {
            $event->data[] = DonationLinker::mergeSql($vc[0], $vc[1], (int) $event->mainId, (int) $event->otherId);
        }
    }

    /** @param \Civi\API\Event\PrepareEvent $event */
    public function onApiPrepare($event): void
    {
        $request = $event->getApiRequest();
        if (!$request instanceof \Civi\Api4\Generic\AutocompleteAction) {
            return;
        }
        // APIv4 parameter getters are magic; read through getParams().
        $params = $request->getParams();
        $field = $params['fieldName'] ?? null;
        $entity = $request->getEntityName();
        unset(self::$narrowTo[(string) $field]);
        if (!empty($params['ids'])) {
            // Render mode: show the saved value even if it is off the list.
            return;
        }
        if ($entity === 'Case' && $field === self::PROJECT_FIELD_NAME) {
            $request->addFilter('case_type_id:name', 'project');
            $request->addFilter('is_deleted', false);
            $contactId = self::knownValue($params, 'contact_id');
            if ($contactId) {
                $ids = DonationLinker::projectIdsForClient($contactId);
                if ($ids) {
                    self::$narrowTo[$field] = $ids;
                }
            }
        }
        elseif ($entity === 'Contact' && $field === self::VC_FIELD_NAME) {
            $ids = [];
            foreach (self::knownValues($params, DonationLinker::FIELD_PROJECT) as $projectId) {
                array_push($ids, ...DonationLinker::coordinatorsFor($projectId));
            }
            if ($ids) {
                self::$narrowTo[$field] = array_values(array_unique($ids));
            }
        }
    }

    /** @param \Civi\Core\Event\GenericHookEvent $event */
    public function onAutocompleteDefault($event): void
    {
        $field = (string) ($event->fieldName ?? '');
        if (!isset(self::$narrowTo[$field]) || !is_array($event->savedSearch)) {
            return;
        }
        $event->savedSearch['api_params']['where'][] = ['id', 'IN', self::$narrowTo[$field]];
        unset(self::$narrowTo[$field]);
    }

    /**
     * A positive integer from the autocomplete's `values`, or NULL. Only for
     * staff who can edit contributions: the role list is read without
     * permission checks, so anyone else must not be able to ask "who
     * coordinated case N" through it.
     */
    private static function knownValue(array $params, string $key): ?int
    {
        $v = DonationLinker::positiveInt($params['values'][$key] ?? null);
        return $v && \CRM_Core_Permission::check('edit contributions') ? $v : null;
    }

    /**
     * Positive integers from a `values` entry that may list several ("12,34"),
     * capped at 20: a list is only ever a handful of projects. Same permission
     * rule as knownValue().
     *
     * @return int[]
     */
    private static function knownValues(array $params, string $key): array
    {
        $ids = array_slice(DonationLinker::ids($params['values'][$key] ?? null), 0, 20);
        return $ids && \CRM_Core_Permission::check('edit contributions') ? $ids : [];
    }

    /** @param \Civi\Core\Event\GenericHookEvent $event */
    public function onBuildForm($event): void
    {
        // The custom-data block (Projects, VCs) is loaded into the
        // contribution form by this AJAX form; only the two fields are touched.
        if ($event->formName === 'CRM_Custom_Form_CustomDataByType') {
            self::multiSelectPickers($event->form);
            return;
        }
        if ($event->formName !== self::FORM) {
            return;
        }
        $form = $event->form;
        \Civi::resources()->addScriptFile('mascode', 'js/donation-contribution-form.js');
        self::multiSelectPickers($form);
        if (!self::isNewEntry($form) || !$form->elementExists('financial_type_id')) {
            return;
        }
        $legacyId = self::legacyTypeId();
        $select = $form->getElement('financial_type_id');
        if ($legacyId && $select instanceof \HTML_QuickForm_select) {
            $select->_options = DonationLinker::withoutOption($select->_options, $legacyId);
        }
    }

    /**
     * R10: the Projects and Volunteer Consultants pickers hold several values.
     * Core renders a serialized EntityReference custom field single-select on
     * this form, so the select params are set here, before the widget is
     * created (re-creating it in the browser races core's label lookup and
     * throws in select2). minimumInputLength 0 opens the short, narrowed lists
     * on click (R1).
     */
    private static function multiSelectPickers($form): void
    {
        foreach ($form->_elements ?? [] as $element) {
            if (!$element instanceof \HTML_Common) {
                continue;
            }
            $api = json_decode((string) $element->getAttribute('data-api-params'), true);
            if (!in_array($api['fieldName'] ?? null, [self::PROJECT_FIELD_NAME, self::VC_FIELD_NAME], true)) {
                continue;
            }
            $select = json_decode((string) $element->getAttribute('data-select-params'), true) ?: [];
            $element->setAttribute('data-select-params', json_encode(array_merge($select, ['multiple' => true, 'minimumInputLength' => 0])));
        }
    }

    /** @param \Civi\Core\Event\GenericHookEvent $event */
    public function onValidateForm($event): void
    {
        if ($event->formName !== self::FORM || !self::isNewEntry($event->form)) {
            return;
        }
        $legacyId = self::legacyTypeId();
        if ($legacyId && (int) ($event->fields['financial_type_id'] ?? 0) === $legacyId) {
            $event->errors['financial_type_id'] = ts('"Donation" is kept for past gifts only. For a gift, choose Client Donation, Private Donation or CAF Donation.');
        }
    }

    private static function isNewEntry($form): bool
    {
        return (bool) ($form->getAction() & \CRM_Core_Action::ADD);
    }

    private static function legacyTypeId(): ?int
    {
        $id = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', self::LEGACY_TYPE);
        return $id ? (int) $id : null;
    }
}
