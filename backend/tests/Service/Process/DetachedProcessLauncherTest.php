<?php

declare(strict_types=1);

namespace App\Tests\Service\Process;

use App\Service\Process\DetachedConsoleCommandLine;
use App\Service\Process\DetachedProcessLauncher;
use App\Service\Process\ShellCommandRunnerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DetachedProcessLauncherTest extends TestCase
{
    public function testLaunchHandsTheBuiltLineToTheShell(): void
    {
        $shell = $this->recordingShell();
        $this->launcher($shell)->launch('app:recommendations:drain', '--detach');

        self::assertSame(
            ["'/opt/php-cli' '/srv/app/bin/console' 'app:recommendations:drain' '--detach'"
                . " --env='prod' </dev/null >/dev/null 2>&1 &"],
            $shell->lines,
        );
    }

    /**
     * No CLI binary known means no line to run: the shell must not be
     * invoked at all, because any guessed line would spawn garbage.
     */
    public function testLaunchWithoutACliBinaryNeverTouchesTheShell(): void
    {
        $shell = $this->recordingShell();
        $launcher = new DetachedProcessLauncher(
            $this->commandLine(configuredCliBinary: '', runningSapi: 'cgi-fcgi'),
            $shell,
            new NullLogger(),
        );

        $launcher->launch('app:recommendations:drain');

        self::assertSame([], $shell->lines);
    }

    /**
     * The launcher's contract is best-effort and silent (#371): on a host
     * with exec() in disable_functions the call is an \Error, and the
     * request must fall back to today's one-step advance rather than 500.
     */
    public function testAThrowingShellIsSwallowed(): void
    {
        $shell = new class implements ShellCommandRunnerInterface {
            public function runDetached(string $shellCommandLine): void
            {
                throw new \Error('Call to undefined function exec()');
            }
        };

        $this->launcher($shell)->launch('app:recommendations:drain');

        $this->addToAssertionCount(1);
    }

    /**
     * @return ShellCommandRunnerInterface&object{lines: list<string>}
     */
    private function recordingShell(): ShellCommandRunnerInterface
    {
        return new class implements ShellCommandRunnerInterface {
            /** @var list<string> */
            public array $lines = [];

            public function runDetached(string $shellCommandLine): void
            {
                $this->lines[] = $shellCommandLine;
            }
        };
    }

    private function launcher(ShellCommandRunnerInterface $shell): DetachedProcessLauncher
    {
        return new DetachedProcessLauncher(
            $this->commandLine(configuredCliBinary: '/opt/php-cli', runningSapi: 'cgi-fcgi'),
            $shell,
            new NullLogger(),
        );
    }

    private function commandLine(string $configuredCliBinary, string $runningSapi): DetachedConsoleCommandLine
    {
        return new DetachedConsoleCommandLine(
            configuredCliBinary: $configuredCliBinary,
            projectDir: '/srv/app',
            environment: 'prod',
            runningSapi: $runningSapi,
            runningPhpBinary: '/usr/bin/php',
        );
    }
}
