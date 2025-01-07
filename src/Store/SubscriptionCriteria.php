<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Store;

use Wwwision\SubscriptionEngine\Engine\SubscriptionEngineCriteria;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionIds;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatusFilter;

final readonly class SubscriptionCriteria
{
    private function __construct(
        public SubscriptionIds|null $ids,
        public SubscriptionStatusFilter $status,
    ) {
    }

    /**
     * @param SubscriptionIds|array<string|SubscriptionId>|null $ids
     * @param SubscriptionStatusFilter|null $status
     */
    public static function create(
        SubscriptionIds|array|null $ids = null,
        SubscriptionStatusFilter|null $status = null,
    ): self {
        if (is_array($ids)) {
            $ids = SubscriptionIds::fromArray($ids);
        }
        return new self(
            $ids,
            $status ?? SubscriptionStatusFilter::any(),
        );
    }

    public static function forEngineCriteriaAndStatus(
        SubscriptionEngineCriteria $criteria,
        SubscriptionStatusFilter $status,
    ): self {
        return new self(
            $criteria->ids,
            $status,
        );
    }

    public static function noConstraints(): self
    {
        return new self(
            ids: null,
            status: SubscriptionStatusFilter::any(),
        );
    }
}
