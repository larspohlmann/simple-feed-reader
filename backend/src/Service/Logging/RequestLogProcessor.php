<?php

declare(strict_types=1);

namespace App\Service\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final readonly class RequestLogProcessor implements ProcessorInterface
{
    public function __construct(
        private RequestIdProvider $requestId,
        private TraceContext $trace,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        $extra['request_id'] = $this->requestId->current();

        $traceId = $this->trace->traceId();
        $spanId = $this->trace->spanId();
        if (null !== $traceId && null !== $spanId) {
            $extra['trace_id'] = $traceId;
            $extra['span_id'] = $spanId;
        }

        return $record->with(extra: $extra);
    }
}
