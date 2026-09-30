<?php

namespace Civi\Mascode\Mcp\Vc;

use Civi\Core\Service\AutoSubscriber;
use Civi\Mascode\Mcp\MasTools;
use Civi\Mcp\Protocol\McpServer;
use Civi\Mcp\Scoped\Api4Executor;
use Civi\Mcp\Tools\CollectToolsEvent;

/**
 * Registers the VC tools (VcTools) on `civi.mcp.tools` with the site's policy, executor, field
 * metadata, directory and active-VC check. Withheld from staff by MasTools::STAFF.
 */
class VcToolsSubscriber extends AutoSubscriber {

  public static function getSubscribedEvents(): array {
    return [CollectToolsEvent::NAME => 'onCollect'];
  }

  public function onCollect(CollectToolsEvent $event): void {
    $tools = new VcTools(
      fn() => VcScopePolicy::forSite(),
      \Closure::fromCallable(new Api4Executor()),
      // Labels and option lists only, read with the caller's permissions (a VC may read field
      // metadata); VcTools describes only the fields the policy lists.
      function (string $entity): array {
        $out = [];
        try {
          $fields = civicrm_api4($entity, 'getFields', [
            'checkPermissions' => TRUE,
            'action' => 'get',
            'loadOptions' => ['name', 'label'],
            'select' => ['name', 'title', 'description', 'data_type', 'options'],
          ]);
        }
        catch (\Throwable $e) {
          // Titles are a convenience: without them vc_describe still lists the policy's fields.
          \Civi::log('mcp')->warning('vc_describe metadata unavailable: {class}: {message}', ['class' => get_class($e), 'message' => McpServer::isDatabaseError($e) ? '(database error; message withheld)' : mb_substr($e->getMessage(), 0, 200)]);
          return [];
        }
        foreach ($fields as $f) {
          $out[$f['name']] = $f;
        }
        return $out;
      },
      // The directory's own rule for its caller (mascode SavedSearch_MAS_VC_Directory.mgd.php), read
      // with the caller's permissions (a VC can read their own contact) and checked in PHP.
      fn(int $contactId): bool => VcTools::isEligibleVcRow(
        \Civi\Api4\Contact::get(TRUE)
          ->addSelect('id', 'contact_sub_type', 'MAS_Rep.VC_Status:name', 'is_deleted')
          ->addWhere('id', '=', $contactId)
          ->execute()->first(),
        $contactId,
      ),
      VcDirectory::display(),
      function (string $reason): void {
        \Civi::log('mcp')->warning('VC tool refused: {reason}', ['reason' => $reason]);
      },
    );
    foreach ($tools->abilities(MasTools::STAFF) as $ability) {
      $event->add($ability);
    }
  }

}
