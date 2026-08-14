<?php

declare(strict_types=1);

namespace App\Service\Process;

final readonly class ExecShellCommandRunner implements ShellCommandRunnerInterface
{
    public function runDetached(string $shellCommandLine): void
    {
        // The line's own redirects and trailing `&` make this return
        // immediately; the shell, not this process, owns the child.
        exec($shellCommandLine);
    }
}
