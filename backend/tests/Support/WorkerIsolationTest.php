<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

use function getenv;
use function putenv;

// No #[CoversClass]: phpunit.dist.xml scopes <source> to src/, so a test-support
// class is not a valid coverage target and the attribute warns under coverage.
final class WorkerIsolationTest extends TestCase
{
    private const array TOUCHED_NAMES = ['TEST_TOKEN', 'DATABASE_URL', 'CACHE_DIRECTORY', 'SYMFONY_DOTENV_VARS'];

    /** @var array<mixed> */
    private array $savedServer = [];

    /** @var array<mixed> */
    private array $savedEnv = [];

    /** @var array<string, string|false> */
    private array $savedProcessEnv = [];

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedEnv = $_ENV;

        foreach (self::TOUCHED_NAMES as $name) {
            $this->savedProcessEnv[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_ENV = $this->savedEnv;

        foreach ($this->savedProcessEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testAWorkerIsolatesItsDatabaseAndCacheDirectory(): void
    {
        $this->startWorker('3', 'APP_ENV,DATABASE_URL,CACHE_DIRECTORY');

        WorkerIsolation::applyToEnvironment();

        self::assertSame('sqlite:///var/data_test3.db', $_SERVER['DATABASE_URL']);
        self::assertSame('/pools/app3', $_SERVER['CACHE_DIRECTORY']);
    }

    public function testAnIsolatedValueIsNoLongerDotenvsToOverwrite(): void
    {
        $this->startWorker('3', 'APP_ENV,DATABASE_URL,CACHE_DIRECTORY,OTHER');

        WorkerIsolation::applyToEnvironment();

        self::assertSame('APP_ENV,OTHER', $_SERVER['SYMFONY_DOTENV_VARS']);
        self::assertSame('APP_ENV,OTHER', $_ENV['SYMFONY_DOTENV_VARS']);
        self::assertSame('APP_ENV,OTHER', getenv('SYMFONY_DOTENV_VARS'));
    }

    public function testAChildProcessDotenvKeepsTheIsolatedValues(): void
    {
        $this->startWorker('3', 'DATABASE_URL,CACHE_DIRECTORY');
        WorkerIsolation::applyToEnvironment();

        (new Dotenv())->populate(['DATABASE_URL' => 'sqlite:///var/data_test.db', 'CACHE_DIRECTORY' => '/pools/app']);

        self::assertSame('sqlite:///var/data_test3.db', $_SERVER['DATABASE_URL']);
        self::assertSame('/pools/app3', $_SERVER['CACHE_DIRECTORY']);
    }

    public function testDotenvsVariableListMayEndUpEmpty(): void
    {
        $this->startWorker('3', 'DATABASE_URL,CACHE_DIRECTORY');

        WorkerIsolation::applyToEnvironment();

        self::assertSame('', $_SERVER['SYMFONY_DOTENV_VARS']);
    }

    public function testASerialRunChangesNothing(): void
    {
        $this->startWorker('', 'APP_ENV,DATABASE_URL,CACHE_DIRECTORY');

        WorkerIsolation::applyToEnvironment();

        self::assertSame('sqlite:///var/data_test.db', $_SERVER['DATABASE_URL']);
        self::assertSame('/pools/app', $_SERVER['CACHE_DIRECTORY']);
        self::assertSame('APP_ENV,DATABASE_URL,CACHE_DIRECTORY', $_SERVER['SYMFONY_DOTENV_VARS']);
    }

    private function startWorker(string $token, string $dotenvVariables): void
    {
        $this->set('TEST_TOKEN', $token);
        $this->set('DATABASE_URL', 'sqlite:///var/data_test.db');
        $this->set('CACHE_DIRECTORY', '/pools/app');
        $this->set('SYMFONY_DOTENV_VARS', $dotenvVariables);
    }

    private function set(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }
}
