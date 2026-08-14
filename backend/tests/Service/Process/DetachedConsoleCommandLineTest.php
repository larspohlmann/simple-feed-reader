<?php

declare(strict_types=1);

namespace App\Tests\Service\Process;

use App\Service\Process\DetachedConsoleCommandLine;
use PHPUnit\Framework\TestCase;

final class DetachedConsoleCommandLineTest extends TestCase
{
    public function testBuildsTheFullDetachedShellLineFromTheConfiguredBinary(): void
    {
        $line = $this->commandLine(configuredCliBinary: '/opt/RZphp84/bin/php-cli', runningSapi: 'cgi-fcgi')
            ->forCommand('app:recommendations:drain', '--detach');

        self::assertSame(
            "'/opt/RZphp84/bin/php-cli' '/srv/app/bin/console' 'app:recommendations:drain' '--detach'"
            . " --env='prod' </dev/null >/dev/null 2>&1 &",
            $line,
        );
    }

    /**
     * Under the cli SAPI, \PHP_BINARY IS a CLI binary, so no configuration
     * is needed -- this is what lets a developer machine and the Docker
     * containers spawn without setting the env var.
     */
    public function testFallsBackToTheRunningBinaryOnlyUnderTheCliSapi(): void
    {
        $line = $this->commandLine(configuredCliBinary: '', runningSapi: 'cli')
            ->forCommand('app:recommendations:drain');

        self::assertNotNull($line);
        self::assertStringStartsWith("'/usr/bin/php'", $line);
    }

    /**
     * A web SAPI's own binary (Strato: cgi-fcgi) cannot run bin/console, and
     * guessing a path would spawn garbage -- refusing to build a line is what
     * makes the whole feature silently self-disable on such a host until the
     * env var names the real CLI binary.
     */
    public function testRefusesToBuildALineUnderAWebSapiWithoutConfiguration(): void
    {
        self::assertNull(
            $this->commandLine(configuredCliBinary: '', runningSapi: 'cgi-fcgi')
                ->forCommand('app:recommendations:drain'),
        );
    }

    public function testShellArgumentsAreEscaped(): void
    {
        $line = (new DetachedConsoleCommandLine(
            configuredCliBinary: '/opt/php cli/php',
            projectDir: "/srv/o'app",
            environment: 'prod',
            runningSapi: 'cgi-fcgi',
            runningPhpBinary: '/usr/bin/php',
        ))->forCommand('app:recommendations:drain');

        self::assertNotNull($line);
        self::assertStringContainsString(escapeshellarg('/opt/php cli/php'), $line);
        self::assertStringContainsString(escapeshellarg("/srv/o'app/bin/console"), $line);
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
