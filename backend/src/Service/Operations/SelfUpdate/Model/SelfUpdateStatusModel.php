<?php

declare(strict_types=1);

namespace App\Service\Operations\SelfUpdate\Model;

final readonly class SelfUpdateStatusModel
{
    public function __construct(
        public bool $updaterPresent,
        public SelfUpdatePhase $phase,
        public ?UpdateRunModel $currentRun,
        public string $log,
    ) {
    }
}
