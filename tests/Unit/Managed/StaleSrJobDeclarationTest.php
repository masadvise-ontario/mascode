<?php

namespace Civi\Mascode\Test\Unit\Managed;

use Civi\Mascode\Test\TestCase;

/**
 * The daily auto-close Job. Its parameters are a JSON string that nothing
 * validates until cron runs it on production, so the ones that decide whether
 * it closes anything, and what, are pinned here.
 *
 * @coversNothing
 */
class StaleSrJobDeclarationTest extends TestCase
{
    private const FILE = __DIR__ . '/../../../Civi/Mascode/Managed/Job_MasCloseStaleServiceRequests.mgd.php';

    private function values(): array
    {
        $decl = include self::FILE;
        $this->assertCount(1, $decl);
        $this->assertSame('Job', $decl[0]['entity']);
        return $decl[0]['params']['values'];
    }

    public function testRunsDailyAgainstTheRealAction(): void
    {
        $v = $this->values();
        $this->assertSame('Daily', $v['run_frequency']);
        $this->assertSame('Mascode', $v['api_entity']);
        $this->assertSame('closeStaleServiceRequests', $v['api_action']);
        $entity = file_get_contents(__DIR__ . '/../../../Civi/Api4/Mascode.php');
        $this->assertStringContainsString('function closeStaleServiceRequests(', $entity, 'api_action names a method that does not exist');
    }

    public function testParametersAreAnUnattendedLiveRunOnApi4(): void
    {
        $p = json_decode($this->values()['parameters'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(4, $p['version'], 'without version 4 the job calls APIv3, where this action does not exist');
        $this->assertSame(0, $p['dryRun']);
        $this->assertSame(1, $p['allEligible']);
        $this->assertFalse($p['checkPermissions'], 'cron may run anonymously; the action is gated on administer CiviCRM');
        $this->assertArrayNotHasKey('caseIds', $p);
    }

    /**
     * civicrm_job.name and .description are varchar(255). Over-length text
     * fails the managed CREATE under strict SQL mode ("Data too long") and is
     * silently cut off elsewhere — and a bad managed create is not healed by a
     * flush. Round 3 of review caught a 279-character description.
     */
    public function testTextFitsItsColumns(): void
    {
        $v = $this->values();
        $this->assertLessThanOrEqual(255, mb_strlen($v['name']));
        $this->assertLessThanOrEqual(255, mb_strlen($v['description']));
    }

    /** A UI disable must survive deploys: 'unmodified' works because Job is an APIv4 ManagedEntity. */
    public function testUiChangesArePreserved(): void
    {
        $decl = include self::FILE;
        $this->assertSame('unmodified', $decl[0]['update']);
    }
}
