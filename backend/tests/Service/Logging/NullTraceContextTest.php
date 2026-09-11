<?php

declare(strict_types=1);

namespace App\Tests\Service\Logging;

use App\Service\Logging\NullTraceContext;
use PHPUnit\Framework\TestCase;

final class NullTraceContextTest extends TestCase
{
    public function testReturnsNoTraceOrSpanWhenTracingIsAbsent(): void
    {
        $context = new NullTraceContext();

        self::assertNull($context->traceId());
        self::assertNull($context->spanId());
    }
}
