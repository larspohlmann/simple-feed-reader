<?php

declare(strict_types=1);

namespace App\Service\Process;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the shell line that launches a console command fully detached from
 * the current request: stdin/stdout/stderr point at /dev/null so no shared
 * pipe holds the request open, and the trailing `&` backgrounds the child so
 * exec() returns immediately. This exact recipe survived a live FastCGI
 * teardown on the production host (#371 smoke test, 2026-08-13).
 *
 * The binary is a policy, not a lookup: under a web SAPI the running binary
 * is the SAPI binary (Strato: cgi-fcgi) and must never be used to run
 * bin/console, so it has to be named explicitly via DRAIN_PHP_CLI_BINARY.
 * Only under the cli SAPI is \PHP_BINARY itself already the right answer,
 * which is what lets a CLI process spawn without configuration -- a developer
 * shell, a console command, the Docker worker container. The Docker *web*
 * container runs fpm-fcgi, so a request there builds no line at all unless
 * DRAIN_PHP_CLI_BINARY names one; it needs none, because its worker keeps the
 * heartbeat fresh and the spawn is suppressed anyway. With neither, forCommand()
 * returns null and the caller must treat the launch as unavailable.
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
