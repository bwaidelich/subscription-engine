<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngine\Engine;

use InvalidArgumentException;

/**
 * @implements \IteratorAggregate<Error>
 */
final readonly class Errors implements \IteratorAggregate, \Countable
{
    private const int CLAMP_ERRORS = 5;

    /**
     * @var non-empty-array<Error>
     */
    private array $errors;

    private function __construct(Error ...$errors)
    {
        if ($errors === []) {
            throw new InvalidArgumentException('Errors must not be empty.', 1736159619);
        }
        $this->errors = array_values($errors);
    }

    /**
     * @param array<Error> $errors
     */
    public static function fromArray(array $errors): self
    {
        return new self(...$errors);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->errors;
    }

    public function count(): int
    {
        return count($this->errors);
    }

    public function first(): Error
    {
        return $this->errors[array_key_first($this->errors)];
    }

    public function getClampedMessage(): string
    {
        $additionalMessage = '';
        $lines = [];
        foreach ($this->errors as $error) {
            $lines[] = sprintf('%s"%s": %s', $error->position ? 'Event ' . $error->position->value . ' in ' : '', $error->subscriptionId->value, $error->message);
            if (count($lines) >= self::CLAMP_ERRORS) {
                $additionalMessage = sprintf('%sAnd %d other exceptions, see log.', ";\n", count($this->errors) - self::CLAMP_ERRORS);
                break;
            }
        }
        return implode(";\n", $lines) . $additionalMessage;
    }
}
