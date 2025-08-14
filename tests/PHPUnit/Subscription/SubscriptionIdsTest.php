<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Tests\PHPUnit\Subscription;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionIds;

#[CoversClass(SubscriptionIds::class)]
final class SubscriptionIdsTest extends TestCase
{

    public static function dataProvider_intersection(): iterable
    {
        yield ['ids1' => [], 'ids2' => [], 'expectedResult' => []];
        yield ['ids1' => ['a'], 'ids2' => [], 'expectedResult' => []];
        yield ['ids1' => [], 'ids2' => ['b'], 'expectedResult' => []];
        yield ['ids1' => ['a'], 'ids2' => ['b'], 'expectedResult' => []];
        yield ['ids1' => ['a'], 'ids2' => ['a'], 'expectedResult' => ['a']];
        yield ['ids1' => ['a', 'b', 'c'], 'ids2' => ['c', 'a'], 'expectedResult' => ['a', 'c']];
        yield ['ids1' => ['c', 'a'], 'ids2' => ['a', 'b', 'c'], 'expectedResult' => ['c', 'a']];
    }

    #[DataProvider('dataProvider_intersection')]
    public function test_intersection(array $ids1, array $ids2, array $expectedResult): void
    {
        $subscriptionIds1 = SubscriptionIds::fromArray($ids1);
        $subscriptionIds2 = SubscriptionIds::fromArray($ids2);
        $intersection = $subscriptionIds1->intersection($subscriptionIds2);
        self::assertSame($expectedResult, $intersection->toStringArray());
    }

    public static function dataProvider_merge(): iterable
    {
        yield ['ids1' => [], 'ids2' => [], 'expectedResult' => []];
        yield ['ids1' => ['a'], 'ids2' => [], 'expectedResult' => ['a']];
        yield ['ids1' => [], 'ids2' => ['b'], 'expectedResult' => ['b']];
        yield ['ids1' => ['a'], 'ids2' => ['b'], 'expectedResult' => ['a', 'b']];
        yield ['ids1' => ['a'], 'ids2' => ['a'], 'expectedResult' => ['a']];
        yield ['ids1' => ['a', 'b', 'c'], 'ids2' => ['c', 'a'], 'expectedResult' => ['a', 'b', 'c']];
        yield ['ids1' => ['c', 'a'], 'ids2' => ['a', 'b', 'c'], 'expectedResult' => ['c', 'a', 'b']];
    }

    #[DataProvider('dataProvider_merge')]
    public function test_merge(array $ids1, array $ids2, array $expectedResult): void
    {
        $subscriptionIds1 = SubscriptionIds::fromArray($ids1);
        $subscriptionIds2 = SubscriptionIds::fromArray($ids2);
        $union = $subscriptionIds1->merge($subscriptionIds2);
        self::assertSame($expectedResult, $union->toStringArray());
    }
}