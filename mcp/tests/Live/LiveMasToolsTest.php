<?php

namespace Civi\Mascode\Mcp\Tests\Live;

use Civi\Mascode\Mcp\MasTools;
use Civi\Mcp\Protocol\McpServer;
use Civi\Mcp\Tools\Registry;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * Level 3 for the MAS tool pack: against a real site (masdemo) with civicrm_mcp + mascode enabled,
 * read-only, as MCP_LIVE_USER. The core tools' own checks live in civicrm_mcp tests/Live.
 * Asserts shapes and counts only — never row contents, which are real data.
 *
 * @group live
 */
class LiveMasToolsTest extends TestCase {

  protected function setUp(): void {
    if (!getenv('MCP_LIVE_USER') || !class_exists('Civi')) {
      $this->markTestSkipped('Set MCP_LIVE_USER and run inside a CiviCRM site.');
    }
  }

  private function call(string $name, array $args = []): array {
    $server = new McpServer(Registry::fromSite(), new ToolContext((int) \CRM_Core_Session::getLoggedInContactID(), 50), NULL, static function (array $call): void {});
    [$status, $body] = $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args ?: new \stdClass()]]), []);
    $this->assertSame(200, $status);
    $this->assertArrayHasKey('result', $body, json_encode($body['error'] ?? NULL));
    return $body['result'];
  }

  private static function isStaff(): bool {
    return \CRM_Core_Permission::check([MasTools::STAFF]);
  }

  private function requireStaff(): void {
    if (!self::isStaff()) {
      $this->markTestSkipped('Staff-tool test; MCP_LIVE_USER is not staff.');
    }
  }

  public function testToolsListIsExactlyTheRegisteredReadOnlyAbilities(): void {
    $this->requireStaff();
    $names = array_map(fn($a) => $a->name, Registry::fromSite()->available());
    $this->assertSame(['case_contact_history', 'case_detail', 'civi_describe', 'civi_get', 'find_cases', 'ops_queue', 'overdue_projects', 'pipeline_summary', 'vc_workload'], $names);
  }

  /** VC access spec Phase 0: a non-staff user (run as test.vc) is offered, and can call, no tool. */
  public function testNonStaffGetsNoTools(): void {
    if (self::isStaff()) {
      $this->markTestSkipped('Run with MCP_LIVE_USER set to a non-staff account (test.vc).');
    }
    $this->assertSame([], Registry::fromSite()->available());
    $server = new McpServer(Registry::fromSite(), new ToolContext((int) \CRM_Core_Session::getLoggedInContactID(), 50), NULL, static function (array $call): void {});
    foreach (['civi_get' => ['entity' => 'Contact', 'select' => ['id']], 'find_cases' => ['limit' => 1], 'ops_queue' => ['queue' => 'new_service_requests']] as $tool => $args) {
      [$status, $body] = $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]), []);
      $this->assertSame(200, $status);
      $this->assertArrayNotHasKey('result', $body, "$tool must not run for a non-staff user");
      $this->assertStringContainsString('Unknown tool', $body['error']['message'] ?? '', "$tool is refused by the Registry");
    }
  }

  /** The queue tool agrees with the dashboard by construction: same display, same count. */
  public function testOpsQueueTotalsEqualSearchDisplayRun(): void {
    $this->requireStaff();
    foreach (MasTools::QUEUES as $key => [$search, $display]) {
      $direct = \Civi\Api4\SearchDisplay::run(TRUE)->setSavedSearch($search)->setDisplay($display)->setReturn('row_count')->execute()->rowCount;
      $r = $this->call('ops_queue', ['queue' => $key]);
      $this->assertFalse($r['isError'], $key);
      $this->assertSame((int) $direct, $r['structuredContent']['total'], $key);
      $this->assertLessThanOrEqual(50, $r['structuredContent']['returned'], "$key respects the row cap");
    }
  }

  /**
   * The queues are all shorter than a page today, so the test above cannot see how a FULL page is
   * counted (APIv4 leaves rowCount NULL there; the tool reported total 0 until 2026-09-30). Run the
   * same code on any display that does fill a page.
   */
  public function testDisplayTotalOnAFullPage(): void {
    $this->requireStaff();
    $run = new \ReflectionMethod(MasTools::class, 'runDisplay');
    // Deterministic order, a bounded scan, and no contact-tab displays: those are meant to run
    // filtered by one contact, and an unfiltered count of one ran past 300 s on masdemo (PR #16 review).
    $displays = \Civi\Api4\SearchDisplay::get(TRUE)->addSelect('name', 'saved_search_id.name', 'settings')
      ->addWhere('type', '=', 'table')->addWhere('acl_bypass', '=', FALSE)
      ->addWhere('name', 'NOT LIKE', 'Contact\\_Summary\\_%')->addOrderBy('id')->setLimit(30)->execute();
    foreach ($displays as $d) {
      $limit = (int) ($d['settings']['limit'] ?? 0);
      if ($limit < 1) {
        continue;
      }
      try {
        $direct = (int) \Civi\Api4\SearchDisplay::run(TRUE)->setSavedSearch($d['saved_search_id.name'])->setDisplay($d['name'])->setReturn('row_count')->execute()->rowCount;
      }
      catch (\Throwable $e) {
        continue;
      }
      if ($direct <= $limit) {
        continue;
      }
      // Ask for exactly one display page, so a pager with expose_limit returns a full page too.
      $out = $run->invoke(NULL, $d['saved_search_id.name'], $d['name'], $limit);
      $this->assertSame($direct, $out['total'], 'total of a display with a full first page');
      $this->assertTrue($out['truncated']);
      return;
    }
    $this->markTestSkipped('No display on this site fills its first page.');
  }

  public function testPipelineSummaryMatchesDashboardTiles(): void {
    $this->requireStaff();
    $r = $this->call('pipeline_summary')['structuredContent'];
    foreach (['MAS_Ops_Dash_SR_Open' => 'open_service_requests_by_status', 'MAS_Ops_Dash_PJ_Open' => 'open_projects_by_status'] as $search => $key) {
      $direct = \Civi\Api4\SearchDisplay::run(TRUE)->setSavedSearch($search)->setDisplay($search . '_Tile')->execute();
      $expected = [];
      foreach ($direct as $row) {
        $expected[] = ['status' => $row['data']['label'], 'count' => (int) $row['data']['c']];
      }
      $this->assertSame($expected, $r[$key]);
    }
  }

  public function testEveryMasToolRunsWithoutError(): void {
    $this->requireStaff();
    foreach (['pipeline_summary' => [], 'vc_workload' => ['limit' => 3], 'overdue_projects' => ['limit' => 3], 'find_cases' => ['case_type' => 'project', 'limit' => 3]] as $tool => $args) {
      $this->assertFalse($this->call($tool, $args)['isError'], $tool);
    }
    $case = $this->call('find_cases', ['limit' => 1])['structuredContent']['cases'][0] ?? NULL;
    if ($case) {
      $this->assertFalse($this->call('case_detail', ['case_id' => $case['case_id']])['isError']);
      $this->assertFalse($this->call('case_contact_history', ['case_id' => $case['case_id']])['isError']);
    }
  }

}
