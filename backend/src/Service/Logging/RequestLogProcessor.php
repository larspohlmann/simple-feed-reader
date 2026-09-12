<?php

declare(strict_types=1);

namespace App\Service\Logging;

use App\Service\Logging\Loki\LokiPushHandler;
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

        // A client error's active span is the ingest request's, not the
        // browser's, so tagging it would offer a trace link that leads nowhere
        // meaningful. Leave it untagged; the Loki->Tempo link then never shows.
        if (LokiPushHandler::CLIENT_ERRORS_CHANNEL === $record->channel) {
            return $record->with(extra: $extra);
        }

        $traceId = $this->trace->traceId();
        $spanId = $this->trace->spanId();
        if (null !== $traceId && null !== $spanId) {
            $extra['trace_id'] = $traceId;
            $extra['span_id'] = $spanId;
        }

        return $record->with(extra: $extra);
    }
}
