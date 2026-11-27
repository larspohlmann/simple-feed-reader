<?php

declare(strict_types=1);

namespace App\Service\Foundation\Stemming\Model;

final readonly class CompoundSplitModel
{
    /** @param list<string> $parts */
    private function __construct(
        private array $parts,
        private int $classSum,
        private int $rarestClass,
        private int $headLength,
    ) {
    }

    public static function single(string $form, int $frequencyClass): self
    {
        return new self([$form], $frequencyClass, $frequencyClass, mb_strlen($form));
    }

    #[\NoDiscard]
    public function prependedBy(self $modifier): self
    {
        return clone($this, [
            'parts' => [...$modifier->parts, ...$this->parts],
            'classSum' => $modifier->classSum + $this->classSum,
            'rarestClass' => max($modifier->rarestClass, $this->rarestClass),
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

    /** Fewer parts win, then the lower mean frequency class, then the longer head. */
    public function beats(self $other): bool
    {
        if ($this->partCount() !== $other->partCount()) {
            return $this->partCount() < $other->partCount();
        }
        if ($this->classSum !== $other->classSum) {
            return $this->classSum < $other->classSum;
        }

        return $this->headLength > $other->headLength;
    }

    public function isMoreFrequentThan(self $other): bool
    {
        return $this->classSum < $other->classSum;
    }

    public function hasPartRarerThan(int $frequencyClass): bool
    {
        return $this->rarestClass > $frequencyClass;
    }

    public function hasMeanClassOfAtLeast(int $frequencyClass): bool
    {
        return $this->classSum >= $frequencyClass * $this->partCount();
    }
}
