<?php

declare(strict_types=1);

namespace App\Repository;

/** The run a recorded call's liveness, usage and adjustments land on: a recommendation run or a profile run. */
final readonly class CallingRun
{
    private function __construct(public string $table, public string $logOwnerColumn, public int $id)
    {
    }

    public static function recommendationRun(int $id): self
    {
        return new self('recommendation_run', 'run_id', $id);
    }

    public static function profileRun(int $id): self
    {
        return new self('profile_run', 'profile_run_id', $id);
    }
}
