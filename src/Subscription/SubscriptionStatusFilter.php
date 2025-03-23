<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscription;

use InvalidArgumentException;
use JsonSerializable;

final class SubscriptionStatusFilter implements JsonSerializable
{
    /**
     * @param array<string, SubscriptionStatus> $statusByValue
     */
    private function __construct(
        private readonly array $statusByValue,
    ) {
    }

    /**
     * @param array<string|SubscriptionStatus> $status
     */
    public static function fromArray(array $status): self
    {
        $statusByValue = [];
        foreach ($status as $singleStatus) {
            if (is_string($singleStatus)) {
                $singleStatus = SubscriptionStatus::from($singleStatus);
            }
            if (!$singleStatus instanceof SubscriptionStatus) {
                throw new InvalidArgumentException(sprintf('Expected instance of %s, got: %s', SubscriptionStatus::class, get_debug_type($singleStatus)), 1737369465);
            }
            if (array_key_exists($singleStatus->value, $statusByValue)) {
                throw new InvalidArgumentException(sprintf('Status "%s" is already part of this set', $singleStatus->value), 1737369466);
            }
            $statusByValue[$singleStatus->value] = $singleStatus;
        }
        return new self($statusByValue);
    }

    public static function fromStatus(SubscriptionStatus $status): self
    {
        return new self([$status->value => $status]);
    }

    /**
     * @return list<string>
     */
    public function toStringArray(): array
    {
        return array_values(array_map(static fn (SubscriptionStatus $id) => $id->value, $this->statusByValue));
    }

    /**
     * @return list<string>
     */
    public function jsonSerialize(): array
    {
        return $this->toStringArray();
    }
}
