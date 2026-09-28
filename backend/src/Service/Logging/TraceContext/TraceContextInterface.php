<?php

declare(strict_types=1);

namespace App\Service\Logging\TraceContext;

interface TraceContextInterface
{
    public function traceId(): ?string;

    public function spanId(): ?string;
}
