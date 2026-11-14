<?php

declare(strict_types=1);

namespace App\Service\Process\DetachedProcessLauncher;

use App\Service\Process\DetachedConsoleCommandLine;
use App\Service\Process\ShellCommandRunner\ShellCommandRunnerInterface;
use Psr\Log\LoggerInterface;

final readonly class DetachedProcessLauncher implements DetachedProcessLauncherInterface
{
    public function __construct(
        private DetachedConsoleCommandLine $commandLine,
        private ShellCommandRunnerInterface $shell,
        private LoggerInterface $logger,
    ) {
    }

    public function launch(string $consoleCommandName, string ...$arguments): void
    {
        try {
            $shellCommandLine = $this->commandLine->forCommand($consoleCommandName, ...$arguments);
            if (null === $shellCommandLine) {
                $this->logger->debug('Detached launch skipped: no CLI php binary is known on this host.', [
                    'command' => $consoleCommandName,
                ]);

                return;
            }

            $this->shell->runDetached($shellCommandLine);
        } catch (\Throwable $exception) {
            // Never rethrow: the interface promises best-effort, and the poll/cron path carries the work.
            $this->logger->info('Detached launch failed; the poll/cron path carries the work.', [
                'command' => $consoleCommandName,
                'exception' => $exception,
            ]);
        }
    }
}
