<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\OtelTraceContext;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class OtelTraceContextTest extends TestCase
{
    public function testReturnsNullWhenNoSpanIsActive(): void
    {
        $context = new OtelTraceContext();

        self::assertNull($context->traceId());
        self::assertNull($context->spanId());
    }

    public function testReturnsTheActiveSpanIdsWhileASpanIsActivatedAndNullAfterItEnds(): void
    {
        $tracer = (new TracerProvider())->getTracer('test');
        $span = $tracer->spanBuilder('test-span')->startSpan();
        $scope = $span->activate();

        $context = new OtelTraceContext();

        self::assertNotNull($context->traceId());
        self::assertNotNull($context->spanId());

        $scope->detach();
        $span->end();

        self::assertNull($context->traceId());
        self::assertNull($context->spanId());
    }
}
