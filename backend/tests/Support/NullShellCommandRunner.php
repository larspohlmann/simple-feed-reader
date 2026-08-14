<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Process\ShellCommandRunnerInterface;

/**
 * The test environment must never actually fork a drainer: a real child
 * process would race the suite's database and outlive the run. Swallowing
 * the line here keeps every container-wired code path identical to
 * production up to the final exec().
 */
final class NullShellCommandRunner implements ShellCommandRunnerInterface
{
    public function runDetached(string $shellCommandLine): void
    {
    }
}
