<?php

declare(strict_types=1);

namespace App\Service\Operations\Logging\Loki\LokiSink;

use App\Service\Operations\Logging\Loki\LokiClient;

final readonly class DirectLokiSink implements LokiSinkInterface
{
    public function __construct(private LokiClient $client)
    {
    }

    public function write(array $lines): void
    {
        $this->client->push($lines);
    }
}
