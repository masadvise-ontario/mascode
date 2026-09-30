<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Mascode\Mcp\Vc\Api4VcScopeSource;
use Civi\Mascode\Mcp\Vc\ScopeRefused;
use Civi\Mascode\Mcp\Vc\VcScopePolicy;
use Civi\Mascode\Mcp\Vc\VcScopeResolver;
use Civi\Mascode\Mcp\Vc\VcTools;
use Civi\Mcp\Protocol\McpServer;
use Civi\Mcp\Tools\CollectToolsEvent;
use Civi\Mcp\Tools\Registry;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * Level 3 for T6: the VC tools through the MCP server, AS A VC — the non-staff active-VC WordPress
 * login in MCP_LIVE_VC_USER. `access MCP` is added to the permission check here rather than granted
 * on the site (T8 grants it by role), so the test writes nothing. Asserts counts and ids only
 * (masdemo is a copy of production).
 *
 * @group live
 */
class LiveVcToolsTest extends TestCase {

  private int $me = 0;

  protected function setUp(): void {
    if (!getenv('MCP_LIVE_USER') || !getenv('MCP_LIVE_VC_USER') || !class_exists('Civi')) {
      $this->markTestSkipped('Set MCP_LIVE_USER (staff) and MCP_LIVE_VC_USER (a non-staff Active or Test VC login).');
    }
    \CRM_Core_Config::singleton()->userSystem->loadUser(getenv('MCP_LIVE_VC_USER'));
    $this->me = (int) \CRM_Core_Session::getLoggedInContactID();
    $this->assertGreaterThan(0, $this->me, 'could not log in as MCP_LIVE_VC_USER');
    $this->assertFalse(\CRM_Core_Permission::check([['view all contacts', 'edit all contacts', 'administer CiviCRM']]), 'MCP_LIVE_VC_USER must not be staff');
  }

  protected function tearDown(): void {
    if ($this->me) {
      \CRM_Core_Config::singleton()->userSystem->loadUser(getenv('MCP_LIVE_USER'));
    }
  }

  /** The site's registry, as if the caller held `access MCP` too. */
  private function registry(): Registry {
    $event = new CollectToolsEvent();
    \Civi::dispatcher()->dispatch(CollectToolsEvent::NAME, $event);
    return new Registry($event->getAbilities(), fn($perm) => $perm === 'access MCP' || \CRM_Core_Permission::check($perm));
  }

  /** @return array{0: bool, 1: array|string} [isError, structured result or error text] */
  private function call(string $tool, array $args = [], ?int $cid = NULL): array {
    $server = new McpServer($this->registry(), new ToolContext($cid ?? $this->me, 200), NULL, static function (array $call): void {});
    [$status, $body] = $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args ?: new \stdClass()]]), []);
    $this->assertSame(200, $status);
    if (isset($body['error'])) {
      return [TRUE, (string) $body['error']['message']];
    }
    $result = $body['result'];
    return $result['isError'] ? [TRUE, (string) $result['content'][0]['text']] : [FALSE, $result['structuredContent']];
  }

  private function ok(string $tool, array $args = []): array {
    [$isError, $out] = $this->call($tool, $args);
    $this->assertFalse($isError, "$tool failed: " . (is_string($out) ? mb_substr($out, 0, 200) : ''));
    return $out;
  }

  public function testTheVcIsOfferedExactlyTheVcTools(): void {
    $this->assertSame(VcTools::NAMES, array_map(fn($a) => $a->name, $this->registry()->available()));
    [$isError, $message] = $this->call('civi_get', ['entity' => 'Contact', 'select' => ['id']]);
    $this->assertTrue($isError);
    $this->assertStringContainsString('Unknown tool', $message);
  }

  public function testQueryMatchesTheResolvedScope(): void {
    $scope = (new VcScopeResolver(new Api4VcScopeSource()))->resolve($this->me);
    $cases = $this->ok('vc_query', ['entity' => 'Case', 'select' => ['id'], 'limit' => 1]);
    $this->assertSame(count($scope->cases), $cases['matched'], 'vc_query Case count == |S_cases|');
    $contacts = $this->ok('vc_query', ['entity' => 'Contact', 'select' => ['id'], 'limit' => 1]);
    $this->assertSame(count(array_unique(array_merge($scope->contacts, $scope->named))), $contacts['matched']);
    $mine = $this->ok('vc_query', ['entity' => 'Contact', 'select' => ['id', 'display_name'], 'where' => [['id', '=', $this->me]]]);
    $this->assertSame(1, $mine['returned']);

    // A forged id outside scope is the same empty result as a miss (D9).
    $outside = \Civi\Api4\CiviCase::get(FALSE)->addSelect('id')->addWhere('id', 'NOT IN', $scope->cases ?: [0])->setLimit(1)->execute()->first();
    if ($outside) {
      $forged = $this->ok('vc_query', ['entity' => 'Case', 'select' => ['id'], 'where' => [['id', '=', (int) $outside['id']]]]);
      $this->assertSame(0, $forged['matched']);
    }
    [$isError, $message] = $this->call('vc_query', ['entity' => 'Case', 'select' => ['id'], 'where' => [['subject', 'IS NOT NULL']]]);
    $this->assertTrue($isError, 'text is not filterable');
    $this->assertStringContainsString('cannot be used in where', $message);
  }

  public function testDescribeListsExactlyThePolicyFieldsForEveryEntity(): void {
    $this->assertSame(VcScopePolicy::entityNames(), $this->ok('vc_describe')['entities']);
    foreach (VcScopePolicy::entityNames() as $entity) {
      $out = $this->ok('vc_describe', ['entity' => $entity]);
      $expected = $entity === 'Activity' ? VcScopePolicy::ACTIVITY_FIELDS : VcScopePolicy::ENTITIES[$entity]['fields'];
      if ($entity === 'Case') {
        $expected = array_merge($expected, VcScopePolicy::CLIENT_FEEDBACK);
      }
      $this->assertSame($expected, array_column($out['fields'], 'name'), $entity);
      foreach ($out['fields'] as $f) {
        $this->assertArrayHasKey('title', $f, "$entity.{$f['name']} has a title");
      }
      $this->assertNotSame('', $out['note']);
    }
  }

  public function testDirectoryRunsThroughTheTool(): void {
    $status = \Civi\Api4\Contact::get(FALSE)->addSelect('MAS_Rep.VC_Status:name')->addWhere('id', '=', $this->me)->execute()->first()['MAS_Rep.VC_Status:name'] ?? NULL;
    $this->assertContains($status, VcTools::ELIGIBLE_STATUSES, 'MCP_LIVE_VC_USER must be an Active or Test VC');
    $out = $this->ok('vc_directory', ['contact_ids' => [$this->me]]);
    // The directory lists Active VCs only: a Test VC may read it but is not in it (D30).
    $this->assertSame($status === 'Active' ? 1 : 0, $out['total'], "a $status VC's own row");
    $this->assertGreaterThan(0, $this->ok('vc_directory')['total'], 'the caller reads the directory');
    $tests = \Civi\Api4\Contact::get(FALSE)->addSelect('id')->addWhere('contact_sub_type', 'CONTAINS', 'MAS_Rep')
      ->addWhere('MAS_Rep.VC_Status:name', '=', 'Test')->execute()->column('id');
    if ($tests) {
      $this->assertSame(0, $this->ok('vc_directory', ['contact_ids' => array_slice(array_map('intval', $tests), 0, 50)])['total'], 'no Test VC is listed');
    }
  }

  public function testAForgedContextContactIsRefused(): void {
    // Never happens in production (Routes checks the context contact equals the token's); here it
    // shows the check reads the context contact, not the session, and fails closed on a contact the
    // VC cannot read. Real non-VC and inactive logins: testARealNonVcLoginIsRefused.
    foreach (['vc_query' => ['entity' => 'Case', 'select' => ['id']], 'vc_describe' => ['entity' => 'Case'], 'vc_directory' => []] as $tool => $args) {
      [$isError, $message] = $this->call($tool, $args, $this->me + 1);
      $this->assertTrue($isError, $tool);
      $this->assertSame(ScopeRefused::MESSAGE, $message, $tool);
    }
  }

  /**
   * D30 against real logins: MCP_LIVE_NON_VC_USERS, a comma list of non-staff logins that are neither
   * Active nor Test VCs (a withdrawn or non-active VC, a non-VC subscriber). Each is refused by all three.
   */
  public function testARealNonVcLoginIsRefused(): void {
    $logins = array_filter(array_map('trim', explode(',', (string) getenv('MCP_LIVE_NON_VC_USERS'))));
    if (!$logins) {
      $this->markTestSkipped('Set MCP_LIVE_NON_VC_USERS to non-staff logins that are not Active or Test VCs.');
    }
    $seen = [$this->me];
    foreach ($logins as $i => $login) {
      $this->assertNotEmpty(\CRM_Core_Config::singleton()->userSystem->loadUser($login), "could not log in as login #$i");
      $cid = (int) \CRM_Core_Session::getLoggedInContactID();
      $this->assertGreaterThan(0, $cid, "could not log in as login #$i");
      // A failed switch leaves the previous login's session (PR #17 review round 2, L1).
      $this->assertNotContains($cid, $seen, "login #$i did not switch the session");
      $seen[] = $cid;
      $this->assertFalse(\CRM_Core_Permission::check([['view all contacts', 'edit all contacts', 'administer CiviCRM']]), "login #$i must not be staff");
      $this->assertSame(VcTools::NAMES, array_map(fn($a) => $a->name, $this->registry()->available()), "login #$i is offered the VC tools (the audience is by permission)");
      foreach (['vc_query' => ['entity' => 'Case', 'select' => ['id']], 'vc_describe' => [], 'vc_directory' => []] as $tool => $args) {
        [$isError, $message] = $this->call($tool, $args, $cid);
        $this->assertTrue($isError, "$tool for login #$i");
        $this->assertSame(ScopeRefused::MESSAGE, $message, "$tool for login #$i");
      }
    }
  }

}
