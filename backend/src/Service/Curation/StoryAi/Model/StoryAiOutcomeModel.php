<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi\Model;

/**
 * What one story AI call gave: a usable value, a reply that did not parse, or a provider failure.
 *
 * @template-covariant T
 */
final readonly class StoryAiOutcomeModel
{
    /** @param T|null $value */
    private function __construct(private mixed $value, private ?\Throwable $failure)
    {
    }

    /**
     * @template V
     *
     * @param V $value
     *
     * @return self<V>
     */
    public static function usable(mixed $value): self
    {
        return new self($value, null);
    }

    /** @return self<never> */
    public static function unusable(): self
    {
        return new self(null, null);
    }

    /** @return self<never> */
    public static function failed(\Throwable $failure): self
    {
        return new self(null, $failure);
    }

    public function isUsable(): bool
    {
        return $this->value !== null;
    }

    public function isFailed(): bool
    {
        return $this->failure !== null;
    }

    /** @return T */
    public function value(): mixed
    {
        return $this->value ?? throw new \LogicException('An unusable story AI outcome has no value.');
    }

    public function failure(): \Throwable
    {
        return $this->failure ?? throw new \LogicException('Only a failed story AI outcome has a failure.');
    }
}
