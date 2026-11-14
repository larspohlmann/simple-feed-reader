<?php

declare(strict_types=1);

namespace App\Service\Process;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The redirects keep any pipe from holding the request open and `&` lets exec() return; this recipe survived a live
 * FastCGI teardown on production (#371). A web SAPI's \PHP_BINARY cannot run bin/console: only cli falls back to it.
 */
final readonly class DetachedConsoleCommandLine
{
    public function __construct(
        #[Autowire('%env(DRAIN_PHP_CLI_BINARY)%')] private string $configuredCliBinary,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        #[Autowire('%kernel.environment%')] private string $environment,
        private string $runningSapi = \PHP_SAPI,
        private string $runningPhpBinary = \PHP_BINARY,
    ) {
    }

    public function forCommand(string $consoleCommandName, string ...$arguments): ?string
    {
        $cliBinary = $this->cliBinary();
        if (null === $cliBinary) {
            return null;
        }

        $argv = array_map(escapeshellarg(...), [
            $cliBinary,
            $this->projectDir . '/bin/console',
            $consoleCommandName,
            ...$arguments,
        ]);

        return sprintf(
            '%s --env=%s </dev/null >/dev/null 2>&1 &',
            implode(' ', $argv),
            escapeshellarg($this->environment),
        );
    }

    private function cliBinary(): ?string
    {
        if ('' !== $this->configuredCliBinary) {
            return $this->configuredCliBinary;
        }

        return 'cli' === $this->runningSapi ? $this->runningPhpBinary : null;
    }
}
