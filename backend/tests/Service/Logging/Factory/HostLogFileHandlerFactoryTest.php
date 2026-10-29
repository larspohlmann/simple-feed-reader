<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging\Factory;

use App\Service\Logging\Factory\HostLogFileHandlerFactory;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class HostLogFileHandlerFactoryTest extends TestCase
{
    private string $logsDirectory;

    protected function setUp(): void
    {
        $this->logsDirectory = sys_get_temp_dir() . '/host-log-' . bin2hex(random_bytes(4));
        mkdir($this->logsDirectory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->logsDirectory . '/*') ?: []);
        rmdir($this->logsDirectory);
    }

    public function testWritesTodaysFileNamedAfterThisHostAsJson(): void
    {
        $factory = new HostLogFileHandlerFactory($this->logsDirectory, 'prod', new JsonFormatter());
        $logger = new Logger('app', [$factory->create()]);

        $logger->info('Tick finished.');

        $expected = \sprintf('%s/prod-%s-%s.log', $this->logsDirectory, gethostname(), date('Y-m-d'));
        self::assertFileExists($expected);
        self::assertStringContainsString('"message":"Tick finished."', (string) file_get_contents($expected));
    }

    public function testDropsRecordsBelowInfo(): void
    {
        $factory = new HostLogFileHandlerFactory($this->logsDirectory, 'prod', new JsonFormatter());

        $debug = new LogRecord(new \DateTimeImmutable(), 'app', Level::Debug, 'Debug detail.');

        self::assertFalse($factory->create()->isHandling($debug));
    }

    public function testFallsBackToTheSharedNameWithoutAHost(): void
    {
        self::assertSame('/logs/prod.log', HostLogFileHandlerFactory::logFileFor('/logs', 'prod', false));
        self::assertSame('/logs/prod.log', HostLogFileHandlerFactory::logFileFor('/logs', 'prod', ''));
    }
}
