<?php

declare(strict_types=1);

namespace App\Repository;

/** The run a recorded call's liveness and usage land on: a recommendation run or a profile run. */
final readonly class CallingRun
{
    private function __construct(public string $table, public int $id)
    {
    }

    public static function recommendationRun(int $id): self
    {
        return new self('recommendation_run', $id);
    }

    public static function profileRun(int $id): self
    {
        return new self('profile_run', $id);
    }
}
