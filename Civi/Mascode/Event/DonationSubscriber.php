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
 *  - After a Contribution is saved: fill its Linked VC from the Linked
 *    Project's Case Coordinator, then send any donation notifications due.
 *  - The contribution form's "Project" picker (custom EntityReference to Case)
 *    offers Project cases only. Core stores no filter for EntityReference
 *    custom fields, so the restriction is added as a trusted filter on the
 *    Case.autocomplete request that the field makes.
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

    public static function getSubscribedEvents(): array
    {
        return [
            'hook_civicrm_postCommit' => 'onPostCommit',
            'civi.api.prepare' => 'onApiPrepare',
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
        }
        catch (\Throwable $e) {
            \Civi::log()->error("DonationSubscriber: filling Linked VC for contribution $id failed: " . $e->getMessage());
        }
        try {
            DonationNotifier::notify($id);
        }
        catch (\Throwable $e) {
            \Civi::log()->error("DonationSubscriber: notifications for contribution $id failed: " . $e->getMessage());
        }
    }

    /** @param \Civi\API\Event\PrepareEvent $event */
    public function onApiPrepare($event): void
    {
        $request = $event->getApiRequest();
        if (!$request instanceof \Civi\Api4\Generic\AutocompleteAction || $request->getEntityName() !== 'Case') {
            return;
        }
        // APIv4 parameter getters are magic; read through getParams().
        $params = $request->getParams();
        if (($params['fieldName'] ?? null) !== self::PROJECT_FIELD_NAME) {
            return;
        }
        $request->addFilter('case_type_id:name', 'project');
        $request->addFilter('is_deleted', false);
    }
}
