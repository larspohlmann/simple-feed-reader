<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\NullTraceContext;
use App\Service\Logging\RequestIdProvider;
use App\Service\Logging\RequestLogProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class JsonLogFormatTest extends TestCase
{
    public function testFormattedLineIsJsonCarryingTheRequestId(): void
    {
        $provider = new RequestIdProvider();
        $provider->set('01J000000000000000000TEST');
        $processor = new RequestLogProcessor($provider, new NullTraceContext());
        $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true, false, true);

        $record = $processor(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello', ['k' => 'v']));
        $line = $formatter->format($record);

        self::assertStringEndsWith("\n", $line);
        $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('hello', $decoded['message']);
        self::assertSame('app', $decoded['channel']);
        self::assertSame('INFO', $decoded['level_name']);
        self::assertSame('01J000000000000000000TEST', $decoded['extra']['request_id']);
        self::assertSame('v', $decoded['context']['k']);
    }
}
