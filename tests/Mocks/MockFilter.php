<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Tests\Mocks;

/**
 * Matches events whose data is one of the given values
 */
final readonly class MockFilter
{
    /**
     * @var array<mixed>
     */
    public array $data;

    public function __construct(mixed ...$data)
    {
        $this->data = $data;
    }
}
