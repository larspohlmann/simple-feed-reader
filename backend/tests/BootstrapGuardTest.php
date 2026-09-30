<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BootstrapGuardTest extends TestCase
{
    private const string SENTINEL = 'not a database';

    private string $databaseFile;

    protected function setUp(): void
    {
        $this->databaseFile = sys_get_temp_dir() . '/bootstrap-guard-' . bin2hex(random_bytes(6)) . '.db';
        file_put_contents($this->databaseFile, self::SENTINEL);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->databaseFile)) {
            unlink($this->databaseFile);
        }
    }

    public function testTheBootstrapRefusesToRunOutsideTheTestEnvironment(): void
    {
        $bootstrap = $this->runBootstrapIn('dev');

        self::assertSame(1, $bootstrap->getExitCode());
        self::assertStringContainsString('APP_ENV=test', $bootstrap->getErrorOutput());
        self::assertStringEqualsFile($this->databaseFile, self::SENTINEL);
    }

    private function runBootstrapIn(string $environment): Process
    {
        // SYMFONY_DOTENV_VARS would let the child's Dotenv swap the throwaway DATABASE_URL for the real dev one.
        $bootstrap = new Process(
            [PHP_BINARY, 'tests/bootstrap.php'],
            dirname(__DIR__),
            [
                'APP_ENV' => $environment,
                'DATABASE_URL' => 'sqlite:///' . $this->databaseFile,
                'SYMFONY_DOTENV_VARS' => false,
                'TEST_TOKEN' => false,
            ],
        );
        $bootstrap->run();

        return $bootstrap;
    }
}
