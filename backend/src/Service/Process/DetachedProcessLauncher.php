<?php

declare(strict_types=1);

namespace App\Service\Process;

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
            // Swallowing a Throwable is this class's documented contract,
            // not an oversight: the launch is a speed-up, and a host that
            // cannot spawn must degrade to the poll/cron path without the
            // user ever seeing an error (#371).
            $this->logger->info('Detached launch failed; the poll/cron path carries the work.', [
                'command' => $consoleCommandName,
                'exception' => $exception,
            ]);
        }
    }
}
