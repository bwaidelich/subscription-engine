<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Tests\PHPUnit\EventStore;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Wwwision\SubscriptionEngine\EventStore\ReadOptions;
use Wwwision\SubscriptionEngine\Subscription\Position;

#[CoversClass(ReadOptions::class)]
final class ReadOptionsTest extends TestCase
{
    public function test_create_without_arguments_has_no_constraints(): void
    {
        $options = ReadOptions::create();

        self::assertNull($options->limit);
        self::assertNull($options->upTo);
        self::assertNull($options->filter);
    }

    public function test_create_throws_for_a_limit_below_1(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReadOptions::create(limit: 0);
    }

    public function test_create_throws_for_upTo_none(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReadOptions::create(upTo: Position::none());
    }
}
