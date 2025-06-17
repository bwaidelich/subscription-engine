<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine\EngineEvent;

use Stringable;

/**
 * @template-covariant E of object|void
 */
interface EngineEvent extends Stringable
{
    /**
     * @return int one of PHPs LOG_* constants
     */
    public function logLevel(): int;
}
