<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** When each named stage first happened, on a test's ticking clock. */
final class StageStamps
{
    /** @var array<string, \DateTimeImmutable> */
    private array $firstSeen = [];

    public function record(string $stage, \DateTimeImmutable $at): void
    {
        $this->firstSeen[$stage] ??= $at;
    }

    /** @return list<string> */
    public function stages(): array
    {
        return array_keys($this->firstSeen);
    }

    public function at(string $stage): \DateTimeImmutable
    {
        return $this->firstSeen[$stage];
    }
}
