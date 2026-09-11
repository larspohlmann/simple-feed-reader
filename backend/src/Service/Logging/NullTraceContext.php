<?php

declare(strict_types=1);

namespace App\Service\Logging;

final class NullTraceContext implements TraceContext
{
    public function traceId(): ?string
    {
        return null;
    }

    public function spanId(): ?string
    {
        return null;
    }
}
