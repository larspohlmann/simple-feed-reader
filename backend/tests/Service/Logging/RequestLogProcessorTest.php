<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\RequestIdProvider;
use App\Service\Logging\RequestLogProcessor;
use App\Service\Logging\TraceContext;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RequestLogProcessorTest extends TestCase
{
    public function testStampsRequestIdWithoutTraceWhenNoSpanIsActive(): void
    {
        $provider = new RequestIdProvider();
        $provider->set('01J000000000000000000TEST');
        $processor = new RequestLogProcessor($provider, $this->partialTracingContext(null, null));

        $record = $processor($this->record());

        self::assertSame('01J000000000000000000TEST', $record->extra['request_id']);
        self::assertArrayNotHasKey('trace_id', $record->extra);
        self::assertArrayNotHasKey('span_id', $record->extra);
    }

    public function testStampsTraceAndSpanWhenASpanIsActive(): void
    {
        $provider = new RequestIdProvider();
        $processor = new RequestLogProcessor($provider, $this->tracingContext('trace-abc', 'span-xyz'));

        $record = $processor($this->record());

        self::assertSame('trace-abc', $record->extra['trace_id']);
        self::assertSame('span-xyz', $record->extra['span_id']);
    }

    public function testOmitsBothIdsWhenOnlyATraceIdIsPresent(): void
    {
        $provider = new RequestIdProvider();
        $processor = new RequestLogProcessor($provider, $this->partialTracingContext('trace-abc', null));

        $record = $processor($this->record());

        self::assertArrayNotHasKey('trace_id', $record->extra);
        self::assertArrayNotHasKey('span_id', $record->extra);
    }

    public function testOmitsBothIdsWhenOnlyASpanIdIsPresent(): void
    {
        $provider = new RequestIdProvider();
        $processor = new RequestLogProcessor($provider, $this->partialTracingContext(null, 'span-xyz'));

        $record = $processor($this->record());

        self::assertArrayNotHasKey('trace_id', $record->extra);
        self::assertArrayNotHasKey('span_id', $record->extra);
    }

    private function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello');
    }

    private function tracingContext(string $traceId, string $spanId): TraceContext
    {
        return new class ($traceId, $spanId) implements TraceContext {
            public function __construct(private string $traceId, private string $spanId)
            {
            }

            public function traceId(): string
            {
                return $this->traceId;
            }

            public function spanId(): string
            {
                return $this->spanId;
            }
        };
    }

    private function partialTracingContext(?string $traceId, ?string $spanId): TraceContext
    {
        return new class ($traceId, $spanId) implements TraceContext {
            public function __construct(private ?string $traceId, private ?string $spanId)
            {
            }

            public function traceId(): ?string
            {
                return $this->traceId;
            }

            public function spanId(): ?string
            {
                return $this->spanId;
            }
        };
    }
}
