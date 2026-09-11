<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\RequestIdProvider;
use App\Service\Logging\RequestLogProcessor;
use App\Service\Logging\TraceContext;
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
        $processor = new RequestLogProcessor($provider, $this->noTracingContext());
        $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true, false, true);

        $record = $processor(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello', ['k' => 'v']));
        $line = $formatter->format($record);

        self::assertStringEndsWith("\n", $line);
        $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('hello', $decoded['message']);
        self::assertSame('app', $decoded['channel']);
        self::assertSame('INFO', $decoded['level_name']);
        $extra = $decoded['extra'];
        self::assertIsArray($extra);
        self::assertSame('01J000000000000000000TEST', $extra['request_id']);
        $context = $decoded['context'];
        self::assertIsArray($context);
        self::assertSame('v', $context['k']);
    }

    private function noTracingContext(): TraceContext
    {
        return new class implements TraceContext {
            public function traceId(): ?string
            {
                return null;
            }

            public function spanId(): ?string
            {
                return null;
            }
        };
    }
}
