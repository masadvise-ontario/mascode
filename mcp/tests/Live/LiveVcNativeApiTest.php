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
 * VCs: the WordPress logins of the contacts in MCP_LIVE_VC_IDS (test.vc — whose login is its email
 * address — and VC B), plus MCP_LIVE_VC_USER; a named VC without a login fails. Read-only; asserts
 * ids and counts only.
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
    self::backToStaff();
  }

  /** Back to the staff login, checked, so a failure cannot leave later tests running as a VC. */
  private static function backToStaff(): void {
    $staff = (string) getenv('MCP_LIVE_USER');
    \CRM_Core_Config::singleton()->userSystem->loadUser($staff);
    if (!function_exists('wp_get_current_user') || wp_get_current_user()->user_login !== $staff) {
      throw new \RuntimeException('could not switch back to MCP_LIVE_USER');
    }
  }

  /** @return string[] the VCs' WordPress logins */
  private function logins(): array {
    $ids = array_filter(array_map('intval', explode(',', (string) getenv('MCP_LIVE_VC_IDS'))));
    $logins = [];
    foreach ($ids as $cid) {
      $uf = \Civi\Api4\UFMatch::get(FALSE)->addSelect('uf_id')->addWhere('contact_id', '=', $cid)->execute()->first();
      $user = $uf && function_exists('get_user_by') ? get_user_by('id', (int) $uf['uf_id']) : FALSE;
      $this->assertNotFalse($user, "VC contact $cid in MCP_LIVE_VC_IDS has no WordPress login");
      $logins[] = $user->user_login;
    }
    if (getenv('MCP_LIVE_VC_USER')) {
      $logins[] = (string) getenv('MCP_LIVE_VC_USER');
    }
    if (!$logins) {
      $this->markTestSkipped('Set MCP_LIVE_VC_IDS (test.vc and VC B).');
    }
    return array_values(array_unique($logins));
  }

  private function signIn(string $login): int {
    \CRM_Core_Config::singleton()->userSystem->loadUser($login);
    // Messages name the VC by contact id, never by login (test.vc's login is an email address).
    $this->assertSame($login, wp_get_current_user()->user_login, 'could not sign in as a test VC');
    $me = (int) \CRM_Core_Session::getLoggedInContactID();
    $this->assertGreaterThan(0, $me, 'a test VC login has no contact');
    $this->assertFalse(\CRM_Core_Permission::check([['view all contacts', 'edit all contacts', 'administer CiviCRM']]), "VC $me must not be staff");
    return $me;
  }

  public function testContactAndItsDetailsAreOnlyTheVcsOwn(): void {
    foreach ($this->logins() as $login) {
      $me = $this->signIn($login);
      $ids = array_map('intval', array_column(Contact::get(TRUE)->addSelect('id')->execute()->getArrayCopy(), 'id'));
      $this->assertSame([$me], $ids, "VC $me: Contact.get returns exactly the own contact");
      foreach (['Email', 'Phone', 'Address'] as $entity) {
        $rows = civicrm_api4($entity, 'get', ['select' => ['id', 'contact_id']])->getArrayCopy();
        $owned = array_filter($rows, fn($r) => $r['contact_id'] !== NULL);
        $owners = array_unique(array_map('intval', array_column($owned, 'contact_id')));
        $this->assertSame([], array_values(array_diff($owners, [$me])), "VC $me: $entity.get returns only own rows");
        $this->assertSame([], self::notVenue($entity, array_map('intval', array_column(array_filter($rows, fn($r) => $r['contact_id'] === NULL), 'id'))),
          "VC $me: $entity.get returns a contact-less row that is not an event venue");
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
    $checked = 0;
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
      self::backToStaff();
      $linked = [];
      foreach (array_chunk($ids, 500) as $chunk) {
        foreach (ActivityContact::get(FALSE)->addSelect('activity_id')->addWhere('activity_id', 'IN', $chunk)->addWhere('contact_id', '=', $me)->execute() as $r) {
          $linked[(int) $r['activity_id']] = TRUE;
        }
      }
      $unlinked = array_values(array_filter($ids, fn($id) => !isset($linked[$id])));
      // A VC linked to live activities must get some back (per VC, not only in total).
      $links = ActivityContact::get(FALSE)->addWhere('contact_id', '=', $me)->addWhere('activity_id.is_deleted', '=', FALSE)
        ->addWhere('activity_id.is_current_revision', '=', TRUE)->addWhere('activity_id.is_test', '=', FALSE)->execute()->count();
      if ($links) {
        $this->assertNotEmpty($ids, "VC $me: linked to $links activity row(s) but Activity.get returned none");
      }
      $this->assertSame([], $unlinked, "VC $me: Activity.get returned " . count($unlinked) . ' activity(ies) the VC is not linked to');
      $checked += count($ids);
    }
    // A VC with real scope has linked activities; an empty result everywhere would prove nothing.
    $this->assertGreaterThan(0, $checked, 'Activity.get returned nothing for any VC');
  }

}
