<?php

namespace Civi\Mascode\Mcp\Tests\Unit;

use Civi\Mascode\Mcp\Vc\ScopeRefused;
use Civi\Mascode\Mcp\Vc\VcScopePolicy;
use Civi\Mascode\Mcp\Vc\VcTools;
use Civi\Mcp\Protocol\ToolException;
use Civi\Mcp\Protocol\ToolRefused;
use Civi\Mcp\Scoped\EntityPolicy;
use Civi\Mcp\Scoped\ScopePolicy;
use Civi\Mcp\Tools\Ability;
use Civi\Mcp\Tools\PublishedDisplay;
use Civi\Mcp\Tools\Registry;
use Civi\Mcp\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * T6: the VC tools' wiring — who is offered them, the active-VC gate on every call, and a scope
 * refusal returned as its one fixed message. The boundaries themselves (ScopedQuery, VcScopePolicy,
 * the directory display) have their own tests.
 */
class VcToolsTest extends TestCase {

  private const STAFF = ['view all contacts', 'edit all contacts', 'administer CiviCRM'];

  private const VC = 42;

  private bool $active = TRUE;

  private bool $refuse = FALSE;

  /** @var string[] */
  private array $logged = [];

  /** @var list<array> queries the fake executor ran */
  private array $executed = [];

  private int $policiesBuilt = 0;

  private int $directoryRuns = 0;

  private function tools(): VcTools {
    $policy = function (): ScopePolicy {
      $this->policiesBuilt++;
      return new class(fn() => $this->refuse) implements ScopePolicy {

        public function __construct(private \Closure $refuse) {}

        public function entities(): array {
          return VcScopePolicy::entityNames();
        }

        public function entityPolicy(string $entity, int $contactId): ?EntityPolicy {
          if (($this->refuse)()) {
            throw new ScopeRefused('scope set cases above 5000');
          }
          return new EntityPolicy([['id', 'IN', [$contactId]]], ['id' => 'Integer', 'display_name' => 'String']);
        }

      };
    };
    $display = new PublishedDisplay('Dir', 'Dir_Table', 'afsearchDir', [], function (array $req): array {
      $this->directoryRuns++;
      return ['columns' => [], 'pageSize' => NULL, 'rows' => [], 'total' => 0];
    });
    return new VcTools(
      $policy,
      function (string $entity, array $params): array {
        $this->executed[] = $params;
        return [[['id' => self::VC, 'display_name' => 'A VC']], 1];
      },
      fn(string $entity): array => ['id' => ['title' => 'ID']],
      fn(int $cid): bool => $this->active && $cid === self::VC,
      $display,
      function (string $reason): void {
        $this->logged[] = $reason;
      },
    );
  }

  /** @return array<string, Ability> */
  private function abilities(): array {
    $out = [];
    foreach ($this->tools()->abilities(self::STAFF) as $a) {
      $out[$a->name] = $a;
    }
    return $out;
  }

  private function call(string $tool, array $args = [], int $cid = self::VC): array {
    $a = $this->abilities()[$tool];
    return ($a->callback)($args, new ToolContext($cid, 50));
  }

  private function assertRefusedWithTheFixedMessage(string $tool, array $args, string $reason, int $cid = self::VC): void {
    try {
      $this->call($tool, $args, $cid);
      $this->fail("$tool must be refused");
    }
    catch (ToolException $e) {
      $this->assertSame(ScopeRefused::MESSAGE, $e->getMessage());
      $this->assertInstanceOf(ToolRefused::class, $e, 'recorded as refused in the audit log (T7)');
    }
    $this->assertSame([$reason], $this->logged, 'the cause goes to the log, not the caller');
    $this->logged = [];
  }

  public function testExactlyTheThreeVcToolsReadOnlyAndWithheldFromStaff(): void {
    $abilities = $this->abilities();
    $names = array_keys($abilities);
    sort($names);
    $this->assertSame(VcTools::NAMES, $names);
    foreach ($abilities as $a) {
      $this->assertTrue($a->readOnly);
      $this->assertSame('access MCP', $a->permission);
      $this->assertSame(self::STAFF, $a->excludedPermissions);
    }
  }

  public function testRegistryOffersThemToAVcAndNeverToStaff(): void {
    $staffTool = new Ability('civi_get', 'x', 'x', ['type' => 'object'], TRUE, ['access CiviCRM', self::STAFF], fn() => []);
    $offered = function (array $held) use ($staffTool): array {
      $check = fn($req) => is_string($req) ? in_array($req, $held, TRUE) : (bool) array_intersect($req[0], $held);
      $registry = new Registry($this->abilities() + ['civi_get' => $staffTool], $check);
      return array_map(fn(Ability $a) => $a->name, $registry->available());
    };
    $this->assertSame(VcTools::NAMES, $offered(['access MCP', 'access CiviCRM']));
    foreach (self::STAFF as $perm) {
      $this->assertSame(['civi_get'], $offered(['access MCP', 'access CiviCRM', $perm]), "a caller holding $perm is staff");
    }
    $this->assertSame([], $offered(['access CiviCRM']), 'no access MCP, no VC tool');
  }

  public function testNoStaffListIsRefused(): void {
    $this->expectException(\LogicException::class);
    $this->tools()->abilities([]);
  }

  public function testQueryAndDescribeRunForAnActiveVc(): void {
    $out = $this->call('vc_query', ['entity' => 'Contact', 'select' => ['id', 'display_name']]);
    $this->assertSame(1, $out['returned']);
    $this->assertSame([['id', 'IN', [self::VC]]], $this->executed[0]['where'], 'scoped to the authenticated contact');
    $this->assertFalse($this->executed[0]['checkPermissions']);

    $this->assertSame(['entities' => VcScopePolicy::entityNames()], $this->call('vc_describe'));
    $described = $this->call('vc_describe', ['entity' => 'Contact']);
    $this->assertSame(['id', 'display_name'], array_column($described['fields'], 'name'));
    $this->assertSame(VcTools::ENTITY_NOTES['Contact'], $described['note']);
    $this->assertStringContainsString('Sent Automated Email', $this->call('vc_describe', ['entity' => 'Activity'])['note']);

    $this->assertSame(0, $this->call('vc_directory')['total']);
    $this->assertSame(1, $this->directoryRuns);
    $this->assertSame([], $this->logged);
  }

  public function testEveryToolRefusesACallerWhoIsNotAnActiveVc(): void {
    $this->active = FALSE;
    foreach (['vc_query' => ['entity' => 'Contact', 'select' => ['id']], 'vc_describe' => ['entity' => 'Case'], 'vc_directory' => []] as $tool => $args) {
      $this->assertRefusedWithTheFixedMessage($tool, $args, 'caller is not an active or Test VC');
    }
    $this->active = TRUE;
    $this->assertRefusedWithTheFixedMessage('vc_query', ['entity' => 'Contact', 'select' => ['id']], 'caller is not an active or Test VC', 0);
    $this->assertSame([], $this->executed, 'no query ran');
    $this->assertSame(0, $this->directoryRuns, 'the directory did not run');
    $this->assertSame(0, $this->policiesBuilt, 'no scope was resolved');
  }

  public function testAScopeRefusalReturnsOnlyTheFixedMessage(): void {
    $this->refuse = TRUE;
    $this->assertRefusedWithTheFixedMessage('vc_query', ['entity' => 'Case', 'select' => ['id']], 'scope set cases above 5000');
    $this->assertRefusedWithTheFixedMessage('vc_describe', ['entity' => 'Case'], 'scope set cases above 5000');
    $this->assertSame([], $this->executed);
  }

  public function testCallerMistakesStillReachTheModel(): void {
    try {
      $this->call('vc_query', ['entity' => 'Contact', 'select' => ['contact_id.email_primary.email']]);
      $this->fail('a join must be refused');
    }
    catch (ToolException $e) {
      $this->assertStringContainsString('Joins are not available', $e->getMessage());
    }
    $this->assertSame([], $this->logged, 'a caller mistake is not a scope refusal');
  }

  /**
   * T7, and PR #17 round-2 L2: the caller sees one message whatever the cause, but the audit row
   * tells a D30 refusal from a scope refusal.
   */
  public function testTheAuditReasonTellsAnIneligibleCallerFromAScopeRefusal(): void {
    $reasons = [];
    foreach ([[FALSE, FALSE], [TRUE, TRUE]] as [$refuse, $active]) {
      $this->refuse = $refuse;
      $this->active = $active;
      try {
        $this->call('vc_query', ['entity' => 'Contact', 'select' => ['id']]);
        $this->fail('must be refused');
      }
      catch (ToolRefused $e) {
        $this->assertSame(ScopeRefused::MESSAGE, $e->getMessage());
        $reasons[] = $e->reason;
      }
    }
    $this->assertSame(['caller is not an active or Test VC', 'VC scope refused: scope set cases above 5000'], $reasons);
  }

  public function testEveryVcToolNotesItsAudienceEvenWhenRefused(): void {
    foreach ([TRUE, FALSE] as $active) {
      $this->active = $active;
      foreach (VcTools::NAMES as $tool) {
        $ctx = new ToolContext(self::VC, 50);
        $args = $tool === 'vc_query' ? ['entity' => 'Contact', 'select' => ['id']] : [];
        try {
          ($this->abilities()[$tool]->callback)($args, $ctx);
        }
        catch (ToolRefused) {
        }
        $this->assertSame(VcTools::AUDIENCE, $ctx->takeAudit()['audience'] ?? NULL, "$tool, active=" . var_export($active, TRUE));
      }
    }
  }

  public function testEveryEntityHasANote(): void {
    foreach (VcScopePolicy::entityNames() as $entity) {
      $this->assertNotSame('', VcTools::entityNote($entity), $entity);
    }
  }

  public function testTheEligibleVcRowCheckIsStrict(): void {
    $row = ['id' => self::VC, 'contact_sub_type' => ['MAS_Rep'], 'MAS_Rep.VC_Status:name' => 'Active', 'is_deleted' => FALSE];
    $this->assertTrue(VcTools::isEligibleVcRow($row, self::VC));
    $this->assertTrue(VcTools::isEligibleVcRow(['contact_sub_type' => ['Staff', 'MAS_Rep']] + $row, self::VC));
    $this->assertTrue(VcTools::isEligibleVcRow(['MAS_Rep.VC_Status:name' => 'Test'] + $row, self::VC), 'a Test VC (MAS test account) is eligible');
    $failing = [
      'no row' => [NULL, self::VC],
      'another contact' => [$row, self::VC + 1],
      'no contact' => [['id' => 0] + $row, 0],
      'not a VC' => [['contact_sub_type' => ['Staff']] + $row, self::VC],
      'no sub-type' => [['contact_sub_type' => NULL] + $row, self::VC],
      'sub-type as text' => [['contact_sub_type' => 'MAS_Rep_x'] + $row, self::VC],
      'Non_Active' => [['MAS_Rep.VC_Status:name' => 'Non_Active'] + $row, self::VC],
      'Withdrawn' => [['MAS_Rep.VC_Status:name' => 'Withdrawn'] + $row, self::VC],
      'status missing (a dropped field)' => [array_diff_key($row, ['MAS_Rep.VC_Status:name' => 1]), self::VC],
      'status lowercase' => [['MAS_Rep.VC_Status:name' => 'active'] + $row, self::VC],
      'Test lowercase' => [['MAS_Rep.VC_Status:name' => 'test'] + $row, self::VC],
      'Friend' => [['MAS_Rep.VC_Status:name' => 'Friend'] + $row, self::VC],
      'status as a list' => [['MAS_Rep.VC_Status:name' => ['Active']] + $row, self::VC],
      'Test on a non-VC' => [['contact_sub_type' => ['Staff'], 'MAS_Rep.VC_Status:name' => 'Test'] + $row, self::VC],
      'trashed' => [['is_deleted' => TRUE] + $row, self::VC],
      'is_deleted missing' => [array_diff_key($row, ['is_deleted' => 1]), self::VC],
    ];
    foreach ($failing as $case => [$r, $cid]) {
      $this->assertFalse(VcTools::isEligibleVcRow($r, $cid), $case);
    }
  }

  public function testAnErrorInTheEligibilityCheckIsARefusalWithTheFixedMessage(): void {
    $tools = new VcTools(
      fn() => $this->fail('no policy may be built'),
      fn() => $this->fail('no query may run'),
      fn(string $e): array => [],
      fn(int $cid): bool => throw new \RuntimeException("Invalid field 'MAS_Rep.VC_Status'"),
      new PublishedDisplay('Dir', 'Dir_Table', NULL, [], fn() => $this->fail('the directory may not run')),
      function (string $reason): void {
        $this->logged[] = $reason;
      },
    );
    foreach ($tools->abilities(self::STAFF) as $a) {
      try {
        ($a->callback)([], new ToolContext(self::VC, 50));
        $this->fail("{$a->name} must be refused");
      }
      catch (ToolException $e) {
        $this->assertSame(ScopeRefused::MESSAGE, $e->getMessage());
      }
    }
    $this->assertSame(array_fill(0, 3, "VC eligibility check failed: RuntimeException: Invalid field 'MAS_Rep.VC_Status'"), $this->logged);
  }

  public function testADatabaseErrorInTheEligibilityCheckIsNotLogged(): void {
    $tools = new VcTools(
      fn() => $this->fail('no policy may be built'),
      fn() => $this->fail('no query may run'),
      fn(string $e): array => [],
      fn(int $cid): bool => throw new \RuntimeException("DB Error: syntax error near 'secret value'"),
      new PublishedDisplay('Dir', 'Dir_Table', NULL, [], fn() => $this->fail('the directory may not run')),
      function (string $reason): void {
        $this->logged[] = $reason;
      },
    );
    try {
      ($tools->abilities(self::STAFF)[0]->callback)([], new ToolContext(self::VC, 50));
      $this->fail('must be refused');
    }
    catch (ToolException $e) {
      $this->assertSame(ScopeRefused::MESSAGE, $e->getMessage());
    }
    $this->assertSame(['VC eligibility check failed: RuntimeException: (database error; message withheld)'], $this->logged);
  }

}
