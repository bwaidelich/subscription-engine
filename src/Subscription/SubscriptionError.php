<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Subscription;

use Throwable;

/**
 * Represents an error that happened during handling of an event by a subscriber
 */
final readonly class SubscriptionError
{
    public function __construct(
        public string $errorMessage,
        public SubscriptionStatus $previousStatus,
        public string|null $errorTrace = null,
    ) {
    }

    public static function fromPreviousStatusAndException(SubscriptionStatus $previousStatus, Throwable $error): self
    {
        $message = self::getExceptionOutput($error);
        $previous = $error->getPrevious();
        while ($previous !== null) {
            $message .= "\n\nCaused by\n" . self::getExceptionOutput($previous);
            $previous = $previous->getPrevious();
        }
        return new self($message, $previousStatus, $error->getTraceAsString());
    }

    private static function getExceptionOutput(Throwable $exception): string
    {
        return sprintf(
            '%s: %s in file %s on line %d',
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );
    }
}
