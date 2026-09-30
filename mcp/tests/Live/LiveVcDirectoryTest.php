<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Api4\Contact;
use Civi\Api4\SearchDisplay;
use Civi\Mascode\Mcp\Vc\VcDirectory;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * Level 3 for T28 (D27): the VC directory display, run AS A VC — a non-staff WordPress login in
 * MCP_LIVE_VC_USER, whose contact is an active VC. The reference sets are read first as
 * MCP_LIVE_USER (staff). Read-only. Comparisons that hold contact data go through same(), so a
 * failure prints counts, never a name or an email (masdemo is a copy of production).
 *
 * Besides the tool path, each adversarial case is also sent straight to SearchDisplay.run, the way a
 * VC's browser could send it: the display is the boundary, so it must hold without this extension.
 *
 * @group live
 */
class LiveVcDirectoryTest extends TestCase {

  private ?array $ref = NULL;

  protected function setUp(): void {
    if (!getenv('MCP_LIVE_USER') || !getenv('MCP_LIVE_VC_USER') || !class_exists('Civi')) {
      $this->markTestSkipped('Set MCP_LIVE_USER (staff) and MCP_LIVE_VC_USER (a non-staff VC login).');
    }
    // Reference, read as staff with permissions off: active VCs, and the opted-in ones' emails.
    $rows = Contact::get(FALSE)
      ->addSelect('id', 'MAS_Rep.Share_Email_with_VC_s', 'email_primary.email')
      ->addWhere('contact_sub_type', 'CONTAINS', 'MAS_Rep')
      ->addWhere('MAS_Rep.VC_Status:name', '=', 'Active')
      ->addWhere('is_deleted', '=', FALSE)
      ->addWhere('is_deceased', '=', FALSE)
      ->execute();
    $active = $shared = $hidden = [];
    foreach ($rows as $r) {
      $active[] = (int) $r['id'];
      if (!empty($r['MAS_Rep.Share_Email_with_VC_s'])) {
        $shared[(int) $r['id']] = $r['email_primary.email'];
      }
      elseif (!empty($r['email_primary.email'])) {
        $hidden[(int) $r['id']] = $r['email_primary.email'];
      }
    }
    sort($active);
    $this->assertNotEmpty($hidden, 'needs at least one active VC who has an email and did not opt in');
    $test = array_map('intval', Contact::get(FALSE)->addSelect('id')->addWhere('contact_sub_type', 'CONTAINS', 'MAS_Rep')
      ->addWhere('MAS_Rep.VC_Status:name', '=', 'Test')->execute()->column('id'));
    $this->ref = ['active' => $active, 'shared' => $shared, 'hidden' => $hidden, 'test' => $test];
    $this->loginAs(getenv('MCP_LIVE_VC_USER'));
    $this->assertFalse(\CRM_Core_Permission::check([['view all contacts', 'edit all contacts', 'administer CiviCRM']]), 'MCP_LIVE_VC_USER must not be staff');
    $this->assertFalse(\CRM_Core_Permission::check('all CiviCRM permissions and ACLs'));
  }

  protected function tearDown(): void {
    if ($this->ref !== NULL) {
      $this->loginAs(getenv('MCP_LIVE_USER'));
    }
  }

  private function loginAs(string $login): void {
    \CRM_Core_Config::singleton()->userSystem->loadUser($login);
    $this->assertNotEmpty(\CRM_Core_Session::getLoggedInContactID(), "could not log in as $login");
  }

  /** assertSame without the diff: a failure reports sizes only. */
  private function same(array $expected, array $actual, string $what): void {
    ksort($expected);
    ksort($actual);
    $this->assertTrue($expected === $actual, sprintf('%s: expected %d entries, got %d (%d differ)', $what, count($expected), count($actual),
      count(array_diff_assoc(array_map('json_encode', $expected), array_map('json_encode', $actual))) + count(array_diff_key($actual, $expected))));
  }

  private function tool(array $args = []): array {
    return VcDirectory::display()->run($args, new ToolContext((int) \CRM_Core_Session::getLoggedInContactID(), 200));
  }

  /** Every page through the tool: id => Email column. */
  private function allPages(array $args = []): array {
    $out = [];
    for ($page = 1; $page < 50; $page++) {
      $r = $this->tool($args + ['page' => $page]);
      foreach ($r['rows'] as $row) {
        $this->assertSame(['id', 'Contact ID', 'Name', 'Primary area of expertise', 'Areas of expertise', 'Email (shared by the VC)'], array_keys($row));
        $out[(int) $row['id']] = $row['Email (shared by the VC)'];
      }
      if (!$r['truncated']) {
        if (!$args) {
          $this->assertSame(count($this->ref['active']), $r['total']);
        }
        break;
      }
    }
    return $out;
  }

  /** The browser's view: SearchDisplay.run with raw SearchKit filters (any operator), through the Afform. */
  private function raw(array $filters, ?string $afform = VcDirectory::AFFORM, string $return = 'page:1'): array {
    $action = SearchDisplay::run(TRUE)->setSavedSearch(VcDirectory::SEARCH)->setDisplay(VcDirectory::DISPLAY)
      ->setFilters($filters)->setReturn($return);
    if ($afform) {
      $action->setAfform($afform);
    }
    return $action->execute()->getArrayCopy();
  }

  public function testDirectoryIsEveryActiveVcWithEmailOnlyWhereShared(): void {
    $rows = $this->allPages();
    $this->same(array_fill_keys($this->ref['active'], TRUE), array_fill_keys(array_keys($rows), TRUE), 'directory ids vs active VCs');
    $this->same($this->ref['shared'], array_filter($rows, fn($e) => $e !== NULL && $e !== ''), 'emails shown vs opted-in emails');
  }

  public function testTotalsOnAFullPageAndPastTheEnd(): void {
    $n = count($this->ref['active']);
    $first = $this->tool();
    $this->assertSame($n, $first['total'], 'a full first page still reports the true total');
    $this->assertSame($n > $first['returned'], $first['truncated']);
    $past = $this->tool(['page' => 40]);
    $this->assertSame([$n, 0, FALSE], [$past['total'], $past['returned'], $past['truncated']]);
  }

  /**
   * T28 review M1: `access CiviCRM` alone is not enough — a subscriber who is neither an Active nor a
   * Test VC (D30) reads nothing. Counts only: a failure must not print directory rows.
   */
  public function testOnlyActiveOrTestVcsMayRead(): void {
    $checked = 0;
    foreach (get_users(['role' => 'subscriber', 'fields' => ['ID', 'user_login']]) as $u) {
      $cid = \Civi\Api4\UFMatch::get(FALSE)->addSelect('contact_id')->addWhere('uf_id', '=', $u->ID)->execute()->first()['contact_id'] ?? NULL;
      if (!$cid || in_array((int) $cid, $this->ref['active'], TRUE) || in_array((int) $cid, $this->ref['test'], TRUE)) {
        continue;
      }
      $this->loginAs($u->user_login);
      $this->assertSame(0, count($this->raw([])), 'a subscriber who is not an Active or Test VC read the directory');
      $this->assertSame(0, $this->tool()['total']);
      if (++$checked >= 3) {
        break;
      }
    }
    if (!$checked) {
      $this->markTestSkipped('No subscriber who is not an Active or Test VC.');
    }
  }

  /** D30: a Test VC (MAS's own test account) reads the directory, and no Test VC is listed in it. */
  public function testATestVcMayReadButIsNotListed(): void {
    $checked = 0;
    foreach (get_users(['role' => 'subscriber', 'fields' => ['ID', 'user_login']]) as $u) {
      $cid = \Civi\Api4\UFMatch::get(FALSE)->addSelect('contact_id')->addWhere('uf_id', '=', $u->ID)->execute()->first()['contact_id'] ?? NULL;
      if (!$cid || !in_array((int) $cid, $this->ref['test'], TRUE)) {
        continue;
      }
      $this->loginAs($u->user_login);
      $this->assertSame(count($this->ref['active']), $this->tool()['total'], 'a Test VC reads every active VC');
      $this->assertSame(0, count($this->raw(['id' => $this->ref['test']])), 'no Test VC is listed');
      $checked++;
    }
    if (!$checked) {
      $this->markTestSkipped('No subscriber whose VC_Status is Test.');
    }
  }

  public function testWithoutTheAfformTheDisplayIsRefused(): void {
    $this->expectException(\Civi\API\Exception\UnauthorizedException::class);
    $this->raw([], NULL);
  }

  public function testTheNativeApiStillShowsNoOtherVc(): void {
    $this->assertLessThanOrEqual(1, Contact::get(TRUE)->addWhere('contact_sub_type', 'CONTAINS', 'MAS_Rep')->selectRowCount()->execute()->rowCount);
  }

  public function testRawSearchKitFiltersCannotTestAHiddenEmail(): void {
    foreach ($this->ref['hidden'] as $id => $email) {
      foreach ([
        ['shared_email' => $email],
        ['shared_email' => ['=' => $email]],
        ['shared_email' => ['LIKE' => mb_substr($email, 0, 1) . '%']],
        ['shared_email' => ['IS NOT NULL' => TRUE]],
        ['shared_email' => ['IS NOT EMPTY' => TRUE]],
      ] as $f) {
        $this->assertCount(0, $this->raw(['id' => $id] + $f), 'a filter on the shared email found an opted-out VC');
      }
      // Filters on fields the display does not select are ignored: the row comes back, email still null.
      foreach ([['email_primary.email' => 'no-such@example.invalid'], ['MAS_Rep.Share_Email_with_VC_s' => TRUE], ['phone_primary.phone' => ['IS NOT NULL' => TRUE]]] as $f) {
        $rows = $this->raw(['id' => $id] + $f);
        $this->assertCount(1, $rows);
        $this->assertTrue(($rows[0]['data']['shared_email'] ?? NULL) === NULL, 'an opted-out VC showed an email');
      }
      break;
    }
  }

  public function testRawRowsCarryOnlyTheSelectedFields(): void {
    foreach ($this->raw([]) as $row) {
      $this->assertSame(['id', 'display_name', 'MAS_Rep.Primary_Area_of_Expertise:label', 'MAS_Rep.Areas_of_Expertise:label', 'shared_email'], array_keys($row['data']));
    }
  }

  public function testAnotherDisplayCannotRideOnTheDirectoryAfform(): void {
    $this->expectException(\Civi\API\Exception\UnauthorizedException::class);
    // The portal's pool report: also acl_bypass, embedded in its own Afform, not in the directory's.
    SearchDisplay::run(TRUE)->setSavedSearch('Service_Requests_Send_for_Assignment')->setDisplay('Service_Requests_Send_for_Assignment_Table_1')
      ->setAfform(VcDirectory::AFFORM)->setReturn('page:1')->execute();
  }

  public function testContactIdsFilter(): void {
    $want = array_slice($this->ref['active'], 0, 3);
    $out = $this->allPages(['contact_ids' => array_merge($want, [PHP_INT_MAX >> 33])]);
    $got = array_keys($out);
    sort($got);
    $this->assertSame($want, $got, 'only the active VCs among the ids; an unknown id adds nothing');
  }

  public function testNameAndAreaFiltersNarrow(): void {
    $all = count($this->ref['active']);
    $byName = $this->tool(['name' => 'a'])['total'];
    $this->assertGreaterThan(0, $byName);
    $this->assertLessThanOrEqual($all, $byName);
    $this->assertSame(0, $this->tool(['name' => 'zzqx-no-such-name'])['total']);

    $area = \Civi\Api4\OptionValue::get(FALSE)->addSelect('label')
      ->addWhere('option_group_id:name', '=', 'Cases_SR_Projects_Practice_Area')->addWhere('is_active', '=', TRUE)
      ->execute()->column('label');
    $expected = 0;
    foreach ($area as $label) {
      $expected = Contact::get(FALSE)->addWhere('id', 'IN', $this->ref['active'])->addClause('OR',
        ['MAS_Rep.Primary_Area_of_Expertise:label', '=', $label], ['MAS_Rep.Areas_of_Expertise:label', 'CONTAINS', $label])
        ->selectRowCount()->execute()->rowCount;
      if ($expected > 0) {
        $this->assertSame($expected, $this->tool(['area' => $label])['total'], "area filter for one practice area");
        break;
      }
    }
    $this->assertGreaterThan(0, $expected, 'no practice area has an active VC');
  }

}
