<?php

namespace Civi\Mascode\Test\Unit\Submission;

use Civi\Mascode\Submission\CaseClientResolver;
use Civi\Mascode\Test\TestCase;

/**
 * Behavioural tests for the staff-copy client preference order.
 *
 * These exist because the source-reading tripwire could not do this job. Over
 * three review rounds the preference order was covered only by substring
 * assertions over AfformSubmitSubscriber, and round 3 demonstrated six wrong
 * implementations that those assertions still accepted — three of them
 * re-introducing defects named in earlier rounds. Moving the decision into a
 * CiviCRM-free class made it testable by simply constructing the row shapes,
 * which is what the tests below do.
 *
 * @covers \Civi\Mascode\Submission\CaseClientResolver
 */
class CaseClientResolverTest extends TestCase
{
    private CaseClientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CaseClientResolver();
    }

    private function row(int $id, string $name, string $type = 'Organization', bool $deleted = false): array
    {
        return [
            'contact_id' => $id,
            'contact_id.display_name' => $name,
            'contact_id.contact_type' => $type,
            'contact_id.is_deleted' => $deleted,
        ];
    }

    public function testRung1LiveOrganizationNamesTheClient(): void
    {
        $result = $this->resolver->resolve([
            $this->row(7, 'Riley Chen', 'Individual'),
            $this->row(42, 'Example Foundation'),
        ]);

        $this->assertSame('Example Foundation', $result['name']);
        $this->assertSame(42, $result['contact_id']);
    }

    public function testTheSubmittingIndividualNeverNamesTheClient(): void
    {
        // The defect this whole feature removes, and the one a source-level
        // assertion could not pin: naming the person instead of the org.
        $result = $this->resolver->resolve([
            $this->row(7, 'Riley Chen', 'Individual'),
        ]);

        $this->assertSame('', $result['name']);
        // The individual is still a valid cid — they are a client of the case.
        $this->assertSame(7, $result['contact_id']);
    }

    public function testRung1BeatsRung2AndRung2IsNotEvenConsulted(): void
    {
        $called = false;
        $result = $this->resolver->resolve(
            [$this->row(42, 'Example Foundation')],
            function () use (&$called): string {
                $called = true;
                return 'Other Org';
            }
        );

        $this->assertSame('Example Foundation', $result['name']);
        // Laziness is the contract: rung 2 costs a query, so it must not run.
        $this->assertFalse($called, 'rung 2 was consulted even though rung 1 answered');
    }

    public function testRung2UsedWhenTheCaseHasNoLiveOrganization(): void
    {
        $result = $this->resolver->resolve(
            [$this->row(7, 'Riley Chen', 'Individual')],
            fn(): string => 'Example Foundation'
        );

        $this->assertSame('Example Foundation', $result['name']);
    }

    public function testRung2BeatsATrashedOrganization(): void
    {
        $result = $this->resolver->resolve(
            [$this->row(99, 'Defunct Charity', 'Organization', true)],
            fn(): string => 'Example Foundation'
        );

        $this->assertSame('Example Foundation', $result['name']);
    }

    public function testRung3TrashedOrganizationIsTheLastResort(): void
    {
        // A trashed name still tells staff whose submission this is; an empty
        // Client row tells them nothing.
        $result = $this->resolver->resolve(
            [$this->row(99, 'Defunct Charity', 'Organization', true)],
            fn(): string => ''
        );

        $this->assertSame('Defunct Charity', $result['name']);
    }

    public function testATrashedOrganizationNeverSuppliesTheContactId(): void
    {
        // No live client means no usable cid, so the caller must build no
        // link — rather than one pointing at a trashed contact.
        $result = $this->resolver->resolve([
            $this->row(99, 'Defunct Charity', 'Organization', true),
        ]);

        $this->assertSame('Defunct Charity', $result['name']);
        $this->assertSame(0, $result['contact_id']);
    }

    public function testRung2NeverSuppliesTheContactId(): void
    {
        // The organization a submission saved need not be a client of the
        // case. A cid that is not on the case yields a link the case tab
        // renders under the wrong contact, so rung 2 gives a NAME only.
        $result = $this->resolver->resolve(
            [$this->row(99, 'Defunct Charity', 'Organization', true)],
            fn(): string => 'Example Foundation'
        );

        $this->assertSame('Example Foundation', $result['name']);
        $this->assertSame(0, $result['contact_id'], 'rung 2 must not supply a cid');
    }

    public function testContactIdIsAlwaysDrawnFromTheRowsHandedIn(): void
    {
        $rows = [
            $this->row(7, 'Riley Chen', 'Individual'),
            $this->row(42, 'Example Foundation'),
        ];
        $ids = array_column($rows, 'contact_id');

        foreach ([null, fn(): string => 'Somewhere Else'] as $rung2) {
            $result = $this->resolver->resolve($rows, $rung2);
            $this->assertContains($result['contact_id'], $ids);
        }
    }

    public function testAnOrganizationWithABlankNameDoesNotConsumeRung1(): void
    {
        // Otherwise the block would show no Client at all while the link
        // pointed at that organization — worse than falling through.
        $result = $this->resolver->resolve(
            [$this->row(42, '   ')],
            fn(): string => 'Example Foundation'
        );

        $this->assertSame('Example Foundation', $result['name']);
    }

    public function testFirstLiveOrganizationWinsSoOrderingIsHonoured(): void
    {
        $result = $this->resolver->resolve([
            $this->row(42, 'First Foundation'),
            $this->row(43, 'Second Foundation'),
        ]);

        $this->assertSame('First Foundation', $result['name']);
        $this->assertSame(42, $result['contact_id']);
    }

    public function testNoRowsAndNoFallbackResolvesNothing(): void
    {
        $result = $this->resolver->resolve([]);

        $this->assertSame('', $result['name']);
        $this->assertSame(0, $result['contact_id']);
    }

    public function testNoCaseButAnOrganizationFromTheSubmission(): void
    {
        // The three forms that declare no Organization1, and the surveys with
        // no case: rows are empty, rung 2 answers, no cid.
        $result = $this->resolver->resolve([], fn(): string => 'Example Foundation');

        $this->assertSame('Example Foundation', $result['name']);
        $this->assertSame(0, $result['contact_id']);
    }
}
