<?php

declare(strict_types=1);

// file: Civi/Mascode/Event/SystemActivitySourceSubscriber.php

namespace Civi\Mascode\Event;

use Civi\Core\Service\AutoSubscriber;
use Civi\Core\Event\PreEvent;
use Civi\Mascode\Util\SystemContact;
use Civi\Mascode\Util\SystemContext;

/**
 * Records system-generated activities against the system contact.
 *
 * Two jobs:
 *  1. While a SystemContext scope is open, every Activity CREATE gets
 *     SystemContact as its source — including the ones core writes as a side
 *     effect (Open Case, Assign Case Role), which take the logged-in user and
 *     cannot be told otherwise. hook_civicrm_pre runs before
 *     CRM_Activity_BAO_Activity::create() writes the source row, so changing
 *     the param here is what lands.
 *  2. A FormProcessor API call IS a scope. The web intake runs through one
 *     (maswpcode calls civicrm_api3('FormProcessor', <form>, …)), and its
 *     database configuration hard-codes contact 9480 as case creator and
 *     activity source. Covering it here fixes that without editing the
 *     FormProcessor config, and without a hard-coded id that differs between
 *     dev and prod.
 *
 * Only CREATE. An edit of an existing activity keeps its source: marking a
 * draft Completed must not re-attribute it.
 */
class SystemActivitySourceSubscriber extends AutoSubscriber
{
    public static function getSubscribedEvents(): array
    {
        return [
            'hook_civicrm_pre' => 'onPre',
            'civi.api.prepare' => 'onApiPrepare',
            'civi.api.respond' => 'onApiDone',
            'civi.api.exception' => 'onApiDone',
        ];
    }

    public function onPre(PreEvent $event): void
    {
        if (SystemContext::shouldStamp((string) $event->entity, (string) $event->action)) {
            $event->params['source_contact_id'] = SystemContact::id();
        }
    }

    /** @param \Civi\API\Event\PrepareEvent $event */
    public function onApiPrepare($event): void
    {
        SystemContext::openForApiRequest($event->getApiRequest());
    }

    /** @param \Civi\API\Event\RespondEvent|\Civi\API\Event\ExceptionEvent $event */
    public function onApiDone($event): void
    {
        SystemContext::closeForApiRequest($event->getApiRequest());
    }
}
