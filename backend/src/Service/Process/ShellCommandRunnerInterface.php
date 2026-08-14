<?php

declare(strict_types=1);

namespace App\Service\Process;

/**
 * The one real side effect of a detached launch, isolated so the launcher's
 * policy is testable without forking a process.
 */
interface ShellCommandRunnerInterface
{
    public function runDetached(string $shellCommandLine): void;
}
