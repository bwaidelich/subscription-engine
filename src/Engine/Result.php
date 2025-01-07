<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine;

final readonly class Result
{
    private function __construct(
        public Errors|null $errors,
    ) {
    }

    public static function success(): self
    {
        return new self(null);
    }

    public static function failed(Errors $errors): self
    {
        return new self($errors);
    }
}
