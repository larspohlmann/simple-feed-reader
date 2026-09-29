<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Process\ShellCommandRunner\ShellCommandRunnerInterface;

/** Never forks in tests: a real child would race the suite's database and outlive the run. */
final class NullShellCommandRunner implements ShellCommandRunnerInterface
{
    public function runDetached(string $shellCommandLine): void
    {
    }
}
