<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\Exception;

use InvalidArgumentException;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionIds;

final class UnknownSubscriptionIdsException extends InvalidArgumentException
{
    public static function forSubscriptionIds(SubscriptionIds $unknownSubscriptionIds): self
    {
        return new self('Unknown subscription id' . ($unknownSubscriptionIds->count() !== 1 ? 's' : '') . ' "' . implode('", "', $unknownSubscriptionIds->toStringArray()) . '"');
    }
}
