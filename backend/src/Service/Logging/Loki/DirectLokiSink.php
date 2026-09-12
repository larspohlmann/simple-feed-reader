<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

final readonly class DirectLokiSink implements LokiSink
{
    public function __construct(private LokiClient $client)
    {
    }

    public function write(array $lines): void
    {
        $this->client->push($lines);
    }
}
