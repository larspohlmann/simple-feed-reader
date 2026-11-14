<?php

declare(strict_types=1);

namespace App\Service\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final readonly class RequestLogProcessor implements ProcessorInterface
{
    public function __construct(private RequestIdProvider $requestId)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [...$record->extra, 'request_id' => $this->requestId->current()]);
    }
}
