<?php

declare(strict_types=1);

namespace App\Service\Process;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the shell line that launches a console command fully detached from
 * the current request: stdin/stdout/stderr point at /dev/null so no pipe
 * holds the request open, and a trailing `&` backgrounds the child so exec()
 * returns immediately. This recipe survived a live FastCGI teardown on the
 * production host (#371 smoke test, 2026-08-13).
 *
 * The binary is a policy, not a lookup. Under a web SAPI the running binary
 * is the SAPI binary (Strato: cgi-fcgi) and must never run bin/console, so it
 * must be named via DRAIN_PHP_CLI_BINARY. Only the cli SAPI can use
 * \PHP_BINARY as-is, so a dev shell, console command, or Docker worker
 * container spawns unconfigured. The Docker *web* container (fpm-fcgi) needs
 * neither: its worker keeps the heartbeat fresh and the spawn is suppressed
 * anyway. With neither set, forCommand() returns null and the caller must
 * treat the launch as unavailable.
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
