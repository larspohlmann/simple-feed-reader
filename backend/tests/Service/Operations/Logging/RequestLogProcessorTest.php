<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\RequestIdProvider;
use App\Service\Logging\RequestLogProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RequestLogProcessorTest extends TestCase
{
    public function testStampsTheRequestId(): void
    {
        $provider = new RequestIdProvider();
        $provider->set('01J000000000000000000TEST');

        $record = new RequestLogProcessor($provider)($this->record([]));

        self::assertSame('01J000000000000000000TEST', $record->extra['request_id']);
    }

    public function testKeepsTheExtrasAnEarlierProcessorAdded(): void
    {
        $provider = new RequestIdProvider();
        $provider->set('01J000000000000000000TEST');

        $record = new RequestLogProcessor($provider)($this->record(['memory_peak' => '12 MB']));

        self::assertSame(['memory_peak' => '12 MB', 'request_id' => '01J000000000000000000TEST'], $record->extra);
    }

    /** @param array<string, mixed> $extra */
    private function record(array $extra): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello', extra: $extra);
    }
}
