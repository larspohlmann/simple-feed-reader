<?php

declare(strict_types=1);

namespace App\Service\SelfUpdate\Model;

final readonly class SelfUpdateStatusModel
{
    public function __construct(
        public bool $updaterPresent,
        public SelfUpdatePhase $phase,
        public ?UpdateRunModel $lastRun,
        public string $log,
    ) {
    }
}
