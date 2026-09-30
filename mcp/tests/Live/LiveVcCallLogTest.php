<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Mcp\Audit\CallLogWriter;
use Civi\Mcp\Protocol\McpServer;
use Civi\Mcp\Tools\CollectToolsEvent;
use Civi\Mcp\Tools\Registry;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * Level 3 for T7 (D10): calls through the MCP server with the real CallLogWriter land in
 * McpCallLog with the right caller, tool and outcome, and only staff can read the log.
 *
 * NOT read-only: every call here writes one McpCallLog row, exactly as a real call does, and the
 * rows are kept (the log is never pruned by a test). As MCP_LIVE_USER (staff); the VC checks also
 * need MCP_LIVE_VC_USER (a non-staff Active or Test VC). Asserts outcomes and counts only.
 *
 * @group live
 */
class LiveVcCallLogTest extends TestCase {

  private const CLIENT = 'live-test';

  protected function setUp(): void {
    if (!getenv('MCP_LIVE_USER') || !class_exists('Civi')) {
      $this->markTestSkipped('Set MCP_LIVE_USER (staff) and run inside a CiviCRM site.');
    }
  }

  protected function tearDown(): void {
    \CRM_Core_Config::singleton()->userSystem->loadUser(getenv('MCP_LIVE_USER'));
  }

  private static function me(): int {
    return (int) \CRM_Core_Session::getLoggedInContactID();
  }

  private static function lastId(): int {
    return (int) (\Civi\Api4\McpCallLog::get(TRUE)->addSelect('id')->addOrderBy('id', 'DESC')->setLimit(1)->execute()->first()['id'] ?? 0);
  }

  /** @param bool $withMcp add `access MCP` to the permission check (it is not granted on masdemo) */
  private function server(bool $withMcp = FALSE): McpServer {
    $event = new CollectToolsEvent();
    \Civi::dispatcher()->dispatch(CollectToolsEvent::NAME, $event);
    $registry = new Registry($event->getAbilities(), fn($perm) => ($withMcp && $perm === 'access MCP') || \CRM_Core_Permission::check($perm));
    $cid = self::me();
    $writer = new CallLogWriter(['contactId' => $cid, 'ufId' => (int) \CRM_Core_BAO_UFMatch::getUFId($cid), 'clientId' => self::CLIENT]);
    return new McpServer($registry, new ToolContext($cid, 50), NULL, \Closure::fromCallable($writer));
  }

  private static function send(McpServer $server, string $tool, mixed $args): void {
    $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]), []);
  }

  /** @return list<array> rows written after $after, oldest first, read as staff */
  private static function rowsAfter(int $after): array {
    \CRM_Core_Config::singleton()->userSystem->loadUser(getenv('MCP_LIVE_USER'));
    return (array) \Civi\Api4\McpCallLog::get(TRUE)
      ->addSelect('*')
      ->addWhere('id', '>', $after)
      ->addOrderBy('id')
      ->execute();
  }

  public function testAVcCallIsRecordedWithAudienceAndScopeSizesButNoIds(): void {
    if (!getenv('MCP_LIVE_VC_USER')) {
      $this->markTestSkipped('Set MCP_LIVE_VC_USER to a non-staff Active or Test VC login.');
    }
    $before = self::lastId();
    \CRM_Core_Config::singleton()->userSystem->loadUser(getenv('MCP_LIVE_VC_USER'));
    $vc = self::me();
    $this->assertGreaterThan(0, $vc, 'could not log in as MCP_LIVE_VC_USER');
    $server = $this->server(TRUE);
    self::send($server, 'vc_query', ['entity' => 'Case', 'select' => ['id'], 'limit' => 1]);
    self::send($server, 'civi_get', ['entity' => 'Contact', 'select' => ['id']]);

    $rows = self::rowsAfter($before);
    $this->assertSame([['vc_query', 'ok'], ['civi_get', 'unknown_tool']], array_map(fn($r) => [$r['ability'], $r['outcome']], $rows));
    $this->assertSame($vc, (int) $rows[0]['contact_id']);
    $this->assertSame('Case', $rows[0]['target']);
    $this->assertSame(1, (int) $rows[0]['returned_rows']);
    $context = json_decode((string) $rows[0]['context'], TRUE);
    $this->assertSame('vc', $context['audience']);
    $this->assertSame(['own_cases', 'pool_cases', 'orgs', 'cases', 'employees', 'contacts', 'named'], array_keys($context['scope_sizes']));
    foreach ($context['scope_sizes'] as $n) {
      $this->assertIsInt($n, 'counts, never ID lists');
    }
  }

}
