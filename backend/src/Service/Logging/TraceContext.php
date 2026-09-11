<?php

declare(strict_types=1);

namespace App\Service\Logging;

interface TraceContext
{
    public function traceId(): ?string;

    public function spanId(): ?string;
}
