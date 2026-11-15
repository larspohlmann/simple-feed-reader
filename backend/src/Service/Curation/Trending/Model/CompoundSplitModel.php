<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class CompoundSplitModel
{
    /** @param list<string> $parts */
    private function __construct(private array $parts, private int $classSum, private int $headLength)
    {
    }

    public static function single(string $form, int $frequencyClass): self
    {
        return new self([$form], $frequencyClass, mb_strlen($form));
    }

    #[\NoDiscard]
    public function prependedBy(self $modifier): self
    {
        return clone($this, [
            'parts' => [...$modifier->parts, ...$this->parts],
            'classSum' => $modifier->classSum + $this->classSum,
        ]);
    }

    /** @return list<string> */
    public function parts(): array
    {
        return $this->parts;
    }

    public function partCount(): int
    {
        return count($this->parts);
    }

    public function isCompound(): bool
    {
        return $this->partCount() > 1;
    }

    /** A lower mean frequency class wins, then fewer parts, then the longer head. */
    public function beats(self $other): bool
    {
        $meanOrder = $this->classSum * $other->partCount() <=> $other->classSum * $this->partCount();
        if (0 !== $meanOrder) {
            return $meanOrder < 0;
        }
        if ($this->partCount() !== $other->partCount()) {
            return $this->partCount() < $other->partCount();
        }

        return $this->headLength > $other->headLength;
    }
}
