<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Api4\Activity;
use Civi\Api4\ActivityContact;
use Civi\Api4\Contact;
use PHPUnit\Framework\TestCase;

/**
 * T10 gap (4), D37: the MCP widens a VC's reach only inside ScopedQuery, so CiviCRM's own API,
 * called as the VC with permission checks on, must stay locked down — `Contact.get` returns exactly
 * the VC's own contact, their email / phone / address rows are their own, and `Activity.get` returns
 * only activities the VC is linked to. This is what the portal and any other native caller see.
 *
 * VCs: the WordPress logins `test.vc` and MCP_LIVE_VC_USER (non-staff). Read-only; asserts ids and
 * counts only.
 *
 * @group live
 */
class LiveVcNativeApiTest extends TestCase {

  protected function setUp(): void {
    if (!getenv('MCP_LIVE_USER') || !class_exists('Civi')) {
      $this->markTestSkipped('Set MCP_LIVE_USER and run inside a CiviCRM site.');
    }
  }

  protected function tearDown(): void {
    \CRM_Core_Config::singleton()->userSystem->loadUser(getenv('MCP_LIVE_USER'));
  }

  /** @return string[] the VC logins present on this site */
  private function logins(): array {
    $logins = array_values(array_unique(array_filter(['test.vc', (string) getenv('MCP_LIVE_VC_USER')])));
    $logins = array_values(array_filter($logins, fn($l) => function_exists('get_user_by') && get_user_by('login', $l)));
    if (!$logins) {
      $this->markTestSkipped('No test.vc login and no MCP_LIVE_VC_USER.');
    }
    return $logins;
  }

  private function signIn(string $login): int {
    \CRM_Core_Config::singleton()->userSystem->loadUser($login);
    $me = (int) \CRM_Core_Session::getLoggedInContactID();
    $this->assertGreaterThan(0, $me, "could not sign in as $login");
    $this->assertFalse(\CRM_Core_Permission::check([['view all contacts', 'edit all contacts', 'administer CiviCRM']]), "$login must not be staff");
    return $me;
  }

  public function testContactAndItsDetailsAreOnlyTheVcsOwn(): void {
    foreach ($this->logins() as $login) {
      $me = $this->signIn($login);
      $ids = array_map('intval', array_column(Contact::get(TRUE)->addSelect('id')->execute()->getArrayCopy(), 'id'));
      $this->assertSame([$me], $ids, "$login: Contact.get returns exactly the own contact");
      foreach (['Email', 'Phone', 'Address'] as $entity) {
        $rows = civicrm_api4($entity, 'get', ['select' => ['id', 'contact_id']])->getArrayCopy();
        $owned = array_filter($rows, fn($r) => $r['contact_id'] !== NULL);
        $owners = array_unique(array_map('intval', array_column($owned, 'contact_id')));
        $this->assertSame([], array_values(array_diff($owners, [$me])), "$login: $entity.get returns only own rows");
        $this->assertSame([], self::notVenue($entity, array_map('intval', array_column(array_filter($rows, fn($r) => $r['contact_id'] === NULL), 'id'))),
          "$login: $entity.get returns a contact-less row that is not an event venue");
      }
    }
  }

  /**
   * Of $ids — rows with no contact — those no LocBlock references. A contact-less email, phone or
   * address is an event venue's (CiviCRM's location block, shown on public event pages), which
   * CiviCRM does not guard by contact ACL; anything else would be unexplained.
   *
   * @param int[] $ids
   * @return int[]
   */
  private static function notVenue(string $entity, array $ids): array {
    if (!$ids) {
      return [];
    }
    $key = strtolower($entity);
    $venue = [];
    foreach (\Civi\Api4\LocBlock::get(FALSE)->addSelect("{$key}_id", "{$key}_2_id")->execute() as $b) {
      $venue[(int) $b["{$key}_id"]] = TRUE;
      $venue[(int) $b["{$key}_2_id"]] = TRUE;
    }
    return array_values(array_filter($ids, fn($id) => !isset($venue[$id])));
  }

  public function testActivityGetReturnsOnlyLinkedActivities(): void {
    foreach ($this->logins() as $login) {
      $me = $this->signIn($login);
      $ids = [];
      $last = 0;
      do {
        $page = array_map('intval', array_column(Activity::get(TRUE)->addSelect('id')->addWhere('id', '>', $last)
          ->addOrderBy('id')->setLimit(500)->execute()->getArrayCopy(), 'id'));
        $ids = array_merge($ids, $page);
        $last = $page ? end($page) : $last;
      } while (count($page) === 500);
      // As staff again, which of those is the VC linked to (source, target or assignee)?
      \CRM_Core_Config::singleton()->userSystem->loadUser(getenv('MCP_LIVE_USER'));
      $linked = [];
      foreach (array_chunk($ids, 500) as $chunk) {
        foreach (ActivityContact::get(FALSE)->addSelect('activity_id')->addWhere('activity_id', 'IN', $chunk)->addWhere('contact_id', '=', $me)->execute() as $r) {
          $linked[(int) $r['activity_id']] = TRUE;
        }
      }
      $unlinked = array_values(array_filter($ids, fn($id) => !isset($linked[$id])));
      $this->assertSame([], $unlinked, "$login: Activity.get returned " . count($unlinked) . ' activity(ies) the VC is not linked to');
      $this->addToAssertionCount(count($ids));
    }
  }

}
