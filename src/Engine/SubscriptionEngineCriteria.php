<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine;

use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionIds;

final readonly class SubscriptionEngineCriteria
{
    private function __construct(
        public SubscriptionIds|null $ids
    ) {
    }

    /**
     * @param SubscriptionIds|array<string|SubscriptionId>|null $ids
     */
    public static function create(SubscriptionIds|array|null $ids = null): self
    {
        if (is_array($ids)) {
            $ids = SubscriptionIds::fromArray($ids);
        }
        return new self(
            $ids
        );
    }

    public static function noConstraints(): self
    {
        return new self(
            ids: null
        );
    }
}
