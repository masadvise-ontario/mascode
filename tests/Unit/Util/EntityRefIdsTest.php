<?php

namespace Civi\Mascode\Test\Unit\Util;

use Civi\Mascode\Test\TestCase;
use Civi\Mascode\Util\EntityRefIds;

/**
 * EntityRefIds feeds SerializedEntityRefDisplaySubscriber, the workaround for
 * core's View Contribution fatal on a multi-value EntityReference field.
 *
 * @coversNothing
 */
class EntityRefIdsTest extends TestCase
{
    public function testFlattenLeavesAFlatListAlone(): void
    {
        $this->assertNull(EntityRefIds::flatten([12, 34]));
        $this->assertNull(EntityRefIds::flatten([]));
    }

    public function testFlattenUnwrapsCoresNestedIds(): void
    {
        // Exactly what formatDisplayValue() sends: 'ids' => [$value].
        $this->assertSame([12], EntityRefIds::flatten([[12]]));
        $this->assertSame([12, 34], EntityRefIds::flatten([[12, 34]]));
        $this->assertSame([12, 34, 56], EntityRefIds::flatten([[12, '34'], 56, [12]]));
    }

    public function testFlattenDropsNonIds(): void
    {
        $this->assertSame([12], EntityRefIds::flatten([[12, 'x; DROP', null, -3, 0, 1.5]]));
    }

    public function testParseReadsEveryStoredShape(): void
    {
        $this->assertSame([12, 34], EntityRefIds::parse([12, '34']));
        $this->assertSame([12, 34], EntityRefIds::parse("\x0112\x0134\x01"));
        $this->assertSame([12, 34], EntityRefIds::parse('12,34'));
        $this->assertSame([12], EntityRefIds::parse('12'));
        $this->assertSame([12], EntityRefIds::parse(12));
    }

    public function testParseOfEmptyOrJunkIsEmpty(): void
    {
        $this->assertSame([], EntityRefIds::parse(null));
        $this->assertSame([], EntityRefIds::parse(''));
        $this->assertSame([], EntityRefIds::parse("\x01\x01"));
        $this->assertSame([], EntityRefIds::parse(false));
        $this->assertSame([], EntityRefIds::parse('abc'));
    }
}
