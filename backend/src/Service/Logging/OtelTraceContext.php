<?php

declare(strict_types=1);

namespace App\Service\Logging;

use OpenTelemetry\API\Trace\Span;

final class OtelTraceContext implements TraceContext
{
    public function traceId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid() ? $context->getTraceId() : null;
    }

    public function spanId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid() ? $context->getSpanId() : null;
    }
}
